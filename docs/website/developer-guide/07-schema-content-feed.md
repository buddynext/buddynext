# Schema: Content and Feed

Reference for the `bn_*` tables that store posts, comments, reactions, shares, bookmarks, polls, the per-recipient feed cache, and the hashtag registry. All tables are created in `BuddyNext\Core\Installer` via `dbDelta()` and are prefixed with the site table prefix (shown here as `bn_`, e.g. `wp_bn_posts`).

## Overview / Contract

These tables follow the rules in the BuddyNext Scale Contract, which targets 100,000 sites of 100,000 members each. The shape of every table below is driven by three of those rules:

- **Denormalized counters.** `COUNT(*)` is never run in a page render. Aggregate counts that show in the UI live in dedicated columns that are incremented and decremented on write: `bn_posts.reaction_count` / `comment_count` / `share_count`, `bn_poll_options.vote_count`, and `bn_hashtags.post_count` / `follower_count`. New tables that need a count get the column from day one.
- **Indexes on every WHERE / JOIN / ORDER BY column.** Composite indexes back the multi-column filters used by the feed, thread, and hashtag-feed queries.
- **Cursor pagination, never OFFSET.** Feed reads use `WHERE created_at < ? AND id < ?` against the composite keys below, so cost stays O(per_page) regardless of page depth. The cursor columns are `created_at` plus the primary key `id`.

Counter columns are written by `PostService::increment_counter()` / `decrement_counter()`; decrements use `GREATEST(1, col) - 1` so an `UNSIGNED` column never underflows.

> **Note:** The `bn_posts.media_ids`, `bn_posts.link_meta`, and `bn_profile_fields.options` columns use the native MySQL `JSON` type. The minimum supported stack (MySQL 5.7+ / MariaDB 10.2+) is assumed.

## bn_posts

The core activity record. One row per post (text, media, link, poll, or share). Privacy and status are enforced at the column level, and the three engagement counts are denormalized.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT UNSIGNED | Author (WordPress user ID). |
| `space_id` | BIGINT UNSIGNED, nullable | Owning space, or NULL for a global post. |
| `shared_post_id` | BIGINT UNSIGNED, nullable | Original post this row re-shares, or NULL. |
| `type` | VARCHAR(32), default `text` | Post type discriminator (e.g. `text`, `media`, `link`, `poll`, `share`). |
| `content` | LONGTEXT, nullable | Post body. |
| `media_ids` | JSON, nullable | Attached media IDs. |
| `link_url` | VARCHAR(2083), nullable | Link-preview URL. |
| `link_meta` | JSON, nullable | Cached link-preview metadata (title, image, description). |
| `privacy` | ENUM(`public`,`followers`,`connections`,`space_members`,`private`), default `public` | Audience scope. |
| `status` | ENUM(`published`,`draft`,`pending`,`scheduled`,`deleted`), default `published` | Lifecycle state; `deleted` is a soft delete. |
| `reaction_count` | INT UNSIGNED, default 0 | Denormalized reaction total. Maintained on write. |
| `comment_count` | INT UNSIGNED, default 0 | Denormalized comment total. Maintained on write. |
| `share_count` | INT UNSIGNED, default 0 | Denormalized share total. Maintained on write. |
| `is_pinned` | TINYINT(1), default 0 | Pinned within its space/profile. |
| `is_announcement` | TINYINT(1), default 0 | Surfaced in the announcement feed. |
| `content_warning` | TINYINT(1), default 0 | Whether a content warning is shown. |
| `content_warning_type` | VARCHAR(32), nullable | Warning category label. |
| `site_pin_expires_at` | DATETIME, nullable | Expiry for a site-wide pin. |
| `edited_at` | DATETIME, nullable | Last edit time, NULL if never edited. |
| `scheduled_at` | DATETIME, nullable | Publish time for `scheduled` posts. |
| `last_activity_at` | DATETIME, nullable | Last reaction/comment/share, used for active-feed ordering. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Creation time. Cursor column. |
| `updated_at` | DATETIME, ON UPDATE CURRENT_TIMESTAMP | Auto-touched on any row update. |

Key indexes:

- `PRIMARY KEY (id)`
- `KEY user_feed (user_id, status, created_at)` - a member's own posts.
- `KEY space_feed (space_id, status, created_at)` - a space feed.
- `KEY announcement_feed (is_announcement, status, created_at)` - the announcement stream.
- `KEY explore (privacy, created_at)` - the discovery/explore stream.
- `KEY active_feed (privacy, status, last_activity_at)` - "recently active" ordering.
- `KEY scheduled (scheduled_at)` - the scheduled-publish worker.
- `KEY shared_post (shared_post_id)` - resolving and counting re-shares.

Relationships: `user_id` references a WordPress user; `space_id` references `bn_spaces.id`; `shared_post_id` is a self-reference to `bn_posts.id`. Reactions, comments, shares, bookmarks, and poll rows all reference a post.

## bn_comments

Threaded comments on posts (and other comment-able objects, keyed by `object_type` + `object_id`).

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT UNSIGNED | Author. |
| `object_type` | VARCHAR(32) | Commented object type (e.g. `post`). |
| `object_id` | BIGINT UNSIGNED | Commented object ID (e.g. a `bn_posts.id`). |
| `parent_id` | BIGINT UNSIGNED, nullable | Parent comment for replies, NULL for top-level. |
| `content` | TEXT | Comment body. |
| `is_edited` | TINYINT(1), default 0 | Edited flag. |
| `is_deleted` | TINYINT(1), default 0 | Soft-delete flag. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Creation time. |
| `updated_at` | DATETIME, ON UPDATE CURRENT_TIMESTAMP | Auto-touched on update. |

Key indexes:

- `PRIMARY KEY (id)`
- `KEY thread (object_type, object_id, parent_id, created_at)` - loads a full thread in order.
- `KEY user (user_id)` - a member's comment history.
- `KEY deleted (is_deleted)` - filtering out soft-deleted rows.

Relationships: `object_type` + `object_id` reference the commented object (a `bn_posts.id` when `object_type` is `post`); `parent_id` is a self-reference. Comment writes increment `bn_posts.comment_count`.

## bn_feed_items

Denormalized per-recipient feed cache. One row per (recipient, post) pair, holding a precomputed ranking score so a member's feed can be read without re-scoring on every request. This table backs the fan-out feed at large member counts; population is driven by Action Scheduler workers, not synchronously in the request.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `recipient_id` | BIGINT UNSIGNED | The member whose feed this row belongs to. |
| `post_id` | BIGINT UNSIGNED | The post placed in that feed. |
| `score` | FLOAT, default 0 | Ranking score for ordering. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Insertion time. Cursor column. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY recipient_post (recipient_id, post_id)` - one feed entry per post per recipient (idempotent fan-out).
- `KEY recipient_score (recipient_id, score, created_at)` - reads a recipient's ranked feed.

Relationships: `recipient_id` references a WordPress user; `post_id` references `bn_posts.id`. This is a cache - rows are derived from `bn_posts` and can be rebuilt.

## bn_reactions

Emoji reactions on a post or comment. The primary key enforces one reaction per user per object; changing reaction updates the `emoji` in place.

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | BIGINT UNSIGNED | Reacting member. Part of PK. |
| `object_type` | VARCHAR(32) | Reacted object type (e.g. `post`, `comment`). Part of PK. |
| `object_id` | BIGINT UNSIGNED | Reacted object ID. Part of PK. |
| `emoji` | VARCHAR(32), default `like` | Reaction key. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Reaction time. |

Key indexes:

- `PRIMARY KEY (user_id, object_type, object_id)` - one reaction per member per object.
- `KEY object_reactions (object_type, object_id)` - counting and listing reactions on an object.

Relationships: `object_type` + `object_id` reference the reacted object (a `bn_posts.id` when `object_type` is `post`). Reaction writes increment `bn_posts.reaction_count`.

## bn_shares

Records that a member re-shared a post (with optional quote text). The accompanying share post lives in `bn_posts` (with `shared_post_id` set); this table is the dedup/lookup record.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT UNSIGNED | Member who shared. |
| `post_id` | BIGINT UNSIGNED | Original post that was shared. |
| `content` | TEXT, nullable | Optional quote/commentary on the share. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Share time. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY user_post (user_id, post_id)` - one share record per member per post.
- `KEY post_shares (post_id)` - listing/counting shares of a post.

Relationships: `post_id` references the original `bn_posts.id`. Share writes increment `bn_posts.share_count`.

## bn_bookmarks

A member's saved posts. The composite primary key is the entire row, so a bookmark is idempotent.

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | BIGINT UNSIGNED | Member who bookmarked. Part of PK. |
| `post_id` | BIGINT UNSIGNED | Bookmarked post. Part of PK. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Bookmark time. |

Key indexes:

- `PRIMARY KEY (user_id, post_id)` - one bookmark per member per post; also serves the "is bookmarked" lookup.

Relationships: `post_id` references `bn_posts.id`.

## bn_poll_options

The options that belong to a poll post. Each option carries a denormalized `vote_count`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `post_id` | BIGINT UNSIGNED | Poll post this option belongs to. |
| `option_text` | VARCHAR(500) | Option label. |
| `display_order` | TINYINT UNSIGNED, default 0 | Order shown in the poll. |
| `vote_count` | INT UNSIGNED, default 0 | Denormalized vote total for this option. |
| `end_date` | DATETIME, nullable | Poll close time, if set. |

Key indexes:

- `PRIMARY KEY (id)`
- `KEY post_options (post_id, display_order)` - loads a poll's options in display order.

Relationships: `post_id` references the poll's `bn_posts.id`. Votes reference an option via `bn_poll_votes.option_id`.

## bn_poll_votes

One row per cast vote. The unique key enforces one vote per member per poll.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `post_id` | BIGINT UNSIGNED | Poll post voted on. |
| `option_id` | BIGINT UNSIGNED | Chosen option. |
| `user_id` | BIGINT UNSIGNED | Voting member. |
| `voted_at` | DATETIME, default CURRENT_TIMESTAMP | Vote time. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY one_vote_per_user (post_id, user_id)` - one vote per member per poll.
- `KEY option_votes (option_id)` - tallying votes for an option.

Relationships: `post_id` references the poll's `bn_posts.id`; `option_id` references `bn_poll_options.id`. Vote writes increment `bn_poll_options.vote_count`.

## bn_hashtags

The hashtag registry. One row per distinct tag, with denormalized `post_count` and `follower_count` aggregates.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `name` | VARCHAR(100) | Display name of the tag. |
| `slug` | VARCHAR(100) | Normalized lookup slug. Unique. |
| `post_count` | INT UNSIGNED, default 0 | Denormalized count of posts using this tag. |
| `follower_count` | INT UNSIGNED, default 0 | Denormalized count of followers of this tag. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | First-seen time. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)` - tag lookup and dedup.

Relationships: linked to posts through `bn_post_hashtags` and to followers through `bn_hashtag_follows`. The `post_count` / `follower_count` aggregates are kept in sync as those join rows are added and removed.

> **Note:** Trending hashtags are computed from this registry as a cached aggregate (lazy transient in `HashtagService::get_trending`), not by counting rows on read.

## bn_post_hashtags

Join table linking a post (or other taggable object) to a hashtag. The composite primary key prevents duplicate tagging.

| Column | Type | Notes |
|--------|------|-------|
| `post_id` | BIGINT UNSIGNED | Tagged object ID. Part of PK. |
| `object_type` | VARCHAR(32), default `post` | Tagged object type. Part of PK (so non-post objects can be tagged). |
| `hashtag_id` | BIGINT UNSIGNED | The tag. Part of PK. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Tagging time. Cursor column for the hashtag feed. |

Key indexes:

- `PRIMARY KEY (post_id, object_type, hashtag_id)` - one link per (object, tag).
- `KEY hashtag_feed (hashtag_id, created_at)` - the "posts for this hashtag" feed, newest first.

Relationships: `hashtag_id` references `bn_hashtags.id`; `post_id` + `object_type` reference the tagged object (a `bn_posts.id` when `object_type` is `post`). Adding a row increments `bn_hashtags.post_count`.

## bn_hashtag_follows

Which members follow which hashtags. Composite primary key makes a follow idempotent.

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | BIGINT UNSIGNED | Following member. Part of PK. |
| `hashtag_id` | BIGINT UNSIGNED | Followed tag. Part of PK. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Follow time. |

Key indexes:

- `PRIMARY KEY (user_id, hashtag_id)` - one follow per member per tag; serves the "is following" lookup.
- `KEY hashtag (hashtag_id)` - listing/counting a tag's followers.

Relationships: `hashtag_id` references `bn_hashtags.id`; `user_id` references a WordPress user. Adding a row increments `bn_hashtags.follower_count`.

## Notes / gotchas

- **Counters must be maintained, never recomputed on read.** Any code path that inserts or deletes a reaction, comment, share, poll vote, or hashtag link must adjust the matching denormalized counter (`bn_posts.*_count`, `bn_poll_options.vote_count`, `bn_hashtags.post_count` / `follower_count`). A periodic recount job (`buddynext_recount_stats`, daily) reconciles drift.
- **Soft deletes.** `bn_posts.status = 'deleted'` and `bn_comments.is_deleted = 1` keep rows for moderation/audit. Queries must filter these out; the `deleted` index on `bn_comments` exists for that.
- **`object_type` is the polymorphism seam.** `bn_reactions`, `bn_comments`, and `bn_post_hashtags` all key off `object_type` + `object_id` so the same machinery can target posts today and other object types later. Today the live value is `post` (and `comment` for reactions).
- **`bn_feed_items` is a cache.** Treat it as derivable from `bn_posts`; it can be truncated and rebuilt by the feed fan-out workers.
- See also the Schema: Spaces page for `bn_spaces` and the membership tables that `bn_posts.space_id` references.
