# Conformance: Realtime Updates (Free)

**Feature:** Realtime Updates
**Repo:** buddynext (free)
**Spec ref:** `docs/specs/features/P3-realtime-websocket.md` (Pro WebSocket spec; Free clause = "gracefully degrades to polling… no broken features, just slower")
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Scope note

The locked spec is Pro's WebSocket upgrade. The Free obligation is the *graceful-degradation* clause: every live surface must still work over REST polling, and the producer/consumer seams must exist so Pro can swap the transport without touching readers. This audit verifies the **Free** journey only. DM realtime is delegated to the WPMediaVerse bridge and is out of scope for `includes/Realtime/`.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Boot presence heartbeat (template_redirect, no-JS baseline) | service | wired | `includes/Core/Plugin.php:222` → `includes/Realtime/PresenceService.php:69-93` |
| Stamp `bn_last_active` (throttled 60s, fires `buddynext_presence_stamped`) | db | wired | `includes/Realtime/PresenceService.php:109-137` |
| REST heartbeat top-up `POST /me/presence/heartbeat` | rest | wired | `includes/REST/Router.php:87`, `includes/Realtime/RealtimeController.php:64-95` |
| Presence readers (online dot, "Online now" filter/sort, OnlineMembersWidget) | service | wired | `includes/SocialGraph/BlockService.php:335-344`, `includes/Profile/MemberDirectoryService.php:66-134` |
| Presence rendered in templates (member card, DM rail, profile hero, directory) | ui | wired | `templates/parts/member-card.php:139`, `templates/directory/members.php:309-360` |
| Notification bell unread poll (30s cold / 5s hot, paused on hidden) | store | wired | `assets/js/notifications/store.js:420-540` |
| → `GET /me/notifications/unread-count` | rest | wired | `includes/Notifications/NotificationController.php:50` |
| Notif context (restUrl + `wp_rest` nonce) on global nav, every page | ui | wired | `templates/partials/nav.php:113-121` |
| Feed "new posts" pill 60s poll (visibility-aware) | store | wired | `assets/js/feed/store.js:2440-2577` |
| → `GET /feed/new-count` (require_auth, cursor `after_id`) | rest | wired | `includes/Feed/FeedController.php:66-87`, `includes/Feed/FeedService.php:485-523` |
| Pill consumes composer context (`restUrl`/`restNonce`/`userId`) on /activity | ui | wired | `templates/partials/composer.php:83-110` |
| Pro transport seam (`buddynext_realtime_transport` filter, no-op Free default) | service | wired | `includes/Realtime/TransportFactory.php:42-67`, `includes/Realtime/PollingTransport.php:41-44` |
| DM instant delivery / typing | service | unknown | delegated to WPMediaVerse bridge — out of scope for `includes/Realtime/` |

## First break

none — journey complete (Free polling journey is wired end-to-end). DM realtime is owned by the WPMediaVerse bridge, not this feature.

## UX gaps

1. **Feed pill own-post filter bypassed in Free poll mode** (low, confirmed-in-code). The pill skips the viewer's own posts by comparing `author === viewerId` (`assets/js/feed/store.js:2487-2492`), but the Free 60s poll synthesizes delta events with `user_id: 0` (`store.js:2553`), so a member's own post made in another tab counts toward the pill. The composer already inserts own posts locally, so impact is a possible +1 over-count, not a broken surface. Cosmetic.
2. **DM 5s polling not verifiable from this feature** (low, needs-live-verification). Spec lists Free DM as 5s poll; `assets/js/messages/store.js` is intentionally empty (DM routed through WPMediaVerseBridge). Confirm DM live update during the live walk — it belongs to the bridge, not `includes/Realtime/`.

## Minimal refactor plan

(empty — usable-leave-as-is)

## Live-walk URL

http://buddynext-dev.local/activity — log in as a member, open a second tab, post from tab B, confirm the "new posts" pill appears in tab A within ~60s; trigger a notification and confirm the nav badge updates within 30s (5s if you just acted); open the members directory and confirm "Online now" lists active members.
