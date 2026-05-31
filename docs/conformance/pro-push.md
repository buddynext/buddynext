# Conformance: Push Notifications (Pro)

**Feature:** Push Notifications (Web Push / FCM)
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P3-realtime-websocket.md` (the only locked doc supplied; it covers the realtime/notification-push intent — "Notification bell: Push on event"). Push delivery itself is implemented as a transactional FCM layer rather than the WebSocket transport described there, so the spec is directional, not line-by-line.
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/Push/`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/

---

## Journey

Site-owner intent: a member opts a browser/device into push, and receives a banner when a BuddyNext notification is created for them; the member can mute push per notification type. An admin configures Firebase and can fire a self-test.

### Chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin enables push + enters server SA JSON and public web config (apiKey/senderId/appId/VAPID) | service/db | wired | `includes/Admin/PushAdmin.php:147-185, 313-331` |
| Member opens notification prefs page; Pro injects the "Browser push" panel | ui | wired | `includes/Push/WebPushAssets.php:74,171` via Free hook `templates/notifications/prefs.php:466` |
| Panel + store module enqueued only on prefs page | ui/store | wired | `WebPushAssets.php:82-124` (gated by `bn_hub=notifications` / `bn_notif_section=prefs`; query vars exist in `buddynext/includes/Core/PageRouter.php:925`) |
| "Enable browser push" button bound to store action | ui→store | wired | `WebPushAssets.php:233-235` `data-wp-on--click="actions.enroll"`; `assets/js/web-push/store.js:143` |
| Store registers SW, mints FCM token, POSTs it | store→rest | wired | `store.js:166-193` → `restTokensUrl` = `buddynext-pro/v1/me/push-tokens` |
| Token persisted (owner-derived, upsert) | rest/db | wired | `PushController.php:208-292`; table created in `includes/Core/Installer.php:207-223` |
| Per-type toggle saves instantly | ui→store→rest/db | wired | `WebPushAssets.php:278` `actions.toggleType` → `store.js:251-283` → `Controllers/PushPrefsController.php:135-160` → `PushPrefService::set_push_enabled` |
| Notification created → push fans out to recipient devices | service | wired | `PushDispatcher.php:56,69-110` hooks `buddynext_notification_created`, gates per-type pref |
| FCM delivery + stale-token cleanup | service | wired | `PushClient.php:157-247` (SA-JWT OAuth, 404/410 prune) |
| Background banner rendered | ui (SW) | wired | `assets/js/web-push/firebase-messaging-sw.js:58-62` |
| Admin self-test | rest/service | wired | `PushController.php:345-379` (`manage_options`, guards disabled/not-configured) |

All classes are instantiated and registered in `includes/Core/Plugin.php:244-254` (front-end) and `:334-335` (REST at `rest_api_init`).

---

## First break

none — journey complete. Every UI control is bound to a store action that reaches a live, registered REST endpoint or service hook; the DB table, admin config surface, service worker, and FCM client are all present and wired.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Returning-member enrollment state is not seeded from the server. `render_panel()` hardcodes initial context `enrolled:false, tokenId:0` and never reads the existing `GET /me/push-tokens` rows, so a member who already enrolled this browser still sees "idle / Enable browser push" on reload and the "Turn off on this device" button stays hidden until they re-enroll. Re-clicking Enable is harmless (token upsert is idempotent) so the journey still completes — cosmetic state drift, not a break. | low | confirmed-in-code | `WebPushAssets.php:191-199` (hardcoded context); `store.js:290-318` (`init` relies on `ctx.enrolled`, always false on load); `list_tokens` exists at `PushController.php:165` but is unused by the panel |
| Graceful-degrade copy depends on runtime browser permission state (denied/unsupported/granted) which cannot be proven statically; logic reads correct but should be confirmed live in a banner-blocked browser. | low | needs-live-verification | `store.js:290-318`, `WebPushAssets.php:149-156` |

Neither gap stops a member from enrolling, muting types, or receiving pushes.

---

## Minimal refactor plan

None. Verdict is usable-leave-as-is. The enrollment-state-seeding nuance is optional polish (seed `enrolled`/`tokenId` in the panel's initial context from `list_tokens`), not a wiring fix, and is out of scope for a usability pass.

---

## Notes on spec alignment

- The locked doc (`P3-realtime-websocket.md`) frames notification push as a WebSocket "push on event." This module delivers the same user outcome (instant banner on notification) through FCM transactional push instead of the socket transport, satisfying the member-facing intent and additionally covering native iOS/Android tokens (`PushController` PLATFORMS; APNs-via-FCM note at `PushClient.php:14-17`).
- Cross-cutting contracts respected: endpoints derive the user from `get_current_user_id()` and never trust a body `user_id` (`PushController.php:208-209`, `PushPrefsController.php:145`); owner-only delete enforced (`PushController.php:324-330`); per-user fan-out is bounded by registered tokens with stale-token pruning, consistent with SCALE-CONTRACT.

**App/REST journey:** fully usable independently — a native client can POST tokens and PUT prefs directly. **Web journey:** usable; only friction is the cosmetic returning-state drift above.
