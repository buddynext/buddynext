# Conformance — Privacy Framework

**Feature:** Privacy Framework (member-facing privacy / profile visibility)
**Repo:** free
**Spec ref:** `docs/specs/features/17-roles-permissions.md` (the `buddynext-profile/view` capability — "public (privacy model applies)") + the visibility cross-cutting contract (`docs/conformance/contract-visibility.md`)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`
**Verdict:** partial-needs-wiring
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/
**Date:** 2026-05-31

---

## Journey (entry → outcome)

A member opens **Edit Profile → Privacy**, sets who can see their profile / find them / follow /
connect / message, saves, and those choices are enforced for every other viewer. A second member
(or anonymous visitor) viewing the first member's profile, activity, directory entry, and search
result must see exactly what the privacy choices permit.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member opens Edit Profile, sees Privacy section (audience selects + toggles) | ui | wired | `templates/profile/edit.php:507-514` |
| Per-field "lock" visibility selects rendered on each field/entry | ui | wired | `templates/profile/edit.php:152-198,482-490,414-424` |
| All named inputs collected on Save | store | wired | `assets/js/profile/store.js:368-375` (`buildPayload`→`collectFlatData`), `:766-803` (`saveProfile`→`doSave`) |
| Save PUTs to `/me/profile` | rest | wired | `assets/js/profile/store.js:395-410` → `ProfileController` PUT `buddynext/v1/me/profile` |
| Audience enums (`see_email`/`dm`/`mention`) + boolean toggles persisted | rest/service | wired | `includes/Profile/ProfileController.php:572-594`, validated `:682-693` |
| Field/entry/group visibility persisted with most-restrictive clamp (tighten-only) | service | wired | `includes/Profile/ProfileService.php:396-461` (`clamp_visibility`), `:1311` |
| Profile VIEW gated by `can_view_profile` (public/followers/connections/private + block) | service | wired (gate) | `templates/profile/view.php:33-40` → `includes/SocialGraph/PrivacyService.php:176-200` |
| Field values filtered per-viewer at read (most-restrictive group/field/entry) | service/db | wired | `includes/Profile/ProfileService.php:616-644` |
| Private-account → posts only to approved followers, new follows become requests | service | wired | toggle `bn_account_private` (`edit.php:511`) → `FollowService::PRIVATE_META` (`includes/SocialGraph/FollowService.php:39,47,97`) → `PrivacyService::can_view_activity` `:221-235` |
| Directory opt-out honored by directory query | service/db | wired | toggle `edit.php:512` persisted `ProfileController.php:575` → `MemberDirectoryService.php:164-170,460` (`bn_privacy_show_in_directory='0'` NOT EXISTS) |
| Search-engine opt-out emits `noindex,nofollow` | service | wired | toggle `edit.php:513` persisted `ProfileController.php:576` → `PageRouter.php:368-378` (`wp_robots` filter on profile route) |
| **Member sets `profile_visibility` (public/followers/connections/private)** | ui | **missing** | `can_view_profile` reads `bn_privacy_profile_visibility` (`PrivacyService.php:185`) but no input/save branch ever writes it; the only privacy rows are `:507-514` |
| **Member sets `who_can_follow` (everyone/nobody)** | ui | **missing** | `can_follow` reads `bn_privacy_who_can_follow` (`PrivacyService.php:126`); no writer |
| **Member sets `who_can_connect` (everyone/followers/nobody)** | ui | **missing** | `can_connect` reads `bn_privacy_who_can_connect` (`PrivacyService.php:148`); no writer |

---

## First break

`includes/SocialGraph/PrivacyService.php:185` — the **profile-view visibility tiers**.
`can_view_profile()` reads `bn_privacy_profile_visibility` (default `public`), and the web
profile-view gate (`templates/profile/view.php:33-40`) relies on it. But **no UI control and no
save branch anywhere writes that meta key** — a repo-wide grep finds `profile_visibility`,
`who_can_follow`, and `who_can_connect` only inside `PrivacyService.php` itself. The three keys are
read-only and pinned to their defaults (`public` / `everyone` / `everyone`), so the
followers/connections/private profile tiers and the follow/connect "nobody"/"followers" gates are
unreachable from the web journey.

This is a real but **partial** break: the most common "make my account private" intent is covered
by the separate, fully-wired `bn_account_private` toggle (which gates posts/activity and turns
follows into approval requests). Field-level visibility, directory opt-out, search-engine opt-out,
the audience selects, and the block graph are all wired end-to-end. Only the structured
profile-view tiers and the follow/connect gates lack a member-facing writer.

---

## UX gaps

1. **`profile_visibility` has no member-facing control** — severity: high — confidence:
   confirmed-in-code. `PrivacyService::can_view_profile` (`PrivacyService.php:185`) and the
   profile-view gate (`view.php:33-40`) read `bn_privacy_profile_visibility`, but nothing writes
   it. A member cannot set their profile to followers/connections/private from the web UI; the gate
   always falls through to `public`. Reachable only by a REST/code client that writes the meta
   directly (so it is api-only, not dead, for app clients).

2. **`who_can_follow` has no member-facing control** — severity: medium — confidence:
   confirmed-in-code. `PrivacyService::can_follow` (`:121-129`) reads `bn_privacy_who_can_follow`;
   no writer. A member cannot set follows to "nobody" from the UI. (The private-account toggle
   covers the common "approve my followers" case via the request flow, which softens this.)

3. **`who_can_connect` has no member-facing control** — severity: medium — confidence:
   confirmed-in-code. `PrivacyService::can_connect` (`:143-160`) reads
   `bn_privacy_who_can_connect`; no writer. A member cannot restrict who may send connection
   requests from the UI.

No other gaps. Field/group/entry visibility, private-account activity gating, directory opt-out,
search-engine `noindex`, the audience selects, and block enforcement are all wired end-to-end on
both web and REST. (The directory-opt-out and noindex items flagged in an earlier revision of this
dossier are now fixed — verified at `MemberDirectoryService.php:164-170,460` and
`PageRouter.php:368-378`.)

---

## Minimal refactor plan

1. Add three rows to the existing privacy block in `templates/profile/edit.php` (`:507-514`),
   reusing the same `profile-edit-privacy-row` render path already used by the audience selects:
   - `bn_privacy_profile_visibility` — select: public / followers / connections / private,
   - `bn_privacy_who_can_follow` — select: everyone / nobody,
   - `bn_privacy_who_can_connect` — select: everyone / followers / nobody.
   Seed current values via `buddynext_service('privacy')->get_preference($user_id, …)`
   (already exists, `PrivacyService.php:89`).
2. Whitelist + constrain those three keys in `ProfileController::save` — extend the audience-key
   handling at `:572-594` and the enum-validation pattern at `:682-693` to each key's
   `PrivacyService` vocabulary, then persist with the `bn_privacy_` prefix (or call
   `buddynext_service('privacy')->set_preference()`, `PrivacyService.php:106`).
3. No new service, REST route, store action, or JS needed — `buildPayload`/`collectFlatData`
   (`store.js:368-375`) already serialise every named field, and the enforcement
   (`can_view_profile`/`can_follow`/`can_connect`) already exists. This is UI surfacing +
   persistence only.

(If product intent is that `bn_account_private` is the *only* intended privacy lever and the three
PrivacyService keys are reserved for REST/automation clients, this is intentional scoping and the
verdict softens to usable-minor-polish. Verify against product intent before building.)

---

## Notes for the live walk

- Seed `bn_privacy_profile_visibility = 'connections'` (or `followers`/`private`) directly in
  usermeta for the target member, then view `/members/varundubey/` from a *second*, unrelated
  account — the profile should show "This profile is private." Confirms the gate works; the gap is
  that a member has no UI to set this themselves.
- Seed mixed field visibility (public/followers/connections/private) and walk as owner, follower,
  connection, and stranger to confirm `ProfileService.php:616-644` filtering. That path is wired;
  this is verification, not a suspected break.
- Toggle directory-off and search-off, then re-walk `/members/` (second account) and view-source
  the profile for the `robots` meta — both should now behave correctly (previously broken, now fixed).

No code changes were made; this dossier is read-only output.
