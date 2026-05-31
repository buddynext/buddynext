# Conformance: Member Types

**Feature:** Member Types (repo: free)
**Spec ref:** `docs/specs/features/05-user-profiles.md` (Member Types is the type-definition + assignment + directory-filter sub-feature; cross-checked against `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`).
**Live-walk URL:** http://buddynext-dev.local/members
**Verdict:** usable-leave-as-is

---

## Journey

Two real journeys: (A) site visitor/member browses the directory and filters by member type; (B) admin defines types and assigns them. Both are wired end to end.

### A. Directory browse + filter by type (web)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Visit `/members` (or `/members/{slug}/`) | ui | wired | `templates/directory/members.php:50-61` reads `bn_member_type` query var + `?type=` fallback |
| Pretty URL `/members/{slug}/` → type filter | rest/db | wired | `includes/Core/PageRouter.php:923,1060,1258-1262` rewrite tag + rule + query mapping |
| Type pill row rendered | ui | wired | `templates/parts/member-directory-tabs.php` (pills with `data-wp-on--click="actions.selectMemberType"`); fed by `members.php:91-110` `get_all_with_counts()` |
| Click pill → store action | store | wired | `assets/js/members/store.js:473-499` `selectMemberType` sets `ctx.memberType`, syncs URL, calls `refresh` |
| Store fetch with `member_type` | rest | wired | `store.js:76` `qp.set('member_type', ctx.memberType)`, fetched at `store.js:344` `GET /members?...` |
| REST honors `member_type` | rest/service/db | wired | `includes/Profile/MemberDirectoryController.php:72,111,141` param; `MemberDirectoryService.php:63,227-231` EXISTS filter on `bn_member_type` usermeta |
| SSR first-paint filter | service/db | wired | `members.php:186-195` `meta_query` on `bn_member_type` usermeta |
| Type badge on member card | ui | wired | `templates/parts/member-card.php:187-191` renders `bn-md-card__type` badge; JS path `store.js:206-210` |
| Sidebar "By type" counts | ui | wired | `members.php:404-460` per-type rows w/ `PageRouter::member_type_url()` (`PageRouter.php:1534`) |

### B. Admin define + assign types

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member Types admin tab loads | ui | wired | `includes/Admin/Members.php:746` `render_member_types_tab()`; manager registered `Members.php:53` |
| Create/edit/delete type (CRUD form) | ui/service/db | wired | `includes/Admin/Members/MemberTypesManager.php:28-30` `admin_post_bn_save_member_type` / `bn_delete_member_type` → `MemberTypeService::create/update/delete` (`MemberTypeService.php:158,212,279`) |
| Assign type to a member | ui/service/db | wired | `MemberTypesManager.php:30-31` `admin_post_bn_assign_member_type` + `render_member_type_field` on `buddynext_after_edit_member_form` → `MemberTypeService::assign_type` (`MemberTypeService.php:401`) |
| Write-through to usermeta + cache | service/db | wired | `MemberTypeService.php:439-446` `update_user_meta('bn_member_type')` + cache busts |
| Tables exist | db | wired | `includes/Core/Installer.php:855,872` `bn_member_types`, `bn_member_type_assignments` |
| REST routes registered | rest | wired | `includes/REST/Router.php:84` registers `MemberTypeController`; service bound `Core/Plugin.php:660` |

---

## First break

none — journey complete. Both the directory web journey and the admin define/assign journey are fully wired UI → store/handler → service → DB.

---

## UX gaps

One non-blocking nuance, not on the core happy path:

- **Member self-assignment of a `self_select` type has no front-end UI** — severity: low — confidence: confirmed-in-code. The REST `PUT /users/{id}/member-type` endpoint explicitly supports a member self-assigning a type flagged `self_select` (`includes/MemberTypes/MemberTypeController.php:282-318`, `can_set_user_type` at `:357`). No front-end control invokes it: searching `templates/` and `assets/js/` for `set_user_type` / a `/member-type` PUT / `self_select` returns no member-facing surface. So `self_select` is **api-only** for the web journey. This is complete for an app/REST client, and assignment is always possible via wp-admin (journey B). The spec mentions the `self_select` flag but defines no front-end self-assign journey, so this is not a proven break of the locked happy path.

No other gaps. Scale path matches `SCALE-CONTRACT` (usermeta write-through read cache + object cache, no hot-path JOIN — see `MemberTypeService.php:6-17,341-388`). Visibility/permissions match `17-roles-permissions` (admin gate on all type writes; self-assign guarded by per-type `self_select` flag).

---

## Minimal refactor plan

(empty — usable-leave-as-is)

No code changes recommended. If the product later decides members should self-select a type from the front end, the only addition needed is a UI control (e.g. a select in profile settings) bound to a store action that PUTs `/users/{id}/member-type`; the entire backend path already exists and works.
