# Conformance — Member Types

**Feature:** Member Types (repo: free)
**Spec ref:** `docs/specs/features/05-user-profiles.md` (no dedicated Member Types spec; the profiles spec governs profiles and references member-type integration). Cross-checked against `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`.
**Verdict:** usable-minor-polish
**Live-walk URL:** http://buddynext-dev.local/members

---

## What the feature is

Admin-defined member types (Alumni, Staff, etc.) with badge color/icon, optional directory filter tab, and an optional "members can self-assign" flag. Types are assigned to users (admin-driven, or self-service when `self_select=1`), shown as badges on the profile hero and member cards, and used as a directory filter (pill row + sidebar + REST filter param).

Scale model is sound: source of truth `bn_member_type_assignments`, write-through `wp_usermeta` key `bn_member_type` for fast `WP_User_Query` / SQL `EXISTS` filtering, object-cache layer on top (`includes/MemberTypes/MemberTypeService.php:7-13, 341-388, 438-439`).

---

## Journey chain (core happy path)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens Members → Member Types tab | ui | wired | `includes/Admin/Members.php:734,745-746` mounts `render_member_types_tab()` |
| Admin creates / edits a type (name, slug, colors, icon, sort, show_in_dir, self_select) | ui→service→db | wired | form `includes/Admin/Members/MemberTypesManager.php:228-322`; `handle_save` `:82-122` → `MemberTypeService::create/update` `:158-265` (table `bn_member_types`) |
| Admin assigns a type to a member (Edit Member view) | ui→service→db | wired | dropdown `MemberTypesManager.php:426-465` hooked on `buddynext_after_edit_member_form`; `handle_assign` `:160-192` → `assign_type` `:401-451` (write-through usermeta `:439`) |
| Type surfaces as badge on profile | service→ui | wired | `templates/profile/view.php:85,365` → `templates/parts/profile-hero.php:222-227` |
| Type surfaces as badge on member card | service→ui | wired | `templates/parts/member-directory-grid.php:127,164` (type_map lookup) → `templates/parts/member-card.php:188` |
| Directory pill tabs + by-type sidebar render with counts | service→ui | wired | `templates/directory/members.php:91-110,404-460`; pills `templates/parts/member-directory-tabs.php:72-110` |
| Visitor filters directory by type (server render) | ui→db | wired | meta_query `templates/directory/members.php:187-195`; pretty URL via `get_query_var('bn_member_type')` `:58` |
| Visitor filters directory by type (reactive, no reload) | ui→store→rest→service→db | wired | pill `data-wp-on--click="actions.selectMemberType"` (tabs `:102`); store `assets/js/members/store.js:473-498` sets `memberType`+`refresh`; query `:76`; REST `member_type` param `includes/Profile/MemberDirectoryController.php:72,111,141`; SQL `EXISTS` filter `includes/Profile/MemberDirectoryService.php:227-231` |
| REST CRUD for types + assignment (app/client) | rest→service | wired | `includes/MemberTypes/MemberTypeController.php:43-158` (GET/POST/PUT/DELETE types; GET/PUT/DELETE user type) |
| Member self-assigns a type (web) | ui | missing | REST gate exists (`MemberTypeController.php:302-308`, `can_set_user_type` `:357-370`) but NO front-end control: `templates/profile/edit.php` has zero member-type markup; profile-hero badge is read-only (`:222-227`); no `set_user_type` / `self_select` reference in any template or front-end store |

---

## First break

**Member self-assign (web) — missing UI.** Every admin-driven leg of the journey is fully wired end-to-end. The only broken link is the `self_select` member-facing path: an admin can tick "Allow members to self-assign" (`MemberTypesManager.php:308-311`) and the REST endpoint `PUT /users/{id}/member-type` honors it for the current user (`MemberTypeController.php:292-318`), but there is no template control anywhere for a member to actually pick their own type. The capability is **api-only** for the web journey.

This does not break the primary (admin-assignment) journey, which is the default operating mode for member types on a community site. It only strands an optional sub-feature.

---

## UX gaps

1. **`self_select` has no web UI** — severity: medium — confidence: confirmed-in-code — evidence: REST self-assign gate at `includes/MemberTypes/MemberTypeController.php:302-308`; admin toggle at `includes/Admin/Members/MemberTypesManager.php:308-311`; but no member-facing control found in `templates/profile/edit.php`, `templates/parts/profile-hero.php`, or `assets/js/members/store.js`. App/REST clients CAN self-assign (endpoint is complete); only the web template is absent.

No other real gaps. Directory filter, badges, admin CRUD, admin assignment, scale/caching, and SVG sanitization (`MemberTypeService.php:592-649`) all check out.

---

## Minimal refactor plan

1. Add a member-type selector to `templates/profile/edit.php` (own-profile only), populated from `buddynext_service('member_types')->get_all()` filtered to `self_select=1`, with the current value from `get_user_type()`. Wire it to call the existing `PUT /users/{id}/member-type` endpoint (reuse the endpoint and `wp_rest` nonce — no new REST work). Render nothing (or the read-only badge) when no self-select types exist. This single step makes the self-select sub-feature usable on the web; the admin journey needs no changes.

---

## Notes

- Verify on a seeded account: empty test users have no assigned type, so badges/pills/counts look absent until at least one type is defined AND assigned. Walk `/members` and `/members/{slug}/` after creating a type and assigning it.
- App/REST clients already have full self-assign capability; the gap is web-template-only.
