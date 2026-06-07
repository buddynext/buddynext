# Conformance Dossier — Email System (Free)

**Feature:** Email System (notifications → transactional email pipeline)
**Spec ref:** `docs/specs/features/06-notifications-email.md` (Locked)
**Journey ref:** `docs/journeys/notifications-email.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Date:** 2026-05-31
**Verdict:** usable-leave-as-is

---

## Summary

The email system is wired end-to-end for both the web journey and the REST/app
journey. A social event fires an action → `NotificationListener` creates a
`bn_notifications` row → `NotificationService::create()` fires
`buddynext_notification_created` → `EmailDispatchListener` reads the user's
pref → `EmailSender` either enqueues an Action Scheduler async send (immediate)
or fires the digest action (daily/weekly) → `send_now()` renders the seeded
`bn_email_templates` row and calls `wp_mail()`, then logs to `bn_email_log`.
Digests are processed by a scheduled cron (`CronScheduler` + `CronService`).
The web UI (notifications page, bell badge, prefs page) is bound to the correct
REST routes through two Interactivity API stores. Unsubscribe is HMAC-signed and
handled on `init`.

No code break stops the journey. The discrepancies found are in the **journey
doc text**, not the code.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Follow event creates notification | service | wired | `includes/Notifications/NotificationListener.php:63-80` (`on_user_followed` → `create()` with `type='bn.new_follower'`) |
| Notification row inserted + action fired | db | wired | `includes/Notifications/NotificationService.php:138-168` (insert `bn_notifications`; `do_action('buddynext_notification_created', $notif_id, $recipient_id, $data)`) |
| Should-send / group / send-at filters honored | service | wired | `NotificationService.php:61-93` (group merge), `:107-110` (`buddynext_notification_should_send`), `:129-134` (`buddynext_notification_send_at`) |
| EmailDispatchListener picks up event | service | wired | `includes/Notifications/EmailDispatchListener.php:53-58` (registered), `:68-80` (`on_notification_created` → `sender->send`); boot `includes/Core/Plugin.php:239-242` |
| Pref gate (off / digest / immediate) | service | wired | `includes/Notifications/EmailSender.php:50-91`; pref read `includes/Notifications/NotificationPrefService.php:45-79` |
| Immediate async dispatch via Action Scheduler | service | wired | `EmailSender.php:76-90` (`as_enqueue_async_action('buddynext_send_notification_email')`); callback `EmailDispatchListener.php:94-96` |
| Template render + wp_mail + payload filter | service | wired | `EmailSender.php:104-173` (`send_now`, `buddynext_email_payload` filter, `wp_mail`) |
| Template row seeded + enabled by default | db | wired | `includes/Core/Installer.php:69-230` (`bn.new_follower` seeded), `:551` (`enabled DEFAULT 1`) |
| Email send logged | db | wired | `EmailSender.php:281-295` (`log_sent` → `bn_email_log`) |
| Digest queue path (daily/weekly) | service | wired | `EmailSender.php:62-72` (`buddynext_queue_email_digest`); cron `includes/Core/CronService.php:59-125`; scheduled `includes/Core/CronScheduler.php:75-86,128` |
| REST: list / unread / read / read-all / delete / prefs | rest | wired | `includes/Notifications/NotificationController.php:37-154` (routes under `/me/notifications` + `/me/notification-prefs`) |
| Web UI bell + page → store → REST | ui/store | wired | `templates/notifications/index.php:464` (`restUrl => .../me/notifications`); `assets/js/notifications/store.js:104,150,240,390` |
| Web prefs page → store → REST | ui/store | wired | `templates/notifications/prefs.php:97-99,155-156`; `assets/js/notifications/prefs-store.js:240` (PUT `/me/notification-prefs`), `:202` (POST space prefs) |
| Unsubscribe (HMAC, no login) | service | wired | `EmailDispatchListener.php:132-168`; signing/verify `EmailSender.php:221-248` |

---

## First break

none — journey complete.

---

## UX gaps

The journey is usable. The items below are doc/cleanliness observations, not
journey-stopping breaks. None block a real user.

1. **Journey doc REST paths are stale (doc bug, not code bug).** The journey doc
   walks `/buddynext/v1/notifications`, `/notifications/unread-count`,
   `POST /notifications/mark-read`, `DELETE /notifications/{id}`,
   `GET|PUT /notification-prefs`. The code registers everything under
   `/me/…`: `GET /me/notifications`, `/me/notifications/unread-count`,
   `PUT|POST /me/notifications/read-all`, `PUT|POST /me/notifications/{id}/read`,
   `DELETE /me/notifications/{id}`, `GET|PUT /me/notification-prefs`
   (`NotificationController.php:37-154`). The shipped web UI uses the correct
   `/me/…` paths (`templates/notifications/index.php:464`,
   `templates/notifications/prefs.php:97`), so the live journey works — but
   anyone curl-walking the doc verbatim will hit 404s.
   Severity: low. Confidence: confirmed-in-code.

2. **Journey doc SQL filters on `type='follow'` but code stores
   `bn.new_follower`.** `NotificationListener::on_user_followed` writes
   `type='bn.new_follower'` (`NotificationListener.php:74`) and the email log
   row uses the same key (`EmailSender.php:281-295`). The doc's verification
   queries (`WHERE type='follow'`) return nothing. Doc bug only.
   Severity: low. Confidence: confirmed-in-code.

3. **Digest user-meta queue is a dead write.**
   `EmailDispatchListener::on_queue_email_digest` appends each digest-eligible
   notification to `buddynext_digest_queue_{freq}` user meta
   (`EmailDispatchListener.php:109-121`), but the digest cron does not read that
   meta — it independently re-derives recipients from `bn_notification_prefs`
   and scans unread `bn_notifications` (`CronService.php:65-84,333-358`). Digest
   emails still send correctly, so this is a harmless accumulating write, not a
   break. Severity: low. Confidence: confirmed-in-code.

---

## Minimal refactor plan

Empty. The journey is usable as-is; do not rewrite working code. If the team
wants doc/code hygiene later (out of scope for usability), the three low-severity
items above are: update the journey doc's REST paths to `/me/…` and its SQL to
`type='bn.new_follower'`; and either consume or drop the
`buddynext_digest_queue_{freq}` user-meta write. None are required for a real
user to complete the journey.

---

## Live-walk URL

http://buddynext-dev.local/wp-admin/ (admin email editor + settings) and Mailpit
at http://localhost:10010/ for delivery confirmation. Member web journey:
`/notifications/` and `/settings/notifications/`.
