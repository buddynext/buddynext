# Conformance: Email Broadcasts (Pro)

**Feature:** Email Broadcasts
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/06-notifications-email.md` (§ Pro/Broadcast, § Email Infrastructure) + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/broadcast-email.md`
**Verdict:** broken-journey
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (+ Mailpit)

## Summary

The broadcast sub-system is almost entirely built and correctly wired: admin UI, REST surface, segment resolution, recipient queueing, cron batching, per-campaign and global unsubscribe, and DB persistence all check out and are bootstrapped in `Plugin.php`. The journey breaks at the single most important link — actual email delivery. When a campaign is dispatched, the cron worker marks every recipient `sent` while **no campaign email ever leaves the server**, because the send call neither carries the campaign content nor matches a seeded template.

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens Broadcasts page (sidebar tab + legacy `?page=buddynextpro-broadcasts`) | ui | wired | `buddynext-pro/includes/Admin/BroadcastAdmin.php:75-111` |
| 2 | Admin fills New Campaign form + submits → create | ui→service→db | wired | `BroadcastAdmin.php:118-160`; `Email/BroadcastService.php:100-134` |
| 3 | Campaign row persisted (status=draft, segment_filter JSON) | db | wired | `BroadcastService.php:111-133` |
| 4 | Send Test → wp_mail with campaign subject/body to admin | ui→service | wired | `BroadcastAdmin.php:203-238` (direct `wp_mail`, real content) |
| 5 | Send Now → dispatch resolves segment, queues recipients, status=sending | ui→service→db | wired | `BroadcastAdmin.php:167-196`; `BroadcastService.php:334-387`; `SegmentService.php:38-81` |
| 6 | Cron `buddynextpro_broadcast_send_pending` picks queued batch | service | wired | `BroadcastService.php:85-89, 399-419, 553-590` |
| 7 | Worker delivers the campaign email to each recipient | service→delivery | **broken** | `BroadcastService.php:447-457`; `buddynext/includes/Notifications/EmailSender.php:104-108,245-256`; `buddynext/includes/Core/Installer.php:74-206` |
| 8 | Recipient marked sent / sent_at populated | db | wired (but false-positive) | `BroadcastService.php:459-470` |
| 9 | Per-campaign one-click unsubscribe (HMAC, `init`) | service | wired | `Email/BroadcastUnsubscribe.php:47-105,234-251` |
| 10 | Global opt-out toggle on user profile + REST `me/email-preferences` | ui/rest | wired | `BroadcastUnsubscribe.php:175-223`; `Controllers/EmailUnsubscribeController.php:67-125` |
| 11 | Unsubscribe respected on future sends | service | wired | `BroadcastService.php:429-445` |

## First break

**Step 7 — the cron worker delivers nothing.** `send_pending()` calls:

```php
$sender->send_now( $user_id, 'bn.broadcast', array( 'campaign_id' => $campaign_id, 'type' => 'bn.broadcast' ) );
```
(`BroadcastService.php:450-457`)

Two compounding defects make this a silent no-op:

1. **No `bn.broadcast` template is seeded.** `EmailSender::send_now()` loads subject/body from a `bn_email_templates` row `WHERE type = 'bn.broadcast'` (`EmailSender.php:245-256`) and returns early at lines 106-108 when the row is null. The Installer seeds template types only up to `bn.onboarding_nudge` (`Installer.php:74-206`); `bn.broadcast` is absent. Result: `send_now()` returns before `wp_mail()` — zero email.
2. **The campaign content is never passed.** Even with a seeded template, `send_now()` only receives `campaign_id` + `type`, not the campaign's `subject`/`body_html`. It would render the generic template, never the per-campaign content the admin authored.

There is a designed seam for Pro to fix this without touching Free — the `buddynext_email_payload` filter inside `send_now()` (`EmailSender.php:129-148`) can override `to/subject/body`. Pro **only references it in a comment** (`BroadcastService.php:448-449`) and never registers a handler. So the seam exists but is unused.

Meanwhile `send_pending()` unconditionally marks each recipient `status='sent'` (`BroadcastService.php:459-470`), and the admin Recipients view (`BroadcastAdmin.php:389-444`) reports success — a false positive masking total delivery failure.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|-----------|----------|
| Dispatched broadcast delivers no email to any recipient; campaign content never reaches the send path and no matching template is seeded | critical | confirmed-in-code | `BroadcastService.php:450-457`; `EmailSender.php:104-108,245-256`; `Installer.php:74-206` |
| Recipients marked `sent` despite nothing being sent; admin Recipients view shows false success | high | confirmed-in-code | `BroadcastService.php:459-470`; `BroadcastAdmin.php:389-444` |
| Journey doc's REST unsubscribe path `GET /buddynext-pro/v1/email/unsubscribe` does not exist; actual one-click unsubscribe is `init`-handler `?bn_unsub_campaign=&bid=&uid=` (functional, but doc is wrong) | low | confirmed-in-code | journey `broadcast-email.md:108-114,164`; `BroadcastUnsubscribe.php:63-105` (no such REST route in `Controllers/`) |

Note for app/REST clients: the create/list/dispatch REST surface is fully wired and admin-gated (`BroadcastController.php:73-172,309-315`); the delivery break is in the shared send path, so it affects both web-admin and REST-driven dispatch equally.

## Minimal refactor plan

Reuse the existing `buddynext_email_payload` seam — no Free changes, no new abstractions.

1. In `BroadcastService`, register an `add_filter('buddynext_email_payload', ...)` handler during `register()`. When `$context['type'] === 'bn.broadcast'`, load the campaign via `$context['campaign_id']`, and override the returned payload's `subject` and `body` with the campaign's `subject` / `body_html` (rendered through Free's token replacement). This makes `send_now()` deliver real campaign content.
2. Because `send_now()` still short-circuits when `get_template('bn.broadcast')` returns null (`EmailSender.php:105-108`), seed a minimal enabled sentinel `bn.broadcast` row in the Pro installer (subject/body can be placeholder `{{subject}}`/`{{body}}` since step 1 overrides them) so the early-return is not hit. Mirror the same for `bn.drip_step` if Drip shares the path.
3. Only mark a recipient `sent` when the payload was actually handed to `wp_mail()` (i.e. delivery not suppressed and template resolved); otherwise mark `failed` so the Recipients view stops reporting false success (`BroadcastService.php:459-470`).
4. Fix the journey doc unsubscribe section to the real `?bn_unsub_campaign` URL, or add the documented REST route — pick one and align doc + code.

## What to confirm in the live walk

- Create campaign → Send Test: expect a real `[TEST] ...` email in Mailpit (this path already uses direct `wp_mail` and should work).
- Send Now → run cron tick → check Mailpit: today this produces **no** recipient email while recipients flip to `sent`. After the fix, expect one rendered campaign email per segment member.
- Unsubscribe via the `?bn_unsub_campaign` link, re-dispatch, confirm that recipient is skipped (`status=unsubscribed`).
