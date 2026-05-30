# Conformance: Realtime Updates (Free)

**Feature:** Realtime Updates
**Repo:** free
**Spec ref:** `docs/specs/features/P3-realtime-websocket.md` (cross-checked against `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`)
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** partial-needs-wiring

---

## Intent (from spec)

The Pro spec defines WebSocket push. The **Free** contract it locks (rows in the
"Upgraded Surfaces" table + "Architecture: Free tier gracefully degrades to polling
when Pro is not active — no broken features, just slower") is the surface under test:

| Surface | Free promise |
|---------|--------------|
| Notification bell | Poll every 30s |
| DM conversations | Poll every 5s |
| Feed new-posts bar | Poll every 60s |
| Online presence | Poll every 2min; "Online now" directory filter reads presence |
| Spaces live activity | None in Free |

The `buddynext_realtime_transport` filter + `RealtimeTransport`/`PollingTransport`
abstraction must exist so Pro can swap transport. That seam is correct and complete.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Transport abstraction + Pro swap seam | service | wired | `includes/Realtime/RealtimeTransport.php:42`, `includes/Realtime/TransportFactory.php:60`, `includes/Realtime/PollingTransport.php:41` |
| Transport bound in container | service | wired | `includes/Core/Plugin.php:691` |
| Notification unread-count REST endpoint | rest | wired | `includes/Notifications/NotificationController.php:48-56`; service `includes/Notifications/NotificationService.php:285` |
| Notification badge background poll (30s cold / 5s hot) | store | wired | `assets/js/notifications/store.js:422-595` |
| Notification poll context (restUrl + nonce) on nav (every page) | ui | broken | `templates/partials/nav.php:109-115` — context omits `nonce`; poll sends `ctx.nonce \|\| ''` (`assets/js/notifications/store.js:481`) → cookie-auth REST rejected per `docs/specs/REST-FRONTEND-CONTRACT.md:18` |
| Notification poll context on notifications page | ui | wired | `templates/notifications/index.php:463-464` (has nonce) — but nav wrapper precedes it in DOM, so `querySelector` still reads the nonce-less nav context (`store.js:433`) |
| DM conversation polling (5s) + typing | store | api-only | `assets/js/messages/store.js:1-9` is an intentional empty stub; messaging is delegated to WPMediaVerse `mvs/messaging` (`includes/Bridges/WPMediaVerseBridge.php`). Not owned by BN-Free; unknown whether mvs polls. |
| Feed new-posts bar (60s poll) | store | missing | `assets/js/feed/store.js:2374-2426` is a **listener only** for `bn:realtime:post-new`; the only dispatcher is Pro. No Free 60s poll/producer exists; no `since`/new-count REST route in `includes/Feed/PostController.php`. Pill never appears in Free. |
| Online presence — directory `online` filter + `is_online` | rest/service | broken | `includes/Profile/MemberDirectoryService.php:133-137,390,560-562` and `includes/SocialGraph/BlockService.php:335` read `bn_last_active` usermeta; **no code writes `bn_last_active`** (only tests do — `tests/Profile/MemberDirectoryServiceTest.php:176`). Heartbeat producer is absent. |
| Online presence heartbeat producer | service | missing | grep across repo: `bn_last_active` is only ever read in production; the only write is `bn_last_login` on admin profile-save (`includes/Admin/Members.php:96`), not a live heartbeat. |

---

## First break

**Online presence heartbeat is missing** (`includes/Profile/MemberDirectoryService.php`
+ `includes/SocialGraph/BlockService.php:335` read `bn_last_active`, nothing writes it).
This is the earliest fully-proven break in the spec's Free presence row: every member
resolves to offline, the "Online now" directory filter / `online` sort returns nothing,
and the OnlineMembersWidget is permanently empty. The notification badge cross-page poll
break (missing nonce) is the second confirmed break.

---

## UX gaps

1. **Online presence has no heartbeat (critical, confirmed-in-code).** `is_user_online()` and the
   `online`/`online_only` directory query depend on `bn_last_active`, which is never written in
   production. The "Online now" filter, online sort, member-card dots, and OnlineMembersWidget are
   all dead in Free. Evidence: `includes/SocialGraph/BlockService.php:335`,
   `includes/Profile/MemberDirectoryService.php:133-137,560-562`; no writer exists repo-wide.

2. **Notification badge poll fails off the notifications page (high, confirmed-in-code).** The nav
   context (present on every page via the shell) supplies `restUrl` but no `nonce`
   (`templates/partials/nav.php:109-115`). The background poll sends an empty `X-WP-Nonce`
   (`assets/js/notifications/store.js:481`); cookie-authenticated REST requires the `wp_rest` nonce
   per `docs/specs/REST-FRONTEND-CONTRACT.md:18,52`, so `/unread-count` returns 401 and the badge
   never refreshes in the background — defeating the spec's "Poll every 30s" row. (Page-load badge
   from server-rendered `unreadCount` still works.)

3. **Feed new-posts bar absent in Free (medium, confirmed-in-code).** The pill
   (`assets/js/feed/store.js:2374-2426`) only listens for Pro's `bn:realtime:post-new`; there is no
   Free 60s poll and no new-posts/since REST route. The spec lists "Poll every 60s" as the Free
   behaviour, so this surface silently does nothing without Pro.

4. **DM polling ownership unverifiable from BN-Free (medium, needs-live-verification).**
   `assets/js/messages/store.js` is an empty stub; DM realtime is delegated to WPMediaVerse
   `mvs/messaging`. Whether the 5s poll promise is met depends on that plugin and cannot be proven
   from this repo. Walk DM live with WPMediaVerse active to confirm.

---

## Minimal refactor plan

1. Add a presence heartbeat writer for `bn_last_active`: on an authenticated front-end request
   (e.g. `template_redirect` for logged-in users, throttled to ~60s via a short transient), call
   `update_user_meta( get_current_user_id(), 'bn_last_active', time() )`. Reuse the existing 300s
   threshold in `BlockService::is_user_online()`. No schema change — the readers already exist.
2. Add `'nonce' => wp_create_nonce( 'wp_rest' )` to the nav notifications context array in
   `templates/partials/nav.php:109-115`, matching the page context at
   `templates/notifications/index.php:463`. This unblocks the existing, already-working poll loop.
3. Wire a Free feed new-posts poll: add a lightweight `GET /me/feed/new-count?since=<id|ts>` route
   in `includes/Feed/PostController.php` (reuse `PostService`/`FeedCache`), then have
   `initRealtimeNewPostsPill()` (`assets/js/feed/store.js:2374`) poll it every 60s when the page is
   visible and dispatch the same `bn:realtime:post-new` events it already consumes — so Pro's push
   path and Free's poll path share one renderer.
4. Live-verify DM 5s polling with WPMediaVerse active at the messages route; only add a BN-side
   fallback if mvs does not already poll.
