# Conformance Dossier — Moderation

**Feature:** Moderation (repo: free)
**Spec ref:** `docs/specs/features/09-moderation.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/moderation-report.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/moderation

---

## Summary

The core moderation journey — member reports content → admin reviews in the queue
→ admin acts (dismiss / remove / warn / strike / suspend) → suspended user is
locked out → user appeals → admin approves and suspension lifts — is wired
end-to-end across UI, store, REST, service, and DB. Every REST route in the
journey's "REST surface walked" list is registered, every `ModerationService`
method named in the journey exists and fires the documented `do_action` hooks,
and there is a real web UI for both the member report path and the admin queue.

No journey-stopping break was found. The web queue is complete enough to resolve
any report. Remaining items are minor: two doc-vs-code parameter-name drifts in
the appeal curl examples, and "Escalate"/"Approve" being REST-only (no dedicated
web button) — neither blocks the happy path.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member clicks "Report" on a post (options menu) | ui | wired | `templates/parts/post-options-menu.php:112` (`data-wp-on--click="actions.reportPost"`, gated by `can_report`) |
| `reportPost` store action posts to `/reports` with nonce | store | wired | `assets/js/feed/store.js:869-887` (`fetch(restUrl + '/reports'`, `X-WP-Nonce`, `object_type:'post'`) |
| Profile report modal → `submitReport` (alternate entry) | ui/store | wired | `templates/partials/report-modal.php:93`; `assets/js/profile/store.js` |
| `POST /reports` registered, auth-gated | rest | wired | `includes/Moderation/ModerationController.php:55-105` (`submit_report`, `require_auth`) |
| `ModerationService::report()` inserts `bn_reports`, fires hook | service/db | wired | `ModerationService.php:54`, `do_action('buddynext_report_created')` at `:121` |
| Admin opens `/moderation` queue page | ui | wired | `PageRouter.php:891-892` (`moderation` → `moderation/queue.php`); rewrite `:1173-1178` |
| Queue page gated by review-queue ability, renders pending reports | ui/db | wired | `templates/moderation/queue.php:39` (`buddynext_can(...'review-queue')`), report query `:106-126` |
| `GET /reports/queue` (REST client path) | rest | wired | `ModerationController.php:109-142` (`get_queue`, `require_queue_access`) |
| Queue inline actions → moderation store | store | wired | `queue.php:645/656/669/681/694`; `assets/js/moderation/store.js:33,66,88,104,127` |
| Dismiss / Resolve / Escalate / Remove report (REST) | rest/service | wired | routes `ModerationController.php:145,165,155,175`; `ModerationService::dismiss/resolve/escalate/remove_content` `:185-256` |
| Issue strike → `bn_user_strikes`, fires hook | rest/service/db | wired | route `:186-221`; `issue_strike()` `:296`, `do_action('buddynext_strike_issued')` `:324` |
| Suspend user → `bn_user_suspensions`, fires hook | rest/service/db | wired | route `:234-265`; `suspend_user()` `:597`, `do_action('buddynext_user_suspended')` `:645` |
| Suspended user blocked from posting (403) | service | wired | `includes/Feed/PostService.php:107-113` (`is_author_suspended` → `WP_Error` status 403) |
| Suspended/shadow-banned excluded from feed | service | wired | `includes/Feed/FeedService.php:82,612`; `BookmarkController.php:221-223` |
| User submits appeal via `/me/appeals` → `bn_appeals`, fires hook | rest/service/db | wired | route `:410-425` (`create_own_appeal`); `create_appeal()` `:1284`, `do_action('buddynext_appeal_submitted')` `:1329` |
| Admin approves appeal → status approved, suspension lifted | rest/service/db | wired | route `:456-477`; `approve_appeal` `:1327` → `decide_appeal()` `:1347`, unsuspend + `do_action('buddynext_appeal_resolved')` `:1405` |
| Every action logged immutably to `bn_mod_log` | service | wired | `ModerationLogService.php`; called e.g. `ModerationController.php:674` |
| Controller registered on `rest_api_init` | rest | wired | `includes/REST/Router.php:52,83` |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Web queue has no dedicated "Escalate" or "Approve" button — spec lists both as per-item queue actions. Resolution is fully reachable via Dismiss/Remove, and `/escalate` + `/resolve` REST routes exist, so this is completeness, not a block. App/REST clients unaffected. | low | confirmed-in-code | buttons in `templates/moderation/queue.php:645-700` (no escalate/approve); routes exist `ModerationController.php:155,165` |
| Journey doc sends `suspension_id` to `POST /me/appeals`, but the route declares only a `message` arg and `create_own_appeal` ignores `suspension_id` (`create_appeal` resolves the active suspension itself). Doc drift, not a code break. | low | confirmed-in-code | `ModerationController.php:410-425,1264-1276` vs journey `:190-193` |
| Journey approve example sends `reviewer_note`; the route arg is `note`, so the note is empty if the doc shape is used verbatim. Doc drift. | low | confirmed-in-code | `ModerationController.php:469-474` (`note`) vs journey `:214` (`reviewer_note`) |
| No bulk "approve all / remove all matching filter" control in the web queue (spec "Bulk actions"). Single-item actions cover the journey; bulk is efficiency only. | low | confirmed-in-code | `templates/moderation/queue.php:641-700` (per-item only) |

---

## Minimal refactor plan

EMPTY — feature is usable as-is. The items above are low-severity polish / doc
drifts that do not stop the journey and are out of scope for a working-feature
audit. The journey doc's appeal curl examples could be corrected to match the
registered route args (`message` only on `/me/appeals`; `note` on approve), but
that is a documentation edit, not a code change.

---

## Notes for the human browser walk

- This is the **free** core plugin, not a Pro addon, so the local
  `buddynext-isolation.php` mu-plugin whitelist does not strip it from front-end
  routes — `/moderation` should render live.
- Walk `/moderation` as an admin (autologin) to see the queue; the page returns
  an "Access Restricted" panel for non-moderators (`queue.php:39-50`), the
  expected role gate.
- Member report entries: the post options menu ("Report") on any feed post, and
  the profile report modal on a member profile.
- Empty test accounts show an empty queue — seed a report first (member reports a
  post) before concluding the queue is dead.
