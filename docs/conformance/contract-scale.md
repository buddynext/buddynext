# Conformance — Scale contract

**Checked:** 2026-05-31
**Spec:** docs/specs/SCALE-CONTRACT.md, docs/specs/features/19-database-scale.md, docs/specs/features/21-performance-routing.md
**Code inspected:** includes/Feed/, includes/Search/, includes/Hashtags/, includes/Core/Installer.php
**Verdict:** usable-minor-polish — the contract holds on every hot read/write path. Two real but bounded deviations on search / hashtag-feed pagination (OFFSET instead of cursor, plus an unenforced deep-page ceiling).

---

## What the contract guarantees, and whether the code keeps it

| # | Guarantee | Status | Evidence |
|---|-----------|--------|----------|
| 1 | No unbounded queries (LIMIT everywhere) | wired | Every `get_results`/`get_var`/`get_col` carries a LIMIT or is a bounded aggregate. FeedService clamps `per_page` to 50. |
| 2 | Cursor pagination, never OFFSET | broken (partial) | Feeds + member directory are cursor-based (`FeedService::cursor_where()`). But `SearchService::search()` and `HashtagService::get_feed()` use `LIMIT %d OFFSET %d` on large tables. |
| 3 | Cached aggregates, never COUNT(*) in render | wired | bn_posts carries reaction/comment/share counts; bn_hashtags carries post_count/follower_count. `home_feed_counts` COUNT(*) is bounded to a 24h window + indexed. |
| 4 | Object cache on every hot read | wired | FeedCache wraps home page-1 (30s); trending hashtags cached+busted; hashtag follow-state, post-get, search object-type list cached. |
| 5 | Indexes on every WHERE/JOIN/ORDER column | wired | Installer declares all spec indexes incl. bn_search_index ft_search FULLTEXT + updated_order, bn_post_hashtags hashtag_feed, bn_notifications bell. |
| 6 | Action Scheduler for write fan-out | wired | HashtagListener + SearchIndexListener dispatch via `as_enqueue_async_action` with inline fallback; batch reindex 100 rows/loop. |
| 7 | REST capacity caps | wired (minor gap) | per_page clamped to 50 in service+controller. `/search` `page` arg has no `maximum` — contract's "hard ceiling 1000" unenforced. |
| 9 | No client-side N+1 | wired | Feed source-blend uses SQL subqueries (no PHP-side IN arrays). Only poll posts fetch options (1 indexed query, ~10% of rows). |
| 10 | Search index = source of truth | wired | bn_search_index FULLTEXT, single MATCH...AGAINST, `buddynext_search_results` driver filter for ES/Algolia swap. |

---

## Deviations

### D1 — OFFSET pagination on large tables (non-negotiable #2). HIGH.
`includes/Search/SearchService.php:398` & `:437` — FULLTEXT and LIKE branches both paginate with `LIMIT %d OFFSET %d`. bn_search_index est. 6M rows. Page 1000 of 50/page scans+discards 50,000 rows/request.
`includes/Hashtags/HashtagService.php:147` — `get_feed()` paginates the hashtag feed (JOIN over bn_post_hashtags, ~10M rows) with the same OFFSET pattern.
Feed surfaces + member directory already use cursor pagination correctly; this is isolated to two list services.

### D2 — Deep-page ceiling unenforced (non-negotiable #1 + #7). MEDIUM.
`includes/Search/SearchController.php:60` — `page` arg is `absint` with no `maximum`; contract specifies a hard ceiling of 1000 across pagination. Caller can request page 50000 → OFFSET ~999980. Compounds D1. (per_page is effectively capped by in-code `min(...,50)` but not declared as a `maximum` validate arg.)

---

## Correct — do NOT touch
- FeedService cursor pagination + page-1 cache wrap.
- Installer index coverage (matches feature-19 table-by-table incl. composite + FULLTEXT).
- Async fan-out wiring (Action Scheduler + graceful inline fallback).
- Denormalized counters on bn_posts / bn_hashtags.
- Search driver filter seam for the 200K+ ElasticSearch/Algolia migration path.

---

## Minimal refactor plan
1. Convert `SearchService::search()` to keyset/cursor pagination on `(updated_at, id)` (relevance+id seek for the FULLTEXT branch). The `updated_order (updated_at)` index already supports it.
2. Convert `HashtagService::get_feed()` to cursor pagination on `(bn_post_hashtags.created_at, post_id)` — `hashtag_feed (hashtag_id, created_at)` index already supports the seek.
3. Interim guard until (1)/(2) land: clamp `page` in SearchController to the contract ceiling (max OFFSET 1000) and add `maximum: 50` to the `per_page` arg so the cap is declared, not just coded.

Localized to two list methods; the rest of the scale infrastructure is sound and must not be reworked.
