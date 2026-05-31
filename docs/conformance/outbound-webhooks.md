# Conformance — Outbound Webhooks

**Feature:** Outbound Webhooks (Event Push)
**Repo:** free
**Spec refs:** `docs/specs/HOOKS.md` (§"Outbound" filter, lines 353–360), `docs/specs/features/16-admin-settings.md` §"Webhooks → Outbound (Event Push)" (lines 83–109)
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Webhooks tab (`?page=buddynext&tab=webhooks`)
**Verdict:** usable-minor-polish

> Re-verified 2026-05-31. Earlier revision of this dossier flagged a "5-of-13 event subset" and a phantom `notification.sent` checkbox — both are now stale. The current `includes/Admin/Settings.php:1293-1308` catalogue exposes all 14 dispatched events and contains no `notification.sent`. Those gaps are resolved in code.

---

## What the journey is

A site owner opens BuddyNext → Settings → Webhooks, optionally sets a shared secret, registers an HTTPS endpoint, ticks which events to forward, clicks Register, optionally Send-test, and later Remove. BuddyNext then HMAC-signs and POSTs JSON envelopes to that endpoint whenever subscribed domain events fire, retries failures via cron, and auto-disables an endpoint after 3 consecutive failures.

---

## Journey chain

| Step | Layer | Status | Evidence |
|---|---|---|---|
| Webhooks tab renders (secret field + endpoint manager) | ui | wired | `includes/Admin/Settings.php:412`, `:1173` `render_tab_webhooks()`, `:1201` `render_webhook_endpoints()` |
| 14-event subscription checklist + add form | ui | wired | `includes/Admin/Settings.php:1293-1334` |
| Settings JS enqueued on the page, binds the card | store | wired | `includes/Admin/Settings.php:219-237` (gated `toplevel_page_buddynext`); `assets/js/admin/settings.js:66-74` |
| Register → POST `buddynext/v1/webhooks` (url+events, X-WP-Nonce) | rest | wired | `assets/js/admin/settings.js:105-122`; route `includes/Outbound/OutboundWebhookController.php:50-87`; registered `includes/REST/Router.php:85` |
| Service validates https, enforces Free limit (1), inserts | service | wired | `includes/Outbound/OutboundWebhookService.php:77-155`; filter doc `HOOKS.md:353-360` |
| Listener fans 14 domain events into dispatch() | service | wired | `includes/Outbound/OutboundWebhookListener.php:30-45`; registered `includes/Core/Plugin.php:224-226` |
| HMAC-SHA256 signed POST + `X-BuddyNext-Event` header | service | wired | `OutboundWebhookService::dispatch()` `:269-303`, `deliver()` `:404-474` (`X-BuddyNext-Signature` `:418,428`) |
| Send test → POST `/webhooks/{id}/test` | rest | wired | `assets/js/admin/settings.js:140-155`; controller `:242-255`; `send_test_ping()` `:313-339` |
| Delivery log written per attempt | db | wired | `OutboundWebhookService.php:447-467`; table `includes/Core/Installer.php:896-907` |
| Auto-disable after 3 consecutive failures | service | wired | `OutboundWebhookService.php:469-511` |
| Retry failed deliveries (cron) | service | wired | `OutboundWebhookService.php:44-61` (`buddynext_5min`), `retry_failed()` `:348-385` |
| Remove → DELETE `/webhooks/{id}` (with confirm) | rest | wired | `assets/js/admin/settings.js:157-181`; controller `:208-220`; `delete()` `:197-215` |
| Per-endpoint delivery-log viewer | ui | missing | REST `GET /webhooks/{id}/log` exists (`OutboundWebhookController.php:108-138`, `get_log()` `:225`) but no UI calls it — no `/log` reference in `assets/js/admin/settings.js` |

---

## First break

None — the core happy path is complete. An admin can register an HTTPS endpoint for any of the 14 contracted events, send a test, see active/disabled status, and remove it; events fan out to live endpoints with signed payloads, and failures retry + auto-disable. Usable for both the web admin and REST clients. Remaining items below are polish, not blockers.

---

## UX gaps (real, non-blocking)

1. **Per-endpoint signing secret is never surfaced to the admin (medium, confirmed-in-code).**
   `register()` auto-generates a 40-char secret and the create response returns it (`OutboundWebhookController.php:184,196`), but the JS discards the response body and reloads (`assets/js/admin/settings.js:117-122`); the rendered table never prints the `secret` column. `deliver()` signs each payload with the *per-endpoint row secret* (`OutboundWebhookService.php:418`), so a receiver that wants to verify `X-BuddyNext-Signature` has no way to learn the secret it was signed with.

2. **"Shared Secret" field over-claims — it does not sign outbound deliveries (medium, confirmed-in-code).**
   The tab's top field stores `buddynext_webhook_secret` with the hint "Used to sign outgoing webhooks (HMAC-SHA256)" (`includes/Admin/Settings.php:1177-1186`, `:1227`). Outbound `deliver()` ignores that option and uses the per-endpoint secret column; the shared option is only consumed by the inbound access webhook. The label misleads and compounds gap #1.

3. **No per-endpoint delivery-log viewer in the UI (medium, confirmed-in-code).**
   Spec requires "per-endpoint delivery log (last 50 calls, response code, latency)" (`16-admin-settings.md:106`). The REST route + service `get_log()` are built (`OutboundWebhookController.php:108-138`, `OutboundWebhookService.php:225`) but nothing in `assets/js/admin/settings.js` calls it and no template renders it. Latency is also not captured — `deliver()` writes no duration column (`:448-467`).

4. **Retry diverges from the spec contract (low, confirmed-in-code).**
   Spec §16 says "3 attempts with exponential backoff via Action Scheduler"; implementation is a flat `buddynext_5min` WP-Cron sweep re-delivering all `error` rows from the last 24h (`OutboundWebhookService.php:44-61,348-385`) — no per-attempt cap, no backoff, not Action Scheduler. Deliveries still retry; spec-text vs implementation mismatch, not a break.

5. **`ability.*` slug naming differs from spec (low, confirmed-in-code).**
   Spec §16 lists `ability.granted` / `ability.revoked`; listener + UI use `member.ability_granted` / `member.ability_revoked` (`OutboundWebhookListener.php:259-283`, `Settings.php:1298-1299`). UI and dispatch are internally consistent, so subscribing works — only the spec doc's slug differs.

---

## Minimal refactor plan

1. After a successful register, surface the returned `secret` once (write `res.body.secret` into the status line / a copy-once field before reload) — the value is already in `res.body` at `assets/js/admin/settings.js:111-122`.
2. Fix the "Shared Secret" hint at `includes/Admin/Settings.php:1184-1186` and `:1227` so it no longer claims to sign outbound deliveries; scope it to inbound access verification.
3. Add a per-endpoint log view: a "View log" action in each row (`Settings.php:1272-1285`) plus a JS handler that GETs `/webhooks/{id}/log` and renders the existing response (event, response_code, status, created_at). Add a latency column to `deliver()`'s log insert only if the spec's latency display is required.
4. (Doc-only, optional) Reconcile spec §16 retry wording and the `ability.*` slug with the shipped WP-Cron retry and `member.ability_*` slugs — pick one source of truth.

> No structural rewrite. The path UI → JS → REST → service → DB → cron retry is intact and working. The live walk only needs to confirm the JS enqueues and the tab renders on the running site.
