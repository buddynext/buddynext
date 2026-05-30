# Conformance: Member Types

**Feature:** Member Types (free)
**Spec ref:** `docs/specs/features/05-user-profiles.md` (assigned spec; Member Types has no dedicated locked spec — intent cross-referenced from `docs/specs/features/04-member-directory-search.md` and in-code docblocks)
**Live-walk URL:** http://buddynext-dev.local/members
**Verdict:** usable-leave-as-is

---

## Summary

Member Types is built and wired end-to-end for the core web journey: a site owner
defines types in the admin, assigns them to members, members carry a type badge on
profiles and directory cards, and visitors filter the member directory by type via
both reactive pills and crawlable `/members/{slug}/` URLs. The REST surface is
complete and registered. No journey-stopping break was found.

The single capability without a confirmed front-end control is **member self-select**
(`self_select` column + REST `PUT /users/{id}/member-type` self path). No spec mandates
a member-facing self-select UI, and the core journey (admin assigns → directory filters)
is fully usable without it, so this is recorded as a low-severity gap, not a break.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin creates / edits / deletes a member type | service | wired | `includes/MemberTypes/MemberTypeService.php:158` (create), `:212` (update), `:279` (delete) |
| Admin types CRUD + assign UI (admin_post handlers) | ui | wired | `includes/Admin/Members/MemberTypesManager.php:29-33` (handle_save / handle_delete / handle_assign / edit-member field) |
| Admin assigns a type to a user | service | wired | `MemberTypeService.php:401` `assign_type()`, write-through usermeta `:439` |
| REST type CRUD + user assignment routes registered | rest | wired | `includes/REST/Router.php:83`; routes in `includes/MemberTypes/MemberTypeController.php:43-158` |
| Directory loads types + per-type counts | service | wired | `templates/directory/members.php:91` `get_all_with_counts()`; `MemberTypeService.php:82` |
| Directory renders member-type pill row | ui | wired | `templates/parts/member-directory-tabs.php:97-108` (pills, `data-wp-on--click="actions.selectMemberType"`) |
| Pill click updates filter state + fetches | store | wired | `assets/js/members/store.js:473` `selectMemberType`, `:76` `buildQuery` sets `member_type` |
| Crawlable per-type URL `/members/{slug}/` | rest/router | wired | `includes/Core/PageRouter.php:906` rewrite tag, `:1041` rewrite rule, `:1243` query var; `:1517` `member_type_url()`; sidebar links `members.php:430` |
| REST directory filters by type | service | wired | `includes/Profile/MemberDirectoryController.php:111-141`; `includes/Profile/MemberDirectoryService.php:217-221` (usermeta EXISTS filter) |
| Server-render directory filter by type | service | wired | `templates/directory/members.php:187-195` (`meta_query` on `bn_member_type`) |
| Type badge on member cards + profile | ui | wired | `templates/parts/member-card.php:103`; `templates/profile/view.php:85,365`; card badge `assets/js/members/store.js:206-210` |
| Tables installed | db | wired | `includes/Core/Installer.php:853` `bn_member_types`, `:870` `bn_member_type_assignments` |
| Member self-selects own type via front-end UI | ui | missing | No control in `templates/profile/edit.php` (grep: none) or any front-end template; REST self path exists at `MemberTypeController.php:357` `can_set_user_type` but nothing calls it from JS |

---

## First break

none — journey complete. The core happy path (admin defines + assigns a type →
member shows the badge → visitor filters the directory by type) is fully wired across
ui / store / rest / service / db. The only missing link (member self-select UI) is
outside the core journey and unmandated by the assigned spec.

---

## UX gaps

1. **No front-end member self-select control** — `self_select` (DB column,
   REST `PUT /users/{id}/member-type` self path, admin checkbox) has no UI for a member
   to pick their own type. A site owner who enables self_select on a type gets no
   member-facing control; only admins can assign.
   Severity: low. Confidence: confirmed-in-code
   (`includes/MemberTypes/MemberTypeController.php:301-308`, `can_set_user_type` at `:357`;
   no caller in `assets/js/`, no field in `templates/profile/edit.php`).
   Note: fully usable today for app/REST clients, which can call the self path directly;
   gap is web-journey only.

---

## Minimal refactor plan

None for the core journey — usable-leave-as-is.

(Optional, only if a member-facing self-select journey becomes a locked requirement:
add a type selector to `templates/profile/edit.php` that lists types where
`self_select=1` and posts to the existing `PUT /users/{id}/member-type` route via the
existing `buddynext/members` store. Reuses existing REST + service; no new backend.)
