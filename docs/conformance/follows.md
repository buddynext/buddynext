# Conformance — Follows (Social Graph)

**Feature:** Follows (free)
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked, 2026-03-19) — "Follow (asymmetric)"
**Journey ref:** `docs/journeys/social-graph.md` — Part 1: Follow / Unfollow
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

---

## Verdict rationale

A logged-in member can land on another member's profile, click Follow, and the
follow row is created in `bn_follows`; the button flips to Following and a
second click (DELETE) unfollows. Every layer of the happy path is wired and
proven by reading code — UI control, Interactivity store action, REST route,
service, and DB write. No usability break found.

The journey doc is framed as a curl/REST automation walk; the *web* journey it
implies (profile → Follow button → toggle) is fully wired through the
`buddynext/profile` Interactivity store, not the `buddynext/follow-button`
store. Both stores exist and both reach the same REST endpoint.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member profile renders Follow button (non-owner, logged-in) | ui | wired | `templates/parts/profile-hero.php:407-420` (Follow/Following, `data-wp-on--click="actions.follow"` / `actions.unfollow`, gated `! $bn_pf_is_owner && $bn_pf_viewer`) |
| Profile view boots `buddynext/profile` store + context | ui | wired | `templates/profile/view.php:343-344` (`data-wp-interactive="buddynext/profile"`, context carries `profileUserId`, `isFollowing`, `restNonce`) |
| Profile bundle enqueued on `/members/{slug}/` | ui | wired | `includes/Core/PageRouter.php:651-655` (people hub + `user_id` → `enqueue('profile')`); module registered `includes/Core/AssetService.php:316` |
| Store action calls REST with nonce + optimistic rollback | store | wired | `assets/js/profile/store.js:1053-1090` (POST/DELETE `buddynext/v1/users/{id}/follow`, `X-WP-Nonce`) |
| REST route registered, auth-gated | rest | wired | `includes/SocialGraph/FollowController.php:32-47`; booted `includes/REST/Router.php:65` |
| Controller blocks self/blocked, calls service | rest | wired | `includes/SocialGraph/FollowController.php:116-161` |
| Service writes row, fires hooks, busts cache | service | wired | `includes/SocialGraph/FollowService.php:64-207` (`buddynext_user_followed` / `buddynext_user_unfollowed`) |
| Row persisted in `bn_follows` | db | wired | `includes/SocialGraph/FollowService.php:100-108` (INSERT), `185-192` (delete) |
| Followers list reads graph back | ui+service | wired | `templates/profile/followers.php:43-60` (`FollowService::followers()`, paginated, block-filtered) |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Alternate v2 hero block `templates/blocks/profile-header.php` renders a Follow button with `data-action="bn-toggle-follow"` + a custom `buddynext_follow_{id}` nonce but NO `data-wp-interactive` / `data-wp-on--click` / `data-wp-context` wiring — inert if that block is the surface in use. The live profile route uses `parts/profile-hero.php` (wired), so the canonical journey is unaffected; risk only if a site composes the page from this block. | low | confirmed-in-code | `templates/blocks/profile-header.php:94-106` vs `templates/parts/profile-hero.php:407-420` |
| Journey-doc/contract drift: `docs/journeys/social-graph.md:19-57` describes a single POST that *toggles* follow and shows unfollow as a second POST. The controller splits POST=follow / DELETE=unfollow; a 2nd POST hits `INSERT IGNORE` and does not unfollow. The web UI correctly issues DELETE, so this is doc drift, not a runtime break. | low | confirmed-in-code | `docs/journeys/social-graph.md:50-57` vs `includes/SocialGraph/FollowController.php:41-45,155-161` |

---

## Notes (not gaps)

- Private-account follow → `pending` flow is implemented end-to-end
  (`FollowService.php:97-123`; approve/reject routes `FollowController.php:89-107`).
  The hero button does not surface a distinct pending label, but for public
  accounts (the spec's core happy path) this is irrelevant.
- Block guard holds at controller (`FollowController.php:120-126`), service
  (`FollowService.php:74-95`), and template levels. Roles/visibility respected.
- Reads cache-backed (group `buddynext_follows`, 10-min TTL) with explicit
  invalidation on write (`FollowService.php:611-617`) — satisfies SCALE-CONTRACT.

---

## Minimal refactor plan

None — usable, leave as-is. The two low-severity items are doc drift and an
unused alternate block surface; neither stops the canonical journey. Per the
prime directive, working code is not rewritten.
