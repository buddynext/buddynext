# Conformance: Email Broadcasts (Pro)

**Feature:** Email Broadcasts
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/06-notifications-email.md` (§ Pro/Broadcast, § Unsubscribe, § Email Infrastructure)
**Journey ref:** `buddynext-pro/docs/journeys/broadcast-email.md`
**Verdict:** usable-minor-polish
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (+ Mailpit at http://localhost:10010/)

---

## Summary

The core broadcast happy-path — admin creates a campaign, sends a test, dispatches to a
segment, cron delivers via `wp_mail()`, recipient rows flip to `sent`, and a recipient can
unsubscribe — is **fully wired end-to-end** through both the admin UI and the REST surface.
All services, the cron handler, the unsubscribe handler, and three REST controllers are
instantiated and registered in `Plugin.php`.

The gaps are all in **spec-promised refinements beyond the manual send path**, not in the
manual send path itself: scheduled campaigns never auto-dispatch, broadcast emails do not
auto-inject the per-campaign unsubscribe link or an open-tracking pixel, and the journey doc
describes a REST unsubscribe endpoint that does not exist (the real unsubscribe mechanism is a
different, working surface). None of these stop the documented manual happy-path.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin opens Broadcasts page (AdminHub "Growth" tab + legacy `?page=buddynextpro-broadcasts`) | ui | wired | `includes/Admin/BroadcastAdmin.php:84` (AdminHub::register_tab), `:103` (add_submenu_page); `buddynext/includes/Admin/AdminHub.php:217` register_tab, `:49` growth section |
| 2 | Create-campaign form posts to admin-post | ui→service | wired | `BroadcastAdmin.php:336` form, `:77` `admin_post_bnpro_broadcast_create`, `:118` handle_create → `BroadcastService::create_campaign` |
| 3 | Campaign row inserted (status=draft, segment_filter JSON) | service→db | wired | `includes/Email/BroadcastService.php:100-134` insert into `bn_email_campaigns` |
| 4 | Send Test → `[TEST] {subject}` to admin email | ui→service | wired | `BroadcastAdmin.php:79` `admin_post_bnpro_broadcast_send_test`, `:203` handle_send_test → `wp_mail()` `:215` |
| 5 | Send Now → dispatch | ui→service | wired | `BroadcastAdmin.php:78` `admin_post_bnpro_broadcast_send_now`, `:167` handle_send_now → `BroadcastService::dispatch_campaign` |
| 6 | Resolve segment → user IDs | service | wired | `BroadcastService.php:346` → `SegmentService::resolve` `includes/Email/SegmentService.php:38` (all 6 segment types implemented) |
| 7 | Queue recipients (status=queued), mark campaign sending, schedule cron | service→db | wired | `BroadcastService.php:349-387` INSERT IGNORE into `bn_campaign_recipients`, `wp_schedule_event` |
| 8 | Cron `buddynextpro_broadcast_send_pending` fires → send batch | service | wired | `BroadcastService.php:88` `add_action(CRON_HOOK, send_pending)` (bound in `register()`), called from `Plugin.php:166` |
| 9 | send_pending delegates to Free EmailSender → wp_mail, flips rows to sent | service | wired | `BroadcastService.php:451` `send_now`; `buddynext/includes/Notifications/EmailSender.php:104` send_now → `wp_mail` `:165`; rows updated `BroadcastService.php:463-472`; campaign marked sent `:492` |
| 10 | Unsubscribe-flag check before send | service | wired | `BroadcastService.php:431-432` calls `is_globally_unsubscribed` / `is_unsubscribed_from_campaign` |
| 11 | Recipient unsubscribes via signed link | ui→service→db | wired | `includes/Email/BroadcastUnsubscribe.php:48` `init` hook, `:63` handle_unsub_request (HMAC verify `:248`), `:137` records usermeta; registered `Plugin.php:165` |
| 12 | REST: list/create/get/update/cancel/dispatch (admin) | rest | wired | `includes/Email/Controllers/BroadcastController.php:73` register_routes, `:309` `manage_options` gate; registered `Plugin.php:288` |
| 13 | REST: member email-preferences opt-out | rest | wired | `includes/Email/Controllers/EmailUnsubscribeController.php:67`; registered `Plugin.php:290` |
| 14 | Open / click tracking recorded | service→db | missing | No pixel injection or click-wrap anywhere in `dispatch_campaign` / `send_pending`; `bn_campaign_recipients.opened/clicked` never written. Recipients view counters always 0 (`BroadcastAdmin.php:401-417`) |
| 15 | Scheduled campaign auto-dispatches at `scheduled_at` | service | broken | `send_pending` only selects `c.status = 'sending'` (`BroadcastService.php:409`). Nothing promotes `scheduled`→`sending` based on `scheduled_at`. A campaign saved with a schedule never sends without a manual Send Now. |

---

## First break

**none for the documented manual happy-path (steps 1-13 complete).** The earliest real
break against the *locked spec's* broader promise is step 15: a campaign with a future
`scheduled_at` is stored as `status=scheduled` (`BroadcastService.php:121-124`) but no cron
ever promotes it to `sending`, so it never delivers. This is silent — the admin sees a
"Scheduled" row that quietly never sends.

---

## UX gaps

### 1. Scheduled campaigns never auto-send (silent dead-end)
- **Severity:** high
- **Confidence:** confirmed-in-code
- **Evidence:** `BroadcastService.php:121-124` sets `status=scheduled`; the cron worker query
  `BroadcastService.php:405-413` filters `c.status = 'sending'` only; no code path promotes
  `scheduled` rows whose `scheduled_at <= now`. The admin form exposes a "Schedule (UTC)"
  field (`BroadcastAdmin.php:367-373`), so a site owner can set a schedule that silently
  never fires.

### 2. Broadcast emails do not auto-inject a per-campaign unsubscribe link
- **Severity:** high
- **Confidence:** confirmed-in-code
- **Evidence:** Spec § Unsubscribe: "Every email includes `{unsubscribe_url}` — one-click."
  `send_pending` (`BroadcastService.php:451-460`) passes only `subject` + `body_html` to
  `EmailSender::send_now`; it never calls `BroadcastUnsubscribe::unsub_url()`
  (`BroadcastUnsubscribe.php:117`) to build the per-campaign `?bn_unsub_campaign=` link.
  `EmailSender::render` will substitute `{{unsubscribe_url}}` only if the admin manually types
  that token into the body, and it then resolves to Free's per-*type* URL
  (`EmailSender.php:199,221` → `?bn_unsub=1&type=bn.broadcast`), not the Pro per-campaign
  token the `BroadcastUnsubscribe` handler verifies. Result: a campaign whose body omits the
  token ships with no unsubscribe link, breaking CAN-SPAM and the spec's one-click guarantee.

### 3. Open / click tracking is unimplemented
- **Severity:** medium
- **Confidence:** confirmed-in-code
- **Evidence:** Spec § Data Stored: `bn_campaign_recipients` — "per-recipient delivery +
  open/click tracking." No tracking pixel is injected and no open/click endpoint exists in
  `includes/Email/`. The admin Recipients view renders `opened`/`clicked` rows
  (`BroadcastAdmin.php:401-417`) that will always read 0, presenting empty metrics as if
  tracking were live. Journey Part 4 itself hedges ("depends on whether dispatch injects it").

### 4. Journey doc references a non-existent unsubscribe REST endpoint
- **Severity:** low
- **Confidence:** confirmed-in-code
- **Evidence:** `broadcast-email.md:111,164` document
  `GET /buddynext-pro/v1/email/unsubscribe?...&token=`. No such route is registered. The real
  surfaces are the `init`-time `?bn_unsub_campaign=<token>&bid=&uid=` handler
  (`BroadcastUnsubscribe.php:63-105`) and the REST `me/email-preferences` controller
  (`EmailUnsubscribeController.php:44`). Doc/spec drift only — the actual unsubscribe works.
  The journey's WP-CLI token recipe (`generate_token(<USER_ID>, <CAMPAIGN_ID>)`) also has the
  argument order reversed vs the signature `generate_token(int $campaign_id, int $user_id)`
  (`BroadcastUnsubscribe.php:234`).

---

## Minimal refactor plan

1. **Add a scheduled-dispatch tick.** In `BroadcastService::send_pending` (or a small sibling
   cron callback on the existing `CRON_HOOK`), before sending, promote due campaigns:
   `UPDATE bn_email_campaigns SET status='sending' WHERE status='scheduled' AND scheduled_at <= UTC_NOW()`,
   then dispatch their recipients via the existing `dispatch_campaign` flow. Reuses the
   already-scheduled 5-minute cron; no new infrastructure.
2. **Inject the per-campaign unsubscribe link at send time.** In `send_pending`, build
   `$this->unsub->unsub_url($campaign_id, $user_id)` and substitute/append it into the email
   body (e.g. replace a `{{unsubscribe_url}}` token before delegating, or append a footer when
   the token is absent) so every broadcast carries the working `?bn_unsub_campaign=` link the
   handler already verifies.
3. **(medium) Implement open tracking** by injecting an HMAC pixel `<img>` referencing a new
   public endpoint that sets `bn_campaign_recipients.opened`, or remove the `opened`/`clicked`
   rows from the Recipients view until tracking lands so empty metrics are not misrepresented.
4. **(doc) Correct `broadcast-email.md`** to describe the real unsubscribe surfaces
   (`?bn_unsub_campaign=` + `me/email-preferences`) and fix the `generate_token` argument order
   in the WP-CLI snippet.

---

## Notes for the live walk

- Steps 1-9 of the journey should pass as written against a seeded multi-user site; watch
  Mailpit for the `[TEST]` email and the per-recipient sends.
- To confirm gap #2, create a campaign whose body omits `{{unsubscribe_url}}`, dispatch, and
  inspect the delivered email in Mailpit — it will contain no unsubscribe link.
- To confirm gap #1, save a campaign with a near-future "Schedule (UTC)" value and let the
  5-minute cron run; the row stays "Scheduled" and no recipients are queued.
