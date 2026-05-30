# Conformance — Scale contract

**Checked:** 2026-05-31
**Spec:** docs/specs/SCALE-CONTRACT.md, docs/specs/features/19-database-scale.md, docs/specs/features/21-performance-routing.md
**Code inspected:** includes/Feed/, includes/Search/, includes/Hashtags/, includes/Core/Installer.php
**Verdict:** usable-minor-polish

---

## Summary

The Scale contract's spine is in place and used. Hot read paths (home/profile/space/explore feeds)
are cursor-paginated with `LIMIT %d`, `per_page` is clamped to 50 everywhere, every WHERE/JOIN/ORDER BY
column has a covering index in `Installer::run()`, denormalized counters live on parent rows, and
heavy writes (hashtag indexing, search indexing) are dispatched through Action Scheduler with an inline
fallback. No unbounded queries and no synchronous fan-out were found.

Two "exists-but-not-used-consistently" gaps remain. Neither breaks a user journey; both are polish.

---

## Journey (key guarantees)

| # | Guarantee | Layer | Status | Evidence |
|---|-----------|-------|--------|----------|
| 1 | Hot read paths are cursor-paginated, never OFFSET | service | wired | includes/Feed/FeedService.php:855 (`cursor_where`), used by home/profile/space/explore |
| 2 | per_page hard-capped at 50 | rest | wired | FeedService.php:154; SearchService.php:169; SearchController.php:84 (members maximum:50) |
| 3 | Every WHERE/JOIN/ORDER BY column indexed | db | wired | Installer.php:412-416 (bn_posts), 357 (bn_follows), 628 (bn_post_hashtags), 698-700 (bn_search_index) |
| 4 | FULLTEXT is source of truth for cross-collection search | db | wired | Installer.php:48 (ft_search); SearchService.php:332-376 (MATCH AGAINST) |
| 5 | Denormalized counters, no COUNT(*) in render | db | wired | bn_posts.reaction/comment/share_count; bn_hashtags.post_count/follower_count maintained on write |
| 6 | Heavy writes async via Action Scheduler | service | wired | HashtagListener.php:170 (`as_enqueue_async_action`); SearchIndexListener.php:356 |
| 7 | Page-1 home feed cached (highest-impact item) | service | api-only | FeedCache wired in container Plugin.php:632, but FeedController.php:408 builds an un-cached instance |
| 8 | Search pagination bounded (ceiling 1000) | rest | broken | SearchController.php:56 `page` has no `maximum`; SearchService.php:371 uses `OFFSET` |

---

## First break

Guarantee #8 — `GET buddynext/v1/search` accepts an unbounded `page` arg (`absint`, no `maximum`),
and `SearchService::search()` paginates with `LIMIT %d OFFSET %d` (SearchService.php:371). Spec §2
("Cursor pagination — never OFFSET") and §19 ("Search results cap at 100/page with hard ceiling at
1000 across pagination") are both unenforced on this endpoint. A request for a deep page issues a
large-offset scan on `bn_search_index`. Bounded blast radius (one endpoint, FULLTEXT-indexed table),
but it is the literal contract clause being violated.

Same OFFSET pattern, lower severity: `HashtagService::get_feed()` (HashtagService.php:147) and the
`handle_reindex_all` batch job (SearchIndexListener.php:266) — the latter is an admin/cron batch,
explicitly acceptable.

---

## Contract violations (ux_gaps)

1. **Search endpoint OFFSET pagination with no page ceiling** — medium. SearchController.php:56-61
   defines `page` with `absint` and no `maximum`; SearchService.php:371 `LIMIT %d OFFSET %d`.
   Violates SCALE-CONTRACT §2 + 19-database-scale ceiling clause. Confirmed in code.

2. **REST feed endpoints skip the page-1 cache** — medium. The container binds `feed` WITH FeedCache
   (Plugin.php:632) and the template path uses `buddynext_service('feed')`, so server-rendered first
   paint is cached. But FeedController::feed_service() (FeedController.php:408) does
   `new FeedService( new FollowService(), new PostService() )` — third (cache) arg null — so
   `/feed/home` and `/feed/home/page` (JS infinite-scroll page 1) bypass the cache the contract names
   the single highest-impact item. Confirmed in code.

3. **Hashtag `get_feed` uses OFFSET** — low. HashtagService.php:147. Same pattern as #1, lower traffic.
   Confirmed in code.

---

## What is already correct (do not touch)

- FeedService cursor pagination + `per_page+1` has-more detection (FeedService.php:897).
- All feed source-blend WHERE clauses use subqueries with `%d` placeholders — no PHP-side ID arrays,
  no interpolated user data (FeedService.php:296).
- Installer index coverage meets or exceeds 19-database-scale; `bn_feed_items` fan-out-on-write table
  is pre-provisioned for the >1M path (Installer.php:438).
- Hashtag/search writes are Action Scheduler-backed with inline fallback for dev/test.
- Trending hashtags cached 5min + busted on write (HashtagService.php:550, 577) — matches the
  contract's accepted state (the dedicated aggregator job remains an unchecked pre-GA TODO, acceptable
  because the read is cached).
- Denormalized counters updated on write; no COUNT(*) in a hot render path. (`home_feed_counts`
  COUNT is clamped to a 24h window and is an explicit on-demand badge call, not a per-row render.)

---

## Refactor plan (minimal)

1. SearchController.php:56 — add `'maximum' => 50` to `per_page` (currently uncapped at the arg layer;
   only the service clamps) and a `'maximum'` on `page` such that `page * per_page <= 1000`, or
   reject `page` beyond the ceiling with a 400. Enforces the §19 hard ceiling at the boundary.
2. FeedController.php:408 — resolve the cached binding instead of constructing a bare instance:
   `return buddynext_service( 'feed' );`. This routes REST `/feed/home` + `/feed/home/page` through
   the page-1 FeedCache already wired in the container.
3. (Optional, low) HashtagService::get_feed — migrate to cursor pagination to match FeedService, or
   document an internal page ceiling consistent with #1.
