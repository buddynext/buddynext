# Conformance — Privacy Framework

**Feature:** Privacy Framework (profile visibility layer of the Roles/Permissions/Abilities model)
**Repo:** free
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (capability `buddynext-profile/view` — "public (privacy model applies)") + cross-cutting `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/
**Verdict:** usable-leave-as-is

---

## Summary

The Privacy Framework is built and wired end to end for both the web journey and the
REST/app journey. A member sets profile-level audience prefs (who can see profile / follow /
connect) and per-field/per-entry visibility locks in the edit form; those persist to
`wp_usermeta` and `bn_profile_values.entry_visibility` via `PUT /me/profile`; and a viewer's
request is gated at two enforcement points: the server-rendered profile page
(`PrivacyService::can_view_profile`) and the field/group/entry visibility filter inside
`ProfileService::get_profile`. No journey break found.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member opens edit form, sees "Who can see my profile" audience select + who_can_follow/who_can_connect + per-field privacy "lock" selects | ui | wired | `templates/profile/edit.php:539-543`, `:152` (`bn_privacy_select`), `:208`; `templates/parts/profile-edit-privacy-row.php:102-111` |
| 2 | Member edits a value; form submit / change fires store action | ui→store | wired | `templates/profile/edit.php:357` `data-wp-on--submit="actions.saveProfile"`; `templates/parts/profile-edit-privacy-row.php:105` `data-wp-on--change`, `:135` `data-wp-on--click="actions.togglePref"` |
| 3 | Store serialises every named select/input (incl. `bn_privacy_*` + `*__visibility`) and PUTs | store→rest | wired | `assets/js/profile/store.js:766` `saveProfile`, `:342-346` `buildPayload`, `:402-405` `PUT buddynext/v1/me/profile` |
| 4 | REST validates + persists privacy gate keys + per-field visibility | rest→service | wired | `includes/Profile/ProfileController.php:576-605` (gate_keys whitelist + `update_user_meta`), `:638` `save_profile`; `__visibility` clamp `includes/Profile/ProfileService.php:451-461` |
| 5 | Prefs/visibility stored in usermeta + value rows | service→db | wired | `bn_profile_values.entry_visibility` ENUM `includes/Core/Installer.php:677`; group/field `visibility` ENUM `:646,:664` |
| 6 | Viewer hits profile URL; server gates whole-profile visibility | rest/ui | wired | `includes/Core/PageRouter.php:352,843` route to `profile/view.php`; `templates/profile/view.php:33-40` `buddynext_service('privacy')->can_view_profile()` |
| 7 | `can_view_profile` resolves block → public/followers/connections/private | service | wired | `includes/SocialGraph/PrivacyService.php:176-200`; container reg `includes/Core/Plugin.php:626-627` |
| 8 | Per-group/field/entry visibility filtered for non-owners (most-restrictive wins) | service→db | wired | `includes/Profile/ProfileService.php:617-644`; viewer relationship resolved once + cache-keyed `:545-564` |
| 9 | Search-engine opt-out emits noindex/nofollow before wp_head | service | wired | `includes/Core/PageRouter.php:370-379` |
| 10 | REST/app client gets the same viewer-scoped profile | rest | wired | `includes/Profile/ProfileController.php:464-468` (`get_current_user_id()` as viewer into same `get_profile`) |

---

## First break

none — journey complete.

---

## UX gaps

None proven in code that stop the journey. Empty test accounts will show few/no custom fields,
but that is data, not a wiring gap — the visibility engine and the UI controls are both present
and bound.

---

## Notes on scale / contract

- `ProfileService::get_profile` caches per viewer-relationship bucket (owner / follower×connection)
  so visibility resolution is one query per bucket, not per-row SQL — `includes/Profile/ProfileService.php:553-564,636-637` (SCALE-CONTRACT).
- The same service path feeds web (PageRouter → view.php) and REST (`/users/{id}/profile`,
  `/me/profile`) — single source of truth for visibility (REST-FRONTEND-CONTRACT).

---

## Minimal refactor plan

None — usable, leave as is.
