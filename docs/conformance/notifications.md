# Conformance: Notifications + Email

**Feature:** Notifications (Free)
**Spec ref:** `docs/specs/features/06-notifications-email.md` (Locked)
**Journey ref:** `docs/journeys/notifications-email.md`, `docs/v2 Plans/v2/notifications.html`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/notifications

---

## Summary

The core notification journey — trigger (follow) → row created → on-site bell/list →
mark read / mark all read / dismiss → preferences (on-site + email freq + per-space) →
immediate-email dispatch → unsubscribe — is fully wired across all five layers
(ui → store → rest → service → db). Both the web journey (templates + Interactivity
stores) and the app/REST journey (controller routes) are intact. No usability break found.

One non-blocking note: the journey doc's curl examples use stale REST paths
(`/notifications`, `/notifications/mark-read`, `/notification-prefs`). The implemented
routes live under `/me/` (`/me/notifications`, `/me/notifications/read-all`,
`/me/notifications/{id}/read`, `/me/notification-prefs`). The UI stores call the correct
`/me/` paths, so the journey works; only the documentation is out of date.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Follow fires `buddynext_user_followed` → notification row created | service | wired | `includes/Notifications/NotificationListener.php:31,70` → `NotificationService::create()` `includes/Notifications/NotificationService.php:54` |
| `buddynext_notification_should_send` filter gates insert; grouping within 24h | service | wired | `includes/Notifications/NotificationService.php:61-110` |
| Notification row persisted; `buddynext_notification_created` fired | db | wired | `includes/Notifications/NotificationService.php:138-168` |
| Immediate email dispatched (freq=immediate) → `bn_email_log` | service | wired | `EmailDispatchListener.php:54,68-80` → `EmailSender::send()` `EmailSender.php:50-94`; log write `EmailSender.php:286` |
| Daily/weekly → `buddynext_queue_email_digest`; off → suppressed | service | wired | `EmailSender.php:55-70` |
| `/notifications` page renders list (server-side, grouped Today/Yesterday/Older) | ui | wired | `templates/notifications/index.php:83-203`; route enqueue `includes/Core/PageRouter.php:690-696` |
| List rows + filter tabs + sidebar + pagination | ui | wired | `templates/parts/notification-row.php`, `notifications-filter-bar.php`, `notifications-group.php` |
| Mark-all-read control → store → `PUT/POST /me/notifications/read-all` | ui→store→rest | wired | `templates/parts/notifications-hero.php`; `assets/js/notifications/store.js:93-124`; controller `NotificationController.php:58-74,229` |
| Per-row mark read / open+mark → `POST /me/notifications/{id}/read` | ui→store→rest | wired | `store.js:126-216,257-277`; controller `NotificationController.php:76-92,211` |
| Dismiss (delete) → `DELETE /me/notifications/{id}` | ui→store→rest | wired | `notification-row.php:177-193`; `store.js:218-255`; controller `NotificationController.php:94-102,242` |
| Unread badge + 30s/5s polling → `GET /me/notifications/unread-count` | ui→store→rest | wired | `store.js:386-595`; controller `NotificationController.php:48-56,198` |
| Preferences page (on-site, email freq, per-space) | ui→store→rest | wired | `templates/notifications/prefs.php:97-452`; `assets/js/notifications/prefs-store.js:105`; controller GET/PUT `NotificationController.php:104-119,264,293`; space prefs `:138-153,420,462` |
| Notification bell block | ui | wired | `templates/blocks/notification-bell.php` |
| Unsubscribe (HMAC, no login) | service | wired | `EmailDispatchListener.php:57,132-168` |

---

## First break

none — journey complete

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Journey doc curl examples reference stale REST paths (`/notifications`, `/notifications/mark-read`, `/notification-prefs`) vs implemented `/me/*` routes. Doc-only drift; UI uses correct paths. | low | confirmed-in-code | doc `docs/journeys/notifications-email.md:61,81,113` vs routes `includes/Notifications/NotificationController.php:38-119` |
| Email delivery / digest cron tick (Action Scheduler) and live bell badge update could not be confirmed statically; needs a live walk with Mailpit + cron run. | low | needs-live-verification | `EmailSender.php:76` (`as_enqueue_async_action`); journey notes `:281` |

---

## Minimal refactor plan

(empty — usable-leave-as-is)

Optional documentation cleanup (not a code change): update the curl paths in
`docs/journeys/notifications-email.md` to the `/me/*` namespace to match the
shipped controller. No code edit required.
