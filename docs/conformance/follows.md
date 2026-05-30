# Conformance — Follows (Social Graph)

**Feature:** Follows (free)
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked) + `docs/journeys/social-graph.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/
**Verdict:** usable-leave-as-is

---

## Summary

The Follow happy path is fully wired end-to-end on both the web journey and the
REST/app journey. The locked spec's REST surface exists and is registered; every
journey step that the spec frames as "via REST" also has a real UI control bound
to a WP Interactivity API store that calls the same endpoint. No usability break
was found by reading the code.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member views another member's profile, sees a Follow button | ui | wired | `templates/parts/profile-hero.php:407-420` (viewer-only Follow/Following buttons, `data-wp-on--click="actions.follow"`/`actions.unfollow`) |
| Profile-hero buttons bound to a hydrated store | store | wired | `templates/profile/view.php:343-344` interactive root `buddynext/profile` with context (`userId`,`profileUserId`,`isFollowing`,`restNonce`); `assets/js/profile/store.js:1053-1090` `follow()`/`unfollow()` POST/DELETE `users/{profileUserId}/follow` |
| Profile JS module enqueued on profile route | store | wired | `includes/Core/PageRouter.php:637` `$assets->enqueue('profile')` for single-profile; `@buddynext/social-buttons` enqueued on every hub `PageRouter.php:588` |
| Standalone Follow partial (sidebar suggestions, member cards, post bylines) | ui | wired | `templates/partials/follow-button.php:68-94` (`data-wp-interactive="buddynext/follow-button"`, `data-wp-on--click="actions.toggleFollow"`); included at `templates/partials/sidebar.php:157-160`, `templates/parts/post-byline.php:221`, `templates/parts/space-members-panel.php:184` |
| Standalone Follow store toggles + calls REST | store | wired | `assets/js/social/follow-store.js:76-128` store `buddynext/follow-button`, `toggleFollow()` POST/DELETE `/users/{userId}/follow`, optimistic + rollback + toast |
| REST follow/unfollow endpoint | rest | wired | `includes/SocialGraph/FollowController.php:31-47` POST+DELETE `/users/{id}/follow`, `permission_callback => require_auth`; registered `includes/REST/Router.php:64` |
| Service writes `bn_follows`, fires hooks, enforces block guard + self-follow guard | service | wired | `includes/SocialGraph/FollowService.php:64-172` (`follow()`: self-follow `WP_Error`, block guard, `INSERT IGNORE`, fires `buddynext_user_followed`/`buddynext_follower_gained`); `unfollow()` 181-207 |
| Services bound in container | service | wired | `includes/Core/Plugin.php:618` `bind('follows')`, `:620` `bind('blocks')` |
| `bn_follows` row persisted (follower_id, following_id, status) | db | wired | `FollowService.php:100-108` INSERT; reads filter `status='approved'` (`:227-235`, `:286-294`) |
| Followers / following lists | rest | wired | `FollowController.php:49-67` public GET endpoints; `:173-201` apply bidirectional block filter `filter_blocked()` `:213-228` |
| Follow suggestions (people-you-may-know) | ui+rest+service | wired | UI `templates/partials/sidebar.php:124-172` "People to Follow" renders follow-button per suggestion; REST `FollowController.php:69-77` `/follow-suggestions` (auth); service `FollowService.php:452-467` friends-of-friends |
| Private-account follow request + approve/reject inbox | ui+store+rest+service | wired | UI `templates/profile/followers.php:122-167` per-row approve/reject bound to `buddynext/follow-requests`; store `assets/js/social/follow-store.js:136-178`; REST `FollowController.php:79-107`; service `FollowService.php:530-603` |

---

## First break

none — journey complete

---

## UX gaps

None that break the journey. Two non-blocking observations, neither stops a user:

- The profile-hero (live entry URL) drives Follow through the `buddynext/profile`
  store, while the sidebar/card/byline partials drive it through the
  `buddynext/follow-button` store. Two stores hit the same REST endpoint with the
  same optimistic+rollback+toast pattern — duplicate logic, but both are correct
  and wired. Not a usability defect; do not refactor working code for this alone.
  Confidence: confirmed-in-code. Evidence: `assets/js/profile/store.js:1053`,
  `assets/js/social/follow-store.js:85`.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## Notes for the live walk

- Walk as a logged-in member viewing `varundubey` (not your own profile — the
  hero Follow cluster only renders for non-owners, `profile-hero.php:407`).
- Confirm the Follow button toggles to "Following" with a success toast and that
  a row lands in `wp_bn_follows` (`status='approved'` for a public account).
- Seed at least one follow before checking the sidebar "People to Follow" —
  suggestions are friends-of-friends and are empty when you follow no one
  (`FollowService.php:455`), which can look like a gap on a fresh account.
- To exercise the request inbox, set `bn_account_private` user meta truthy on the
  target, then follow; the row lands `pending` and surfaces on that user's
  followers page approve/reject inbox.
