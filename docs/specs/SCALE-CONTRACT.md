# BuddyNext — Scale contract

**Target: 100,000 sites × 100,000 members per site.** Every architectural decision in this plugin must hold at that scale. This document is the binding contract — when a feature proposal violates it, the proposal needs to change, not the contract.

## Non-negotiables

### 1. No unbounded queries

Every `$wpdb->get_results()` / `get_var()` / `get_col()` must have a `LIMIT` clause. The REST per_page maximum is 50. Sidebar widgets cap at 5-10 rows. Search results cap at 100/page with hard ceiling at 1000 across pagination.

### 2. Cursor pagination — never OFFSET

`OFFSET N` scans + discards N rows. At page 1000 of 50/page that's 50,000 rows scanned per request. Use cursor pagination (`WHERE created_at < ? AND id < ?`) instead — every page is O(per_page) regardless of position.

The feed already does this (`FeedService::cursor_where()`). Every new paginated list must follow the same pattern.

### 3. Cached aggregates

`COUNT(*)` on `bn_posts` runs a full table scan. At 100k sites × 100k members × 100 posts each = 10 billion rows. Counts that show up in UI (post count, follower count, space member count, unread notification count) come from cached `bn_post_counts` / equivalent denormalised columns updated on insert/delete. Never `SELECT COUNT(*)` in a page render.

Already in place: `bn_posts.reaction_count` / `comment_count` / `share_count` columns, `bn_spaces.member_count`, `bn_hashtags.post_count`. New tables that need a count get a column from day 1.

### 4. Object cache on every hot read

Anything that runs on more than 50% of page loads gets a cache wrapper:

```php
$cached = wp_cache_get( $cache_key, 'buddynext_widgets' );
if ( false !== $cached ) {
    return $cached;
}
// ... DB query ...
wp_cache_set( $cache_key, $result, 'buddynext_widgets', 60 ); // 60s TTL
return $result;
```

Cache groups in use:
- `buddynext_widgets` — sidebar widget aggregates (60s TTL)
- `buddynext_feed_meta` — post hydration (300s)
- `buddynext_user_meta` — user-relative computations (300s, busted on write)
- `buddynext_space_meta` — space hydration (300s)
- `buddynext_hashtag_meta` — hashtag aggregates (300s)

Use Redis / Memcached in production. WordPress's built-in object cache is per-request only — fine for dev, useless for scale.

### 5. Indexes on every WHERE column

Every table created in `Installer::run()` declares an index for every WHERE / JOIN / ORDER BY column. Composite indexes for multi-column WHERE clauses.

Audit: when adding a new query that filters on column X, ALTER TABLE add an index on X before the query goes live.

### 6. Action Scheduler for write fan-out

When a post is created, notifications go to N space members. At 100k members per space that's 100k row INSERTs synchronously in the request — request times out.

Pattern: fire `do_action( 'buddynext_async_notify_space_members', $post_id, $space_id )` then a worker scheduled via Action Scheduler does the fan-out in batches of 100. Free already uses this for `buddynext_async_index_hashtags` and `buddynext_async_space_new_post_notification`.

### 7. REST endpoint capacity caps

Every `register_rest_route` has:
- `per_page` arg max 50 (enforced via `validate_callback`)
- A `permission_callback` that doesn't run an unbounded query
- Output minimised to fields the consumer asks for via `_fields`

Authenticated rate limits (per user, per IP) are CDN-layer, but in-app we still check `current_user_can` and short-circuit early.

### 8. Asset bytes

CSS bundle: under 200KB after minify+gzip. JS bundle: under 150KB per module. Web fonts: subset latin only (already done), preload critical weights only, font-display: swap.

Lazy-load images via `loading="lazy"`. Sized via width/height attributes to reserve layout space.

### 9. No client-side N+1

Per-post fetches in a feed loop are an anti-pattern. The feed REST endpoint returns hydrated posts with author + reactions + comments-preview in a single response. New endpoints that return collections always include the related data in the same payload.

### 10. Search index is the source of truth for cross-collection queries

`bn_search_index` (FULLTEXT) is the only correct way to query across posts + users + spaces + hashtags. Per-table search is too expensive at scale.

## What this means for current code

### Hot paths to cache (in order of impact)

1. **`templates/partials/sidebar.php`** — runs on EVERY hub page. Three queries (trending hashtags, suggested follows, your spaces). At 100k sites this is the single highest cost item. CACHE FIRST.
2. **`PageRouter::build_hub_context()`** — runs on every BN hub page. Settings + counts. Cache 60s.
3. **Notification unread count** — shown in the rail badge on every page. Cache per user, bust on write.
4. **Feed home** — already cursor-paginated. Add per-user 30s cache on page 1.
5. **Trending hashtags** — cache 5min, refresh on write to `bn_hashtags`.

### What's already correct

- `FeedService` uses cursor pagination + LIMIT.
- `bn_posts` has denormalised count columns.
- `bn_search_index` exists with FULLTEXT.
- Action Scheduler is wired for hashtag indexing + space notifications.
- REST controllers cap `per_page` at 50.

### What needs to land before v0.3.0 GA

- [ ] `partials/sidebar.php` widgets wrapped in `wp_cache_get` (this commit)
- [ ] Unread notification count cached + busted on `buddynext_notification_created`
- [ ] `Trending hashtags` aggregator runs as scheduled job, not on read
- [ ] `Suggested follows` precomputed via P2.1 AI signals (when AI feed is on) or static "most followed" fallback
- [ ] Asset bundle audit: WPMediaVerse + Jetonomy bridge JS lazy-load, not eager
- [ ] Per-user object cache groups added to wp-config notes for ops

### What's deferred to Pro v0.3.0+

- P3 Soketi WebSocket presence (replaces 5s polling)
- P4 FCM push (replaces email-only notifications)
- P5 Analytics aggregator job (denormalises DAU/WAU/MAU to a stats table)

## How to verify a feature obeys this contract

Before any PR is merged:

1. Identify every new query the PR introduces. Each one must have a LIMIT and use an existing index.
2. Identify every cache invalidation point. Every write to a cached aggregate must `wp_cache_delete` the matching key.
3. Identify every loop that fires async actions. Each one must be Action Scheduler-backed or capped to a small N.
4. Run `EXPLAIN` on the slow queries (`bin/explain-queries.sh` — TODO).
5. Profile the response size — REST endpoint < 100KB without media URLs.

## Boundary skills + scale

- `/wp-plugin-development` already covers nonces, escaping, capability checks. It does NOT enforce scale. This contract fills that gap.
- `/ux-audit` already covers visual rules. It does NOT cover query patterns. This contract fills that gap.

When this contract conflicts with a boundary skill — escalate. The two should agree.

---

**Last reviewed**: 2026-05-21. Owner: BN core team. Update when any of the 10 non-negotiables changes.
