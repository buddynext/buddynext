# Conformance: Drip / Welcome Sequences (Pro)

**Feature:** Drip / Welcome Sequences
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/06-notifications-email.md` (Pro Email Automation → Drip Welcome Sequences, lines 159–175) + journey `buddynext-pro/docs/journeys/drip-welcome.md`
**Verdict:** usable-minor-polish

The feature is built and fully wired end-to-end. A site owner can create a sequence, add ordered steps, enable it, auto-enroll registrants, and have the cron tick send step emails and complete the enrollment. The single defect is a **journey-doc vs code mismatch in the delay-simulation mechanism** (Parts 3–5), not a code break. The code works on its own internally-consistent model and its unit tests pass; the human-walk instructions are written against a different (non-existent) gating field.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin page reachable (`admin.php?page=buddynextpro-drip-sequences` + Growth tab) | ui | wired | `includes/Admin/DripAdmin.php:94-104` (submenu), `:79-86` (AdminHub Growth tab); base render `buddynext/includes/Admin/AdminPageBase.php:58-67` |
| Create sequence form (name + trigger) | ui→service | wired | form `DripAdmin.php:413-437`; handler `:111-145`; `DripService::create_sequence` `includes/Email/DripService.php:42-80` |
| Add ordered steps (delay/subject/body/slug) | ui→service→db | wired | form `DripAdmin.php:517-544`; handler `:186-225`; `DripService::add_step`/`save_steps` `DripService.php:92-107, 361-375` (JSON `steps` column) |
| Steps persisted as JSON | db | wired | `steps LONGTEXT` `buddynext-pro/includes/Core/Installer.php:409-419` + idempotent alter `:98-113` |
| Enable/disable toggle | ui→service→db | wired | toggle form `DripAdmin.php:381-389`; handler `:152-179`; `set_enabled` `DripService.php:384-403` |
| Step reorder (up/down) + delete | ui→db | wired | `DripAdmin.php:232-322` (direct `wpdb->update` of `steps` JSON) |
| Auto-enroll on `user_register` | service | wired | `DripEnrollmentService::register/on_user_register/enroll_by_trigger` `includes/Email/DripEnrollmentService.php:56-69, 176-194`; bootstrapped `includes/Core/Plugin.php:163-167` |
| Disabled sequence skipped at enroll | service | wired | `enroll_by_trigger` filters `enabled = 1` `DripEnrollmentService.php:182-185`; covered by `tests/Email/DripServiceTest.php:317-341` |
| Duplicate-enroll resets clock (UNIQUE `sequence_user`) | service→db | wired | `enroll_user` re-enroll branch `DripService.php:156-176`; `UNIQUE KEY sequence_user` `Installer.php:431` |
| Cron tick sends step email + advances `current_step` | service | wired | cron hook `DripEnrollmentService.php:59`, activation `buddynext-pro.php:30`; `process_due_enrollments` `DripService.php:271-350`; `EmailSender::send_now` inline-content path `buddynext/includes/Notifications/EmailSender.php:104-163` |
| Enrollment reaches `completed` after last step | service→db | wired | `complete_enrollment` `DripService.php:411-426`; test `DripServiceTest.php:419-468` |
| Manual enroll + full admin REST surface | rest | wired (admin-only) | `includes/Email/Controllers/DripController.php:70-190`; `manage_options` gate `:326-332`; registered `Plugin.php:289` |
| **Delay simulation per journey doc (move `last_step_at` back)** | broken | broken | journey `drip-welcome.md:110-116, 128-134` edits `last_step_at`; gating uses `enrolled_at + delay_days*DAY` and ignores `last_step_at` `DripService.php:308-309` |

## First break

`buddynext-pro/docs/journeys/drip-welcome.md:110-116` — the Part 4/5 delay-simulation step instructs the walker to move `last_step_at` backward, but `DripService::process_due_enrollments()` computes due-time from `enrolled_at + delay_days * DAY_IN_SECONDS` (`DripService.php:308-309`) and never reads `last_step_at` for gating. Following the doc verbatim, Steps 2 and 3 never fire (their `due_at` stays in the future relative to the freshly-set `enrolled_at`), so a human walker wrongly concludes the cron is dead.

The feature itself is NOT broken. Its model is "each step's `delay_days` is an absolute offset from `enrolled_at`", which is internally consistent and verified by `tests/Email/DripServiceTest.php:348-414` (the tests simulate by moving **`enrolled_at`**, not `last_step_at`). The `last_step_at` column is written on advance (`DripService.php:341`) but is currently informational only.

## UX gaps

- **Journey doc Parts 3–5 unwalkable as written** — severity: medium — confidence: confirmed-in-code — `drip-welcome.md:110-134` simulates delays via `last_step_at`; gating reads `enrolled_at` (`DripService.php:308-309`). The documented manual walk produces "no email sent" at Step 2 and the walker mis-files a false break. Fix the doc (move `enrolled_at`) or change the service to gate on `last_step_at`.
- **Cron model diverges from spec ("Action Scheduler job enqueued at enroll time")** — severity: low — confidence: confirmed-in-code — spec `06-notifications-email.md:170-174` describes per-step Action Scheduler jobs; implementation uses one hourly WP-cron poller (`DripEnrollmentService.php:33, 90-92`). Functionally equivalent for the journey outcome. Not a usability break.
- **`cancel_condition` steps from spec not implemented** — severity: low — confidence: confirmed-in-code — spec `:171, 225` and the default sequence (`:166-167`, day-14 "cancel if posted", day-30 milestone) describe condition-cancelled steps; the step shape is `{delay_days, template_slug, subject, body_html}` only (`DripService.php:12, 434-445`). Manual sequences still complete fully; conditional auto-cancel is absent. Does not block the locked journey happy-path.
- **Tick is not transactional** — severity: low — confidence: confirmed-in-code — already self-documented in `drip-welcome.md:212`; a mid-send crash can resend a step. Out of scope for this verdict.

## Minimal refactor plan

1. Reconcile the journey doc with the implemented gating model (`buddynext-pro/docs/journeys/drip-welcome.md:108-142`): change the delay-simulation SQL from updating `last_step_at` to updating `enrolled_at` (e.g. `SET enrolled_at = DATE_SUB(NOW(), INTERVAL 8 DAY)`), and update the Step 12/14/17 expected-state notes to match the `enrolled_at`-anchored absolute-offset semantics already covered by `tests/Email/DripServiceTest.php:379-389`. This is the only change needed to make the human walk pass — doc-only, no code change.

(Optional, out of scope for usability — do NOT bundle: if the team later wants per-step relative delays, switch the due calc in `DripService.php:308-309` to anchor on `COALESCE(last_step_at, enrolled_at)`. Not required for the journey to be usable today.)

## Live-walk URL

http://buddynext-dev.local/wp-admin/ — Drip Sequences under the BuddyNext Growth tab / `admin.php?page=buddynextpro-drip-sequences`; confirm step emails in Mailpit at http://localhost:10010/.
