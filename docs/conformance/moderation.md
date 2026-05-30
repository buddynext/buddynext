# Conformance — Moderation (Free)

**Spec ref:** `docs/specs/features/09-moderation.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/moderation-report.md`
**Live-walk URL:** http://buddynext-dev.local/moderation
**Verdict:** partial-needs-wiring

---

## Summary

The core moderation happy path — **member reports content → report lands in `bn_reports` → moderator opens the queue at `/moderation` → acts (dismiss / remove / warn / strike / suspend)** — is fully wired across UI, store, REST, service, and DB. Member-side report entry points exist on posts, comments, and profiles. The queue template binds every action button to the `buddynext/moderation` Interactivity store, which calls real, registered REST routes.

Two journey segments are **broken at the DB/service layer** (not UI), and one suspension enforcement gate is **missing**:

1. **Suspended users are not blocked from posting.** `is_suspended()` exists and is used for read-side hiding (feed, search, directory, profile) but no write-side gate exists on `PostService::create()`. Journey Step 14 expects 403; the API returns 201.
2. **Appeal submission via `/me/appeals` fails.** `create_appeal()` inserts without `suspension_id`, which is `NOT NULL` in `bn_appeals`. The insert errors → route returns 400, not the expected 201. (The sibling route `POST /appeals` → `submit_appeal()` is correct.)
3. **Appeal approval via `/appeals/{id}/approve` fails and does not lift the suspension.** `decide_appeal()` writes to columns `admin_note` / `resolved_by` that do not exist in `bn_appeals` → SQL error → 400. Even if it succeeded, neither it, `resolve_appeal()`, nor the `on_appeal_resolved` listener calls `unsuspend_user()`, so `lifted_at` is never set (Step 18 fails).

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member sees Report control on post/comment/profile | ui | wired | `templates/parts/post-options-menu.php:112`, `templates/parts/member-card.php:362`, `templates/parts/profile-hero.php:519` |
| Report dialog → POST /reports | store | wired | `assets/js/feed/store.js:869` (`reportPost`), `assets/js/profile/store.js:1322` (`submitReport`); dialog `assets/js/shell/dialog.js:281` |
| POST /reports route | rest | wired | `includes/Moderation/ModerationController.php:55` (CREATABLE, require_auth) |
| create_report inserts + fires hook | service | wired | `includes/Moderation/ModerationService.php:81` (insert), `:121` (`buddynext_report_created`) |
| Report row persisted | db | wired | `bn_reports` insert at `ModerationService.php:81` |
| Moderator opens /moderation queue | ui | wired | route `includes/Core/PageRouter.php:874` → `templates/moderation/queue.php`; access gate `queue.php:39` |
| Queue lists pending reports | rest/db | wired | `GET /reports/queue` `ModerationController.php:111` (require_queue_access:admin or space mod); template SQL `queue.php:106` |
| Dismiss action | store→rest | wired | store `assets/js/moderation/store.js:33` → `POST /reports/{id}/dismiss` `ModerationController.php:147` |
| Remove content action | store→rest | wired | store `store.js:53` → `POST /reports/{id}/remove` `ModerationController.php:177` |
| Warn user action | store→rest | wired | store `store.js:82` → `POST /users/{id}/warn` `ModerationController.php:321`; service `ModerationService.php:1268` fires `buddynext_user_warned` |
| Strike user action | store→rest | wired | store `store.js:99` → `POST /users/{id}/strikes` `ModerationController.php:203`; service `ModerationService.php:304` insert + `:324` `buddynext_strike_issued` |
| Suspend user action | store→rest | wired | store `store.js:115` → `POST /users/{id}/suspend` `ModerationController.php:238`; service `ModerationService.php:613` insert + `:645` `buddynext_user_suspended` |
| Suspended user blocked from posting | service | **missing** | no `is_suspended` check in `PostService::create()` (`includes/Feed/PostService.php:70`); SafeguardService has no suspension rule (`includes/Moderation/SafeguardService.php:40`); ModerationListener registers no `buddynext_safeguard_check` filter (`ModerationListener.php:27-37`) |
| Suspended user submits appeal (/me/appeals) | service/db | **broken** | `create_own_appeal` → `create_appeal()` inserts without `suspension_id` (`ModerationService.php` create_appeal block), but `bn_appeals.suspension_id` is `NOT NULL` (`includes/Core/Installer.php:824`) → insert fails → 400 |
| Admin approves appeal (/appeals/{id}/approve) | service/db | **broken** | `decide_appeal()` writes `admin_note`/`resolved_by` (not in schema) — schema has `reviewed_by`/`reviewer_note`/`reviewed_at` (`Installer.php:828-831`) → SQL error → 400 |
| Appeal approval lifts suspension | service | **missing** | `decide_appeal`, `resolve_appeal`, and `on_appeal_resolved` (`ModerationListener.php` on_appeal_resolved) never call `unsuspend_user()` (`ModerationService.php:659`); `lifted_at` stays NULL |

---

## First break

**Suspended-user posting gate is missing** (Journey Step 14): `includes/Feed/PostService.php:70` `create()` runs `SafeguardService::check()` (`includes/Moderation/SafeguardService.php:40`) which checks banned words, blocked domains, rate limit, and new-member gate — but never suspension. No code path returns 403 for a suspended author, so a suspended member can still post. This is the earliest deviation from the locked spec ("Suspend — locked out... cannot post/comment/react", spec lines 75-78). The report→queue→act core is complete; the breaks cluster in suspension enforcement and the appeal sub-flow.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Suspended users can still create posts (no write-side gate) | critical | confirmed-in-code | `includes/Feed/PostService.php:70`; `includes/Moderation/SafeguardService.php:40-76`; no `is_suspended` call in posting path |
| `POST /me/appeals` insert fails — `create_appeal()` omits required `suspension_id` | high | confirmed-in-code | `create_appeal()` insert in `includes/Moderation/ModerationService.php` vs `bn_appeals.suspension_id NOT NULL` `includes/Core/Installer.php:824` |
| `POST /appeals/{id}/approve` writes to non-existent columns (`admin_note`, `resolved_by`) → 400 | high | confirmed-in-code | `decide_appeal()` `ModerationService.php` update vs schema `Installer.php:828-831` |
| Appeal approval never lifts the suspension (`lifted_at` stays NULL) | high | confirmed-in-code | `decide_appeal`/`resolve_appeal`/`on_appeal_resolved` never call `unsuspend_user()` (`ModerationService.php:659`) |
| Duplicate appeal-resolution methods diverge (`decide_appeal` vs `resolve_appeal`) on column names and routes | medium | confirmed-in-code | `/appeals/{id}/approve`→`decide_appeal`; `/appeals/{id}/resolve`→`resolve_appeal` (`ModerationController.php:295,1332`) |
| No member-facing appeal-submission UI; "appeals" admin tab actually renders Space join requests, not suspension appeals | medium | confirmed-in-code | `templates/community-admin.php:583-642`; no appeal form in `templates/` or `assets/js/` |

App/REST note: the report→queue→dismiss/remove/warn/strike/suspend flow works for both web and REST clients. The appeal sub-flow is broken for **both** web and REST.

---

## Minimal refactor plan

1. **Block suspended users from posting.** Add a suspension check to the post-create path. Cleanest reuse: hook `is_suspended()` into the existing `buddynext_safeguard_check` filter from `ModerationListener` (register `add_filter( 'buddynext_safeguard_check', ... )`), returning a `WP_Error` with `status => 403` when `buddynext_service('moderation')->is_suspended( $user_id )`. Apply the same gate to comment/react create paths. Uses existing `is_suspended()` (`ModerationService.php:764`) and existing filter (`SafeguardService.php:75`).
2. **Fix `/me/appeals` insert.** Make `create_own_appeal` read `suspension_id` from the request and pass it through; route it to `submit_appeal()` (which already validates ownership and inserts `suspension_id` correctly, `ModerationService.php` submit_appeal) instead of `create_appeal()`. Then delete the redundant `create_appeal()`.
3. **Fix `/appeals/{id}/approve` + `/deny`.** Point `approve_appeal`/`deny_appeal` at `resolve_appeal()` (which writes the real columns `reviewed_by`/`reviewer_note`/`reviewed_at`, `ModerationService.php:894`). Delete the divergent `decide_appeal()` so only one resolution method remains.
4. **Lift suspension on appeal approval.** In `resolve_appeal()`, when `decision === 'approved'`, look up the appeal's `suspension_id` and call `unsuspend_user( $user_id, $actor_id )` (`ModerationService.php:659`) before firing `buddynext_appeal_resolved`. This sets `lifted_at` and emits `buddynext_user_unsuspended`.
5. **(Optional, medium) Surface real suspension appeals in the admin "appeals" tab** of `templates/community-admin.php` (currently shows join requests), or add a member appeal form linked from the suspension email so the spec's appeal URL has a UI target.

---

## Notes

- The journey doc's "Known limitations" (pending hooks for `buddynext_user_warned`, `buddynext_user_shadow_banned`, `buddynext_appeal_submitted`, `buddynext_appeal_resolved`) are **outdated** — all four fire in `ModerationService` today.
- The journey's strike-list/reverse, shadow-ban, content-warning, and report-create routes are all registered and consistent with the manifest.
- Verdict is **partial-needs-wiring** (not broken-journey) because the dominant moderator workflow is complete; the failures are confined to suspension-posting enforcement and the appeal sub-flow.
