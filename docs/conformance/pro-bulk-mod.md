# Conformance: Bulk Moderation (Pro)

**Feature:** Bulk Moderation
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/09-moderation.md` (Moderation Queue → "Bulk actions: approve all / remove all matching filter"; User Moderation → warn/suspend); journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/bulk-moderation.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → `admin.php?page=buddynextpro-bulk-mod` (also surfaced via AdminHub Moderation → Bulk tab)

---

## Journey chain

Core happy path: admin opens Bulk Moderation page → sees pending `bn_reports` queue with checkboxes → selects all → picks Dismiss/Remove → Apply → reports updated, redirect notice. Plus Bulk User Actions: enter user IDs → Warn/Suspend.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin page registered under BuddyNext menu + AdminHub tab | ui | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:93-109,141-151`; instantiated `buddynext-pro/includes/Core/Plugin.php:176` |
| Pending reports queue rendered (table, checkboxes, select-all, pagination) | ui | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:244-360` (query `bn_reports WHERE status='pending'`, `#bnpro_select_all`, `bnpro_report_ids[]`) |
| Reports bulk form → admin-post.php with chosen action | ui→store | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:293-310` form; JS copies select value into hidden `action` input + select-all toggle `buddynext-pro/assets/js/admin/bulk-mod.js:24-47` |
| admin_post_bnpro_bulk_dismiss / _remove handlers (cap + nonce) | rest | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:95-96,160-187,420-425` (`manage_options` + `check_admin_referer`) |
| Bulk User Actions form → user-id parse → warn/suspend | ui→store | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:366-402`; JS splits CSV into `bnpro_user_ids[]`, sets hidden action `buddynext-pro/assets/js/admin/bulk-mod.js:50-83` |
| admin_post_bnpro_bulk_warn / _suspend handlers | rest | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:97-98,194-222` |
| BulkModService batch loop (try/catch per item, summary) | service | wired | `buddynext-pro/includes/Moderation/BulkModService.php:39-197` |
| Delegates to Free ModerationService dismiss/resolve/warn/suspend | service | wired | `buddynext-pro/includes/Moderation/BulkModService.php:84,129-137,181`; resolves via `buddynext_service('moderation')` → `buddynext/includes/Core/Plugin.php:658` |
| Free methods exist with matching signatures | service | wired | `buddynext/includes/Moderation/ModerationService.php:185 dismiss`, `:207 resolve`, `:341 warn(uid,actor,reason)`, `:1076 suspend(uid,reason,days,hide,by)` |
| bn_reports schema matches queue query + status ENUM | db | wired | `buddynext/includes/Core/Installer.php:737-754` (status ENUM `pending,dismissed,escalated,resolved`; columns reporter_id/object_type/object_id/reason/created_at) |
| Redirect with succeeded/failed summary notice | ui | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:266-280,434-445` |
| REST: POST /buddynext-pro/v1/moderation/bulk (admin-only, same service) | rest | wired | `buddynext-pro/includes/Moderation/Controllers/BulkModerationController.php:65-160`; registered `buddynext-pro/includes/Core/Plugin.php:294` |

## First break

none — journey complete. Both the web-admin journey (page + admin-post handlers) and the REST/app journey (programmatic bulk endpoint) are wired through to Free's ModerationService and the `bn_reports` table. Dismiss → status `dismissed`, Remove → status `resolved`, both valid ENUM members; the journey doc's open question on the status mapping is resolved here.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Reports bulk dropdown offers only Dismiss + Remove; spec lists Approve and Escalate as queue actions too. Bulk Approve/Escalate are not exposed on the Pro bulk page (single-item versions live in Free's own queue). | low | confirmed-in-code | `buddynext-pro/includes/Admin/BulkModAdmin.php:299-303` (only `bnpro_bulk_dismiss`/`bnpro_bulk_remove`) vs spec `buddynext/docs/specs/features/09-moderation.md:61-64`. Convenience shortfall, not a journey break. |
| Journey seed SQL inserts free-text `reason` values (e.g. "Spam content") but `bn_reports.reason` is an ENUM — manual seed rows may coerce/fail. Affects only the journey's own seed snippet, not runtime code. | low | confirmed-in-code | seed block `buddynext-pro/docs/journeys/bulk-moderation.md:20-28` vs ENUM `buddynext/includes/Core/Installer.php:742`. Flag for journey-doc accuracy only. |

## Minimal refactor plan

(none — usable-leave-as-is. The locked spec calls for bulk approve/remove "all matching filter"; Pro delivers bulk dismiss/remove on the report queue plus bulk warn/suspend on users, all wired UI→admin-post→service→Free ModerationService→`bn_reports`, and the same service is exposed over REST for the app/programmatic path. Bulk Approve/Escalate is a future enhancement, not a usability break. The standalone page is a deliberate, documented seam pending Free's `buddynext_mod_queue_columns` hook — see `buddynext-pro/includes/Admin/BulkModAdmin.php:8-18`. Do not rewrite.)

## Live-walk note

Walk at http://buddynext-dev.local/wp-admin/ → Moderation → Bulk (or `admin.php?page=buddynextpro-bulk-mod`). Seed pending reports first using ENUM-valid `reason` values (spam/harassment/etc.), not free text. Verify: select-all checks rows; Apply with Dismiss redirects with "Dismissed: N succeeded, 0 failed" and `bn_reports.status` flips to `dismissed`; Remove flips to `resolved`. For user actions, enter IDs, Warn/Suspend, confirm Free's warning/suspension writes. Note the local isolation mu-plugin only strips front-end routes; this is a wp-admin page, so it is unaffected.
