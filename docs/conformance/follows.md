# Conformance: Follows

**Feature:** Follows (Social Graph — follow / unfollow)
**Repo:** free
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked, 2026-03-19, "Follow (asymmetric)") + journey `docs/journeys/social-graph.md` Part 1
**Cross-cutting checked:** `docs/specs/REST-FRONTEND-CONTRACT.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

---

## Journey chain

Core happy path: a logged-in member visits another member's profile, clicks Follow, the
relationship persists and the follower count updates; clicking again unfollows. Plus the
public follower/following lists and the follow-request inbox for private accounts.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Profile page renders Follow / Following buttons for a non-owner viewer | ui | wired | `templates/parts/profile-hero.php:407-420` (`data-wp-on--click="actions.follow"` / `actions.unfollow`, SSR `hidden` driven by `$bn_pf_is_following`) |
| Buttons sit inside the profile Interactivity root with full context | store | wired | `templates/profile/view.php:343-345` (`data-wp-interactive="buddynext/profile"`); context `profileUserId`/`isFollowing`/`restNonce`/`followerCount` at `view.php:317-329` |
| `actions.follow` / `actions.unfollow` POST/DELETE the follow endpoint with nonce + optimistic rollback | store | wired | `assets/js/profile/store.js:1053-1090` (fetch `buddynext/v1/users/{id}/follow`, `X-WP-Nonce: ctx.restNonce`) |
| Profile store module enqueued on the members route | store | wired | `includes/Core/PageRouter.php:660` `$assets->enqueue('profile')`; module map `includes/Core/AssetService.php:316`; enqueue `AssetService.php:373-377` |
| `POST/DELETE /buddynext/v1/users/{id}/follow` registered, auth-gated | rest | wired | `includes/SocialGraph/FollowController.php:32-47` (routes), `:312-322` (`require_auth`); router `includes/REST/Router.php:65` |
| Controller blocks self/blocked, returns `{following,pending}` | rest | wired | `FollowController.php:116-161` (block guard `:120`, follow `:128-129`, response `:140-146`) |
| `FollowService::follow/unfollow` writes `bn_follows`, fires actions, busts cache | service | wired | `includes/SocialGraph/FollowService.php:64-183` (INSERT IGNORE, `buddynext_user_followed` `:143`, first-follow `:178`); unfollow `:192-218` |
| Row persisted in `bn_follows` (status approved/pending) | db | wired | `FollowService.php:108-119` INSERT; `:196-203` DELETE |
| Public followers / following lists, block-filtered | rest | wired | `FollowController.php:173-201` + `filter_blocked` `:213-228`; service `followers()`/`following()` `FollowService.php:286-346` |
| Private-account follow-request inbox (list / approve / reject) | rest+store | wired | routes `FollowController.php:79-107`; store `assets/js/social/follow-store.js:136-178`; service `FollowService.php:489-614` |
| `is_following` seeded into the profile render | service | wired | `includes/Profile/ProfileService.php:546` (`->is_following($viewer_id,$profile_user_id)`) |

## First break

none — journey complete. Every link from the profile Follow button down to the
`bn_follows` write is present, registered, and enqueued on the live members route. The
service binding (`includes/Core/Plugin.php:622` `$container->bind('follows', ...)`) and
REST registration (`includes/REST/Router.php:65`) are both confirmed.

## UX gaps

No code-confirmed gap stops the journey. Non-blocking observations:

- **Two follow-button surfaces coexist (intentional).** The profile-hero uses the
  `buddynext/profile` store (`profile-hero.php:407-420`); standalone member cards / sidebar
  use the separate `buddynext/follow-button` store (`templates/partials/follow-button.php`,
  `assets/js/social/follow-store.js:76-128`). Both reach the same REST endpoint and both are
  correctly wired — different DOM contexts, not a break. Severity low, confidence
  confirmed-in-code.
- **Profile-hero has no explicit "Requested" (pending) state.** The hero buttons only
  toggle Follow/Following (`profile-hero.php:407-420`), whereas the standalone partial
  surfaces a pending state (`follow-store.js:42-72`). For the locked spec's *asymmetric
  public follow* this is correct; private-account behaviour on the hero would need a live
  walk on a private test account to confirm the UX. Severity low, confidence
  needs-live-verification.

## Minimal refactor plan

Empty — feature is usable as-is. No code changes required to complete the journey.

## Notes for the human browser walk

Walk http://buddynext-dev.local/members/varundubey/ logged in as a *different* member.
Confirm: Follow button shows, click flips to Following + toast + follower count +1,
reload persists, click again unfollows. Network tab should show
`POST` / `DELETE /wp-json/buddynext/v1/users/{id}/follow` returning 200 with a
`{"following":...}` body. Seed at least one follow relationship first — an empty test
account hides the followers/following lists; that is not a bug.
