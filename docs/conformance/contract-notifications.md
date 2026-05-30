# Contract conformance — Notification / email / push template model

**Contract:** Templates carry their own subject/body; every event emits through one
channel + preference model.
**Locked spec:** `docs/specs/NOTIFICATION-MESSAGES.md`, `docs/specs/features/06-notifications-email.md`
**Code inspected:** `includes/Notifications/` (Free), `buddynext-pro/includes/Email/`,
`buddynext-pro/includes/Push/`
**Verdict:** broken-journey
**Date:** 2026-05-31

---

## Spine that holds (the parts that conform)

- **Single create path.** `NotificationService::create()` is the one insertion point;
  it fires `buddynext_notification_created` exactly once per row (insert OR group-merge),
  carrying `($notif_id, $recipient_id, $data)`. (`NotificationService.php:89,168`)
- **One channel/preference fan-out from that action.**
  - Email: `EmailDispatchListener::on_notification_created` → `EmailSender::send()`
    consults `NotificationPrefService::get_pref()` `email_freq` (`off`/`daily`/`weekly`/`immediate`)
    before dispatch. (`EmailDispatchListener.php:54,79`; `EmailSender.php:55-91`)
  - Push: `PushDispatcher::on_notification_created` consults `PushPrefService::is_push_enabled()`
    per type before fanning out. (`PushDispatcher.php:56,87`)
- **Transactional templates carry their own subject/body.** `EmailSender::send_now()`
  loads the `bn_email_templates` row keyed on the notification type and renders
  `$template->subject` + `$template->body_html` with token replacement. The
  `buddynext_email_payload` filter lets Pro mutate the final payload.
  (`EmailSender.php:104-158`)
- **Transactional types are seeded.** `Installer::seed_email_templates()` inserts a
  template row per transactional type slug. (`Installer.php:69-210`)

So for ordinary per-event notifications the contract holds: one create → one action →
preference-gated email + push, each carrying its own subject/body.

---

## First break — Pro broadcast and drip senders ignore the per-message subject/body

The spec is explicit that broadcast campaigns and drip steps each author their own
`subject` + `body_html` (NOTIFICATION-MESSAGES email model; 06-notifications-email
"Admin Email Editor" / "Drip Step ... configurable per sequence step" / "Broadcast
Campaign (admin-authored)"). The per-campaign and per-step copy is stored correctly:

- `bn_email_campaigns.subject` / `.body_html` written by `BroadcastService::create_campaign()`
  (`BroadcastService.php:111-119`).
- Drip step `{subject, body_html}` written into the sequence `steps` JSON
  (`DripService::validate_step` / `add_step`, `DripService.php:434-444`).

But the production senders throw that copy away:

### Broadcast (`BroadcastService::send_pending`, `BroadcastService.php:450-457`)
```php
$sender->send_now(
    $user_id,
    'bn.broadcast',
    array( 'campaign_id' => $campaign_id, 'type' => 'bn.broadcast' )
);
```
No `subject` / `body_html` passed. `send_now()` only reads the template row, never
`$data['subject']`. The campaign's authored copy is never used.

### Drip (`DripService::process_due_enrollments`, `DripService.php:317-328`)
```php
$sender->send_now(
    (int) $enrollment->user_id,
    'bn.drip_step',
    array( ...'subject' => $step['subject'], 'body_html' => $step['body_html'], 'type' => 'bn.drip_step' )
);
```
The step's `subject`/`body_html` ARE passed in `$data`, but `send_now()` ignores those
keys — it renders the `bn.drip_step` template row only. Every step of every sequence
would render identical copy.

### Compounding failure — the templates are not even seeded
`Installer::seed_email_templates()` seeds transactional slugs only. There is no
`bn.broadcast` and no `bn.drip_step` row. So `EmailSender::get_template()` returns null
and `send_now()` returns early (`EmailSender.php:105-108`) **before calling `wp_mail()`**.
Result today: broadcast and drip produce **zero emails**, yet `send_pending` still marks
recipients `sent` and the campaign `sent` (`BroadcastService.php:459-477`), and drip
still advances/completes enrollments (`DripService.php:330-348`). Silent total send
failure that reports success.

This is precisely the broadcast/drip bug class: a sender that ignores the per-message
subject/body and routes everything through a single static (here, missing) template.

---

## Contract violations

| # | Violation | Severity | Evidence |
|---|---|---|---|
| 1 | Broadcast sender passes no subject/body; renders a single static type instead of the campaign's authored copy | critical | `buddynext-pro/includes/Email/BroadcastService.php:450-457` |
| 2 | `bn.broadcast` template not seeded → `send_now` returns before `wp_mail()`; broadcast sends nothing but marks recipients `sent` | critical | `EmailSender.php:105-108`; `BroadcastService.php:459-477`; `Installer.php:69-210` |
| 3 | Drip sender passes per-step subject/body in `$data` but `send_now` ignores those keys; renders the `bn.drip_step` template only | high | `DripService.php:317-328`; `EmailSender.php:119-120` |
| 4 | `bn.drip_step` template not seeded → drip sends nothing but still advances/completes enrollments | high | `DripService.php:317-348`; `Installer.php:69-210` |
| 5 | Push `build_snippet` switches on legacy bare slugs (`follower`,`reaction`,`comment`,`mention`) not the `bn.*` types the create path emits; only `bn.mention`/`bn.reaction`/`bn.comment`/`bn.follower` happen to match, the rest fall to generic "You have a new notification." | medium | `PushDispatcher.php:159-199` vs catalogue slugs in `NOTIFICATION-MESSAGES.md` |

Violations 1-4 share one root: `EmailSender::send_now()` has no path to accept a
caller-supplied subject/body. Broadcast and drip are per-message by nature and cannot be
expressed as a single fixed template type, so they must be able to pass their own copy.

---

## Minimal refactor plan

1. Add an optional per-call override in `EmailSender::send_now()`: when
   `$data['subject']` / `$data['body_html']` are present and non-empty, render those
   instead of (or as fallback when the template row is absent) the `bn_email_templates`
   lookup. Keep token replacement and the `buddynext_email_payload` filter intact.
2. `BroadcastService::send_pending()` — load the campaign once per batch and pass
   `subject => $campaign->subject`, `body_html => $campaign->body_html` into `send_now()`.
3. `DripService::process_due_enrollments()` — already passes `subject`/`body_html`; once
   step 1 lands this begins working. Verify the step copy renders.
4. Either seed sentinel `bn.broadcast` / `bn.drip_step` rows OR document that these
   types are intentionally template-less and always use caller-supplied copy (step 1
   must then not early-return on a missing template when copy is supplied).
5. Align `PushDispatcher::build_snippet()` switch to the canonical `bn.*` catalogue slugs
   (or route push copy through `NotificationMessageService` for one source of truth).

Do not touch the transactional path — it conforms.
