# Conformance: Notifications (Free)

**Feature:** Notifications + Email System (on-site bell, full notifications page, prefs, email dispatch)
**Spec ref:** `docs/specs/features/06-notifications-email.md` (Locked)
**Journey ref:** `docs/journeys/notifications-email.md`, `docs/v2 Plans/v2/notifications.html`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/notifications

---

## Summary

The core happy-path notifications journey is wired end-to-end across all five layers. A
member triggers a notification (e.g. follow), a `bn_notifications` row is created, an email
is dispatched/logged honoring per-type prefs, and the recipient sees the count on the bell
(rail + mobile nav), opens `/notifications`, and can mark-read / mark-all / dismiss / filter —
all bound to real REST endpoints through the Interactivity store. Nothing in the documented
happy path is broken or API-only.

One spec sub-feature — the **inline bell dropdown** ("recent notifications, mark read, mark
all read" without leaving the page) — has JS but no markup, so on the web the bell degrades
to a navigate-to-full-page link. This is NOT part of the documented happy-path journey and
does not stop any journey, so it is logged as a gap, not a break.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Bell + unread badge entry (desktop rail) | ui | wired | `templates/shell/rail.php:99-103` (nav link to notifications_url, `badge => $bn_unread_notifs`) |
| Bell + live badge entry (mobile nav) | ui | wired | `templates/partials/nav.php:120-139` (`data-wp-interactive="buddynext/notifications"`, `state.badgeHidden`) |
| Badge stays fresh (poll) | store | wired | `assets/js/notifications/store.js:422-595` (30s/5s poll of `/unread-count`, paints badge) |
| Follow triggers notification | service | wired | `includes/Notifications/NotificationListener.php:31,63-80` (`buddynext_user_followed` -> `create()` type `bn.new_follower`) |
| Notification row created + action fired | db | wired | `includes/Notifications/NotificationService.php:54-171` (insert `bn_notifications`, `should_send` filter, `buddynext_notification_created`) |
| Email dispatched per pref | service | wired | `includes/Notifications/EmailDispatchListener.php:53-80` -> `EmailSender::send()` `includes/Notifications/EmailSender.php:50-90` (off/digest/immediate branch) |
| Email log written | db | wired | `includes/Notifications/EmailSender.php:285-286` (insert `bn_email_log`) |
| Open `/notifications` page | rest | wired | `includes/Core/PageRouter.php:696-697` (enqueue notifications module), `:878-883` (serves `notifications/index.php`) |
| List rendered (grouped Today/Yesterday/Older) | ui | wired | `templates/notifications/index.php:194-203,539-556`; rows via `templates/parts/notification-row.php` |
| List over REST (in-app/mobile) | rest | wired | `includes/Notifications/NotificationController.php:38-46,162-190` (`GET /me/notifications`) |
| Unread count over REST | rest | wired | `NotificationController.php:48-56,198-203` (`GET /me/notifications/unread-count`) |
| Click row -> mark read | store->rest | wired | `notification-row.php:110` (`actions.markRead`) -> `store.js:126-173` (POST `/{id}/read`) -> `NotificationController.php:76-92,211-221` |
| Mark all read | store->rest | wired | `notifications-hero.php` action + `store.js:93-124` (POST `/read-all`) -> `NotificationController.php:58-74,229-234` |
| Dismiss (delete) | store->rest | wired | `notification-row.php:177-183` (`actions.dismiss`) -> `store.js:218-255` (DELETE) -> `NotificationController.php:94-102,242-252` |
| Filter tabs (no reload) | store->rest | wired | `notifications-filter-bar.php` + `store.js:302-383` (HX partial swap) |
| Get/Set prefs (per-type, per-channel) | rest | wired | `NotificationController.php:104-136,264-409`; UI `templates/notifications/prefs.php` + `assets/js/notifications/prefs-store.js` |
| Per-space pref override | rest | wired | `NotificationController.php:138-153,420-509` (member-gated, 403 for non-members) |
| Unauthorized blocked (401) | rest | wired | `NotificationController.php:516-526` (`require_auth`) |
| Unsubscribe (HMAC, no login) | service | wired | `EmailDispatchListener.php:132-168` |

---

## First break

none — journey complete. The documented happy-path (trigger -> row -> email log -> bell badge ->
page -> mark/dismiss/filter -> prefs) is fully traceable in code at every layer.

---

## UX gaps

- **Inline bell dropdown has no markup (orphaned JS).** `assets/js/shell/extras.js:41-126`
  implements a full bell dropdown (toggle `[data-bn-action="toggle-notif-dropdown"]`, container
  `.bn-nav-notif-wrap` / `.bn-notif-dropdown`, list `#bn-notif-dropdown-list`, mark-all via
  `restNotifsReadUrl`), and `includes/Core/PageRouter.php:607-615` localizes the URLs for it.
  No template in `templates/` or `includes/` emits those selectors (grep for `bn-nav-notif-wrap`
  / `bn-notif-dropdown` / `toggle-notif-dropdown` returns zero markup hits). The
  `templates/blocks/notification-bell.php` block is only a plain link with a static badge.
  Result: the spec's "Dropdown: recent notifications, mark read, mark all read" is unreachable
  on the web; the bell instead navigates to the full `/notifications` page (which fully works).
  Severity: medium. Confidence: confirmed-in-code.

- **Journey doc REST paths drift from code.** The journey doc walks `/notifications`,
  `/notifications/mark-read`, etc., but the live routes are `/me/notifications`,
  `/me/notifications/read-all`, `/me/notifications/{id}/read` (`NotificationController.php:38-153`).
  The code is internally consistent (UI + store use the `/me/...` paths); only the doc curl
  examples are stale. Not a usability break — a doc-accuracy gap.
  Severity: low. Confidence: confirmed-in-code.

---

## Minimal refactor plan

Empty. The documented happy-path journey is fully usable and should be left as-is.

The two gaps above are optional, non-blocking follow-ups (out of scope for "make the journey
usable"): (a) add the bell-dropdown markup that `extras.js` already expects, or delete the
orphaned dropdown JS if the navigate-to-page bell is the intended UX; (b) refresh the journey
doc curl examples to the `/me/notifications` paths. Neither is required for the journey to work.

---

## Live-walk note

Verify on http://buddynext-dev.local/notifications as `member1`. Seed first: have `member2`
follow `member1` (custom-table follow, not usermeta) so the list is non-empty — an empty test
account will make the page look bare even though it is fully wired. Walk light + dark.
