# BuddyNext — Hashtags

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Hashtags connect content across the platform. Any post, media item, or discussion tagged with `#photography` belongs to that hashtag's feed. Users can follow hashtags — posts from followed hashtags appear in the home feed alongside followed users.

---

## How They Work

- Written inline in post content: `#photography`, `#opentowork`, `#buildinpublic`
- Auto-extracted from content on post save (async via Action Scheduler — doesn't block save)
- Case-insensitive, normalized to lowercase on extraction (`#Photography` = `#photography`)
- No admin pre-registration needed — hashtags are created automatically on first use
- Admin can: rename, merge, ban (banned hashtags are stripped on extraction)

---

## Hashtag Feed

Each hashtag has its own public feed page at `/hashtag/{slug}/`:
- All public posts tagged with that hashtag, chronological
- Follow button on the hashtag page
- Post count, follower count shown
- Privacy: only public posts appear in hashtag feeds — followers/connections/private posts are never surfaced here

---

## Following Hashtags

- Users follow hashtags the same way they follow people
- Followed hashtag posts appear in home feed mixed with followed-user posts
- Hashtag follow count shown on hashtag page
- "Hashtags you follow" list on user profile (optional, controlled by user)

---

## Trending Hashtags

- Trending = post count within a rolling 24h window, weighted by unique users (prevents spam)
- Computed async by a cron job every 30 minutes — never calculated per request
- Trending list shown on Explore page + Trending Hashtags block
- Site-wide trending (not personalised in v1 — AI personalisation is Pro)
- Admin can pin or ban hashtags from trending list

---

## Cross-Plugin Support

| Content type | Hashtag support |
|-------------|----------------|
| Feed posts (text, link, poll) | Yes — extracted from content |
| WPMediaVerse media | Yes — extracted from media title + description via bridge |
| Jetonomy discussions | Yes — extracted from discussion title + body via bridge |
| Career Board jobs | Yes — extracted from job description via bridge (e.g. `#hiring`, `#remote`) |
| Comments | No — too noisy, hashtags in comments are not extracted |

**Who fires `buddynext_index_hashtags`:** BuddyNext's own bridge code (`includes/Bridges/`) is responsible. Each bridge hooks the addon's native action and then calls `do_action( 'buddynext_index_hashtags', $object_type, $object_id, $content )`. BuddyNext core handles extraction and association — addon plugins themselves do NOT need to know about this hook.

```
mvs_media_uploaded  → WPMediaVerse bridge → buddynext_index_hashtags( 'mvs_media', $id, $title . ' ' . $description )
jetonomy_discussion_created → Jetonomy bridge → buddynext_index_hashtags( 'jt_discussion', $id, $title . ' ' . $body )
wp_cb_job_published → Career Board bridge → buddynext_index_hashtags( 'job', $id, $description )
bn_posts (save)     → BuddyNext core     → buddynext_index_hashtags( 'post', $id, $content )
```

---

## Autocomplete

When a user types `#` in the post composer:
- Dropdown shows matching hashtags (searched against `bn_hashtags.slug`)
- Shows: hashtag name + post count
- Top 5 suggestions, instant (debounced REST call)
- If no match exists: "Create #newtag" option shown

---

## Data Stored

```
bn_hashtags        -- id, slug, post_count (cached), follower_count, is_banned, created_at
bn_post_hashtags   -- post_id, object_type, hashtag_id, created_at  (pivot)
bn_hashtag_follows -- user_id, hashtag_id, created_at
```

`post_count` and `follower_count` are cached counters, updated async — not computed on every query.

Hashtags are also indexed in `bn_search_index` (type: `hashtag`) so they appear in unified search results.

---

## Scale Notes

- Extraction is always async (Action Scheduler) — post saves never blocked
- Trending computed by cron (30-min interval) — never per-request
- `bn_post_hashtags` indexed on `(hashtag_id, created_at)` for fast feed queries
- At 100K posts, a hashtag feed query is a single indexed lookup — no full scans
- `bn_hashtags.slug` has a UNIQUE index — dedup handled at DB level

---

## Addon Behavior

### WPMediaVerse
Bridge extracts hashtags from media title + description on `mvs_media_uploaded`. Associates via `bn_post_hashtags` with `object_type = 'mvs_media'`.

### Jetonomy
Bridge extracts hashtags from discussion title + body on `jetonomy_discussion_created`. Associates via `bn_post_hashtags` with `object_type = 'jt_discussion'`.

### Career Board
Bridge extracts hashtags from job description on `wp_cb_job_published`. Associates via `bn_post_hashtags` with `object_type = 'job'`.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Followed hashtag posts appear in home feed |
| Search | Hashtags indexed in `bn_search_index`, appear in unified search |
| Explore page | Trending hashtags list |
| Gutenberg Blocks | Trending Hashtags block — displays current trending list |
| Notifications | No notifications for hashtags in v1 (v2: notify when followed hashtag gets X posts) |
| Moderation | Admin can ban hashtags — banned tags stripped from all future posts |

---

## Gaps / Open Questions

- None — fully locked
