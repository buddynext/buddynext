# Contract Conformance — Scale

**Contract:** Scale contract
**Specs:** `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/19-database-scale.md`, `docs/specs/features/21-performance-routing.md`
**Scope inspected:** `includes/Feed/`, `includes/Search/`, `includes/Hashtags/`, `includes/Core/Installer.php`, plus the space-post notification fan-out in `includes/Notifications/NotificationListener.php` (the contract's headline scale case).
**Reviewed:** 2026-05-31 — static code read only, no live walk.

**Verdict: partial-needs-wiring.** The spine is overwhelmingly correct — cursor pagination, denormalised counters, comprehensive indexes, async hashtag/search indexing, cached hot reads, REST caps. But two provable breaks against the locked non-negotiables remain: a synchronous + unbounded fan-out enumeration on space posts, and OFFSET pagination on two large tables.

---

## What is solid (do NOT touch)

- **FeedService — cursor pagination + LIMIT everywhere.** `home_feed`, `profile_feed`, `space_feed`, `explore_feed` all use keyset cursor (`created_at < ? OR (created_at = ? AND id < ?)`), fetch `per_page+1`, clamp `min($per_page, 50)`. No OFFSET. Page-1 of `for-you` home feed is cache-wrapped via `FeedCache` (contract hot path #4). `FeedService.php:168-336, 975-1005`.
- **Source-blend uses subqueries, not PHP ID arrays.** `home_source_clause()` keeps the whole follow/space/hashtag blend in-SQL — no client-side N+1. `FeedService.php:348-406`.
- **Denormalised counters.** `bn_posts.reaction_count/comment_count/share_count`, `bn_hashtags.post_count/follower_count`, `bn_spaces.member_count` all declared and maintained on write. `Installer.php:387-417, 611-619, 496-505`.
- **Indexes match or exceed spec 19.** Every WHERE/JOIN/ORDER BY column is indexed: `bn_posts` user_feed/space_feed/explore/scheduled, `bn_notifications` bell covering index `(recipient_id,is_read,created_at)`, `bn_post_hashtags` hashtag_feed `(hashtag_id,created_at)`, `bn_search_index` FULLTEXT + visibility_type + author. `Installer.php:351-702`.
- **Async write indexing.** Hashtag extraction (`HashtagListener::dispatch` → `as_enqueue_async_action('buddynext_async_index_hashtags')`) and search indexing (`SearchIndexListener` → `buddynext_async_index_post/user/space`) both dispatch via Action Scheduler with an inline `do_action_ref_array` fallback for AS-absent envs. `HashtagListener.php:169-175`, `SearchIndexListener.php:350-358`.
- **Cached hot reads + bust-on-write.** Notification unread count cached `unread_{user_id}` busted on create/read/delete (`NotificationService.php:285-304, 86/154/210/251/276`). Trending hashtags + autocomplete + is_following cached with TTL and busted on sync/follow (`HashtagService.php:520-553, 410-433`). Search object-type discovery cached 300s.
- **REST caps.** SearchService and FeedService clamp `per_page` to 50 at the service layer; HashtagController declares `maximum: 50/20` on its args.

---

## Confirmed contract breaks

### B1 — Space-post notification fan-out is synchronous and unbounded (CRITICAL)

`NotificationListener::on_post_created_in_space()` is hooked **directly** to `buddynext_post_created` (`NotificationListener.php:51`), so it runs inside the post-create request. It then:

1. `SELECT user_id FROM bn_space_members WHERE space_id = ? AND status='active' AND user_id != ?` — **no LIMIT** (`NotificationListener.php:548-555`). At the contract's stated target (100k members per space) this loads 100k rows into PHP.
2. `foreach ($member_ids ...)` runs **synchronously in-request**, and per member calls `get_space_pref()` and `is_blocked()` — each a DB query (`NotificationListener.php:564-576`).

Only the final notification *insert* is enqueued async. The enumeration + 2 queries/member loop is the exact synchronous fan-out non-negotiable #6 forbids ("100k row INSERTs synchronously … request times out") and the unbounded query non-negotiable #1 forbids. Spec 21 §6 mandates this be "batched 500/job" inside Action Scheduler, not enumerated in-request.

This is the contract's own headline example, violated in the one place it matters most.

### B2 — OFFSET pagination on large tables (HIGH)

Non-negotiable #2: "Cursor pagination — never OFFSET." Two read paths violate it:

- `SearchService::search()` — `LIMIT %d OFFSET %d` on `bn_search_index` (~6M rows) for both FULLTEXT and LIKE paths. `SearchService.php:398, 437`. `page` has no upper ceiling (`SearchController.php:60-65`, `233`), so deep pages are reachable. Contract also specifies a 1000-row hard ceiling across search pagination — not enforced.
- `HashtagService::get_feed()` — `LIMIT %d OFFSET %d` on a `bn_posts` ⋈ `bn_post_hashtags` JOIN (~10M rows), `page` uncapped. `HashtagService.php:128-153`.

The feed got this right (`FeedService::cursor_where()`); these two collections diverge from the established pattern.

### B3 — COUNT(*) on read in search + hashtag feed (MEDIUM)

Non-negotiable #3: "Never SELECT COUNT(*) in a page render." Both `SearchService::search()` (`SearchService.php:370-383, 410-423`) and `HashtagService::get_feed()` (`HashtagService.php:157-167`) run a full `COUNT(*)` over the matched set on every request to produce a `total`. Softer than B1/B2 (FULLTEXT/indexed JOIN limit the scan, and a total is needed for result-count UI), but still an uncached aggregate on a hot path.

### Bounded `COUNT(*)` — NOT flagged

`FeedService::home_feed_counts()` and `home_feed_new_count()` use `COUNT(*)`, but clamped to a 24-hour window (`created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)`) inside the indexed source-blend. Bounded by the time window + index, not a full scan. Acceptable.

---

## Refactor plan (minimal, in priority order)

1. **B1:** Move `on_post_created_in_space` enumeration off the request. Either hook a single `buddynext_async_space_fanout` job to `buddynext_post_created` that pages `bn_space_members` in batches of 500 (LIMIT/keyset) and enqueues per-batch notification jobs, or push the member SELECT itself behind Action Scheduler. The per-member `get_space_pref`/`is_blocked` checks must run in the worker, not in-request.
2. **B2:** Convert `SearchService::search()` and `HashtagService::get_feed()` to keyset cursor pagination (reuse the `FeedService` cursor pattern). Until then, enforce the spec's hard page ceiling (max page such that `page*per_page <= 1000`) in both controllers to bound the OFFSET scan.
3. **B3:** Drop the live `COUNT(*)` from the hot path — return `has_more` from a `per_page+1` fetch (as the feed does) instead of an exact total, or cache the total per query for the TTL the spec allows (hashtag autocomplete already caches 10min; apply the same to feed/search totals).
