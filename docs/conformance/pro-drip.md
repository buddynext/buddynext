# Conformance: Drip / Welcome Sequences (Pro)

**Feature:** Drip / Welcome Sequences
**Repo:** buddynext-pro
**Spec ref:** docs/specs/features/06-notifications-email.md (§ "Pro Email Automation → Drip Welcome Sequences", lines 158–175)
**Journey doc:** buddynext-pro/docs/journeys/drip-welcome.md
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (+ Mailpit at http://localhost:10010/)

---

## Summary

The full admin → enroll → cron → email journey is wired end to end. A site owner can build a
multi-step sequence in a real admin UI, enable it, and newly registered users are auto-enrolled and
emailed step-by-step by an hourly cron. UI, service, REST, cron, and DB layers all exist and connect.

The one mismatch found is in the **journey doc itself**, not the code: the doc's delay-simulation SQL
edits `last_step_at`, but the cron's due-check is computed from `enrolled_at + delay_days`. The feature
works correctly; the doc's manual-test recipe for fast-forwarding delays is inaccurate. This does not
stop a real user's journey (real elapsed days, or backdating `enrolled_at`, produce correct sends).

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens "Drip Sequences" page (submenu + AdminHub tab) | ui | wired | includes/Admin/DripAdmin.php:94-104, :79-86 |
| Create sequence form (name + trigger) → admin-post handler | ui→service | wired | includes/Admin/DripAdmin.php:111-145; DripService.php:42-80 |
| Add step form (delay/subject/body/slug) → appended to steps JSON | ui→service→db | wired | includes/Admin/DripAdmin.php:186-225; DripService.php:92-107, save_steps :361-375 |
| Steps stored as JSON on bn_drip_sequences.steps | db | wired | includes/Core/Installer.php:409-419, :112 (steps col); DripSequence::from_row :96-108 |
| Reorder (Up/Down) + delete step | ui→db | wired | includes/Admin/DripAdmin.php:232-322 |
| Enable/disable toggle | ui→service→db | wired | includes/Admin/DripAdmin.php:152-179; DripService::set_enabled :384-403 |
| New user registers → on_user_register auto-enrolls | service→db | wired | DripEnrollmentService.php:57, on_user_register :68-70, enroll_by_trigger :176-194 |
| Enrollment row inserted (current_step=0, status=active) | service→db | wired | DripService::enroll_user :139-197; Installer.php:421-434 |
| Hourly cron tick (buddynextpro_drip_tick) | rest | wired | DripEnrollmentService.php:59, :90-92; activate_cron :149-153; buddynext-pro.php:30 |
| Cron computes due step, sends email, advances/completes | service | wired | DripService::process_due_enrollments :271-350 |
| Step email delivered via Free EmailSender (inline subject/body) | service | wired | DripService.php:317-328 → buddynext EmailSender::send_now :104-173 (inline path :107-114, :131-135) |
| Enrollment reaches status=completed after last step | db | wired | DripService::complete_enrollment :411-426 |
| REST surface (list/create/get/update/steps/enroll) for app/REST clients | rest | wired | includes/Email/Controllers/DripController.php:70-190; registered Plugin.php:289 |
| Unsubscribe from sequence (status=unsubscribed, row preserved) | service | wired | DripEnrollmentService::unsubscribe :124-140 |

---

## First break

none — journey complete. Every link from the admin UI through cron delivery to completion is present
and connected; the REST surface for app/REST clients is registered with manage_options gating.

---

## UX gaps

1. **Journey-doc delay simulation is inaccurate (doc bug, not code bug).**
   Severity: low. Confidence: confirmed-in-code.
   Evidence: drip-welcome.md:110-116 instruct moving `last_step_at` back to simulate a delay, but
   DripService::process_due_enrollments computes `due_at = strtotime(enrolled_at) + delay_days*DAY`
   (DripService.php:308-309) and never reads `last_step_at` for the due check. Editing `last_step_at`
   has no effect; the working fast-forward is to backdate `enrolled_at`. The feature behaves correctly
   under real elapsed time — only the manual-test recipe in the doc misleads. Fix the doc, not the code.

2. **Catch-up after cron downtime advances one step per tick.**
   Severity: low. Confidence: confirmed-in-code.
   Evidence: process_due_enrollments (DripService.php:291-349) advances at most one step per enrollment
   per invocation. After a long cron outage where several steps are simultaneously past due, steps
   resume one-per-hourly-tick rather than all firing at once. Not a journey break; acceptable for a
   welcome-drip cadence and arguably desirable (avoids burst sending). No action required.

No critical/high gaps. The "known limitations" in drip-welcome.md:209-212 (scheduled-post re-fire,
non-transactional tick) are pre-existing documented seams, not journey breaks for this flow.

---

## Minimal refactor plan

None — usable-leave-as-is. (Optional, out of scope for code: correct the delay-simulation SQL in
buddynext-pro/docs/journeys/drip-welcome.md to backdate `enrolled_at` instead of `last_step_at`, and
reconcile the DripService docblock at line 265 which implies a `last_step_at`-based due check that the
code does not perform. Both are documentation accuracy items, not feature work.)

---

## Notes for the live browser walk

- Entry: wp-admin → BuddyNext menu → Drip Sequences (slug `buddynextpro-drip-sequences`).
- Build a 3-step sequence (delay 0/3/7), enable it, register a test user, then trigger
  `do_action('buddynextpro_drip_tick')` via WP-CLI. Step 1 (delay 0) sends immediately — confirm in
  Mailpit (http://localhost:10010/).
- To fast-forward steps 2/3 without waiting real days, backdate `enrolled_at` (NOT `last_step_at`):
  `UPDATE wp_bn_drip_enrollments SET enrolled_at = DATE_SUB(NOW(), INTERVAL 4 DAY) WHERE ...` then tick.
- App/REST clients: the same operations are available under `buddynext-pro/v1/drip-sequences`
  (manage_options required), so the journey is served for both web admin and REST clients.
