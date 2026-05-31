# Conformance — Email System (Free)

**Feature:** Email System (transactional notification emails, prefs, dispatch, digest, unsubscribe)
**Spec ref:** `docs/specs/features/06-notifications-email.md`
**Journey ref:** `docs/journeys/notifications-email.md`
**Verdict:** usable-leave-as-is
**Date:** 2026-05-31

---

## Summary

The email system happy path is wired end-to-end and internally consistent. A social
event (follow) creates a notification row, `EmailDispatchListener` hears
`buddynext_notification_created`, `EmailSender` reads the user's `email_freq` pref,
and on `immediate` it enqueues an Action Scheduler job that renders the seeded
`bn.new_follower` template and calls `wp_mail()`, then logs to `bn_email_log`.
`daily`/`weekly` divert to the digest queue; `off` short-circuits. The web prefs UI
(`templates/notifications/prefs.php` + `assets/js/notifications/prefs-store.js`) PUTs
to the REST pref endpoint, so members can change email frequency from the browser.
Admin email editing exists (`includes/Admin/EmailEditor.php`, submenu registered).

The journey doc has cosmetic drift from the shipped code (see UX gaps) — but the
code itself is correct and complete. No usability break found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Follow fires `buddynext_user_followed` → notification created, type `bn.new_follower` | service | wired | `includes/Notifications/NotificationListener.php:63-80` |
| `NotificationService::create()` inserts `bn_notifications`, fires `buddynext_notification_created($id,$recipient,$data)` | db | wired | `includes/Notifications/NotificationService.php:138-168` |
| `buddynext_notification_should_send` filter evaluated before insert | service | wired | `includes/Notifications/NotificationService.php:107-110` |
| `EmailDispatchListener` constructed + registered on the action | service | wired | `includes/Core/Plugin.php:239-242`; `EmailDispatchListener.php:53-58` |
| Listener reads `$data['type']`, calls `EmailSender::send()` | service | wired | `includes/Notifications/EmailDispatchListener.php:68-80` |
| `EmailSender::send()` pref gate: off→stop, daily/weekly→`buddynext_queue_email_digest`, immediate→async | service | wired | `includes/Notifications/EmailSender.php:50-91` |
| Immediate path enqueues `as_enqueue_async_action('buddynext_send_notification_email')` (sync fallback if AS absent) | service | wired | `includes/Notifications/EmailSender.php:76-90` |
| `send_now()` fetches seeded template, renders tokens, applies `buddynext_email_payload`, `wp_mail()`, logs | service/db | wired | `includes/Notifications/EmailSender.php:104-173` |
| `bn.new_follower` (and full catalog) seeded into `bn_email_templates`, `enabled` defaults 1 | db | wired | `includes/Core/Installer.php:69-227,545-551` |
| Email send recorded to `bn_email_log` | db | wired | `includes/Notifications/EmailSender.php:281-295` |
| Member reads notifications / unread count via REST | rest | wired | `includes/Notifications/NotificationController.php:38-56` |
| Member edits email frequency pref via REST PUT | rest | wired | `includes/Notifications/NotificationController.php:104-119`; `NotificationPrefService.php:90-112` |
| Web UI prefs page binds `setEmailFreq`/`saveAll` to REST PUT (Interactivity API) | ui/store | wired | `templates/notifications/prefs.php:155,293,418`; `assets/js/notifications/prefs-store.js:105,221,240-241` |
| On-site notification bell template present and bound | ui | wired | `templates/blocks/notification-bell.php`; `templates/notifications/index.php` |
| One-click HMAC unsubscribe (no login) sets `email_freq=off` | service | wired | `includes/Notifications/EmailSender.php:221-248`; `EmailDispatchListener.php:132-168` |
| Digest queue accumulator (cron-processed) | service | wired | `includes/Notifications/EmailDispatchListener.php:109-121`; `includes/Core/CronService.php` |
| Admin email editor (list/edit/toggle) registered | ui | wired | `includes/Admin/EmailEditor.php:25-72`; `includes/Core/Plugin.php:128` |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Journey-doc drift: doc uses `type='follow'` and routes `/buddynext/v1/notifications`, `/notifications/mark-read`, `/notification-prefs`; shipped code uses `bn.new_follower` and `/buddynext/v1/me/notifications`, `/me/notifications/read-all`, `/me/notification-prefs`. Running the doc's curl literally returns 404/empty and could be misread as a break. Code is correct; doc is stale. | low | confirmed-in-code | `docs/journeys/notifications-email.md:26,61,81,113` vs `includes/Notifications/NotificationController.php:38-119`; `includes/Notifications/NotificationListener.php:74` |
| Spec/journey describe the action signature as `(...,string $type, array $data)`; code fires/handles `(int $id,int $recipient,array $data)` with type inside `$data['type']`. Producer and consumer agree; only the docs differ. | low | confirmed-in-code | `includes/Notifications/NotificationService.php:168`; `includes/Notifications/EmailDispatchListener.php:68-79` |
| Inbox arrival depends on local SMTP/Mailpit; `bn_email_log` is the reliable dev assertion surface. Not a code defect. | low | needs-live-verification | `includes/Notifications/EmailSender.php:165-172`; Mailpit http://localhost:10010/ |

No critical/high/medium usability breaks identified.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

Optional, non-blocking doc hygiene (not a code change): update
`docs/journeys/notifications-email.md` REST paths to the `/me/...` namespace and the
type token from `follow` to `bn.new_follower` so the documented walk runs as-is.

---

## Live-walk URL

http://buddynext-dev.local/wp-admin/  (+ Mailpit http://localhost:10010/)

Use shipped routes when walking: `GET /wp-json/buddynext/v1/me/notifications`,
`GET .../me/notifications/unread-count`, `PUT .../me/notification-prefs` with
`{"bn.new_follower":{"on_site":true,"email_freq":"off"}}`. Assert `wp_bn_email_log`
rows by `type = 'bn.new_follower'`. The digest path requires a cron tick
(`wp cron event run`) to dispatch.
