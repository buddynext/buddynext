# Conformance — Privacy Framework

**Feature:** Privacy Framework (member-facing privacy / profile visibility)
**Repo:** free
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (the `buddynext-profile/view` capability — "public (privacy model applies)" — and the visibility cross-cutting contract)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Verdict:** usable-minor-polish
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

---

## Journey (entry → outcome)

A member opens **Edit Profile → Privacy**, sets who can see their profile / message them / find them, saves, and those choices are enforced for every other viewer. A second member (or anonymous visitor) viewing the first member's profile, activity, directory entry, and search result must see exactly what the privacy choices permit.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member opens Edit Profile, sees Privacy section (audience selects + toggles) | ui | wired | `templates/profile/edit.php:506-514`, `templates/parts/profile-edit-privacy-row.php:95-138` |
| Per-field "lock" visibility selects rendered on each field | ui | wired | `templates/profile/edit.php:152,482-490` |
| Toggle (private account / directory / search) fires store action | store | wired | `templates/parts/profile-edit-privacy-row.php:135` → `assets/js/profile/store.js:702-735` (`togglePref`) |
| Audience selects + field-visibility selects collected on Save | store | wired | `assets/js/profile/store.js:342-347` (`collectFlatData` grabs all `select[name]`), `:766-803` (`saveProfile` → `doSave`) |
| Save PUTs to `/me/profile` | rest | wired | `assets/js/profile/store.js:716,402,434` → `ProfileController` PUT `buddynext/v1/me/profile` |
| Audience enums + boolean toggles persisted to usermeta | rest/service | wired | `includes/Profile/ProfileController.php:572-594`, validated `:682-693` |
| Field/entry/group visibility persisted with most-restrictive clamp | service | wired | `includes/Profile/ProfileService.php:396-461` (`clamp_visibility`, `upsert_value`) |
| Profile VIEW gated by `can_view_profile` (public/followers/connections/private + block) | service | wired | `templates/profile/view.php:33-40` → `includes/SocialGraph/PrivacyService.php:176-200` |
| Field values filtered per-viewer at read (most-restrictive of group/field/entry) | service/db | wired | `includes/Profile/ProfileService.php:616-644` |
| Private-account → posts only to approved followers | service | wired | toggle `bn_account_private` (`profile-edit-privacy-row`) → `FollowService::PRIVATE_META` (`includes/SocialGraph/FollowService.php:39,47,97`) → `PrivacyService::can_view_activity` (`:221-235`) consumed by `includes/Feed/FeedService.php:521` |
| who_can_follow / who_can_connect enforced | service | wired (API) | `includes/SocialGraph/PrivacyService.php:121-160` (no edit-UI control surfaces these two keys; see gaps) |
| "Show me in member directory" toggle honored by directory query | service/db | **broken** | toggle persisted `ProfileController.php:575`; directory WHERE clauses never read `bn_privacy_show_in_directory` — `includes/Profile/MemberDirectoryService.php:149-225` only exclude suspended/shadow-banned/blocked |
| "Show my profile to search engines" toggle emits `noindex` | service | **broken** | toggle persisted `ProfileController.php:576`; no per-profile robots emission keyed to `bn_privacy_search_indexable` anywhere (`grep noindex/wp_robots` finds only `Feed/SinglePostMeta.php:91` and site-wide `Admin/Settings.php`) |

---

## First break

`templates/profile/edit.php:512` — the **"Show me in the member directory"** toggle. It renders, saves, and stores `bn_privacy_show_in_directory`, but `MemberDirectoryService` (`includes/Profile/MemberDirectoryService.php:149-225`) never filters on that meta, so a member who opts out still appears in `/members/`. This is the earliest point where a stated privacy choice silently does nothing.

The core visibility journey before this (profile_visibility, field/entry visibility, private-account, block) is complete and enforced.

---

## UX gaps

1. **Directory opt-out is a no-op** — severity: high — confidence: confirmed-in-code. The "Show me in the member directory" toggle (`templates/profile/edit.php:512`, persisted `ProfileController.php:575`) is never read by the directory query (`MemberDirectoryService.php:149-225`). A member who turns it off remains listed in `/members/`. The toggle's own helper text says "Turn off to hide from /members/." — promise not kept.

2. **Search-engine opt-out (`noindex`) is a no-op** — severity: high — confidence: confirmed-in-code. The "Show my profile to search engines" toggle (`templates/profile/edit.php:513`, persisted `ProfileController.php:576`) has zero consumers. No per-profile `<meta name="robots" content="noindex">` is emitted on the profile page (robots emission exists only for single posts at `Feed/SinglePostMeta.php:91` and as a blanket site setting). Helper text promises "your profile carries noindex" — not kept.

3. **`who_can_follow` / `who_can_connect` have no member-facing control** — severity: medium — confidence: confirmed-in-code. `PrivacyService::can_follow/can_connect` (`:121-160`) read `bn_privacy_who_can_follow` / `bn_privacy_who_can_connect`, but the Privacy section only exposes `see_email` / `dm` / `mention` audiences plus the private-account / directory / search toggles (`templates/profile/edit.php:507-514`). A member cannot set "who can follow me" from the web UI; the enforcement code is reachable only via REST/code/defaults. Fine for app/REST clients that send those keys; a gap for the web journey if the product intends members to control follow/connect gating from the UI. (Note: the private-account toggle already covers the most common "gate my follows" case via the request/approve flow, so this may be intentional scoping — verify against product intent.)

No other gaps. `profile_visibility`, per-field/group/entry visibility, private-account activity gating, and block enforcement are all wired end-to-end on both web and REST.

---

## Minimal refactor plan

1. In `MemberDirectoryService` add one WHERE clause to the existing `$where_clauses` array (`includes/Profile/MemberDirectoryService.php:149-163`), mirroring the suspended/shadow-banned `NOT EXISTS` pattern, to exclude users whose `bn_privacy_show_in_directory` meta is `'0'`. Reuse the same correlated-subquery form already in place — no new abstraction. Honor the owner/admin path (a member should still see their own row in previews if that is the existing behavior).
2. Emit per-profile `noindex` keyed to `bn_privacy_search_indexable`. Reuse the existing robots-emission pattern in `Feed/SinglePostMeta.php:68-91` (hook `wp_robots` or print the meta tag on the profile-view route) so that when the toggle is `'0'` the profile carries `noindex, nofollow`. No new service.
3. (Verify intent first) If members are meant to control follow/connect gating from the web UI, add two audience/select rows to the Privacy section in `templates/profile/edit.php:507` for `bn_privacy_who_can_follow` and `bn_privacy_who_can_connect`, and whitelist those keys in `ProfileController` save (`:572-594`) and validation (`:682-693`). The enforcement (`PrivacyService::can_follow/can_connect`) already exists — this is UI + persistence only. Skip if the private-account model is the intended UX.

---

## Notes for the live walk

- `/members/varundubey/` viewed as that member shows everything; the gap (#1) is only observable from a *second* account, or anonymously, after the target member toggles directory-off — then re-walk `/members/` and confirm whether the target still appears (it will, until the fix).
- For gap #2, view-source the profile page after toggling search-off and check for a `robots` meta tag (none will be present).
- Empty test accounts hide field-visibility behavior — seed profile field values with mixed visibility (public/followers/connections/private) and walk as owner, follower, connection, and stranger to confirm the `ProfileService.php:616-644` filtering. That part is wired; this is verification, not a suspected break.
