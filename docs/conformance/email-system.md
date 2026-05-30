# Conformance — Email System (Free)

**Spec ref:** `docs/specs/features/06-notifications-email.md`
**Journey ref:** `docs/journeys/notifications-email.md`
**Verdict:** usable-minor-polish
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (+ Mailpit http://localhost:10010/)

---

## What was traced

The core happy-path email journey: a social event (follow) creates a notification,
`EmailDispatchListener` picks it up, `EmailSender` resolves the user's `email_freq`
preference and either sends an immediate transactional email (via Action Scheduler →
`wp_mail()`, logged to `bn_email_log`) or queues for a daily/weekly digest that the
`CronService` digest handlers render and send. Plus the two web-facing surfaces:
the admin Email Template editor and the one-click unsubscribe handler.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Follow event → notification row | service | wired | `includes/Notifications/NotificationListener.php:63` (`on_user_followed` → `create()` type `bn.new_follower`) |
| `buddynext_notification_created` fires | service | wired | `includes/Notifications/NotificationService.php:168` |
| Listener catches event, calls EmailSender | service | wired | `includes/Notifications/EmailDispatchListener.php:54,68-79` |
| Pref check (`off`/digest/immediate) | service | wired | `includes/Notifications/EmailSender.php:55-72` |
| Immediate send enqueued async (Action Scheduler) | service | wired | `includes/Notifications/EmailSender.php:76-90` |
| AS callback → `send_now()` render + `wp_mail()` | service | wired | `includes/Notifications/EmailDispatchListener.php:55,94-96`; `EmailSender.php:104-158` |
| Template loaded from `bn_email_templates` | db | wired | `EmailSender.php:245-257`; seeded `Installer.php:69-211` (`bn.new_follower` at :86) |
| Token render | service | wired (seeded), partial (editor defaults) | `EmailSender.php:172-194` — see UX gap 1 |
| Send logged to `bn_email_log` | db | wired | `EmailSender.php:266-280` |
| Digest queue path (`daily`/`weekly`) | service | wired | `EmailSender.php:62-72` → `EmailDispatchListener.php:109-121` |
| Digest cron renders + sends | service | wired | `includes/Core/CronService.php:59-122,499-534`; scheduled `Core/CronScheduler.php:80-81` |
| Admin Email Template editor (list/edit/toggle/test/reset) | ui | wired | `includes/Admin/EmailEditor.php` (registered `Core/Plugin.php:128`); save/test/reset handlers :47-49,414-501; token picker :852-872 |
| One-click HMAC unsubscribe | ui+service | wired | `EmailDispatchListener.php:57,132-168`; sign/verify `EmailSender.php:206-233` |

## First break

none — journey complete. The seeded `bn.new_follower` template uses only tokens the
runtime resolver supports, so the email renders and sends correctly out of the box.

## UX gaps

### 1. Token vocabulary divergence between EmailEditor and runtime EmailSender (medium)
- **Confidence:** confirmed-in-code
- **Evidence:** `includes/Admin/EmailEditor.php:97-100` (catalogue defaults + token picker for
  `bn.new_follower` expose `{{recipient_name}}`, `{{follower_name}}`, `{{follower_bio}}`,
  `{{profile_url}}`, `{{follow_back_url}}`) vs `includes/Notifications/EmailSender.php:178-191`
  (runtime `render()` only resolves `{{site_name}}`, `{{site_url}}`, `{{user_name}}`,
  `{{actor_name}}`, `{{notification_message}}`, `{{unsubscribe_url}}` + scalar keys present
  in the notification `$data`).
- **Impact:** The notification `$data` for a follow (`NotificationListener.php:70-79`) carries
  no `recipient_name`/`follower_name`/`profile_url`, so any template body using the editor's
  advertised tokens emits **literal `{{follower_name}}` text** in live mail. The seeded default
  (`Installer.php:86-90`) sidesteps this by using only the narrow set — but the moment an admin
  edits or clicks "Reset to default" (which restores the editor catalogue body, not the seeded
  body), or composes using the token picker, outgoing email breaks. `send_test`
  (`EmailEditor.php:362-389`) masks the problem because it resolves the rich tokens with its own
  sample data — so the admin's test looks perfect while production mail does not.
- **Note (app/REST):** Not applicable — email is server-side dispatch; no REST client surface.

### 2. Digest user-meta queue is written but never read (low)
- **Confidence:** confirmed-in-code
- **Evidence:** `EmailDispatchListener::on_queue_email_digest` writes
  `buddynext_digest_queue_{freq}` user meta (`EmailDispatchListener.php:109-121`), but the digest
  cron builds its content by querying `bn_notifications` directly
  (`CronService.php:359-389`, `get_digest_user_ids` :333-350). The user-meta queue is dead-write.
- **Impact:** None on the journey — the digest still renders correctly from notification rows.
  Pure redundancy / latent confusion for the next developer.

## Minimal refactor plan

1. Align the `render()` token map in `EmailSender.php` with the tokens the EmailEditor advertises
   per template — resolve `{{recipient_name}}` (recipient display name), `{{follower_name}}` /
   `{{actor_name}}` (sender display name), `{{profile_url}}` / `{{follow_back_url}}` (sender
   profile URL) from `$user_id` and `$data['sender_id']` inside `render()`. Keep the existing
   safe tokens. This is additive — no change to the seeded defaults, no rewrite.
2. (Optional cleanup, not required for usability) Either make the digest cron consume the
   `buddynext_digest_queue_{freq}` user-meta queue, or drop the `on_queue_email_digest` writes
   and the action, since the digest already reads `bn_notifications` directly.
