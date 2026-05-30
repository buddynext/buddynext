# Conformance: Advanced Profile Field Types (Pro)

**Repo:** buddynext-pro (depends on buddynext Free FieldType engine)
**Spec ref:** `docs/specs/features/member-fields-search-privacy.yaml` (workstreams F/E + contracts.field_type_engine, member_field_privacy_input); `docs/specs/features/05-user-profiles.md`; journey `docs/journeys/profile-fields.md`.
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/edit/

## Verdict: partial-needs-wiring

The member-facing half of the journey (render advanced control → validate → store → display) is fully wired through the Free FieldType engine. The **admin field-type configuration save** is broken: Pro's per-type option inputs (`bn_field_options[*]`) are emitted into Free's field-builder form but no save handler on either side reads them, so type config (min/max/unit, allowed MIME, conditional trigger) is silently discarded on save.

This is NOT the "no-op until Free ships a seam" state described in the stale class docblocks. Free already ships every needed seam; the only true break is a `$_POST` key-name mismatch in the admin save path.

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Pro registers advanced types into engine + allowed list | service | wired | `buddynext-pro/includes/Profile/AdvancedFieldTypes.php:70-110`; consumed at `buddynext/includes/Profile/FieldType.php:156` |
| 2 | Pro register() calls fire at bootstrap | service | wired | `buddynext-pro/includes/Core/Plugin.php:184-187` |
| 3 | Admin type dropdown shows Pro labels | ui | wired | filter `buddynext_profile_field_type_labels` fired `buddynext/includes/Admin/Members/ProfileFieldsManager.php:965`; Pro adds labels `AdvancedFieldsAdmin.php:82-94` |
| 4 | Admin sees Pro per-type option inputs | ui | wired | action `buddynext_profile_field_type_options` fired add/edit panels `ProfileFieldsManager.php:1262,1362`; Pro renders `AdvancedFieldsAdmin.php:108-254` |
| 5 | Admin SAVE persists Pro option config | service/db | **broken** | save reads only `$_POST['options']` (choice) / `date_display`, else `$parsed_opts=null` — `ProfileFieldsManager.php:421-430` (add), `713-722` (edit). `bn_field_options` is read nowhere in Free (grep: zero hits in `includes/`) |
| 6 | Admin creates Pro-type field (type slug) | db | wired | type validated against `field_types()` incl. Pro `ProfileFieldsManager.php:410,705`; row created `446-458` |
| 7 | Member edit form renders advanced control | ui→service | wired | `templates/profile/edit.php:408,467` → `FieldType::render_input` → filter `buddynext_field_render_input` `FieldType.php:278` → Pro `AdvancedFieldRenderer.php:99-113` |
| 8 | Member save validates + sanitizes value | service | wired | `ProfileService.php:360,429` → `FieldType::sanitize` → filter `buddynext_field_sanitize` `FieldType.php:654` → Pro `AdvancedFieldValidator.php:83-95` |
| 9 | Profile view renders advanced display | ui→service | wired | `templates/profile/view.php:431,463` → `FieldType::render_display` → filter `buddynext_field_render_display` `FieldType.php:503` → Pro `AdvancedFieldRenderer.php:125-159` |

## First break

Step 5 — admin field-config save. Free's `add_profile_field` / `edit_profile_field` route options through three branches (`choice_types` → `$_POST['options']`, `DATE_TYPES` → `date_display`, else `null`). Pro's non-choice types (`file`, `number_advanced`, `conditional`, `date_extended`, `location`) fall into the `else null` branch, so every `bn_field_options[...]` input Pro renders is dropped. `multi_select_advanced` (is_choice=true) is partially saved via Free's core `options` textarea but renders a confusing duplicate Pro `choices_raw` textarea that is also dropped.

## UX gaps

1. **Admin cannot configure Pro field-type options (config silently lost on save).** severity: high. confidence: confirmed-in-code. evidence: inputs `buddynext-pro/includes/Admin/AdvancedFieldsAdmin.php:148-217` use `name="bn_field_options[...]"`; save handlers `buddynext/includes/Admin/Members/ProfileFieldsManager.php:421-430,713-722` never read that key (`$parsed_opts=null` for these types). Downstream renderer/validator read `$field['options'][...]` (`AdvancedFieldRenderer.php:318-323`, `AdvancedFieldValidator.php:344-346`), so min/max enforcement, unit display, MIME filter, and conditional trigger never take effect.
2. **`multi_select_advanced` shows two competing option editors.** severity: medium. confidence: confirmed-in-code. evidence: Free core `options` textarea (`ProfileFieldsManager.php:1240,1343`) renders for any `choice_types()` member, and Pro also renders `bn_field_options[choices_raw]` (`AdvancedFieldsAdmin.php:248`). Only the core one saves; the Pro one is dead.
3. **Stale "blocked / namespace anchor" docblocks contradict working code.** severity: low. confidence: confirmed-in-code. evidence: `AdvancedFieldsAdmin.php:5-32`, `AdvancedFieldRenderer.php:5-39`, `AdvancedFieldValidator.php:5-28` claim the Free seams are missing, but the seams exist and the register() methods wire real hooks. Misleading for maintainers; not a runtime break.

Member/REST journey usability: the app/REST and member web paths for filling, validating, storing, and displaying advanced field values are fully functional once a field exists — the gap is admin-only and config-only.

## Minimal refactor plan

1. In `buddynext/includes/Admin/Members/ProfileFieldsManager.php`, in both `add_profile_field` (after line 430) and `edit_profile_field` (after line 722): when `$_POST['bn_field_options']` is present, sanitize it (per-key) and merge into `$parsed_opts` (array merge, not overwrite, so choice `options` + Pro keys coexist). Keep the existing `choice_types`/`DATE_TYPES` branches. This is the single change that unblocks the journey — reuses the existing `options` JSON column and Pro's existing render/validate code.
2. In `buddynext-pro/includes/Admin/AdvancedFieldsAdmin.php::render_multi_select_options`, drop the duplicate `choices_raw` textarea (gap 2) — let `multi_select_advanced` use Free's core `options` textarea, OR remove `is_choice=true` from its engine meta in `AdvancedFieldTypes.php:98` so only the Pro editor shows. Pick one source of truth.
3. Refresh the stale "blocked/missing seam" docblocks in the three Pro files to describe the now-wired filter hooks (gap 3, doc-only).
