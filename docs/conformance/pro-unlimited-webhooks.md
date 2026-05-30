# Conformance: Unlimited Webhooks (Pro)

**Feature:** Unlimited Webhooks (repo: pro)
**Spec ref:** `docs/specs/HOOKS.md` → "Outbound" section (`buddynext_outbound_webhook_limit`, default 1, Pro = PHP_INT_MAX)
**Cross-cutting:** REST-FRONTEND-CONTRACT.md (UI bound to REST), 17-roles-permissions.md (manage_options gate)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Webhooks tab

---

## What the feature is

Free caps outbound webhook endpoints at 1 via the `buddynext_outbound_webhook_limit`
filter. Pro removes the cap by returning `PHP_INT_MAX` from the same filter — three
lines, no rebind, no inheritance (Pattern C). All registration, dispatch, logging,
retry, test, and the admin management UI already live in Free. The Pro contribution
is solely lifting the count gate.

The happy path tested: a site admin (manage_options) opens the Webhooks settings tab,
registers an HTTPS endpoint, sends a test ping, sees delivery, and — on a Pro site —
can register a *second and Nth* endpoint that Free would have blocked.

---

## Journey chain

| Step | Layer | Status | Evidence |
|---|---|---|---|
| Pro lifts the Free webhook cap | service | wired | buddynext-pro/includes/Outbound/UnlimitedWebhooksIntegration.php:30-41 (filter → PHP_INT_MAX) |
| Pro integration is registered at boot | service | wired | buddynext-pro/includes/Core/Plugin.php:201 (`UnlimitedWebhooksIntegration::register()` from `wire_extensions()`) |
| Admin opens Webhooks settings tab | ui | wired | buddynext/includes/Admin/Settings.php:131 (tab registered), :408 (tab bar), :1173 (`render_tab_webhooks`) |
| Endpoint manager card renders (list of endpoints) | ui | wired | buddynext/includes/Admin/Settings.php:1201-1290 (server-rendered table via `buddynext_service('webhooks')->list_all()`) |
| Limit gate reflected in UI (Add disabled at cap) | ui | wired | buddynext/includes/Admin/Settings.php:1206-1207 (`$at_limit` from same filter); Pro returns PHP_INT_MAX → never at cap |
| JS wires card to REST (nonce, fetch) | store | wired | buddynext/assets/js/admin/settings.js:65-184 (`initWebhookManager`); enqueued at Settings.php:224-230 |
| Register endpoint (POST /webhooks) | rest | wired | settings.js:105-122 → buddynext/includes/Outbound/OutboundWebhookController.php:60-87,178-200 |
| Service enforces limit + inserts row | service | wired | buddynext/includes/Outbound/OutboundWebhookService.php:77-155 (filter check :105-120, insert :133) |
| Send test ping (POST /webhooks/{id}/test) | rest | wired | settings.js:136-156 → OutboundWebhookController.php:242-255 → OutboundWebhookService.php:313-339 |
| Delete endpoint (DELETE /webhooks/{id}) | rest | wired | settings.js:156-181 → OutboundWebhookController.php:208-220 → OutboundWebhookService.php:197-215 |
| Real events dispatched (signed POST + log + retry) | service | wired | buddynext/includes/Outbound/OutboundWebhookListener.php:31-44 (event hooks → `dispatch()`); registered at buddynext/includes/Core/Plugin.php:222; service init :221 |
| Persistence (endpoints + log tables) | db | wired | buddynext/includes/Core/Installer.php:882,894 (`bn_outbound_webhooks`, `bn_outbound_webhook_log`) |
| REST routes mounted + admin-gated | rest | wired | buddynext/includes/REST/Router.php:84; OutboundWebhookController.php:262-272 (`manage_options`) |

---

## First break

none — journey complete.

The cap-lift filter is registered, the same filter drives both the service-side
limit check and the UI's at-limit disable logic, and the entire management surface
(list/add/test/delete) is a real admin UI bound to live REST routes with a nonce.
Event dispatch, HMAC signing, logging, auto-deactivation, and 5-minute cron retry
are all wired in Free. The journey runs in wp-admin, so the front-end isolation
mu-plugin does not apply.

---

## UX gaps

None that break the journey. Minor observations (NOT breaks, no action required):

- After a successful registration the JS does a full `window.location.reload()`
  rather than appending the row in place (settings.js:122). This is intentional
  (server-rendered numbering stays authoritative) and is documented inline. Not a gap.
- The events catalogue in the add form is a fixed list of 5 slugs
  (Settings.php:1293-1299) while the dispatch listener fires more event types
  (OutboundWebhookListener.php:31-44, e.g. connection.accepted, reaction.added,
  member.verified). An endpoint registered with an empty events array receives all
  events (OutboundWebhookService.php:288), so power users are not blocked — the UI
  simply does not surface every available slug as a checkbox. Cosmetic, not a journey
  break; confidence: confirmed-in-code.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## Notes on app/REST vs web journey

Both paths are served. The REST routes (`buddynext/v1/webhooks*`) are admin-gated and
fully functional for any in-app/REST client. The web (wp-admin) journey has a real UI
control set bound to those same routes. No api-only gap.
