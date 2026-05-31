# Conformance: Advanced Profile Field Types (Pro)

**Feature:** Advanced Profile Field Types (repo: buddynext-pro)
**Spec ref:** `docs/specs/features/member-fields-search-privacy.yaml` (contracts.field_type_engine, contracts.field_types), `docs/specs/features/05-user-profiles.md`, journey `docs/journeys/profile-fields.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/edit/
**Verdict:** usable-minor-polish

---

## What was checked

The Pro layer adds six field-type slugs on top of Free's built-in engine:
`date_extended`, `location`, `file`, `multi_select_advanced`, `number_advanced`, `conditional`.
The locked contract is that EVERY type is wired end-to-end through ONE engine
(`BuddyNext\Profile\FieldType`) across all six layers: admin-config → input →
validate/sanitize → store → display → search. Pro must reach this engine purely
through filters/actions (native APIs, no Free edits).

The doc-block comments inside the three Pro Profile files (AdvancedFieldTypes,
AdvancedFieldRenderer, AdvancedFieldValidator) and AdvancedFieldsAdmin are STALE —
they claim the feature is "blocked on a missing Free seam" and renders as a plain
`<input type=text>`. Reading the actual code disproves this: Free now fires every
seam those files need, and Pro hooks all of them. The TODO prose is obsolete; the
wiring is live.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin sees Pro types in the field-type dropdown | ui | wired | `ProfileFieldsManager.php:1118` applies `buddynext_profile_field_type_labels`; Pro adds labels in `AdvancedFieldsAdmin.php:82-94` |
| Admin sees per-type option inputs (MIME, min/max/unit, trigger, choices) | ui | wired | Free fires `do_action('buddynext_profile_field_type_options', ...)` at `ProfileFieldsManager.php:1415` (edit) and `:1515` (add); Pro renders at `AdvancedFieldsAdmin.php:108-129` |
| Pro type slug accepted + persisted with options/searchable/visibility | service | wired | type list via `FieldType::types()` includes Pro types `AdvancedFieldTypes.php:93`; save validates `in_array($type, self::field_types())` `ProfileFieldsManager.php:410,845`; `bn_field_options` merged into stored JSON `:437,464`; `is_searchable` `:419` |
| Member edit form renders the correct Pro input control | ui | wired | `edit.php:408,467` call `FieldType::render_input` → fires `buddynext_field_render_input` `FieldType.php:278` → Pro `AdvancedFieldRenderer::render_input_filter` `AdvancedFieldRenderer.php:99-113` |
| Member saves; value validated + sanitized by type | service | broken (1/6) | `ProfileService.php:429` `FieldType::sanitize` → fires `buddynext_field_sanitize` `FieldType.php:654` → Pro `AdvancedFieldValidator::sanitize_filter` `AdvancedFieldValidator.php:83-95`. Works for 5 types; multi_select_advanced web array rejected (gap 1) |
| Per-field privacy selector present + clamped to admin default | ui | wired | selector in `edit.php:474-483` (flat) and `:414-421` (repeater); server clamp `ProfileService.php:401` |
| Profile view renders the Pro type's display output | ui | wired | `view.php:431,463` `FieldType::render_display` → fires `buddynext_field_render_display` `FieldType.php:503` → Pro `AdvancedFieldRenderer::render_display_filter` `AdvancedFieldRenderer.php:125-159` |
| Directory search honours searchable Pro types | service | wired | engine metadata `is_searchable_capable` set per type `AdvancedFieldTypes.php:96,98,100`; Free mirror/search keys off `is_searchable` + engine capability |

---

## First break

`multi_select_advanced` value round-trip on the WEB edit form. The journey
completes for the other five Pro types and for REST/app clients; only the
`multi_select_advanced` type, driven from its own rendered web input, fails to
save and fails to re-populate.

---

## UX gaps

1. **`multi_select_advanced` web save silently drops the value (high, confirmed-in-code).**
   `render_multi_select_advanced` emits `<select name="bn_field_X[]" multiple>`
   (`AdvancedFieldRenderer.php:294`), so a browser POST sends an ARRAY.
   `sanitize_filter` runs `validate(true, $type, $raw, ...)` on that raw array
   FIRST (`AdvancedFieldValidator.php:89`), and `validate_multi_select_advanced`
   rejects any non-string with "Multi-select value must be a JSON string"
   (`AdvancedFieldValidator.php:284-289`). The resulting `WP_Error` makes
   `ProfileService.php:431-433` silently `continue` — the value is discarded with
   no error shown to the member. REST clients that send a JSON string are
   unaffected, so this is a web-journey-only break.

2. **`multi_select_advanced` saved value never re-populates on re-edit (high, confirmed-in-code).**
   Storage is comma-joined (`AdvancedFieldValidator.php:119`) and display
   re-explodes by comma (`AdvancedFieldRenderer.php:144`), but `render_input`
   reads the stored value with `json_decode` (`AdvancedFieldRenderer.php:290`).
   A comma-joined string decodes to `null`, so no option shows as `selected`
   when the member reopens the form. The three representations (input array,
   stored comma-string, input-read JSON) are mutually inconsistent for this one
   type. The other five Pro types use scalar strings consistently and round-trip
   cleanly.

3. **Stale "blocked on missing Free seam" doc-blocks (low, confirmed-in-code).**
   AdvancedFieldTypes.php:19-31, AdvancedFieldRenderer.php:6-39,
   AdvancedFieldValidator.php:5-27, AdvancedFieldsAdmin.php:5-31 all describe the
   feature as non-functional pending Free filters that Free now ships
   (`buddynext_field_render_input/display`, `buddynext_field_sanitize`,
   `buddynext_profile_field_type_options`, `buddynext_profile_field_type_labels`).
   No runtime impact; misleads the next maintainer into thinking the feature is dead.

---

## Minimal refactor plan

1. In `AdvancedFieldValidator::sanitize_filter` (`AdvancedFieldValidator.php:83-95`),
   normalise an array `$raw` to its storable scalar BEFORE calling `validate()`,
   or make `validate_multi_select_advanced` accept an array (the same shape the
   rendered `[]` select submits). Keep one canonical stored shape — comma-joined
   slugs, matching the display path.
2. In `AdvancedFieldRenderer::render_multi_select_advanced`
   (`AdvancedFieldRenderer.php:289-305`), read the saved value the same way it is
   stored: explode the comma-joined string instead of `json_decode`, so saved
   selections re-appear as `selected` on re-edit. Align the three representations
   on the comma-joined form already used by Free's native `multiselect`.
3. Update the stale doc-blocks in the four Pro files to reflect that the Free
   seams now exist and the types are live (documentation only).

These are surgical fixes to one type's value handling plus a comment refresh — not
a rewrite. Five of six Pro types and the entire admin/privacy/display/search chain
are working and must be left as-is.

---

## Notes for the live walk

- Seed at least one field of each Pro type before walking; empty accounts hide
  the rendered controls.
- The `multi_select_advanced` gap is the one to reproduce: create that field with
  a couple of choices, select two in the member edit form, save, and confirm
  (a) the value persists in `bn_profile_values` and (b) reopening the edit form
  shows them selected. Both are expected to fail until the refactor lands.
- Confirm the other five types (date_extended, location, file, number_advanced,
  conditional) render their dedicated control, save, and display correctly in
  both light and dark.
