# Conformance — Outbound Webhooks (free)

**Verdict:** usable-leave-as-is

> **Resolution (2026-06-08).** The delivery-log viewer UI now exists. Added a "View log" action per webhook row in `render_webhook_endpoints()` (`includes/Admin/Settings.php`) that expands an inline panel; `assets/js/admin/settings.js` fetches `GET /webhooks/{id}/log` and renders Event/Status/Code/Time rows (XSS-safe via textContent escaping). Verified live: seeded deliveries render in the expandable panel. No backend change — the endpoint already existed.
**Spec ref:** `docs/specs/HOOKS.md` (Outbound filter, lines 353-358) + `docs/specs/features/16-admin-settings.md` (Webhooks tab, lines 76-109)
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Webhooks tab
**Date:** 2026-05-31 (re-verified, all line refs read)

---

## What the journey is

A site owner opens **BuddyNext → Settings → Webhooks**, sets a shared HMAC secret,
registers an HTTPS endpoint, picks which community events to forward, sends a test
ping, and thereafter every matching domain event (post created, member registered,
etc.) fires a signed POST to that endpoint. Failed deliveries retry on a cron; an
endpoint that fails 3 deliveries in a row auto-disables.

This is an **admin-only web journey** (`manage_options`). It is also fully usable as
a REST-only journey for app/automation clients (Zapier/Make-style), since every
operation is a documented REST route.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Webhooks tab registered in admin settings shell | ui | wired | `includes/Admin/Settings.php:131,385` |
| 2 | Shared-secret field + endpoint-manager card renders with live endpoint list | ui | wired | `includes/Admin/Settings.php:1173-1191,1201-1344` |
| 3 | Endpoint table rows render (label, events, status, created, actions) | ui | wired | `includes/Admin/Settings.php:1242-1290` |
| 4 | Settings JS enqueued; webhook manager binds `[data-bn-webhooks]` card | ui | wired | `includes/Admin/Settings.php:210`; `assets/js/admin/settings.js:64-75,186-189` |
| 5 | "Add endpoint" button → POST /webhooks with URL + events + nonce | store | wired | `assets/js/admin/settings.js:91-128` |
| 6 | REST create route, admin-gated, auto-generates 40-char secret | rest | wired | `includes/Outbound/OutboundWebhookController.php:59-85,178-200,262-272`; `includes/REST/Router.php:85` |
| 7 | Service registers endpoint, https-only, enforces Free limit via filter | service | wired | `includes/Outbound/OutboundWebhookService.php:77-155` |
| 8 | Persist endpoint + log tables on install | db | wired | `includes/Core/Installer.php:884,896` |
| 9 | "Send test" button → POST /webhooks/{id}/test → signed ping | store→rest→service | wired | `assets/js/admin/settings.js:134-156`; `OutboundWebhookController.php:242-255`; `OutboundWebhookService.php:313-339` |
| 10 | "Remove" button → DELETE /webhooks/{id} (confirm + row removal) | store→rest→service | wired | `assets/js/admin/settings.js:156-181`; `OutboundWebhookController.php:208-220`; `OutboundWebhookService.php:197-215` |
| 11 | Domain events dispatch HMAC-SHA256 signed POST to subscribed endpoints | service | wired | `includes/Outbound/OutboundWebhookListener.php:30-45`; `OutboundWebhookService.php:269-303,404-474` |
| 12 | Listener + service container binding wired into bootstrap | service | wired | `includes/Core/Plugin.php:226,673` |
| 13 | Cron retry of recent failures + auto-disable after 3 fails | service | wired | `OutboundWebhookService.php:44-61,348-385,481-511`; `includes/Core/CronScheduler.php:103-104,152` |
| 14 | Per-endpoint delivery log viewer (web UI) | ui | api-only | route works `OutboundWebhookController.php:108-138` + `OutboundWebhookService.php:225-258`; no UI control reaches it — no `data-bn-webhook-log` anywhere; table actions are only test+remove (`Settings.php:1272-1285`) |
| 15 | Inbound access-webhook handler (HMAC-verified) | service | wired | `includes/Outbound/AccessWebhookController.php`; `REST/Router.php:61` |

---

## First break

**none — journey complete.** The core happy path (configure secret → register endpoint →
choose events → test → receive live signed events → auto-disable on failure) is fully
wired end-to-end across UI, JS store, REST, service, and DB. Verified by reading each
layer; nothing is absence-only or runtime-hidden.

---

## UX gaps (real, non-blocking)

1. **No delivery-log viewer UI (medium).** `GET /webhooks/{id}/log` is implemented and
   returns paginated rows (`OutboundWebhookController.php:108-138`,
   `OutboundWebhookService.php:225-258`), and the spec
   (`16-admin-settings.md:80,106`) explicitly requires a per-endpoint outbound log
   (last 50, response code, latency) plus an inbound log viewer (last 100). Neither is
   surfaced in the admin table — the only delivery feedback a web admin gets is the
   transient status line after a manual test (`settings.js:144-150`). App/REST clients
   can read the log; web admins cannot. Confidence: confirmed-in-code.

2. **Spec event-slug names diverge from implementation (low).** Spec table
   (`16-admin-settings.md:100-101` region) lists `ability.granted` / `ability.revoked`;
   the listener and the UI catalogue both use `member.ability_granted` /
   `member.ability_revoked` (`OutboundWebhookListener.php:259-283`,
   `Settings.php:1298-1299`). UI and dispatcher agree with each other, so the journey
   works — only the locked spec label is stale. Confidence: confirmed-in-code.

3. **Retry is fixed 5-min cron, not Action Scheduler exponential backoff (low).** Spec
   (`16-admin-settings.md:105`) says "3 attempts with exponential backoff via Action
   Scheduler"; implementation re-delivers any error from the last 24h every 5 minutes
   via WP-Cron (`OutboundWebhookService.php:348-385`,
   `CronScheduler.php:103-104,152`). It functionally retries and eventually
   auto-disables, so the journey outcome holds. Confidence: confirmed-in-code.

---

## Minimal refactor plan

Optional polish only — none of these block the journey. Reuse existing working code.

1. Add a "View log" action button per row in `render_webhook_endpoints()`
   (`Settings.php:1272-1285`, alongside Send test / Remove) plus a small
   expand/modal in `settings.js` that GETs the already-implemented
   `/webhooks/{id}/log` route and renders event / response_code / status /
   created_at. Closes gap #1 with no backend work.
2. (Doc-only) Reconcile the spec event-slug labels in `16-admin-settings.md` to
   `member.ability_granted` / `member.ability_revoked`. No version bump.

---

## Notes

- Web vs app: web admin journey is complete except the log viewer; app/REST clients
  have full parity including log read.
- Visibility/permissions respected: all management routes require `manage_options`
  (`OutboundWebhookController.php:262-272`); inbound access route is HMAC-gated
  (`AccessWebhookController.php`, `REST/Router.php:61`).
- Free limit (1 endpoint) enforced server-side via `buddynext_outbound_webhook_limit`
  filter (`OutboundWebhookService.php:105-120`) — native `apply_filters`, Pro lifts the
  cap. Conforms to SCALE-CONTRACT (paginated log, bounded response body, cron fan-out).
- Read-only audit — all statuses confirmed by reading code; no step needed live
  verification.
