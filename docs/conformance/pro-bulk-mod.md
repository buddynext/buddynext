# Conformance: Bulk Moderation (Pro)

**Feature:** Bulk Moderation
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/09-moderation.md` (Moderation Queue → "Bulk actions: approve all / remove all matching filter"; User Moderation → warn/suspend)
**Journey ref:** `buddynext-pro/docs/journeys/bulk-moderation.md`
**Verdict:** usable-minor-polish
**Live-walk URL (canonical):** `http://buddynext-dev.local/wp-admin/admin.php?page=buddynext-moderation&tab=bulk`
**Live-walk URL (legacy, as written in journey doc):** `http://buddynext-dev.local/wp-admin/admin.php?page=buddynextpro-bulk-mod` — renders, but see UX gap 1.

---

## Journey chain

Admin triages the pending report queue (select rows → Dismiss/Remove) and runs bulk warn/suspend on a user-ID list. Backend also exposed over REST for programmatic clients.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | "Bulk Moderation" tab registered under Moderation section | ui | wired | `buddynext-pro/includes/Admin/BulkModAdmin.php:101` (AdminHub::register_tab 'moderation','bulk') |
| 2 | Page renders pending-reports table with checkboxes + bulk-action select | ui | wired | `BulkModAdmin.php:245` (query bn_reports status='pending'), `:312-337` (table), `:315` select-all, `:327` row checkbox, `:299-303` action select |
| 3 | Bulk User Actions form (user-ID list, reason, duration, Warn/Suspend buttons) | ui | wired | `BulkModAdmin.php:366-402` |
| 4 | JS copies chosen select value into hidden `action`, toggles select-all, parses user-ID list into `bnpro_user_ids[]` | store(js) | wired | `buddynext-pro/assets/js/admin/bulk-mod.js:24-86` |
| 5 | JS enqueued on the page | store(js) | wired (canonical URL only) | `BulkModAdmin.php:118-134`; gated by `AdminHub::is_active('moderation','bulk')` which matches only `page=buddynext-moderation` (`buddynext/includes/Admin/AdminHub.php:335-353`). Does NOT fire on legacy `page=buddynextpro-bulk-mod`. |
| 6 | admin-post handlers for dismiss/remove/warn/suspend (cap + nonce) | rest(admin-post) | wired | `BulkModAdmin.php:95-98` registrations; `:160-222` handlers; `:420-425` `require_admin_and_nonce` (manage_options + check_admin_referer) |
| 7 | BulkModService batches each ID, isolates failures, returns succeeded/failed | service | wired | `buddynext-pro/includes/Moderation/BulkModService.php:39-197` |
| 8 | Delegates to Free ModerationService dismiss/resolve/warn/suspend | service | wired | Free `buddynext/includes/Moderation/ModerationService.php:185` dismiss, `:207` resolve, `:341` warn, `:1076` suspend — signatures match BulkModService calls. Service bound `'moderation'` at `buddynext/includes/Core/Plugin.php:654` |
| 9 | DB writes (bn_reports status, suspensions) | db | wired | Reads `bn_reports` at `BulkModAdmin.php:245-261`; writes occur inside Free ModerationService methods above |
| 10 | REST `POST /buddynext-pro/v1/moderation/bulk` (action/ids/reason/duration_days, manage_options) | rest | wired | `buddynext-pro/includes/Moderation/Controllers/BulkModerationController.php:65-159`; registered `buddynext/includes/... Plugin.php:290` |

---

## First break

none — journey complete. The end-to-end chain (admin UI → JS → admin-post → BulkModService → Free ModerationService → DB) is wired on the canonical AdminHub tab URL, and the REST surface is independently wired. No step stops a real admin who reaches the feature via the in-product Moderation → Bulk tab.

---

## UX gaps

1. **Legacy entry URL silently no-ops bulk actions.** (medium, confirmed-in-code)
   The journey doc documents the entry point as `admin.php?page=buddynextpro-bulk-mod` (the hidden legacy submenu, `BulkModAdmin.php:143-150`). That URL renders the full page but `enqueue_assets()` returns early because `AdminHub::is_active('moderation','bulk')` only matches `page=buddynext-moderation` (`AdminHub.php:339-344`). With no JS: the reports "Apply" leaves the hidden `action` field empty (`BulkModAdmin.php:310`) so the form posts `action=""` and routes to no handler; select-all does nothing; and the Warn/Suspend buttons never populate `bnpro_user_ids[]`, so even if submitted the service receives an empty ID list. Evidence: `BulkModAdmin.php:118-124` vs `bulk-mod.js:24-86`. Works correctly via the in-product tab URL `page=buddynext-moderation&tab=bulk`. This is documentation/enqueue drift, not a backend defect.

2. **Spec "approve" action not surfaced in bulk UI.** (low, confirmed-in-code)
   Spec queue actions are Approve / Remove / Escalate / Dismiss (`09-moderation.md:60-64`). The bulk reports select offers only Dismiss and Remove Content (`BulkModAdmin.php:301-302`); Approve and Escalate are single-item-only. Not a journey break — bulk approve/escalate are not in the locked bulk-action list ("approve all / remove all matching filter"), and the documented happy path only exercises Dismiss. Noted for completeness.

---

## Minimal refactor plan

1. Make the legacy `?page=buddynextpro-bulk-mod` URL enqueue the same JS as the tab URL: in `BulkModAdmin::enqueue_assets()` add an OR condition that also enqueues when `$_GET['page'] === self::PAGE_SLUG` (alongside the existing `AdminHub::is_active` check). One-line guard change in `buddynext-pro/includes/Admin/BulkModAdmin.php:118-124`. (Alternatively, fix the journey doc to use the canonical tab URL `page=buddynext-moderation&tab=bulk` — doc-only, zero code.)

No other changes. Backend service, handlers, REST controller, and Free delegation are correct as-is — do not modify.
