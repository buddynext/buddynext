# Conformance — Realtime Updates (Free tier)

**Feature:** Realtime Updates
**Repo:** free
**Spec ref:** `docs/specs/features/P3-realtime-websocket.md` (intent), cross-checked against `docs/specs/REST-FRONTEND-CONTRACT.md` and `docs/specs/SCALE-CONTRACT.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Code traced:** `includes/Realtime/`, plus the Free polling consumers in `assets/js/`

---

## Scope note

The locked spec is written from the Pro/WebSocket angle ("upgrades the free polling-based
real-time approximation to true WebSocket"). It explicitly defines the **Free** behaviour in
its surface table: notification bell polls every 30s, DM polls every 5s, **feed new-posts bar
polls every 60s**, online presence polls every 2min. This audit verifies that the **Free
polling approximation** described in the spec is actually delivered on the web journey — Pro's
WebSocket transport is out of repo and out of scope.

`includes/Realtime/` itself is a clean, correct seam:
- `RealtimeTransport` interface + `PollingTransport` no-op + `TransportFactory` filter
  (`buddynext_realtime_transport`) — the Free/Pro swap point. Correct and harmless.
- `PresenceService` — the Free presence **producer** (stamps `bn_last_active`).
- `RealtimeController` — REST presence heartbeat top-up.

---

## Verdict

**partial-needs-wiring** — three of the four Free realtime surfaces are fully wired, but the
**feed new-posts bar** (a surface that lives directly on the `/activity` entry URL) has **no Free
producer**. The pill only listens for a Pro-only event, so a Free user on the activity feed never
gets the "N new posts" indicator the spec promises at a 60s poll cadence.

---

## Journey chain

| # | Step (Free surface) | Layer | Status | Evidence |
|---|---------------------|-------|--------|----------|
| 1 | Presence producer stamps `bn_last_active` on every front-end page view (zero-JS) | service | wired | `includes/Realtime/PresenceService.php:69-93,109-137`; booted at `includes/Core/Plugin.php:222` |
| 2 | REST presence heartbeat top-up for long single-page sessions | rest | api-only | `includes/Realtime/RealtimeController.php:64-95`; route registered `includes/REST/Router.php:87`; **no JS client posts to `/me/presence/heartbeat`** (grep of `assets/`,`templates/` = 0 hits) |
| 3 | Presence readers: "Online now" filter / `most_active` sort / member-card dot | db | wired | `includes/Profile/MemberDirectoryService.php:133-137,400`; consumed in UI `assets/js/members/store.js:172` |
| 4 | Notification bell: 30s/5s adaptive poll of unread count | store→rest | wired | `assets/js/notifications/store.js:472-530`; route `/me/notifications/unread-count` `includes/Notifications/NotificationController.php:48-58` |
| 5 | Feed new-posts bar: show "N new posts — refresh to view" on `/activity` | ui | broken | `assets/js/feed/store.js:2386-2444` — pill renders **only** on `bn:realtime:post-new` |
| 6 | Producer that drives step 5 on Free (60s poll for newer posts) | store | missing | No `setInterval`/poll in `assets/js/feed/store.js`; `bn:realtime:post-new` dispatched by **nobody** in Free (grep `assets/` = listener only, no dispatcher); FeedController has no `since`/`after_id` param (`includes/Feed/FeedController.php:38-130`) |

---

## First break

**Step 6 — the Free producer for the feed new-posts bar is missing.** The pill UI in
`assets/js/feed/store.js:2423` only listens for `bn:realtime:post-new`, a CustomEvent that is
documented (lines 2378-2384) as fired by `buddynext-pro/.../realtime/store.js` when Soketi
delivers a message. In the Free repo nothing dispatches that event and there is no fallback
60s poll. Result: on `/activity`, a Free member sees new posts only after a manual full-page
reload — contradicting the spec's "Feed new posts bar | Poll every 60s" Free row.

Steps 1-4 are complete; the journey does not break at presence or notifications.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Feed new-posts bar never appears on Free. Spec promises a 60s-poll "new posts" indicator on the activity feed; the pill is event-only and no Free event/poll producer exists. User must manually reload to see new posts. | medium | confirmed-in-code | `assets/js/feed/store.js:2386-2444` (listener only); no dispatcher/poll in `assets/js/feed/store.js`; no `since`/`after_id` in `includes/Feed/FeedController.php:38-130` |
| REST presence heartbeat `/me/presence/heartbeat` has no web client. A Free user who keeps one tab open without navigating for >300s drops to "offline" until the next navigation. Endpoint is correct and serves app/REST clients; only the web SPA-tab edge case is uncovered. | low | confirmed-in-code | `includes/Realtime/RealtimeController.php:64-95` exists; 0 callers in `assets/`,`templates/`. Baseline `template_redirect` stamp (`PresenceService.php:70`) covers normal page-to-page navigation, so this is an edge case, not a journey break. |

No gap for notifications or presence-on-navigation — both fully wired.

---

## Minimal refactor plan

Reuse the existing notification-poll pattern (`assets/js/notifications/store.js:472-530`) and the
existing pill renderer (`assets/js/feed/store.js:2401-2421`); do **not** rewrite the seam.

1. Add a `since`/`after_id` (or newest-known-id) param to the home feed listing in
   `includes/Feed/FeedController.php` (+ `FeedService`) returning only the count and IDs of posts
   newer than the client's top-of-feed id. (Or expose a lightweight `/feed/new-count?after_id=` route.)
2. In `assets/js/feed/store.js`, add a visibility-aware 60s `setTimeout` poll (mirror
   `schedule()`/`poll()` from the notifications store, including the `document.hidden` guard and
   focus re-poll) that calls the new route and, on a positive delta, dispatches
   `bn:realtime:post-new` for each new id — feeding the existing `initRealtimeNewPostsPill()`
   listener unchanged. This keeps the Pro path identical (Pro just pre-empts the poll, same as the
   notification `kickHot` seam at `notifications/store.js:526`).
3. (Optional, low) Add a JS presence top-up: in the shell init, POST to `/me/presence/heartbeat`
   on a 2-min interval while the tab is visible, reusing the `wp_rest` nonce already localized per
   `REST-FRONTEND-CONTRACT.md`. Closes the long-idle-tab presence edge case.

Steps 1-2 close the spec gap on the entry URL; step 3 is polish for the SPA-tab edge case.
