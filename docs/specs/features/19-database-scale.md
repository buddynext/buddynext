# BuddyNext — Database Design + Scale (100K Members)

**Status:** Locked
**Last updated:** 2026-03-19

---

## Target

100,000 members. ~50 posts per user = 5M posts. ~200 follows per user = 20M follow rows. Every query must complete in under 100ms on shared hosting. No Redis required for free tier.

---

## Required Indexes Per Table

### bn_follows
```
PRIMARY KEY (follower_id, following_id)
INDEX (following_id)                      -- follower count, "who follows this user"
```
Home feed needs `WHERE follower_id = ?` → primary key covers it.
Follower count needs `WHERE following_id = ?` → second index required.

### bn_connections
```
PRIMARY KEY auto-increment
UNIQUE KEY (requester_id, recipient_id)
INDEX (recipient_id, status)              -- pending requests inbox
INDEX (requester_id, status)              -- sent requests list
```

### bn_blocks
```
PRIMARY KEY (blocker_id, blocked_id)
INDEX (blocked_id)                        -- "who has blocked this user" check
```

### bn_posts
```
PRIMARY KEY (id)
INDEX (user_id, created_at)               -- profile feed
INDEX (space_id, created_at)              -- space feed
INDEX (privacy, created_at)               -- explore feed (public posts)
INDEX (scheduled_at)                      -- scheduled post publisher job
INDEX (type, created_at)                  -- type-filtered feeds
```
Home feed (`WHERE user_id IN (...)`) relies on `(user_id, created_at)` index across the IN list. At 100K this is acceptable — see Feed Query section below.

### bn_poll_options
```
PRIMARY KEY (id)
INDEX (post_id, display_order)            -- load options for a poll
```

### bn_poll_votes
```
PRIMARY KEY (id)
UNIQUE KEY (post_id, user_id)             -- one vote per user per poll
INDEX (option_id)                         -- count votes per option
```

### bn_space_members
```
PRIMARY KEY (space_id, user_id)
INDEX (user_id, role)                     -- "my spaces" list
```

### bn_notifications
```
PRIMARY KEY (id)
INDEX (recipient_id, is_read, created_at) -- bell count + list (covering index)
INDEX (recipient_id, group_key)           -- grouping dedup lookup
```

### bn_comments
```
PRIMARY KEY (id)
INDEX (object_type, object_id, parent_id, created_at)  -- thread load
INDEX (user_id)                           -- "my comments" / moderation
```

### bn_reactions
```
UNIQUE KEY (user_id, object_type, object_id)   -- one reaction per user per object
INDEX (object_type, object_id)                  -- reaction counts per object
```

### bn_post_hashtags
```
PRIMARY KEY (post_id, object_type, hashtag_id)
INDEX (hashtag_id, created_at)            -- hashtag feed query
```

### bn_hashtag_follows
```
PRIMARY KEY (user_id, hashtag_id)
INDEX (hashtag_id)                        -- hashtag follower count
```

### bn_search_index
```
UNIQUE KEY (object_type, object_id)
FULLTEXT KEY ft_search (title, content)
INDEX (visibility, object_type)           -- privacy-filtered searches
INDEX (author_id)                         -- "all content by user" query
```

### Ability grants (wp_usermeta)
Ability grants are stored as `bn_ability_{slug}` user_meta entries. The
`(user_id, meta_key)` index on `wp_usermeta` already gives O(log n)
lookups; no schema work needed.

---

## Denormalized Counters

Never COUNT(*) in a hot query path. Store counts on the parent row, update async.

| Counter | Stored on | Updated by |
|---------|-----------|-----------|
| Follower count | `wp_usermeta bn_follower_count` | Action Scheduler after follow/unfollow |
| Following count | `wp_usermeta bn_following_count` | Action Scheduler after follow/unfollow |
| Post reaction count | `bn_posts.reaction_count` | Action Scheduler after reaction add/remove |
| Post comment count | `bn_posts.comment_count` | Action Scheduler after comment create/delete |
| Space member count | `bn_spaces.member_count` | Action Scheduler after join/leave |
| Hashtag post count | `bn_hashtags.post_count` | Action Scheduler after post tag/untag |
| Hashtag follower count | `bn_hashtags.follower_count` | Action Scheduler after follow/unfollow |

Counters are eventually consistent (seconds lag). A `buddynext_recount_stats` job runs every 5 minutes to fix any drift from failed jobs.

---

## Home Feed Query

The hardest query in the system. At 100K users:

```
Step 1: Get followed user IDs    → SELECT following_id FROM bn_follows WHERE follower_id = ?
Step 2: Get followed hashtag IDs → SELECT hashtag_id FROM bn_hashtag_follows WHERE user_id = ?
Step 3: Get posts from hashtags  → SELECT post_id FROM bn_post_hashtags WHERE hashtag_id IN (...)
Step 4: Combined feed query      → SELECT * FROM bn_posts
                                     WHERE (user_id IN (...step1) OR id IN (...step3))
                                     AND scheduled_at IS NULL
                                     AND privacy IN ('public','followers','connections')
                                     ORDER BY created_at DESC
                                     LIMIT 20
```

**At 100K with good indexes:** Step 1 = ~200 rows, Step 4 = index scan on `(user_id, created_at)` for each followed user. Acceptable.

**Fan-out on write** (pre-built per-user feed table `bn_feed_items`) is only needed above 1M members. Not needed for v1.

**Cursor pagination** means no OFFSET — always `WHERE created_at < {cursor} LIMIT 20`. Stays fast regardless of how deep the user scrolls.

---

## Caching Strategy (No Redis Required)

Uses WordPress object cache (`wp_cache_*`). On hosts with Redis/Memcached it automatically uses them. On shared hosting it uses the in-memory request cache.

| Data | Cache key | TTL | Invalidated by |
|------|-----------|-----|----------------|
| Notification unread count | `bn_notif_count_{user_id}` | 30s | New notification created |
| Trending hashtags | `bn_trending_hashtags` | 30min | Cron job replaces |
| Space member count | `bn_space_members_{space_id}` | 5min | Join/leave event |
| User follow counts | `bn_follow_counts_{user_id}` | 5min | Follow/unfollow event |
| Hashtag autocomplete | `bn_hashtag_ac_{query}` | 10min | New hashtag created |
| User abilities | `bn_abilities_{user_id}` | 60s | Webhook grants/revokes ability |

---

## Action Scheduler Job Schedule

| Job | Trigger | Priority |
|-----|---------|---------|
| `buddynext_send_email` | On event (follow, reaction, etc.) | 1=auth emails, 5=social, 10=digests |
| `buddynext_daily_digest` | Cron daily 08:00 UTC | 10 |
| `buddynext_weekly_digest` | Cron Monday 08:00 UTC | 10 |
| `buddynext_cleanup_tokens` | Cron daily | 10 |
| `buddynext_cleanup_notifications` | Cron weekly (prune read 90d+) | 10 |
| `buddynext_index_object` | On content create/update/delete | 5 |
| `buddynext_reindex_all` | On activation / manual trigger | 10 |
| `buddynext_trending_hashtags` | Cron every 30 min | 10 |
| `buddynext_recount_stats` | Cron every 5 min | 10 |
| `buddynext_publish_scheduled` | Cron every 1 min (checks scheduled_at) | 5 |

---

## Table Size Estimates at 100K Members

| Table | Estimated rows | Notes |
|-------|---------------|-------|
| `bn_follows` | 20M | 200 avg follows per user |
| `bn_posts` | 5M | 50 avg posts per user |
| `bn_poll_votes` | 2M | ~10% of posts are polls, 4 avg votes per poll |
| `bn_notifications` | 30M | pruned at 90 days |
| `bn_comments` | 15M | 3 avg comments per post |
| `bn_reactions` | 10M | 2 avg reactions per post |
| `mvs_messages` | 25M | DM volume — owned by WPMediaVerse, not counted in BuddyNext budget |
| `bn_post_hashtags` | 10M | 2 avg hashtags per post |
| `bn_search_index` | 6M | posts + profiles + spaces + addon content |
| `bn_connections` | 3M | 30 avg connections per user |
| `bn_activity_log` | 50M | pruned at 90 days — highest volume table |

`bn_activity_log` tracks user actions (login, post, join space, follow) for stats and support — lightweight event rows, pruned at 90 days. No separate spec needed.

`bn_notifications` is the fastest-growing table. The 90-day cleanup cron is critical — without it this table becomes the biggest performance risk in the system.

---

## MySQL Configuration Notes

Minimum recommended settings for 100K communities:

```
innodb_buffer_pool_size = 1G    -- more if available, keeps hot indexes in memory
max_connections = 150
query_cache_size = 0            -- query cache is deprecated in MySQL 8, disable it
innodb_file_per_table = ON      -- easier table maintenance
slow_query_log = ON             -- catch regressions early
long_query_time = 0.5           -- flag anything over 500ms
```

---

## Search Engine Scaling

| Scale | Recommendation |
|-------|---------------|
| < 50K members | MySQL FULLTEXT (default, no config needed) |
| 50K–200K members | MySQL FULLTEXT with tuned `innodb_buffer_pool_size` |
| 200K+ members | Switch to ElasticSearch, Algolia, or Typesense via `buddynext_search_driver` filter |

Migration path: swap the driver filter, run `do_action('buddynext_reindex_all')`, done. No schema changes.

---

## Gaps / Open Questions

- None — fully locked
