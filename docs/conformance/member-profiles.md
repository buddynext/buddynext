# Conformance — Member Profiles (Free)

**Spec ref:** `docs/specs/features/05-user-profiles.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/profile-fields.md`, `docs/v2 Plans/v2/user-profile.html`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

## Verdict: usable-leave-as-is

The core happy path — admin defines field groups/fields → member edits and fills fields through a real UI → values persist → profile renders with visibility enforced for the viewer — is fully wired across ui / store / rest / service / db. No journey-stopping break exists in the code. The refactor plan is empty.

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Built-in groups/fields seeded; admin lists them | service/db | wired | `ProfileService::get_fields()` / `get_groups()` read `bn_profile_groups`+`bn_profile_fields` — `includes/Profile/ProfileService.php:64`, `:147` |
| 2 | Admin creates a custom group | rest→service | wired | `POST /profile-groups` → `ProfileController::create_group()` → `ProfileService::create_group()` — `includes/Profile/ProfileController.php:277`, `:862`; `ProfileService.php:191` |
| 3 | Admin creates a custom field | rest→service | wired | `POST /profile-fields` → `create_field()` → `ProfileService::create_field()` — `ProfileController.php:226`, `:839`; `ProfileService.php:226` |
| 4 | Member opens Edit Profile; every admin-defined group/field renders an input via the field-type engine | ui | wired | `templates/profile/edit.php:371` (group loop), `:467` `FieldType::render_input()`; per-field privacy lock `:152`, `:482` |
| 5 | Member submits the form | store | wired | `data-wp-on--submit="actions.saveProfile"` `edit.php:324`; `actions.saveProfile`→`doSave`→`PUT buddynext/v1/me/profile` `assets/js/profile/store.js:766`, `:402` |
| 6 | Server saves flat + repeater values, denormalises searchable flats to usermeta | rest→service→db | wired | `ProfileController::update_profile()` `ProfileController.php:536`; `ProfileService::save_profile()`→`upsert_value()` `ProfileService.php:317`, `:1541`; search mirror `:1373` |
| 7 | Profile view renders all groups via the engine; hero/about-cards for known keys, generic detail cards for the rest | ui→service | wired | `templates/profile/view.php:89` `get_profile()`, generic renderer `:405`, `FieldType::render_display()` `:431`,`:463` |
| 8 | Visibility gate (public/followers/connections/private) applied per viewer relationship | service | wired | `ProfileService::get_profile()` effective-visibility resolution `ProfileService.php:617-644`; follower/connection state resolved into cache key `:545-564` |
| 9 | Profile completion score (own profile) | service→ui | wired | `get_completion_score()` `ProfileService.php:755`; rendered for owner `view.php:186` |

## First break

none — journey complete. The happy path (define field → edit via UI → save via REST → render with visibility) has a real UI control bound to a store action bound to a live REST route at every step.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Server does not surface a 422 for invalid custom-field values (e.g. non-numeric into a `number` field) or for missing `is_required` custom fields. `FieldType::sanitize()` correctly returns `WP_Error`, but `save_profile()` swallows it with `continue` — the bad value is dropped silently and the save reports success. Journey edge cases "non-numeric → 422" and "required field missing → 422" are therefore not met for custom fields. Built-in display_name + URL fields ARE validated to 422. Data integrity is preserved (no bad value stored); only the inline-error feedback is missing. | low | confirmed-in-code | `ProfileService.php:362`,`:431` (`continue` on WP_Error); `FieldType.php:703` (number WP_Error); `ProfileController.php:657` `validate_profile_payload()` only covers display_name + URL + audience enums |
| Journey doc's "known limitation" (no `POST /profile-groups` / `POST /profile-fields`) is stale — both routes now exist and are admin-gated. Doc, not code, is out of date. | low | confirmed-in-code | `ProfileController.php:226`, `:277` |
| Repeater client-side add (`buildEntryNode` in store.js) hardcodes the field sets for `work_experience`/`education` only. A custom repeater group renders its existing entries server-side and saves them, but "Add entry" produces no new row for custom repeaters (the `containerMap`/`groupConfig` maps omit them). Existing-entry edit + save works; only client-side row-adding for non-built-in repeaters is unsupported. Needs live confirmation that an admin actually creates custom repeater groups in practice. | low | needs-live-verification | `assets/js/profile/store.js:469-492` (`groupConfig` only work/edu), `:886` (`containerMap` only work/edu) |

## Minimal refactor plan

(empty — usable-leave-as-is)

## Notes for the live walk

- Seed `varundubey` with bio, location, website, a skill or two, and at least one work/education entry before walking — an empty account hides the about-cards and detail sections and can read as "missing".
- Walk both light and dark; verify the per-field privacy "lock" selector on each field and that a `followers`-scoped field disappears for an anonymous/stranger viewer and reappears once a follow relationship exists (journey steps 11–14).
- App/REST journey is fully served independently of the web UI (`GET/PUT /me/profile`, `GET /users/{id}/profile`, group/field CRUD) — REST-FRONTEND-CONTRACT satisfied.
