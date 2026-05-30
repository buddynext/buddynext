# Conformance Dossier — Spaces (Communities)

**Feature:** Spaces (Communities)
**Repo:** buddynext (free)
**Spec ref:** `docs/specs/features/03-spaces.md` (Locked) + cross-cutting `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**UX intent:** `docs/v2 Plans/v2/spaces-directory.html`, `docs/v2 Plans/v2/space-home.html`
**Live-walk URL:** http://buddynext-dev.local/spaces
**Verdict:** usable-leave-as-is

---

## Summary

The core web journey — discover spaces in the directory → join/request → land on the space home → read feed / view members / about → (owner) create a space — is wired end-to-end from UI control to Interactivity store action to REST endpoint to service to DB. Routing, templates, the `buddynext/spaces` Interactivity store, REST controller, and the member/space services are all present and bound. No usability break was proven by reading the code.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/spaces` directory route resolves to directory template | rest (router) | wired | `includes/Core/PageRouter.php:842` (returns `spaces/directory.php`); rewrite rules `:1059-1083`, rewrite tag `:902` |
| Directory lists spaces (SSR) with category/type/sort filters + secret exclusion | db | wired | `templates/spaces/directory.php:64` (`type != 'secret'`), `:104-142` (query), `:539` (filter state) |
| Reactive filter/search/sort re-query | store→rest | wired | `directory.php:456,470,486` bind `actions.setType/toggleSortPopover/setSort`; `assets/js/spaces/store.js:1053,1074,1405` → `executeSpacesFilter` → GET `/spaces` |
| Join / Request-to-join button on card | ui→store→rest→service→db | wired | `directory.php:705-722` (`actions.joinSpace`/`requestJoin`); `store.js:134,176` → POST `/spaces/{id}/join`; `SpaceController.php:706` `join_space`; `SpaceMemberService` `join`/`request_join` (`:148,:229`) |
| Create a space (CTA → modal → submit) | ui→store→rest→service→db | wired | `directory.php:377` CTA `actions.openCreate`; `partials/create-space-modal.php:171` `actions.submitCreate`; `store.js:1141,1159` → POST `/spaces`; `SpaceController.php:439` `create_space` |
| `/spaces/{slug}` resolves to space home, passes `space_id` | rest (router) | wired | `PageRouter.php:732-746` sets `$context['space_id']`; `:839` returns `spaces/home.php`; `home.php:99` reads `$space_id` |
| Space home renders hero + tabs (Feed/Members/Media/About, +Moderation for mods) | ui/db | wired | `home.php:564-595` tab list, `:638` `parts/space-hero.php`, `:832` feed panel; secret-space 404 gate `:148-155`; private feed gate `:159` |
| Member roster (sidebar + Members tab) | db | wired | `home.php:204-214` sidebar members; `:300-313` full roster; `parts/space-members-panel.php` |
| Join request approval (owner/mod) | rest→service→db | wired | `SpaceController.php:851` `approve_request` (POST `/spaces/{id}/members/{user}/approve`); `get_pending_requests` `:665`; `SpaceMemberService` `:360` |
| Roles / ban / remove / transfer ownership | rest→service→db | wired | `SpaceController.php:973` `ban_user`, `:1147` `change_member_role`, `:1173` `remove_member`, `:1233` `transfer_ownership`; bound in store `:601,647,681,725` |
| Per-space notification preference | ui→store→rest→service→db | wired | `home.php:293` SSR pref; `store.js:958` POST `/spaces/{id}/notification-pref`; `SpaceController.php:293` |
| Join → gamification / notification hooks | service (event) | wired | `SpaceMemberService.php:148,229,360,369` fire `buddynext_space_member_joined`/`_join_requested`/`_join_approved` (consumed by Notifications + GamificationBridge) |

---

## First break

none — journey complete.

The directory, join/request, create, space-home, feed, members, and moderation/management surfaces all trace from a real UI control through the store to a backing REST endpoint and service. Both the web journey (SSR templates + Interactivity store) and the app/REST journey (full controller in `SpaceController.php`) are served.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| `leaveSpace` count-decrement reads `.bn-space-card__stats span` / `.bn-space-card__privacy` (legacy selectors), but the v2 directory renders `.bn-sd-card__stats` and a `.bn-badge[data-tone]`. The leave REST call still succeeds and the button label swaps via `swapButtonState`; only the live member-count tweak and open-vs-private re-derivation may no-op until reload. Cosmetic, self-heals on navigation. | low | confirmed-in-code | `store.js:226-247` vs `directory.php:650,664` |
| `joinSpace` optimistic count bump targets the same legacy `.bn-space-card__stats span` selector; count increment may not appear until reload on the v2 card. Join itself succeeds. | low | needs-live-verification | `store.js:152-161` vs `directory.php:664` |

Both are minor optimistic-UI selector drifts on the directory card, not journey breaks — the underlying join/leave succeeds and the page reflects truth on next load. Not worth a refactor under the prime directive.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## Notes for the live walk

- Seed at least a few spaces (open + private + secret) and memberships in `bn_spaces` / `bn_space_members` before walking; empty accounts hide the built directory/roster surfaces.
- Confirm the directory and space-home enqueue the `spaces` script bundle (`PageRouter.php:645`) — the Interactivity store only binds when `assets/js/spaces/store.js` is loaded. If front-end isolation mu-plugin is active locally, verify `buddynext` is whitelisted on `/spaces` routes.
- Verify the two low-severity optimistic-count selectors live (join/leave a space from the directory and watch the member count) to decide if the cosmetic decrement is worth aligning to `.bn-sd-card__stats`.
