# Conformance: Notifications (Free)

**Feature:** Notifications
**Repo:** free
**Spec ref:** `docs/specs/features/06-notifications-email.md` (Locked) + `docs/journeys/notifications-email.md`
**Live-walk URL:** http://buddynext-dev.local/notifications
**Verdict:** usable-minor-polish

## Summary

The core notification journey — trigger (follow) → row inserted → bell badge → list page → mark read / mark all read / dismiss → preferences → email dispatch — is fully wired end-to-end across UI, store, REST, service, and DB. The on-page list uses the WordPress Interactivity API store (`buddynext/notifications`) bound to the real REST routes; the bell renders a live count and links to the page; background polling keeps the badge fresh. The locked spec's happy path is achievable by a real web user and a REST/app client.

One contained defect: the **space-invite inline Accept/Decline buttons** in `parts/notification-row.php` call store actions (`actions.acceptSpaceInvite` / `actions.declineSpaceInvite`) that are not defined in any JS store in the repo. Those two buttons are dead. This does not block the core follow journey, and the space-invite row still navigates on row click (markRead), so a user can still reach the invite — they just can't act on it from the bell row.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Follow fires trigger → notification created | service | wired | `includes/Notifications/NotificationListener.php:31,70` (`on_user_followed` → `create('bn.new_follower')`) |
| `create()` inserts row, dedups by group_key, fires `buddynext_notification_created`, evaluates `buddynext_notification_should_send` | service/db | wired | `includes/Notifications/NotificationService.php:54,107,168` |
| Email dispatched on immediate pref | service | wired | `includes/Notifications/EmailDispatchListener.php:53-79`; digest queue `:109` |
| Bell shows unread count + links to page | ui | wired | `templates/blocks/notification-bell.php:20,29,36` |
| Page route `/notifications` renders hub template + enqueues store | ui | wired | `includes/Core/PageRouter.php:62,673-674`; `templates/notifications/index.php:470` |
| List notifications (REST) | rest | wired | `includes/Notifications/NotificationController.php:42-46,162` (`GET /me/notifications`) |
| Unread count (REST) | rest | wired | `NotificationController.php:51-55,198` |
| Mark one / mark all read (REST) | rest | wired | `NotificationController.php:58-92,211,229` (PUT+POST) |
| Delete notification (REST) | rest | wired | `NotificationController.php:94-102,242` (DELETE `/{id}`) |
| Row click → markRead; mark-all → markAllRead; dismiss → DELETE; tabs → setFilter; Open → openAndMark | store/ui | wired | `assets/js/notifications/store.js:93,126,218,302,257`; bindings in `templates/parts/notification-row.php:110,148-194` and `index.php:283,464` |
| Badge live refresh (30s poll, 5s hot, pause on hidden) | store | wired | `assets/js/notifications/store.js:422-595` |
| Get/update notification preferences (REST) | rest | wired | `NotificationController.php:104-119,264,293`; prefs UI `templates/notifications/prefs.php` |
| Per-space notification pref (REST) | rest | wired | `NotificationController.php:138-153,420,462` |
| Space-invite inline Accept/Decline buttons | store | broken | `templates/parts/notification-row.php:148-159` call `actions.acceptSpaceInvite` / `actions.declineSpaceInvite`; repo-wide grep finds no definition in any store |

## First break

For the core happy path (follow notification): **none — journey complete.**

The earliest broken link in the broader feature is the space-invite inline Accept/Decline buttons (`templates/parts/notification-row.php:148-159`) — store actions referenced but never defined. This is off the core follow journey.

## UX gaps

1. **Space-invite Accept/Decline buttons are inert** — severity: high — confidence: confirmed-in-code. `parts/notification-row.php:149,155` bind `actions.acceptSpaceInvite` / `actions.declineSpaceInvite`; neither exists in `assets/js/notifications/store.js` or anywhere else in the repo. Interactivity API silently no-ops undefined actions, so the buttons appear clickable but do nothing. Row click still navigates the user to the invite via `markRead`, so the action is reachable elsewhere — but the prominent primary "Accept" CTA in the bell row is dead.

2. **Journey doc REST paths drift from implemented routes** — severity: low — confidence: confirmed-in-code. `docs/journeys/notifications-email.md` lists `/notifications`, `/notifications/mark-read`, `/notification-prefs`; the controller registers them under `/me/...` (`NotificationController.php:42,58,104`). Curl-based verification in the journey doc will 404 as written. Implementation is correct; the doc is stale. Not a user-facing break.

## Minimal refactor plan

1. Add `acceptSpaceInvite` and `declineSpaceInvite` actions to the `buddynext/notifications` store in `assets/js/notifications/store.js`, reusing the existing optimistic-update + rollback pattern already used by `dismiss`/`markReadOnly`. Point them at the existing Spaces membership REST endpoints (accept/decline invite) — do not invent new endpoints; verify the Spaces controller route names first and bind to them. On success, remove the row and decrement the badge as `dismiss` does.

(Item 2 above is a doc-only fix and is intentionally excluded from the code refactor plan — the implementation is correct.)
