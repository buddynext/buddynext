# Conformance Dossier — Spaces (Communities)

**Feature:** Spaces (Communities)
**Repo:** buddynext (free)
**Spec ref:** `docs/specs/features/03-spaces.md` (Locked, 2026-03-19) + cross-cutting `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**UX intent:** `docs/v2 Plans/v2/spaces-directory.html`, `docs/v2 Plans/v2/space-home.html`
**Live-walk URL:** http://buddynext-dev.local/spaces
**Verdict:** partial-needs-wiring

---

## Summary

Most of the Spaces journey is wired UI → store → REST → service → db: directory listing,
reactive filter/sort/search, create-space, join an OPEN space, leave, space-home with
feed/members/media tabs, settings (general/permissions/transfer/delete with name gate),
member role/kick/ban, and per-space notification prefs.

Two real breaks remain on the **private-space approval journey** for the web client:

1. **Moderator Approve/Decline of a pending join request is a dead no-op (critical).**
2. **"Request to join" silently reverts though the request did succeed (high).**

The REST contract is complete and correct, so app/REST clients can complete the full
private-space flow. These are web-UI Interactivity-wiring defects, not service/db gaps.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Browse `/spaces` directory | ui | wired | `templates/spaces/directory.php:590-728` |
| Directory list query (secret excluded) | db | wired | `includes/Spaces/SpaceService.php:418-480`; `directory.php:124-142` |
| Reactive filter / sort / search | store→rest | wired | `directory.php:447-491`; `assets/js/spaces/store.js:1227-1317`; `SpaceController.php:363-431` |
| Create a space (modal → POST) | store→rest | wired | `partials/create-space-modal.php:166-173`; `store.js:1342-1404`; `SpaceController.php:439-512` |
| Join an OPEN space | store→rest | wired | `directory.php:704-712`; `store.js:254-292`; `SpaceController.php:770-778` |
| Leave / cancel | store→rest | wired | `directory.php:683-702`; `store.js:390-471`; `SpaceController.php:787-804` |
| Space home + tabs | ui | wired | `templates/spaces/home.php:598-700`; `parts/space-hero.php:147-241` |
| Settings (general/perms/transfer/delete) | store→rest | wired | `store.js:908-1104`; `SpaceController.php:262-353,584-617,1233-1285` |
| Member manage (role/kick/ban) | store→rest | wired | `store.js:784-890`; `SpaceController.php:973-1225` |
| Request to join a PRIVATE space | store | broken | `directory.php:714-722`/`space-hero.php:240-241` bind `actions.requestJoin`; `store.js:313` checks `data.pending`; `SpaceController.php:767` returns `{requested:true}` |
| Moderator approves the request | store | broken | `templates/spaces/moderation.php:246,566,575` bind `actions.approveJoinRequest`/`declineJoinRequest` under namespace `buddynext/spaces`; actions absent from `assets/js/spaces/store.js` |
| Approve → active membership (REST/db) | rest/service | wired | `SpaceController.php:851-883`; correct & live for app clients |

---

## First break

`actions.requestJoin` response-key mismatch (`assets/js/spaces/store.js:313` expects
`data.pending`; server returns `{requested:true}` at `includes/Spaces/SpaceController.php:767`).
The moderator-approval break (`templates/spaces/moderation.php:566`) is the second, harder stop.

---

## UX gaps

### 1. Moderator Approve / Decline join request is a dead no-op (CRITICAL, confirmed-in-code)
`templates/spaces/moderation.php:246` declares the interactive root as `buddynext/spaces`,
and lines 566 / 575 bind `actions.approveJoinRequest` / `actions.declineJoinRequest`. Those
actions do not exist in `assets/js/spaces/store.js`. They exist only in
`assets/js/moderation/store.js:192-214` (namespace `buddynext/moderation`), which PageRouter
does not enqueue for the spaces hub — `includes/Core/PageRouter.php:661-684` enqueues only
`spaces` + `feed`. Even that implementation reads `ctx.userId` from `getContext()` (the rows
pass `data-user-id`/`data-space-id` attributes, not context state) and uses `PUT` while the
route is `POST` (`SpaceController.php:147`). Net: a private space's owner/moderator cannot
admit requesters from the web UI, so the private-space journey cannot complete. The REST
endpoint is correct and works for app clients.
**Evidence:** `templates/spaces/moderation.php:246,566,575`; `assets/js/spaces/store.js` (no such actions); `assets/js/moderation/store.js:192-214`; `includes/Core/PageRouter.php:661-684`

### 2. "Request to join" silently reverts despite succeeding (HIGH, confirmed-in-code)
`actions.requestJoin` (`assets/js/spaces/store.js:298-324`) only swaps the button to
"Requested" when `res.ok && data.pending`. The server returns `{ requested: true }`
(`SpaceController.php:767`), so the success branch is skipped and the button reverts to
"Request to join". The request is created server-side, so a confused user re-clicks. Affects
the directory card CTA (`directory.php:714-722`) and the space-home hero/gate
(`parts/space-hero.php:240-241`, `home.php:693`).
**Evidence:** `assets/js/spaces/store.js:313`; `includes/Spaces/SpaceController.php:767`

---

## Minimal refactor plan

1. Add `approveJoinRequest` and `declineJoinRequest` actions to `assets/js/spaces/store.js`
   (the namespace `moderation.php` already declares). Read `data-user-id`/`data-space-id`
   from the clicked button, call the existing routes `POST /spaces/{id}/members/{user}/approve`
   and `POST /spaces/{id}/members/{user}/decline` (`SpaceController.php:143-161`), then remove
   the row and decrement the pending badge. Do not depend on the separate `buddynext/moderation`
   store or on `ctx.userId`.
2. In `assets/js/spaces/store.js:313`, accept the locked server shape:
   `res.ok && ( data.requested || data.pending )`. Adjust the client, not the REST contract.
3. Re-walk the private-space flow live (request → moderator approve), seeding a pending
   request first, before closing.
