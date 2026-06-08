# Conformance: Advanced Profile Field Types (Pro)

**Feature:** Advanced Profile Field Types
**Repo:** buddynext-pro (depends on buddynext Free FieldType engine)
**Spec ref:** `docs/specs/features/member-fields-search-privacy.yaml` (contracts.field_type_engine + buddynext_field_types extensibility) and `docs/specs/features/05-user-profiles.md`. Journey: `docs/journeys/profile-fields.md`.
**Date:** 2026-05-31
**Verdict:** usable-leave-as-is (both gaps resolved)

> **Resolution.** Gap #1 (2026-06-07): `multi_select_advanced` validator now mirrors the renderer (array/JSON/comma-joined, reads `options['choices']`), so the field saves and round-trips. Gap #2 (2026-06-09): the JS hydration now exists — `AdvancedFieldRenderer::enqueue_assets()` loads a Pro bundle on profile pages that wires the **location map** (Leaflet + OpenStreetMap Nominatim geocoding → `{address,lat,lng}` to the hidden input; Leaflet via a filterable CDN so sites can self-host), **conditional show/hide** (`render_conditional` now resolves the trigger field's key + reads `options[]`), and **file** filename preview. Verified live: map geocoded "Eiffel Tower, Paris" and wrote coordinates; conditional toggled on its trigger value. `buddynext-pro/includes/Profile/AdvancedFieldRenderer.php`, `assets/js/profile-fields.js`.

---

## What was traced

Pro adds six field types (`date_extended`, `location`, `file`, `multi_select_advanced`, `number_advanced`, `conditional`) by hooking Free's FieldType engine seams. The journey: admin creates a field of a Pro type → member edits profile and sees the Pro input control → value validated + stored → profile view renders the Pro display → visibility honoured.

The header comments in all three Pro files (`AdvancedFieldTypes.php`, `AdvancedFieldRenderer.php`, `AdvancedFieldValidator.php`) describe a "missing Free render/validate seam" and claim Pro types "render as a plain text input". **Those comments are stale.** Free now exposes the engine filters and Pro registers against them. The `register()` methods hook the current engine filters (`buddynext_field_render_input`, `buddynext_field_render_display`, `buddynext_field_sanitize`, `buddynext_field_types`) in addition to the legacy `buddynext_profile_field_*` filters.

All Pro registrants are wired in bootstrap: `buddynext-pro/includes/Core/Plugin.php:185-188`.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin: Pro types appear in field-type dropdown | service | wired | `buddynext-pro/includes/Admin/AdvancedFieldsAdmin.php:82` extends `buddynext_profile_field_type_labels`, fired at `buddynext/includes/Admin/Members/ProfileFieldsManager.php:1118` |
| Admin: per-type option inputs (mime, min/max, unit, trigger, choices) | ui | wired | `AdvancedFieldsAdmin.php:108` on `buddynext_profile_field_type_options`, fired at `ProfileFieldsManager.php:1415,1515` |
| Admin: field type slug accepted on save | service | wired | `AdvancedFieldTypes.php:123` extends `buddynext_profile_field_types`; validated `ProfileFieldsManager.php:410,845` via `field_types()` (`:194`) |
| Admin: Pro option keys persist | db | wired | `ProfileFieldsManager.php:511` `sanitize_field_options()` handles all Pro keys + scalar default; merged at `:437,869` into `bn_field_options` |
| Member edit form renders Pro input control | ui→service | wired | `buddynext/templates/profile/edit.php:441,500` call `FieldType::render_input()` for every field; engine fires `buddynext_field_render_input` (`FieldType.php:278`); Pro handles it `AdvancedFieldRenderer.php:99` |
| Save: Pro value validated + sanitized | service | wired | REST/web both route through `ProfileService::save_profile`; `FieldType::sanitize()` (`ProfileService.php:360,429`) fires `buddynext_field_sanitize` (`FieldType.php:654`); Pro `AdvancedFieldValidator::sanitize_filter` `:83`; legacy `buddynext_profile_field_validate` also fired `ProfileService.php:383,438` |
| Save: store value | db | wired | `ProfileService::upsert_value` (`ProfileService.php:406`) into `bn_profile_values` |
| REST parity (app clients) | rest | wired | `ProfileController::update_profile` calls `save_profile` (`ProfileController.php:638,769`) — same Pro validation path |
| Profile view renders Pro display | ui→service | wired | `templates/profile/view.php:431,463` call `FieldType::render_display()`; engine fires `buddynext_field_render_display` (`FieldType.php:503`); Pro `render_display_filter` `AdvancedFieldRenderer.php:125` |
| `multi_select_advanced` choices render + selection round-trip | ui→db | broken | renderer reads wrong option shape + wrong value encoding — see First break |

---

## First break

None for five of six types — journey completes for `date_extended`, `location`, `file`, `number_advanced`, `conditional` (data round-trips; controls render).

Earliest break is at the **member edit render + value round-trip for `multi_select_advanced` only**: admin saves choices under `options['choices']` (`AdvancedFieldsAdmin.php:230`, `ProfileFieldsManager.php:549`), but the Pro renderer iterates `$field['options']` directly (`AdvancedFieldRenderer.php:291,297`) and reads the selected value via `json_decode()` (`:290`), while the save path stores it comma-joined (`AdvancedFieldValidator.php:119`) from an `name[]` post. Net: an admin-configured `multi_select_advanced` field renders no choices and does not persist selection correctly.

---

## UX gaps

1. **`multi_select_advanced` options/value shape mismatch (high).** Renderer reads `$field['options']` and `json_decode($value)`; admin persists choices at `options['choices']` and engine stores value comma-joined. Choices don't render, selection doesn't round-trip. Evidence: `AdvancedFieldRenderer.php:290-301` vs `AdvancedFieldsAdmin.php:230` / `ProfileFieldsManager.php:549` / `AdvancedFieldValidator.php:119`. Confidence: confirmed-in-code. Severity: high (one of six types fully unusable end-to-end).

2. **No JS hydration for location map picker / file upload / conditional show-hide (medium).** Renderers emit `.bn-location-map-placeholder`, `.bn-file-input`, and `data-trigger-*` wrappers (`AdvancedFieldRenderer.php:238,261,364`) that expect a Pro JS bundle to geocode, upload, and toggle. No bundle enqueue confirmed for these. Fields degrade to text/hidden + native file input; values still store, but the advertised picker UX is absent. Confidence: needs-live-verification. Severity: medium.

3. **Invalid advanced value silently dropped, not surfaced as 422 (low).** On `FieldType::sanitize()` returning `WP_Error`, `save_profile` does `continue` (`ProfileService.php:362-363,431-432`) — field skipped, no error bubbled. Journey edge case expects a 422 (`docs/journeys/profile-fields.md:179`). Happy path unaffected. Confidence: confirmed-in-code. Severity: low.

4. **Stale TODO/header comments claim feature is non-functional (low, doc-only).** `AdvancedFieldTypes.php:20-30`, `AdvancedFieldRenderer.php:5-39`, `AdvancedFieldValidator.php:5-31` describe a missing seam — contradicted by live engine hooks and template call sites. Misleads maintainers; no runtime impact. Confidence: confirmed-in-code. Severity: low.

---

## Minimal refactor plan

1. Fix `multi_select_advanced` in `AdvancedFieldRenderer::render_multi_select_advanced` (`AdvancedFieldRenderer.php:289-305`): read options from `$field['options']['choices']` (fallback to `$field['options']`), and parse the saved value as comma-joined (matching `AdvancedFieldValidator::sanitize_value`) rather than `json_decode`. Align `validate_multi_select_advanced` (`AdvancedFieldValidator.php:279`) and `render_display_filter` (`AdvancedFieldRenderer.php:143`) to the same comma-joined + `choices` shape.
2. Confirm/enqueue the Pro JS bundle that hydrates location/file/conditional controls, or document them as progressive-enhancement-only (live verification of enqueue state first).
3. Refresh the stale header/TODO comments in the three Pro Profile files to reflect that the engine seam exists and is hooked (doc-only).
4. (Optional, cross-repo) Decide whether silent-drop on invalid advanced value should surface a 422; if so propagate the `WP_Error` from `save_profile`. Affects all types and is a Free change — confirm intent first.

---

## Live-walk URL

http://buddynext-dev.local/members/varundubey/edit/

Walk as admin: create one field each of `date_extended`, `file`, `number_advanced` (with unit/min/max), `location`, `multi_select_advanced` (with choices), `conditional`. Then as the member open the edit URL and confirm each renders its control; save and re-open profile view to confirm display. Expect `multi_select_advanced` to fail (gap #1) and the rest to work. Seed values first — empty accounts hide rendered output.
