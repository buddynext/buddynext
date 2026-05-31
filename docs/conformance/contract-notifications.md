# Contract conformance — Notification / email / push template model

**Verdict:** usable-minor-polish
**Checked:** 2026-05-31
**Spec:** `docs/specs/NOTIFICATION-MESSAGES.md` + `docs/specs/features/06-notifications-email.md`
**Code:** `buddynext/includes/Notifications/`, `buddynext-pro/includes/Email/`, `buddynext-pro/includes/Push/`

## Question asked

Do templates carry their own subject/body, and does every event emit through one
channel + preference model? Specifically — are there senders that ignore
per-message subject/body (the broadcast / drip bug class)?

## Answer

The core contract holds. The historic broadcast/drip bug class is **fixed**.

### Guarantee 1 — one creation event, fanned to channels (WIRED)

`NotificationService::create()` is the single insert point and fires
`do_action('buddynext_notification_created', $notif_id, $recipient_id, $data)`
exactly once per row (also on group-merge).
- Free `EmailDispatchListener::register()` hooks it at prio 10 → email.
- Pro `PushDispatcher::register()` hooks it at prio 20 → FCM push.
Evidence: `NotificationService.php:168`, `EmailDispatchListener.php:54`,
`PushDispatcher.php:56`.

### Guarantee 2 — templates carry their own subject/body (WIRED)

`EmailSender::send_now()` resolves subject/body from the `bn_email_templates`
row for event emails, OR from an **inline composed-email path** when the caller
passes `data['subject']` + `data['body_html']`. Disabled template rows suppress
their own event emails but never a composed campaign/drip email.
Evidence: `EmailSender.php:104-173` (`$has_inline` branch at 112-132).

### Guarantee 3 — broadcast/drip carry per-message authored subject/body (WIRED — the bug-class fix)

Both Pro senders delegate to Free's `EmailSender::send_now()` via the composed
path, passing the campaign/step's authored `subject` + `body_html`. They no
longer depend on a per-type `bn_email_templates` row, so they cannot silently
fall back to event-template copy or to a "sent you a notification" string.
- Broadcast: `BroadcastService::send_pending()` selects `c.subject, c.body_html`
  and passes them inline — `BroadcastService.php:405-460`.
- Drip: `DripService::process_due_enrollments()` passes
  `step['subject']` / `step['body_html']` inline — `DripService.php:317-328`.
This is the class the directive flagged; it is closed.

### Guarantee 4 — per-type, per-channel preference model (WIRED)

- Email channel: `NotificationPrefService::get_pref()` →
  `{on_site, email_freq}`; `EmailSender::send()` honours off/daily/weekly/
  immediate. `NotificationPrefService.php:45-79`, `EmailSender.php:55-91`.
- Push channel: its own `PushPrefService::is_push_enabled()` gate before
  fan-out. `PushDispatcher.php:87`.
- Cross-channel pref surfacing via `buddynext_notification_prefs` filter so Pro
  appends push rows without forking Free. `NotificationPrefService.php:167`.

### Guarantee 5 — exhaustive in-app composition, no fallback string (WIRED)

`NotificationMessageService::compose_single()` / `compose_grouped()` carry a
case for every catalogue slug (`bn.*`), matching the locked table 1:1.
Evidence: switch at `NotificationMessageService.php:110-360` (all slugs present).

## Contract observations (minor — not breaks)

1. **Push copy reimplements composition instead of reusing the canonical
   source, and matches legacy slugs.** `PushDispatcher::build_snippet()`
   switches on `follower/reaction/comment/mention` + `bn.follower/bn.reaction/
   bn.comment/bn.mention`, but the canonical catalogue slugs are
   `bn.new_follower`, `bn.post_reacted`, `bn.post_commented`. Only `bn.mention`
   matches. Every other type falls to the generic
   "You have a new notification." default (or `data['message']` when the
   emitter happens to pass one). Push still sends — no break — but the rich
   per-type copy guaranteed for the bell does not reach the push banner, and
   push duplicates copy logic that lives in `NotificationMessageService`.
   Evidence: `PushDispatcher.php:159-199` vs catalogue slugs in
   `NOTIFICATION-MESSAGES.md` and `NotificationMessageService.php:112-347`.
   Severity: medium. Confidence: confirmed-in-code.

2. **Catalogue lists email slugs for several types that have no seeded
   template.** The locked catalogue marks an `email` slug for
   `bn.connection_declined`, `bn.comment_reply`, `bn.space_join`,
   `bn.space_new_post`, `bn.space_role_changed`, `bn.new_message`,
   `bn.user_unsuspended`, `bn.media_favorited`, `bn.space_join_declined`. The
   Installer seed (`Installer.php:86-206`) and the EmailEditor defaults
   (`EmailEditor.php:94-242`) only cover ~21 types and omit these. With no row
   and no inline content, `EmailSender::send_now()` returns at line 116 → silent
   no-email for those events. May be intentional (in-app-only at runtime, or
   bridge-seeded for media/message), so flagged as needs-live-verification
   rather than a proven break. Severity: low-medium.
   Confidence: confirmed-in-code (seed list) / needs-live-verification (intent).

## Refactor plan (minimal, polish only)

1. Map `PushDispatcher::build_snippet()` onto the canonical catalogue slugs
   (`bn.new_follower`, `bn.post_reacted`, `bn.post_commented`, ...) — ideally by
   calling `NotificationMessageService::compose()` on the row instead of a
   private switch, so push copy stays in lockstep with the bell automatically.
2. Reconcile the catalogue `email` column with the seed list: either seed the
   missing template rows or change those rows' `email` column to `—` in the
   spec to reflect in-app-only intent. Verify on a live install which path is
   correct before editing.

Both are polish on a working spine. The senders, the per-message subject/body
contract, the single creation event, and the per-channel preference model are
all intact. No rewrite warranted.
