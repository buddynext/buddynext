# Conformance: Drip / Welcome Sequences (Pro)

**Feature:** Drip / Welcome Sequences
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/06-notifications-email.md` (Pro Email Automation → Drip Welcome Sequences)
**Journey ref:** `buddynext-pro/docs/journeys/drip-welcome.md`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (+ Mailpit at http://localhost:10010/)

## Verdict

**partial-needs-wiring**

The admin authoring path (create sequence → add/reorder/delete steps → enable → auto-enroll on registration) is fully wired and works end to end. The *delivery* path is broken: the cron tick advances enrollment state but the step email is never actually sent, because no `bn.drip_step` email template is seeded and `EmailSender::send_now()` hard-returns when the template row is missing. The headline outcome of the journey — "Welcome aboard!" lands in the member's inbox — does not happen.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens Drip Sequences page | ui | wired | `buddynext-pro/includes/Admin/DripAdmin.php:94` (`add_submenu`), registered at `includes/Core/Plugin.php:169` |
| Create sequence (name + trigger) | ui→service→db | wired | form `DripAdmin.php:413`, handler `DripAdmin.php:111`, `DripService::create_sequence()` `includes/Email/DripService.php:42` |
| Add / reorder / delete steps (JSON `steps` col) | ui→service→db | wired | `DripAdmin.php:186` (add), `:232` (move), `:284` (delete); `DripService::add_step()` `DripService.php:92`; column added `buddynext/includes/Core/Installer.php:112` |
| Enable sequence toggle | ui→service→db | wired | `DripAdmin.php:152`, `DripService::enable()` `DripService.php:115` |
| New user registers → auto-enroll | service→db | wired | `DripEnrollmentService::on_user_register()` `includes/Email/DripEnrollmentService.php:68`, hook bound `:57`, `register()` called `Plugin.php:166`; enroll `DripService::enroll_user()` `DripService.php:139` |
| Cron tick advances current_step | service→db | wired | `DripEnrollmentService::tick()` `DripEnrollmentService.php:90`, cron scheduled `:149`; `DripService::process_due_enrollments()` `DripService.php:271` |
| Step email actually sent to member | service | **broken** | `DripService.php:317` calls `EmailSender::send_now($uid,'bn.drip_step',...)`; `send_now` early-returns at `buddynext/includes/Notifications/EmailSender.php:106-108` because `get_template('bn.drip_step')` returns null — no such template is seeded (EmailEditor defaults list has no drip slug: `buddynext/includes/Admin/EmailEditor.php:94-242`) |
| Enrollment reaches status=completed | service→db | wired | `complete_enrollment()` `DripService.php:411` (state advances even though no mail leaves) |
| REST surface (app/admin client) | rest | api-only | `DripController` all routes `manage_options`-gated `includes/Email/Controllers/DripController.php:70`, registered `Plugin.php:285`. Same send break applies to REST-driven enroll. |

## First break

`DripService::process_due_enrollments()` → `EmailSender::send_now($user_id, 'bn.drip_step', $data)` at `buddynext-pro/includes/Email/DripService.php:317`. `send_now()` looks up the `bn.drip_step` template (`buddynext/includes/Notifications/EmailSender.php:105` → `get_template()` `:245`), gets null (no seed exists anywhere in Free or Pro), and returns before `wp_mail()` at `EmailSender.php:106-108`. The enrollment counter still advances and the row reaches `completed`, so DB state looks correct while zero emails are delivered — a silent failure.

## UX gaps

1. **No `bn.drip_step` email template seeded — drip emails never send.** Severity: critical. Confidence: confirmed-in-code. The only references to `bn.drip_step` are the two emitting lines in `DripService.php:319` and `:326`; EmailEditor's default catalog (`buddynext/includes/Admin/EmailEditor.php:94-242`) contains no drip slug, and neither Free's nor Pro's Installer inserts one. `send_now` returns at `EmailSender.php:106-108`.

2. **Per-step subject/body authored in the admin UI are not used as the email subject/body.** Severity: high. Confidence: confirmed-in-code. `send_now` renders the DB template's own `subject`/`body_html` (`EmailSender.php:119-120`), not the step's. The step's `subject`/`body_html` are only exposed as `{{subject}}`/`{{body_html}}` replacement tokens (`EmailSender.php:187-191`). So a seeded template must explicitly use those tokens or every step in a sequence sends identical static content. The admin "Subject"/"Body (HTML)" fields (`DripAdmin.php:528-534`) are otherwise inert.

3. **Day-delay simulation in the journey has no effect; delays are measured from `enrolled_at`, not `last_step_at`.** Severity: medium. Confidence: confirmed-in-code. `process_due_enrollments()` computes `due_at = enrolled_at + delay_days` (`DripService.php:308-309`) and never reads `last_step_at`. The journey (steps 14, 16) moves `last_step_at` backwards to release steps 2/3 — that column is written on advance (`DripService.php:341`) but never read, so the documented manual-test procedure cannot release later steps. Steps still fire eventually once wall-clock passes `enrolled_at + delay_days`, so this is a test-harness/journey-doc mismatch rather than a member-facing outage, but it blocks the documented verification walk.

Note (not a gap): the journey's "already enrolled → skip" edge case (drip-welcome.md:159) contradicts the locked spec, which states "re-enroll resets the clock" (06-notifications-email.md:173). `enroll_user()` resets progress (`DripService.php:156-176`), matching the spec. Spec wins; no change needed.

## Minimal refactor plan

1. Seed a `bn.drip_step` template into `bn_email_templates` (enabled by default) whose `subject` is `{{subject}}` and whose `body_html` is `{{body_html}}`, so the per-step content authored in `DripAdmin` flows through `EmailSender::render()` token replacement (`EmailSender.php:187-191`). Add it to Free's EmailEditor default catalog (`buddynext/includes/Admin/EmailEditor.php` defaults array, alongside `bn.onboarding_nudge`) so it appears in the editor and is seeded like every other template. This is the single change that makes the journey's headline outcome happen.
2. Fix the delay reference so it matches the documented behavior and the spec's "each step delivers after the configured delay": in `process_due_enrollments()` (`DripService.php:308-309`) base `due_at` on `last_step_at` (the per-step clock) when set, falling back to `enrolled_at` for step 0. This makes step 2/3 timing relative to the previous step's send and makes the journey's `last_step_at` delay-simulation work as written.

No other changes. Authoring, enrollment, toggling, reorder, REST surface, and DB schema are all correct and should be left as-is.
