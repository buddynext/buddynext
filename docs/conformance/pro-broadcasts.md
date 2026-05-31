# Conformance: Email Broadcasts (Pro)

**Feature:** Email Broadcasts
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/06-notifications-email.md` §Pro/Broadcast + journey `buddynext-pro/docs/journeys/broadcast-email.md`
**Verdict:** usable-leave-as-is

---

## Summary

The core broadcast happy-path — admin creates a campaign, sends a test, dispatches,
the cron sends to the resolved segment, and recipients are marked sent — is fully
wired end-to-end through a real admin UI (`admin.php?page=buddynextpro-broadcasts`,
also surfaced as the AdminHub "Growth → Broadcasts" tab), the `BroadcastService`,
the segment resolver, and Free's `EmailSender::send_now()` composed-email path.
A parallel admin REST surface (`buddynext-pro/v1/broadcasts*`) exists and is wired
for app/REST clients. Unsubscribe works via a signed HMAC `init` handler plus a
per-recipient opt-out check in the send loop. Nothing in the happy path is broken.

Two items in the **journey doc** describe surfaces that are not built the way the
doc claims, but neither stops the journey and neither is required by the locked spec:
the unsubscribe REST endpoint shape differs from the implemented `init` handler, and
no open/click tracking pixel is injected (recipients view shows Opened/Clicked but
they stay 0). These are documented below as gaps; they do not change the verdict.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens Broadcasts page (form + list) | ui | wired | `includes/Admin/BroadcastAdmin.php:99-111` (submenu + AdminHub tab `:84-91`) |
| Create campaign form → admin-post handler | ui→service | wired | `BroadcastAdmin.php:118-160` → `BroadcastService::create_campaign()` `includes/Email/BroadcastService.php:100-134` |
| Campaign row inserted (status=draft) | db | wired | `BroadcastService.php:127` insert into `bn_email_campaigns` |
| Send Test → wp_mail to admin | ui→service | wired | `BroadcastAdmin.php:203-238` (subject `[TEST] %s`, html body) |
| Send Now → dispatch | ui→service | wired | `BroadcastAdmin.php:167-196` → `BroadcastService::dispatch_campaign()` `:334-387` |
| Segment resolves recipient list | service | wired | `SegmentService::resolve()` `includes/Email/SegmentService.php:38-61` (all_users `:70-81`) |
| Recipients queued (status=queued) | db | wired | `BroadcastService.php:349-359` INSERT IGNORE `bn_campaign_recipients`; status→sending `:364-370` |
| Cron tick processes batch | service | wired | hook bound in `register()` `BroadcastService.php:85-89`; `send_pending()` `:399-482`; cron scheduled `:556-563` |
| Unsubscribe flags honored before send | service | wired | `send_pending` checks global/per-campaign opt-out `BroadcastService.php:430-445` |
| Email actually sent via Free | service | wired | `EmailSender::send_now()` composed-email path `buddynext/includes/Notifications/EmailSender.php:104-173` (inline subject/body honored `:112-135`) |
| Recipient marked sent / campaign marked sent | db | wired | `BroadcastService.php:463-472`, `maybe_mark_sent()` `:492-519` |
| Admin REST surface (app clients) | rest | wired | `BroadcastController` `includes/Email/Controllers/BroadcastController.php:73-172`, `manage_options` gate `:309-315`; registered `includes/Core/Plugin.php:288` |
| Per-recipient unsubscribe link | rest/service | wired (different shape than journey) | `BroadcastUnsubscribe::handle_unsub_request()` on `init`, `?bn_unsub_campaign=&bid=&uid=` `includes/Email/BroadcastUnsubscribe.php:47-105` |
| Global broadcast opt-out (profile + REST) | ui/rest | wired | profile toggle `BroadcastUnsubscribe.php:175-223`; `EmailUnsubscribeController` `me/email-preferences` `includes/Email/Controllers/EmailUnsubscribeController.php:67-125` |
| Open / click tracking | service | missing | no pixel injected in `dispatch_campaign`/`send_pending`; raw `body_html` sent `BroadcastService.php:451-460` |

All registration confirmed in `includes/Core/Plugin.php:159-170, 288-290`.

---

## First break

none — journey complete. The core create→test→dispatch→cron-send→sent path is wired
at every layer with real UI controls bound to working service methods.

---

## UX gaps (do not block the verdict)

1. **Open/click tracking not implemented (recipients view always shows Opened=0, Clicked=0).**
   Severity: low. Confidence: confirmed-in-code. The admin recipients breakdown renders
   Opened/Clicked rows (`BroadcastAdmin.php:401-417`) but no tracking pixel or click-redirect
   is ever injected (`BroadcastService.php:451-460` sends raw `body_html`). The locked spec's
   Pro/Broadcast section does not require open/click analytics; the journey Part 4 frames it as
   conditional ("depends on whether dispatch injects it"). Cosmetically the always-zero rows can
   mislead an admin into thinking tracking is broken.

2. **Journey-doc unsubscribe surface diverges from the built one.**
   Severity: low. Confidence: confirmed-in-code. The journey doc (Part 5, steps 11-13) describes
   `GET /buddynext-pro/v1/email/unsubscribe?user_id=&campaign_id=&token=` flipping the recipient row
   to `status=unsubscribed`. The implemented unsubscribe is an `init` handler at
   `?bn_unsub_campaign=<token>&bid=&uid=` that records a usermeta opt-out
   (`BroadcastUnsubscribe.php:63-105, 137-145`); the recipient row is only flipped to
   `unsubscribed` later, inside `send_pending` at send time (`BroadcastService.php:430-445`).
   The actual unsubscribe works and is honored; only the doc's endpoint/SQL expectation is stale.
   Recommend updating the journey doc, not the code.

3. **Segment selector in admin UI only exposes type, not its parameters.**
   Severity: low. Confidence: confirmed-in-code. The admin form lets you pick `by_space`/`by_tag`/etc.
   but provides no field for space_ids/tags/dates; the form note says advanced options are "configured
   via the REST API for this release" (`BroadcastAdmin.php:356-364`). Picking any non-`all_users` type
   in the admin UI resolves to an empty recipient set (`SegmentService` returns `array()` when the
   parameter key is empty, e.g. `:96-98`). For the locked happy-path (`all_users`) this is fine;
   parameterized segments are an app/REST-only capability today.

---

## Minimal refactor plan

EMPTY — usable-leave-as-is. The locked happy-path is complete and wired. The three gaps
above are low-severity polish / doc-sync items, not journey breaks, and the prime directive
is not to rewrite working code. (If later prioritized: update the journey doc to match the
`init`-based unsubscribe; hide or label the Opened/Clicked rows until tracking ships; add
parameter inputs to the admin segment selector.)

---

## Live-walk URL

http://buddynext-dev.local/wp-admin/ (Broadcasts under BuddyNext → Growth; verify mail in Mailpit at http://localhost:10010/)
