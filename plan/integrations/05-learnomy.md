# Integration #4 — Learnomy (Courses / LMS)

**Status:** 🟢 BUILT (2026-06-14) — activity + 2 panels + the first live notification listener.
**Tier:** Pro (application-layer, Tier-2 community layer).
**Plugin:** Learnomy free + pro (1.1.0, "Simple LMS"). Standalone — keeps its own
course pages, lesson player, student + instructor dashboards, certificates, and a full
notification/email/webhook stack. BN adds a community layer on top: profile + activity +
notifications (+ search). Never takes over Learnomy's screens.

> Mirrors Career Board (`01`) / Listora (`04`) — same shape, same rules — but richer:
> a member can be a **learner** AND an **instructor**, so there are two profile angles.

## Guiding principle — LinkedIn-minimum (locked 2026-06-14)

**Show only what has profile value, the way LinkedIn does — credentials and outcomes,
never activity churn.** LinkedIn surfaces *Licenses & certifications* and *what you create*;
it never shows "enrolled in X" or "watched lesson 3". So for Learnomy the profile shows:

- ✅ **Completed / certified courses** (the credential — verifiable, durable).
- ✅ **Courses you teach** (instructor = a credential of expertise).
- ❌ NOT enrollments, NOT in-progress courses, NOT lesson/quiz progress, NOT reviews.

Same lens trims the feed: celebrate **completion + certificate** only — the milestones a
person would actually want broadcast. Everything else is noise and is dropped.

### Tab cleanliness + role clarity (locked — BN is for everyone)

BN is a general community, not an LMS, so the Portfolio tab must never read as a course
dashboard. Rules:

- **Each panel is independently data-gated.** Certifications renders only if the member has
  ≥1 completed course; Teaching only if they're an instructor with ≥1 authored course. A
  member who is neither (the majority) sees **nothing** Learnomy — the tab stays clean.
- **No role bleed.** A student only ever gets the student certificates link; an instructor
  only ever gets the instructor link. Neither sees the other's CTA unless they truly hold
  both roles. No instructor controls leak onto a student panel or vice-versa.
- **Plain labels, one tab.** "Certifications" / "Teaching" inside the single shared Portfolio
  tab — never a new top-level tab, never LMS jargon or LMS chrome.

## Data model (manifest-first)

- **No CPTs** — 40 custom `{prefix}lrn_*` tables. Key ones: `lrn_courses`,
  `lrn_enrollments` (learner ↔ course), `lrn_progress`, `lrn_certificates`,
  `lrn_reviews`, `lrn_course_instructors` (instructor ↔ course junction).
- REST `learnomy/v1` (166 routes) incl. `/me`, `/me/notifications`,
  `/courses/{id}`, `/courses/{id}/enroll`. App data already first-class.
- **URLs:** `\Learnomy\route_url( $name, $args )` — named routes `course` (by slug),
  `lesson`, `catalog`, `student-dashboard`, `instructor-dashboard`,
  `account-instructor-profile`, `account-certificates`.
- Guard constant: **`LEARNOMY_VERSION`**. Namespace `Learnomy`.

### Lifecycle hooks (real signatures, from source)

| Hook | Signature | Use |
|---|---|---|
| `learnomy_student_enrolled` | `($enrollment_id,$user_id,$course_id,$source)` | optional "started learning" activity |
| `learnomy_course_completed` | `($enrollment_id,$user_id,$course_id)` | **activity — the headline moment** |
| `learnomy_certificate_issued` | `($certificate_id,$user_id,$course_id)` | **activity — social proof** |
| `learnomy_review_submitted` | `($review_id,$user_id,$course_id)` | optional activity (engagement) |
| `learnomy_lesson_completed` | `($user_id,$lesson_id,$course_id)` | NOT used — too granular, would spam the feed |
| `learnomy_send_notification` | `($user_id,$type,$data)` | **notification mirror — see below** |

## Notifications — ALREADY COMPLETE (no card needed) ✅

Unlike Career Board / Jetonomy / WPMediaVerse / Listora, Learnomy's notification seam
**already carries everything BN needs**. `learnomy_send_notification( $user_id, $type, $data )`
fires on every dispatch, and `$data` includes `message`, `action_url` (deep link),
`title`, `object_type`, `object_id`, `actor_id` (verified in
`includes/notifications/class-notifier.php`). So:

- **No Basecamp card.** BN consumes the hook directly.
- `SuiteNotifications::register_source( 'learnomy', 'Courses', 'graduation-cap' )`, then a
  listener on `learnomy_send_notification` mirrors `$data` into BN's central center
  (`message` + `action_url`), per `02-notification-aggregation.md`. Coexists with
  Learnomy's own student/instructor notification UI.
- **This is the first integration whose central-notification listener is buildable NOW**
  (the other three are blocked on hook cards). Good reference implementation to land first.

## Touchpoints (community layer)

| Touchpoint | What | How |
|---|---|---|
| **Activity — completion** | "completed the course {title}" → links to the course | `learnomy_course_completed` → `Feed\IntegrationActivity::publish`. Read title from `lrn_courses`, URL via `route_url('course',['slug'=>…])`. |
| **Activity — certificate** | "earned a certificate in {title}" → links to the public certificate | `learnomy_certificate_issued` → `IntegrationActivity::publish` (cert verify / `account-certificates` URL). |
| ~~Activity — enrollment~~ | **DROPPED** — enrollment is not a milestone worth broadcasting (LinkedIn principle). | — |
| **Profile — Certifications** | Portfolio panel: the member's **completed** courses only, each shown as a credential (links to its certificate when issued, else the course) | `buddynext_member_suite_panels` → `learnomy_certifications` panel, reads `lrn_enrollments WHERE status=completed` (+ `lrn_certificates`). Owner CTA → `route_url('account-certificates')` (`/account/certificates/`). **No in-progress / enrollment rows.** |
| **Profile — Teaching** | Portfolio panel: courses the member authored (only if they're an instructor) | `lrn_course_instructors` → `learnomy_teaching` panel. Owner CTA → `route_url('instructor-dashboard')` (`/instructor/`). |
| **Notifications** | Learnomy notifications into BN's central center | `learnomy_send_notification` → `SuiteNotifications` (✅ no card, see above). |
| ~~Search~~ | **DEFERRED** — courses are instructor content, not member-owned; no clean per-member value. | — |
| **App coverage** | certifications + teaching panels + activity + notifications | Portfolio REST (`GET buddynext-pro/v1/members/{id}/portfolio`, generic) + feed REST + notifications REST. ✅ no extra endpoint. |

## Organisation (consistent with Career Board / Listora)

- `includes/Bridges/LearnomyBridge.php` (Pro) — event wiring: `course_completed` +
  `certificate_issued` (+ optional `student_enrolled`) → `Feed\IntegrationActivity`;
  guard `LEARNOMY_VERSION`. Activity removed on `learnomy_student_unenrolled` /
  `learnomy_enrollment_expired` if it was an enrollment card.
- `includes/Integrations/Learnomy/LearnomySocial.php` (Pro) — the two Portfolio panels
  (Learning + Teaching). Mirrors `CareerBoardSocial` / `ListoraSocial`.
- Notifications → the shared `SuiteNotifications` listener on `learnomy_send_notification`
  (buildable now — no card).

## Locked decisions (2026-06-14 — LinkedIn-minimum)
1. **Enrollment activity: DROPPED.** Only `course_completed` + `certificate_issued` post
   activity. No "started learning", not even behind a filter.
2. **Profile = credentials only.** ONE `learnomy_certifications` panel of **completed**
   courses (each a credential, linking to its certificate when issued). No in-progress, no
   enrollment list, no separate certificates panel. Plus the `learnomy_teaching` panel for
   instructors. Two panels max, both credential-style.
3. **Search: deferred.** Courses are instructor content; revisit only if there's a clear
   member-search need.
4. **Teaching panel gate:** only when the member has ≥1 authored course
   (`lrn_course_instructors`) — confirm the junction's column names at build time.

## Build status (2026-06-14) — DONE, verified against learnomy 1.1.1
1. ✅ `BuddyNextPro\Bridges\LearnomyBridge` — `course_completed` → activity ("completed a
   course"); `certificate_issued` → activity ("earned a certificate", links to the public
   `certificate-verify` URL by uuid); `learnomy_send_notification` → `SuiteNotifications`
   mirror. Reads `lrn_courses`/`lrn_certificates` via `$wpdb`; URLs via `\Learnomy\route_url`.
   Guard `LEARNOMY_VERSION`. No enrolment handler (dropped).
2. ✅ `BuddyNextPro\Integrations\Learnomy\LearnomySocial` — `learnomy_certifications`
   (completed courses only; certificate-verify link + "Certified"/"Completed" meta) +
   `learnomy_teaching` (published authored courses). Owner CTAs → `account-certificates` /
   `instructor-dashboard`. Both independently data-gated. Wired in `Core\Plugin`.
3. ✅ Notifications: the FIRST live `SuiteNotifications` listener (no card — Learnomy's seam
   was already complete). Source `learnomy` → `suite.learnomy`, `can_email=false`.
4. ✅ Verified: 11 unit tests (`LearnomyBridgeTest` + `LearnomySocialTest`) green; full
   integration/suite slice 42/42. Live on `buddynext-dev.local` with Learnomy's own
   `wp learnomy seed-model-site` (Aurora Academy: 60 courses / 100 students / 10
   instructors). Student profile (Kai Sato) shows Certifications only (mixed
   Completed/Certified, real thumbnails); instructor profile (Olusegun Adeyemi) shows
   Teaching only — clean role separation, no panel for non-LMS members. Desktop + 390px,
   0 console errors. App: panels via generic Portfolio REST; notifications via notifications REST.

> Pre-existing, unrelated suite failures (Reactions / WhiteLabel / Members / AI / Realtime)
> were confirmed to fail with this work stashed out — not introduced here.
