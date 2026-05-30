# Conformance: Bookmarks (free)

**Spec ref:** `docs/specs/features/02-activity-feed.md` (Post Features → Free: "bookmark (private)"; Data Stored → `bn_bookmarks`)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/bookmarks
**Date:** 2026-05-31

---

## What the spec locks

Bookmarks is a Free post feature: "React, comment, share, bookmark (private)". Storage is the
`bn_bookmarks` table (`id, user_id, post_id, created_at — private saved posts`). The only locked
intent is: a member can privately save any post and later view the list of saved posts; only that
member can see their own bookmarks. No journey doc / V2 mockup exists for this surface.

## Happy-path journey (entry → outcome)

A logged-in member taps **Save** on a post card → the post is stored privately → the member opens
their bookmarks hub and sees the saved post. Unfollowing / losing visibility hides it at read time.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | "Save" button rendered on every post card for a logged-in viewer | ui | wired | `templates/parts/post-actions.php:173-184` (gated on `can_bookmark`); `templates/partials/post-card.php:221` sets `$can_bookmark = ( $current_user_id > 0 )` |
| 2 | Button bound to Interactivity action + reflects current state | store | wired | `post-actions.php:178` `data-wp-on--click="actions.toggleBookmark"`, `:180` class bind, `:182` `aria-pressed`; `post-card.php:331,335` seed context `bookmarked` + `bookmarkNonce` |
| 3 | toggleBookmark calls REST POST/DELETE with nonce, optimistic + revert | store | wired | `assets/js/feed/store.js:785-803` (method by state, `X-WP-Nonce: ctx.bookmarkNonce`, revert on non-ok) |
| 4 | REST routes `POST/DELETE /posts/{id}/bookmark` registered, auth-gated | rest | wired | `includes/Feed/BookmarkController.php:36-51,239-249`; registered in `includes/REST/Router.php:70` |
| 5 | Service writes/deletes `bn_bookmarks`, busts cache, fires hooks | service/db | wired | `includes/Feed/BookmarkService.php:39-105` (INSERT IGNORE / delete, `buddynext_post_bookmarked`/`_unbookmarked`) |
| 6 | Initial bookmarked state hydrated on card render | service | wired | `post-card.php:265-267` `buddynext_service('bookmarks')->is_bookmarked(...)` → `BookmarkService::is_bookmarked()` `BookmarkService.php:114-116` |
| 7 | Bookmarks hub route resolves to template | rest | wired | `includes/Core/PageRouter.php:986-991` rewrite `^me/bookmarks/?$`; dispatch `PageRouter.php:782-783` → `feed/bookmarks.php` |
| 8 | Hub lists saved posts, cursor paginated, re-applies visibility gates | db/service | wired | `templates/feed/bookmarks.php:62-72` (cursor query on `bn_bookmarks`), `:97-149` (block / secret-space / followers / private / suspended gates), renders `partials/post-card.php` |
| 9 | Empty + end states present | ui | wired | `templates/feed/bookmarks.php:214-227` empty state with CTA, `:208-211` end-of-list |
| 10 | App/REST client can list bookmarks (IDs or hydrated `?expand=posts`) | rest | wired | `BookmarkController.php:127-157` + same visibility gates in `hydrate_visible_posts()` `:171-232` |

## First break

none — journey complete. Web journey (save → list) and app/REST journey both fully wired,
visibility gates applied at read time on both the template and the `?expand=posts` endpoint.

## UX gaps

- **Live-walk URL mismatch (not a code break).** The canonical bookmarks URL registered by the
  router is `/me/bookmarks/` (`PageRouter.php:988`, `bookmarks_url()` `:1369-1370`). The task's
  live-walk URL is `/bookmarks`. No `^bookmarks` rewrite rule exists, so a human walking
  `http://buddynext-dev.local/bookmarks` will likely 404 / hit the WP fallback. Walk
  `http://buddynext-dev.local/me/bookmarks/` instead. Severity: low. Confidence:
  needs-live-verification (a theme/menu link or higher-level redirect could exist outside the
  router; verify in browser). This does not affect the in-product journey, which links via
  `PageRouter::bookmarks_url()`.

No other gaps. The "Save" control, optimistic toggle, nonce, REST auth, DB write, cache
invalidation, hub listing, cursor pagination, read-time visibility re-filtering, and empty/end
states are all present and bound. Bookmarks correctly stay private (per-user query on
`user_id`).

## Minimal refactor plan

(empty — usable-leave-as-is)

## Notes for the browser walk

1. Log in as a member with seeded posts. Open the activity feed.
2. Click **Save** on a post — expect "Saved" toast and the icon to flip to `is-bookmarked`.
3. Navigate to `/me/bookmarks/` (not `/bookmarks`) — expect the saved post listed.
4. Click **Save** again to unsave — expect "Removed from saved"; refresh the hub to confirm it drops off.
5. Empty account: hub shows "No bookmarks yet" with a "Browse the feed" CTA.
