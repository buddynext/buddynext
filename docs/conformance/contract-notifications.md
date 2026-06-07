# Contract Conformance — Notification / Email / Push Template Model

**Contract:** Templates carry their own subject/body; every event emits through one channel + preference model.
**Spec refs:** `docs/specs/NOTIFICATION-MESSAGES.md`, `docs/specs/features/06-notifications-email.md`
**Verdict:** usable-minor-polish
**Date:** 2026-05-31

---

## What was checked

The flagged bug class is the broadcast/drip "sender ignores per-message subject/body"
failure — where a campaign or drip step authored unique copy but the sender rendered
a shared template-row instead. Verified end-to-end across Free email, Pro broadcast,
Pro drip, and Pro push.

## Result — the email bug class is CLEAN

`EmailSender::send_now()` (Free) implements an explicit **composed-email path**:

- `includes/Notifications/EmailSender.php:104-135` — when `$data['subject']` and
  `$data['body_html']` are both present, those win and no `bn_email_templates` row
  is required. Event emails (e.g. `bn.new_follower`) still render from their template
  row. A disabled template row suppresses its own event email but never a composed
  campaign/drip email (`:122`).
- `BroadcastService::send_pending()` — `buddynext-pro/includes/Email/BroadcastService.php:451-460`
  passes the campaign's own `subject` + `body_html` per recipient via that path.
- `DripService::process_due_enrollments()` — `buddynext-pro/includes/Email/DripService.php:317-328`
  passes each step's own `subject` + `body_html` per enrollee.
- No rogue `wp_mail()` anywhere in Pro Email/Push — both senders delegate to Free's
  `send_now()`. Space-digest reuses the same BroadcastService path (type=space_digest).

So per-message subject/body is honored everywhere it should be. The bug class the task
hunts for does not exist in the current code.

## One channel + preference model

- All events fan in through `NotificationService::create()` →
  `do_action('buddynext_notification_created', ...)`
  (`includes/Notifications/NotificationService.php:168`).
- Email subscribes via `EmailDispatchListener` → pref-gated `EmailSender::send()`
  (`email_freq`: off / daily / weekly / immediate — `EmailSender.php:50-91`).
- Push subscribes via `PushDispatcher` at priority 20, pref-gated by
  `PushPrefService::is_push_enabled()` (`Push/PushDispatcher.php:56,87`).
- In-app copy is the single source of truth in `NotificationMessageService::compose()`
  (public, `NotificationMessageService.php:44`).

The channel + preference spine is intact.

## Divergence found — Push copy does NOT route through the canonical message service

`PushDispatcher::build_snippet()` (`buddynext-pro/includes/Push/PushDispatcher.php:124-205`)
maintains its **own** `switch` on type instead of calling
`NotificationMessageService::compose()`. Its cases use stale slugs:

| Push switch case | Spec / actually-emitted slug | Match? |
|---|---|---|
| `bn.follower`  | `bn.new_follower`  (Listener:74)  | NO |
| `bn.reaction`  | `bn.post_reacted`  (Listener:253) | NO |
| `bn.comment`   | `bn.post_commented`(Listener:306) | NO |
| `bn.mention`   | `bn.mention`       (Listener:387) | yes |

The default branch falls back to `$data['message']`, but `NotificationListener` does
not pass a `message` key for these events (confirmed at Listener:70-79). Net effect:
push banners for new-follower / reaction / comment render the generic
`"You have a new notification."` instead of the spec copy. Only `bn.mention` shows
correct text.

**Severity: medium.** Push is a Pro addon and the notification still fires + deep-links
correctly; only the banner preview text is generic. It is a copy-quality drift, not a
broken journey, and it duplicates copy logic the spec declares single-source. Needs a
live push to confirm runtime banner text, but the slug mismatch is provable in code.

## Recommendation

Route push copy through the canonical service rather than a private switch:
replace the `build_snippet()` switch with a call to
`NotificationMessageService::compose()` (or `compose_single`) keyed on the row's real
type, so push inherits the same copy as bell + email and new types never need a second
edit. This is the "native APIs / single source of truth" pattern, not a rewrite of
working infrastructure.
