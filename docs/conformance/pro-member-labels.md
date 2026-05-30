# Conformance: Member Labels (Pro)

**Feature:** Member Labels (admin-defined editorial labels — Verified / Expert / Staff — on member profiles and post bylines)
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/05-user-profiles.md` (extended-profile data; labels are a Pro extension layered on the profile-extras seam) + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/member-labels.md`
**Live-walk URL:** http://buddynext-dev.local/members
**Date:** 2026-05-31

---

## Verdict: partial-needs-wiring

The admin CRUD, the data layer, the REST surface, and the **post-byline chip** display are all wired and correct. Two journey outcomes are NOT delivered:

1. **Profile-page label display is broken** — the only consumer of the `buddynext_profile_extra_data` filter expects stat-tile shapes, so the injected `labels` array is silently dropped and never renders on the profile.
2. **Profile REST does not carry labels** — `ProfileService::get_profile()` never fires the `buddynext_profile_extra_data` filter, so the journey's Part 3 ("profile JSON includes a `labels` field") is not satisfied.

Assignment / unassignment is REST-only by design (the journey itself walks it via curl), which is fine for app/REST clients but means there is **no web UI control** for an admin to apply a label from a profile or the members directory.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens Member Labels page (submenu + AdminHub tab) | ui | wired | `buddynext-pro/includes/Admin/MemberLabelsAdmin.php:123` (`add_submenu`), `:103` (AdminHub tab); registered at boot `includes/Core/Plugin.php:181` |
| Create label (form → admin_post → service → DB) | service | wired | `MemberLabelsAdmin.php:99,144` (`admin_post_buddynextpro_add_label` → `handle_add`) → `LabelService::create_label()` `LabelService.php:56` |
| Tables exist with expected columns + UNIQUE(slug) / UNIQUE(user,label) | db | wired | `includes/Core/Installer.php:459` (bn_member_labels), `:472` (bn_member_label_assignments) |
| Edit / Delete label (delete purges assignments first) | service | wired | `MemberLabelsAdmin.php:197` (edit), `:254` (delete → `delete_by_label()` then `delete_label()`) |
| Admin "Members" count per label | service | wired | `LabelService::get_member_count()` `LabelService.php:298`; rendered `MemberLabelsAdmin.php:348` |
| REST: list/get/create/update/delete labels | rest | wired | `LabelsController.php:87` (`register_routes`), registered on `rest_api_init` `Plugin.php:259,293` |
| REST: assign label `POST /users/{id}/labels/{slug}` | rest | wired | `LabelsController.php:361`; write caps gated `:454` (`manage_options`) |
| REST: unassign `DELETE /users/{id}/labels/{slug}` | rest | wired | `LabelsController.php:410` |
| Assign a label from a web UI (profile / directory) | ui | missing | No template control, no JS, no apiFetch reaches the assign endpoint (grep of `includes/`, `templates/`, `assets/` finds none). Journey assigns via curl only. |
| Labels appear as chips on post cards | ui | wired | `ProfileLabelInjector::register()` hooks `buddynext_part_post_byline_after` `ProfileLabelInjector.php:75` → `render_chips_from_part()` `:93`; Free part fires that action with `author_id` `buddynext/templates/parts/post-byline.php:236,56` |
| Labels appear on the member profile page | ui | broken | Injector appends `$extra['labels'] = [{slug,name,color,icon}]` `ProfileLabelInjector.php:142`. The only filter consumer is `buddynext/templates/parts/profile-stats-strip.php:154`, which loops expecting `['label'=>…, 'value'=>…]` stat tiles and `continue`s past the `labels` entry (`profile-stats-strip.php:155-158`). Labels are silently dropped. |
| Profile REST response includes `labels` (journey Part 3) | rest | broken | `ProfileService::get_profile()` (`buddynext/includes/Profile/ProfileService.php:520`) never calls `apply_filters('buddynext_profile_extra_data', …)`. The filter fires in exactly one place site-wide — the stats-strip template (`profile-stats-strip.php:154`) — not in REST. |

---

## First break

**Profile-page / profile-REST label display.** The `buddynext_profile_extra_data` filter has a single Free consumer (`profile-stats-strip.php:154`) whose contract is stat tiles (`['label'=>string,'value'=>scalar]`), not label-object arrays. `ProfileLabelInjector::inject_labels()` produces the wrong shape for that consumer, and no profile template or REST path reads an `extras['labels']` key. Result: labels are stored, counted, and REST-assignable, and they render on post bylines — but they do **not** render on the member profile, and they are absent from the profile REST payload the journey asserts in Part 3.

(Note: the journey's own "Known limitations" claims "the data is correctly present in the profile API response." That claim does not hold against the current Free code — the filter is not invoked in `get_profile()`.)

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Injected `labels` never render on the member profile — filter-shape mismatch with the sole `buddynext_profile_extra_data` consumer | high | confirmed-in-code | `ProfileLabelInjector.php:142` vs `buddynext/templates/parts/profile-stats-strip.php:155-158` |
| Profile REST endpoint (`get_profile`) omits labels — filter not fired there; journey Part 3 acceptance fails | high | confirmed-in-code | `buddynext/includes/Profile/ProfileService.php:520` (no `apply_filters('buddynext_profile_extra_data')`); only call site is `profile-stats-strip.php:154` |
| No web UI to assign/unassign a label to a member — admin must use curl/REST | medium | confirmed-in-code | No assign control in `includes/`, `templates/`, `assets/`; journey steps 6 & 12 use curl. App/REST clients are fully served; the web admin journey is not. |

Notes:
- Post-byline chip display is genuinely wired and correct — not a gap.
- Admin CRUD, validation (slug/color/icon), member counts, idempotent assign, delete-purges-assignments, and `manage_options` gating on writes are all present and correct.

---

## Minimal refactor plan

Reuse existing working code; do not rewrite the services or REST layer.

1. **Render labels on the profile.** Add a small profile-template seam that calls the assignment service (or reads a dedicated key) and emits `.bn-badge` chips — mirror the already-working `ProfileLabelInjector::render_chips_from_part()` (`ProfileLabelInjector.php:93`). Hook it to an existing profile part action (e.g. a `buddynext_profile_*` action already fired in `templates/profile/view.php`, such as `buddynext_profile_before` at `:204`) instead of overloading the stat-tile filter. This avoids the shape clash entirely.
2. **Decouple labels from the stat-tile filter.** Stop appending `labels` to `buddynext_profile_extra_data` (it is silently dropped there). Either register the chip renderer on a profile action (per step 1) or, if a data key on the profile is required, have Free fire a distinct filter / expose labels under `extras` in a path that templates and REST both read.
3. **Expose labels in profile REST.** Add labels to the `ProfileService::get_profile()` payload (e.g. via a dedicated `buddynext_profile_labels` filter that Pro's injector answers), so app/REST clients and the journey's Part 3 assertion are satisfied. Use the existing `LabelAssignmentService::get_user_labels()` (`LabelAssignmentService.php:150`).
4. **(Optional, medium) Add a web assign control.** A members-directory or profile-admin control bound to the existing `POST/DELETE /users/{id}/labels/{slug}` endpoints would close the web-admin journey. Lower priority — the REST surface already serves app clients and the journey treats curl as the assignment path.

---

## What is solid (leave as-is)

- `LabelService` CRUD + validation (slug/name/color/icon, uniqueness) — `LabelService.php`
- `LabelAssignmentService` assign/unassign (idempotent, fires documented actions) — `LabelAssignmentService.php`
- `LabelsController` full REST surface, `manage_options` write gating, 404s for missing user/label — `LabelsController.php`
- Admin page: table, add/edit/delete forms, nonces, caps, delete-purges-assignments — `MemberLabelsAdmin.php`
- DB schema with correct unique keys — `Installer.php:459,472`
- Post-byline chip rendering — `ProfileLabelInjector.php:75,93` ↔ `buddynext/templates/parts/post-byline.php:236`
