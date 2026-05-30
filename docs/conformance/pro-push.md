# Conformance — Pro Push Notifications (FCM)

**Feature:** Push Notifications (mobile/web FCM fan-out of Free notifications)
**Repo:** buddynext-pro
**Spec ref:** `docs/specs/features/P3-realtime-websocket.md` (the only spec that names push — "Notification bell: Push on event"). No dedicated push spec exists; the FCM device-push surface is implementation beyond the locked WebSocket spec.
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**Verdict:** partial-needs-wiring (web journey) / wired (native app or REST-client journey)
**Live-walk URL:** http://buddynext-dev.local/

---

## What the spec asks for

The locked P3 spec promises that, with Pro active, the notification bell is "Push on event instead of polling." The `includes/Push/` code implements transactional **FCM device push**: when Free fires `buddynext_notification_created`, Pro fans the notification out to every FCM token registered to the recipient. This is the mobile/web-push half of "push on event."

## Core happy-path journey (web member)

A site owner expects: member enables browser notifications → device token is stored → when someone follows/comments/mentions them, the member's device shows a push banner → member can tune which types push from their notification settings.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin configures FCM (toggle, project ID, service-account JSON) | ui (admin) | wired | `includes/Admin/PushAdmin.php:171-249` form + `handle_save` `:91-146`; wired into AdminHub `:58-66` |
| `bn_push_tokens` table created | db | wired | `includes/Core/Installer.php:207-223` |
| Member's browser registers a service worker, requests `Notification.permission`, gets an FCM token | ui (web front-end) | **missing** | No service worker, no Firebase JS, no `Notification.requestPermission` anywhere. `grep firebase\|fcm\|serviceWorker\|getToken` across `assets/` returns only `assets/vendor/pusher.min.js` (WebSocket, unrelated). Realtime store `assets/js/realtime/store.js` is pusher-js only — no token registration. |
| Web client calls `POST /me/push-tokens` to store the token | store→rest | **missing** (no caller) | Endpoint exists and is correct: `includes/Push/PushController.php:208-292`. No JS in the repo calls it (`grep push-tokens --include=*.js` = 0 hits). |
| Notification fires → Pro fans out to recipient's tokens | service | wired | `includes/Push/PushDispatcher.php:55-110` hooks `buddynext_notification_created`; `PushClient::send_to_user` `includes/Push/PushClient.php:182-210` |
| FCM HTTP v1 delivery (JWT auth, stale-token cleanup) | service | wired | `includes/Push/PushClient.php:120-169`, `:279-320` |
| Member tunes per-type push prefs | ui (web front-end) | **missing** | Prefs REST works: `includes/Push/Controllers/PushPrefsController.php:72-160`. The only UI is admin-only (`manage_options`): `includes/Admin/PushPrefsAdmin.php` docblock `:13-17` explicitly defers the front-end member surface to "P4.3 ticket." No member-facing toggle reaches `GET/PUT /me/push-prefs`. |
| Admin "Send test push" button | ui (admin) | broken | Button renders `data-bn-push-test=...` `includes/Admin/PushAdmin.php:242`, but no JS handles `data-bn-push-test` anywhere (`grep bn-push-test --include=*.js` = 0 hits). Button is inert. |

## First break

A web member can never register a device token. There is no service worker, no Firebase/FCM web SDK, no permission prompt, and no JS calling `POST /me/push-tokens`. `send_to_user()` therefore finds zero tokens for any web-only member, so no push is ever delivered through a browser. The earliest broken link is the **web front-end token-registration step** (and, in parallel, the inert admin test button which is the only way an admin could self-verify credentials).

## Important distinction: app/REST vs web

The REST surface (`/me/push-tokens` CRUD, `/me/push-prefs` read/bulk-update, owner-scoped, derives user from `get_current_user_id()`, never trusts a body `user_id`) is complete and correct. A **native mobile app or REST client** that obtains its own FCM token via the platform SDK and POSTs it WILL receive pushes — the dispatcher, pref gate, JWT auth, and stale-token cleanup are all wired. So for the app/REST journey this feature is usable. The gap is specifically the **web browser** journey: no client code turns a logged-in web member into a registered FCM device.

## UX gaps

1. **No web push-token registration path (critical, confirmed-in-code).** No service worker / Firebase web SDK / permission prompt / `POST /me/push-tokens` caller exists. Web members cannot enroll a device, so browser push never fires. Evidence: `assets/` JS grep for firebase/fcm/serviceWorker/getToken/push-tokens returns nothing relevant; `includes/Push/PushController.php:208` endpoint has no client.
2. **Admin "Send test push" button is inert (high, confirmed-in-code).** `data-bn-push-test` attribute is rendered but no JS handler exists. The only credential-verification affordance does nothing. Evidence: `includes/Admin/PushAdmin.php:242` vs zero `bn-push-test` JS matches.
3. **No member-facing push-pref UI (high, confirmed-in-code).** Per-type push prefs are admin-only (`manage_options`); the member front-end surface is explicitly deferred. Web members cannot tune their own push types even though the REST endpoints support it. Evidence: `includes/Admin/PushPrefsAdmin.php:13-17`.

## Minimal refactor plan (web journey → usable; reuse existing working REST)

1. Add a Firebase web SDK + `firebase-messaging-sw.js` service worker to `assets/`, and a small front-end store module that: registers the SW, calls `Notification.requestPermission()`, gets the FCM token via the VAPID key, and POSTs it to the existing `buddynext-pro/v1/me/push-tokens` endpoint. Enqueue it alongside the realtime assets (mirror `includes/Realtime/RealtimeAssets.php`).
2. Add the FCM web config fields (web API key, sender ID, VAPID key) to `PushAdmin` so the SW/JS can be configured — currently only the server-side service-account JSON is captured.
3. Wire the `data-bn-push-test` admin button to a tiny JS handler that POSTs to `/me/push-tokens/test` with the printed `wp_rest` nonce (the button + endpoint already exist — only the click handler is missing).
4. Build the deferred member-facing push-pref surface (P4.3): a front-end notifications-settings panel bound to `GET/PUT /me/push-prefs`. Reuse the catalog grouping already in `PushPrefsAdmin::get_grouped_catalog()`.

No backend rewrite: the REST controllers, dispatcher, pref gate, FCM client, and DB table are all correct and should be left as-is.
