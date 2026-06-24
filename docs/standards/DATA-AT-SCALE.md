# Data-at-Scale Standard (100k users / rows)

> Portable foundation for **every Wbcom plugin**. The schema and read paths in
> BuddyNext were designed for this from day one; this doc codifies the rules so new
> code does not erode it. Reference paths are BuddyNext-specific; the rules are not.
>
> Reference implementation + rationale: [`../plans/scale-readiness-100k.md`](../plans/scale-readiness-100k.md).

## 1. The one principle

**Every list, query, write, and option must be correct at 100k rows on the day it
ships — not after someone files a slow-site ticket.** Cost per request must not grow
with the size of the community.

## 2. The six rules

### Rule 1 — No autoloaded bloat

`add_option()` / `update_option()` default to **autoload = yes**, which loads the value
on *every request for every user*. Autoload is only acceptable for a small, scalar
value read on most requests.

- Per-entity settings (per-space, per-group, per-campaign) are **never** stored as
  `option_<id>_<key>` rows. One option per entity per setting × thousands of entities =
  thousands of autoloaded rows on every page. Store them in a meta table
  (`bn_space_meta`, `bn_*_meta`) instead.
- Large blobs (custom CSS, JSON config, banned-word lists) are `autoload = false`.
- New option? Pass the autoload arg explicitly: `add_option( $k, $v, '', false )`.

### Rule 2 — Time/range filters must be sargable

Never filter or sort on `CAST(meta_value AS UNSIGNED)` over `wp_usermeta` /
`wp_postmeta` — `meta_value` is unindexable and `CAST()` defeats any index, forcing a
full meta scan + filesort on a hot surface (the classic "who's online" killer).

- Store the value you range-query on as a **real typed column with an index**
  (`bn_presence(user_id PK, last_active INT, INDEX(last_active))`), or
- cache the matching id-set in object cache with a short TTL and intersect in PHP.

### Rule 3 — No unbounded reads

Every query that returns a row set has a `LIMIT`. `number => -1`,
`posts_per_page => -1`, and `SELECT *` with no bound are banned on any set that grows
with the community.

- Admin "resolve all matching users" work (segments, recounts) is **chunked** into a
  paged/cron batch (write ids into a worktable), never one `WP_User_Query number => -1`
  that loads 100k ids into PHP memory.

### Rule 4 — High-volume writes are batched, sampled, and pruned

A render loop must never do one synchronous `$wpdb->insert` per item (impressions,
analytics events, signals). At 100k users × ~20 items/view that is a write storm plus
an unbounded table.

**Action Scheduler is the first-class offload — we already run it (see
[`BACKGROUND-JOBS.md`](./BACKGROUND-JOBS.md)); use it, don't invent a second queue.**

- Collect the render-loop events in memory, then enqueue **one** async job per request
  with the batch, never one job per item:
  ```php
  // In the render loop: buffer, don't write.
  $this->impression_buffer[] = $post_id;

  // Once, at request end (shutdown / after the loop):
  if ( $this->impression_buffer && function_exists( 'as_enqueue_async_action' ) ) {
      as_enqueue_async_action(
          'buddynextpro_record_impressions',
          array( get_current_user_id(), array_values( array_unique( $this->impression_buffer ) ) ),
          'buddynextpro_analytics'                       // one group per plugin (BACKGROUND-JOBS §5)
      );
  }
  // The handler does ONE bulk multi-row INSERT for the whole batch.
  ```
- Add a sampling rate and an enable toggle for pure-analytics writes (don't enqueue
  every impression if a sample suffices).
- Every high-volume table has a **retention / rollup cron** — an AS recurring job in the
  same group (daily prune + optional rollup), per
  [`BACKGROUND-JOBS.md` §5](./BACKGROUND-JOBS.md).

### Rule 5 — Lists: keyset pagination + dedicated COUNT + cached page-1

- Cursor/keyset pagination (not large `OFFSET`) for feeds and directories.
- Totals come from a dedicated `count_*()` running `SELECT COUNT(*)` — never
  `count( $service->list_all() )`, and never re-running the full filtered join wrapped
  in `COUNT(*) ... LIMIT PHP_INT_MAX` on every page.
- Cache page-1 per relevant key (per-user feed, per-filter directory).

### Rule 6 — No N+1; prime then loop

- Before looping a result set, prime caches: `cache_users()` + `update_meta_cache()`
  (users), `update_post_caches()` (posts). Never `get_userdata()` / `get_*_meta()`
  per row un-primed.
- Prefer denormalized counter columns (`reaction_count`, `comment_count`,
  `member_count`) incremented on write over `COUNT(*)`-per-row at read.

## 3. Index discipline (keep the schema honest)

Every column in a `WHERE`, `ORDER BY`, or `JOIN` has a matching `KEY` in the
`dbDelta()` schema — verified when the query is written, not after profiling. Composite
indexes match the actual column order of the query. Add a `FULLTEXT` index before
relying on `LIKE '%term%'` for search at volume.

## 4. Per-feature checklist (gate before shipping any data surface)

1. Any new option — is `autoload` explicitly set, and is per-entity data in a meta
   table not in options?
2. Any range/time filter — is it on an indexed typed column, not `CAST(meta_value)`?
3. Every query bounded by `LIMIT`? No `number => -1` on a growing set?
4. High-volume writes batched + sampled + pruned?
5. List paginated (keyset) + dedicated `COUNT(*)` + page-1 cached?
6. Caches primed before any loop (no N+1)?
7. Every `WHERE`/`ORDER`/`JOIN` column indexed in the schema?

## 5. BuddyNext reference implementation

| Pattern | File |
|---|---|
| Keyset cursor + page-1 cache (do this) | `includes/Feed/FeedService.php::home_feed()` |
| Keyset directory + batched meta prime | `includes/Profile/MemberDirectoryService.php` |
| Disciplined composite indexes | `includes/Core/Installer.php` (`bn_posts`, `bn_notifications`, `bn_follows`) |
| Full audit, ranked risks + decisions | `docs/plans/scale-readiness-100k.md` |

### Known violations to fix (tracked in the plan doc)

1. **Synchronous per-impression INSERT** — `buddynext-pro` `AnalyticsCollector::record()`
   fires per post in the feed loop. Batch + sample + add retention. (Worst risk.)
2. **Non-sargable presence scans** — `MemberDirectoryService` "online" filter/sort and
   presence widgets `CAST(bn_last_active)` over `wp_usermeta`. Move to a `bn_presence`
   table.
3. **Autoloaded per-space options** — `bn_space_<id>_*` (incl. banned-words). Move to
   `bn_space_meta` or set `autoload = false`.
4. **Unbounded segment resolution** — Pro `Email/SegmentService` `number => -1`. Chunk
   into `bn_campaign_recipients`.
5. **One un-primed N+1** — `Search/SearchService::enrich_members()`. Add `cache_users()`.
