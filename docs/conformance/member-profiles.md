# Conformance: Member Profiles

**Feature:** Member Profiles (Free)
**Spec ref:** `docs/specs/features/05-user-profiles.md` (Locked, 2026-03-19); journey `docs/journeys/profile-fields.md`; cross-cutting `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md` (visibility)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

---

## Journey traced (entry → outcome)

The happy path a site owner expects: admin seeds field groups at activation → a
member opens Edit Profile, fills built-in + custom fields (including a repeater
entry), tightens a field's privacy, saves → the values persist → the profile
view renders the saved fields with per-viewer visibility enforced → another
member sees only what their relationship (anon / follower / connection) allows.

Every link in this chain is wired across all five layers. The member edit form
is built server-side via the single `FieldType` engine, bound to the
Interactivity API store `buddynext/profile`, which `PUT`s `buddynext/v1/me/profile`,
which routes through `ProfileService::save_profile` → `bn_profile_values`. Read
back goes `get_profile` → visibility filter → `view.php` generic field renderer.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin field groups/fields seeded + managed | service/db | wired | `includes/Profile/ProfileService.php:191` (`create_group`), `:226` (`create_field`), `:983` (`update_field`) |
| Edit form renders every group/field via the type engine | ui | wired | `templates/profile/edit.php:371` (group loop), `:408`/`:467` (`FieldType::render_input`) |
| Per-field privacy "lock" selector (member may only tighten) | ui | wired | `templates/profile/edit.php:152` (`bn_privacy_select`), `:482` |
| Form submit bound to store action | store | wired | `templates/profile/edit.php:324` (`data-wp-on--submit="actions.saveProfile"`); `assets/js/profile/store.js:766` |
| Store collects flat + repeater + `__visibility` and PUTs | store→rest | wired | `assets/js/profile/store.js:340` (`collectFlatData`), `:352` (repeater), `:402` (`PUT buddynext/v1/me/profile`) |
| REST endpoint validates + persists | rest | wired | `includes/Profile/ProfileController.php:158` (route), `:536` (`update_profile`), `:544` (422 on validation fail) |
| Service upserts values + clamps visibility + search mirror | service→db | wired | `ProfileService.php:317` (`save_profile`), `:406`/`:461` (`upsert_value`), `:1311` (`clamp_visibility`), `:1373` (`sync_search_mirror`) |
| Profile view read-back with visibility gating | service | wired | `ProfileService.php:520` (`get_profile`), `:617-643` (group/field/entry visibility, most-restrictive-wins, relationship-keyed cache) |
| View template renders all fields incl. custom types | ui | wired | `templates/profile/view.php:34` (`can_view_profile` gate), `:405-488` (generic group/field renderer via `FieldType::render_display`) |
| REST profile fetch (app/REST clients) | rest | wired | `ProfileController.php:464` (`get_profile`), `:507` (`get_own_profile`) |
| Avatar / cover upload + slug | store→rest | wired | `store.js:866` (slug), `:981` (avatar), `:1026` (cover); `ProfileController.php:124`/`141`/`175` |

---

## First break

none — journey complete. The core member-profile happy path is wired end-to-end
for both the web journey (template + Interactivity store) and the app/REST
journey (REST endpoints sharing the same service layer).

---

## UX gaps (real, minimal)

The journey is complete. The items below are spec **edge cases** the journey doc
itself lists under "Edge cases to also verify" — they do not block the happy
path, and one is a documented intentional limitation. None warrant a rewrite.

1. **Required-field rejection is not enforced server-side.** The spec/journey
   edge case expects `PUT /me/profile` to return 422 when an `is_required`
   profile field is submitted empty. `validate_profile_payload`
   (`ProfileController.php:657`) only validates `display_name`, URL fields, and
   audience enums — it does not consult `is_required` on `bn_profile_fields`. An
   empty value for a required custom field is silently skipped by
   `FieldType::sanitize` (returns `''`) rather than rejected. Severity: low —
   completion scoring still reflects the unfilled required field
   (`ProfileService.php:755`), so the member is nudged; the data model is not
   corrupted. Confidence: confirmed-in-code.

2. **Field-type value enforcement returns 200, not 422, on a bad value.** The
   edge case "save a non-numeric value for a `number` field → 422" is not met:
   when `FieldType::sanitize` returns a `WP_Error` (`FieldType.php:705`),
   `save_profile` (`ProfileService.php:362`/`431`) silently `continue`s and skips
   that one field; the request still succeeds 200. The bad value is correctly
   NOT stored, so there is no data-integrity break — but the member gets no
   inline error for that field. Severity: low. Confidence: confirmed-in-code.

3. **Group/field CREATE has no browser admin UI; REST POST exists but the walked
   journey relies on WP-CLI.** Per the journey "Known limitations", new
   group/field creation in the walked flow is done via direct DB insert.
   `ProfileController.php:226` (`create_field` route) does exist, so app/REST
   admin tooling is covered; this is a documented intentional gap for the
   browser admin, not a member-journey break. Severity: low. Confidence:
   confirmed-in-code.

---

## Minimal refactor plan

EMPTY. The feature is usable end-to-end. The gaps above are low-severity spec
edge cases (the journey doc flags them as such, and #3 as a known limitation);
none stop a real user from completing the profile journey. Do not rewrite
working code.

---

## Notes for the human browser walk

- Walk as the profile owner (varundubey) at the live URL: confirm hero, about
  cards, and any custom-group detail cards render filled values; open Edit
  Profile, change a field + tighten its privacy lock, Save, confirm the toast
  and redirect, then reload the view.
- Walk as a second member (member2) and as logged-out to confirm `followers` /
  `connections` / `private` fields drop out exactly per `get_profile`'s
  most-restrictive-wins gate. Seed a value first — empty accounts hide the
  whole feature.
- To verify edge-case #1/#2 above, mark a custom field `is_required`/`number`
  and submit an empty/non-numeric value; observe a 200 with the field simply
  unsaved (no inline error). That is the current behaviour, not a regression.
