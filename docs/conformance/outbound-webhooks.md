# Conformance — Outbound Webhooks

**Feature:** Outbound Webhooks (Event Push)
**Repo:** free
**Spec ref:** `docs/specs/features/16-admin-settings.md` (Webhooks → Outbound section, lines 83–109) + `docs/specs/HOOKS.md` (Outbound filter, lines 353–360)
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Webhooks tab
**Verdict:** usable-minor-polish

---

## What the journey is

A site owner opens **BuddyNext → Settings → Webhooks**, sets a shared secret, registers an HTTPS endpoint, picks which events to forward, clicks Register, then Send-test, and later Remove. BuddyNext then signs and POSTs JSON envelopes to that endpoint whenever the subscribed domain events fire, retries failures, and auto-disables an endpoint after 3 consecutive failures.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Webhooks settings tab renders (secret field + endpoint manager) | ui | wired | `includes/Admin/Settings.php:1173` `render_tab_webhooks()`; `:1201` `render_webhook_endpoints()` |
| Settings JS enqueued on the BuddyNext settings screen | ui | wired | `includes/Admin/Settings.php:219` gated to `toplevel_page_buddynext`, enqueues `assets/js/admin/settings.js` |
| Add-endpoint button → POST /webhooks with url+events, X-WP-Nonce | store | wired | `assets/js/admin/settings.js:105-122`; nonce/rest-url emitted at `includes/Admin/Settings.php:1208-1216` |
| Send-test button → POST /webhooks/{id}/test | store | wired | `assets/js/admin/settings.js:140-155` |
| Remove button → DELETE /webhooks/{id} (with confirm) | store | wired | `assets/js/admin/settings.js:157-181` |
| REST routes registered, manage_options gated | rest | wired | `includes/Outbound/OutboundWebhookController.php:49-158`; permission `:262`; route registered in `includes/REST/Router.php:84` |
| Register / list / delete / test / log service methods | service | wired | `includes/Outbound/OutboundWebhookService.php:77,164,197,313` + `get_log():225` |
| HMAC-SHA256 signed POST + X-BuddyNext-Event header + log row | service | wired | `OutboundWebhookService::deliver()` `:404-474` (`X-BuddyNext-Signature` `:418,428`) |
| Free 1-endpoint cap via filter | service | wired | `OutboundWebhookService.php:105-119`; filter doc `HOOKS.md:353-360` |
| Domain-event listener fans events into dispatch() | service | wired | `includes/Outbound/OutboundWebhookListener.php:30-45` (14 handlers); registered `includes/Core/Plugin.php:222` |
| Source events actually fire | service | wired | e.g. `includes/Auth/VerificationService.php:126` (`buddynext_user_verified`), `includes/Outbound/AccessWebhookController.php:197,228` (ability granted/revoked), ReactionService/PostService/ConnectionService |
| Retry cron + auto-deactivate on 3 failures | service | wired | `OutboundWebhookService.php:44-61` (schedule), `:348-385` (retry_failed), `:481-511` (maybe_deactivate_on_failure) |
| Persisted endpoints + delivery log | db | wired | `bn_outbound_webhooks`, `bn_outbound_webhook_log` (inserts at `:133`, `:448`) |
| Subscribe to the full spec event set via UI checkboxes | ui | broken | catalogue at `includes/Admin/Settings.php:1293-1299` exposes only 5 slugs; spec lists 13 (`16-admin-settings.md:88-102`) |
| Per-endpoint delivery-log viewer | ui | missing | REST `GET /webhooks/{id}/log` exists (`OutboundWebhookController.php:108-138`) but no UI calls it — no `/log` reference in `assets/js/admin/settings.js` |

## First break

First break: the **event-subscription checkbox catalogue** (`includes/Admin/Settings.php:1293-1299`). It only lets a web user subscribe to 5 events and includes one phantom event that never dispatches. The core happy-path (register HTTPS endpoint for a common event, sign-and-deliver, test, remove) still completes, so the journey is usable — but a site owner cannot, from the UI, subscribe to most of the spec's contracted events.

## UX gaps

1. **Phantom `notification.sent` checkbox** — severity medium, confirmed-in-code. The UI offers `notification.sent` as a subscribable event (`Settings.php:1298`), but no listener dispatches it (`OutboundWebhookListener.php` has no such handler; grep finds no `notification.sent` dispatch). A subscriber to that event silently receives nothing. It is also not in the locked spec event list.

2. **UI event catalogue is a 5-of-13 subset of the spec** — severity high, confirmed-in-code. Spec contracts 13 events (`16-admin-settings.md:88-102`); the UI checkbox list (`Settings.php:1293-1299`) offers only `post.created`, `comment.created`, `user.followed`, `space.joined`, plus the phantom `notification.sent`. The listener actually dispatches the missing ones (`member.registered`, `member.verified`, `member.suspended`, `post.deleted`, `space.left`, `connection.accepted`, `reaction.added`, `member.ability_granted`, `member.ability_revoked`), so the back end is ready — they are simply unreachable from the web UI. The JS also requires at least one checkbox (`settings.js:99-101`), so a UI user cannot register an empty=all-events endpoint as a workaround. Note: an app/REST client can still POST any `events[]` array directly, so this is a **web-journey** gap, not an app-journey gap.

   Slug mismatch to watch when fixing: the listener dispatches abilities as `member.ability_granted` / `member.ability_revoked` (`OutboundWebhookListener.php:261,277`), whereas the spec table names them `ability.granted` / `ability.revoked` (`16-admin-settings.md:100-101`). Whichever slug the new checkboxes use must match the listener's dispatch slug or the subscription will silently never fire.

3. **No per-endpoint delivery-log viewer in the UI** — severity medium, confirmed-in-code. Spec requires "per-endpoint delivery log (last 50 calls, response code, latency)" (`16-admin-settings.md:106`). The REST route `GET /webhooks/{id}/log` is built (`OutboundWebhookController.php:108-138`, service `get_log()` at `:225`) but nothing in `assets/js/admin/settings.js` calls it and no template renders it. Latency is also not captured in `bn_outbound_webhook_log` (no duration column written in `deliver()` `:448-466`).

## Minimal refactor plan

1. In `includes/Admin/Settings.php` `render_webhook_endpoints()`, replace the 5-entry `$catalogue` (lines 1293-1299) with the full spec event set, using the exact slugs the listener dispatches (`member.registered`, `member.verified`, `member.suspended`, `post.created`, `post.deleted`, `space.joined`, `space.left`, `connection.accepted`, `user.followed`, `reaction.added`, `comment.created`, plus the two ability slugs). Remove `notification.sent`.
2. Reconcile the ability slug naming between `OutboundWebhookListener.php:261,277` and the spec (`ability.granted` / `ability.revoked`); use one canonical pair in both the listener dispatch and the new checkbox values so subscriptions actually match.
3. Add a per-endpoint log view: a "View log" action in each row (`Settings.php:1272-1285`) plus a JS handler in `assets/js/admin/settings.js` that GETs `/webhooks/{id}/log` and renders the existing response (event, response_code, status, created_at). Optionally add a latency column to `deliver()`'s log insert if the spec's latency display is required.
