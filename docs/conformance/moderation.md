# Conformance — Moderation (free)

**Spec ref:** `docs/specs/features/09-moderation.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/moderation-report.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/moderation

---

## Scope walked

Core happy path: a member reports content → admin reviews it in the moderation
queue → admin dismisses / escalates / resolves / removes → admin issues a
strike → admin suspends the user (who can then no longer post) → user appeals →
admin approves the appeal and the suspension is lifted.

Both surfaces of the platform were checked:

- **Web journey** — member report modals + the `/moderation` queue page, driven
  by the WP Interactivity API store, reaching the REST layer.
- **App / REST journey** — the same `buddynext/v1` routes consumed directly.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member opens report modal | ui | wired | `templates/partials/report-modal.php:32`, `templates/parts/member-report-modal.php:97` |
| 2 | Modal submit → store action | store | wired | `assets/js/profile/store.js:1322` (`submitReport` → `fetch('buddynext/v1/reports')`) |
| 3 | `POST /reports` creates report, dedupes, fires `buddynext_report_created` | rest→service→db | wired | `includes/Moderation/ModerationController.php:618`; `includes/Moderation/ModerationService.php:54` (dedup :67, hook :121) |
| 4 | Admin opens queue at `/moderation` | ui | wired | `includes/Core/PageRouter.php:897` → `templates/moderation/queue.php:39` (ability gate `buddynext-moderation/review-queue`) |
| 5 | Queue assets (CSS + store module) enqueued | store | wired | `includes/Core/PageRouter.php:721`; `includes/Core/AssetService.php:330,376` (`@buddynext/moderation` → `moderation/store.js`) |
| 6 | Queue rows render from `bn_reports` | rest/db | wired | `templates/moderation/queue.php:106-204`; REST equiv `ModerationController.php:836` `get_queue` |
| 7 | Dismiss → `POST /reports/{id}/dismiss` | ui→store→rest→db | wired | `queue.php:645` `actions.dismiss`; `assets/js/moderation/store.js:33-51`; `ModerationController.php:663`; `ModerationService.php:185` |
| 8 | Escalate → `PUT /reports/{id}/escalate` | rest→service | api-only | `ModerationController.php:155-173`, `ModerationService.php:196`. No web button (gap G1) |
| 9 | Resolve → `PUT /reports/{id}/resolve` | rest→service | api-only | `ModerationController.php:165-173`, `ModerationService.php:207`. No web button (gap G1) |
| 10 | Remove content → `POST /reports/{id}/remove` | ui→store→rest→db | wired | `queue.php:656` `actions.removeContent`; `store.js:53-80`; `ModerationController.php:750`; `ModerationService.php:224` (`buddynext_content_removed`) |
| 11 | Issue strike → `POST /users/{id}/strikes` | ui→store→rest→db | wired | `queue.php:681` `actions.strikeUser`; `store.js:99-113`; `ModerationController.php:779`; `ModerationService.php:296` (`buddynext_strike_issued`) |
| 12 | Suspend → `POST /users/{id}/suspend` | ui→store→rest→db | wired | `queue.php:694` `actions.suspendUser`; `store.js:115-136`; `ModerationController.php:893`; `ModerationService.php:597` (`buddynext_user_suspended`) |
| 13 | Suspended user blocked from posting (403) | service | wired | `includes/Feed/PostService.php:107-113`; `includes/Comments/CommentService.php:58-62`; feed exclusion `includes/Feed/FeedService.php:82,764` |
| 14 | User submits appeal → `POST /me/appeals` or `/appeals` | rest→service→db | wired | `ModerationController.php:264,933`; `ModerationService.php:839,1284`; fires `buddynext_appeal_submitted` |
| 15 | Admin approves appeal → `PUT /appeals/{id}/approve`, suspension lifted | rest→service→db | wired | `ModerationController.php:1327`; `ModerationService.php:1347` `decide_appeal`, lifts at :1394-1396, fires `buddynext_appeal_resolved` |
| 16 | Every action written to immutable `bn_mod_log` | service | wired | `ModerationController.php:674,791,915,1339`; `includes/Moderation/ModerationLogService.php` |

---

## First break

none — journey complete. The full report → queue → strike → suspend → appeal →
lift chain is wired end to end on both the web (Interactivity store) and REST
surfaces. Suspension is actually enforced at the content-creation gate
(`PostService.php:107`), and appeal approval actually lifts the suspension
(`ModerationService.php:1394`).

---

## UX gaps (real, minor)

**G1 — Escalate / Resolve have no web button in the queue page (api-only).**
Severity low, confirmed-in-code. Spec lists Escalate and Approve/Resolve as
queue actions (`09-moderation.md:59-64`). The REST routes work
(`ModerationController.php:155-173`), but `templates/moderation/queue.php:598-701`
renders only View / Dismiss / Remove / Warn / Strike / Suspend. Web moderators
cannot escalate or explicitly resolve from the page; Dismiss + Remove cover the
common paths. App/REST clients unaffected. Not a journey break.

**G2 — Stale route comment in the queue template docblock.**
Severity low, confirmed-in-code. `templates/moderation/queue.php:11-12` says
actions call `buddynext/v1/moderation/{report_id}/{action}`; the runtime store
(`assets/js/moderation/store.js`) calls the correct `reports/{id}/...` routes.
Comment-only drift, no user impact.

**G3 — Space-panel `dismissReport` action targets a non-existent route.**
Severity low, confirmed-in-code. `assets/js/moderation/store.js:145-159`
(used by `templates/spaces/moderation.php`, NOT this journey's `/moderation`
page) PUTs to `reports/{id}` with `{action:'dismiss'}` — no such route exists.
Outside the audited happy path; the main queue uses `actions.dismiss` →
`POST /reports/{id}/dismiss`, which is correct.

---

## Minimal refactor plan

Empty. Verdict is usable-leave-as-is. The journey completes on both web and
REST. G1–G3 are low-severity secondary-action / doc items, not journey breaks.
If the team later closes G1, the minimal change is two buttons
(`actions.escalate`, `actions.resolve`) in the `queue.php:598` action cluster
plus two matching store actions in `moderation/store.js` against the already-live
`/reports/{id}/escalate` and `/reports/{id}/resolve` routes.

---

## Notes for the live walk

- Seed first: an empty queue renders the "Nothing to review" state
  (`queue.php:408-413`) — correct, not a break. Report a post before judging.
- Access gate: `/moderation` requires `buddynext-moderation/review-queue`
  (`PermissionService.php:57` → `moderator`). Plain members see the Access
  Restricted panel (`queue.php:39-50`).
- Confirm the journey-step-14 403 by posting as the suspended `member2`; gate is
  `PostService.php:107`.
