# BuddyNext — Activity Feed

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

The central social stream. Aggregates posts, activity items, and bridge content (media, discussions, jobs) into a unified timeline. Scoped per context.

---

## Feed Scopes

| Scope | What it shows | Login required |
|-------|--------------|----------------|
| Home | Posts from followed users + joined spaces + followed hashtags | Yes |
| Profile | Single user's posts | No (public posts) |
| Space | Posts within a specific space | Depends on space type |
| Explore | All public posts — discovery | No — indexed by Google |

---

## Post Types

| Type | Notes | Tables |
|------|-------|--------|
| `text` | Rich text, @mentions, #hashtags | `bn_posts` |
| `photo` | 1–4 images, 2×2 grid. Upload handled by WPMediaVerse — `bn_posts.media_ids` stores refs | `bn_posts` (media_ids JSON) |
| `file` | Any file type. Upload handled by WPMediaVerse — `bn_posts.media_ids` stores refs | `bn_posts` (media_ids JSON) |
| `link` | URL auto-unfurl + oEmbed inline playback (YouTube, Vimeo, Twitter/X, Spotify etc.) | `bn_posts` (link_url + link_meta JSON) |
| `poll` | 2–5 options, vote inline, optional end date | `bn_posts` + `bn_poll_options` + `bn_poll_votes` |
| `announcement` | Admin-pinned site-wide post, dismissable | `bn_posts` (type + site_pin flag) |
| `activity` | System-generated: "X followed Y", "X joined Space" | `bn_posts` |
| `media` | WPMediaVerse rich media (video, album, transcoding) — bridge only | `bn_posts` (media_ids JSON ref to MVS) |
| `discussion` | Jetonomy bridge — discussion card | `bn_posts` (ref to Jetonomy) |
| `job` | Career Board bridge — job listing card | `bn_posts` (ref to Career Board) |

**All uploads (photo, file, video) route through WPMediaVerse.** BuddyNext stores only the resulting media IDs in `bn_posts.media_ids` (JSON). No BuddyNext file storage layer.
Bridge types only appear when the respective plugin is active.

---

## Post Privacy

public / followers / connections / space_members / private

---

## Post Features

**Free:**
- React, comment, share, bookmark (private), report
- Edit with "edited" timestamp
- Pin 1 post to profile or space feed
- Delete own posts

**Pro:**
- Schedule (future publish datetime) — `scheduled_at` column on `bn_posts`
- Multiple pinned posts (up to 10 per space or profile)
- Post reach stats (impressions, engagement rate per post)

---

## Admin Announcements

Admins can pin a post to the top of the home feed for all users — site-wide announcements ("Server maintenance tonight", "New feature launched", "Community guidelines updated").

- Pinned at the top of every user's home feed regardless of who they follow
- Shown as a distinct "Announcement" card with a badge — visually separate from regular posts
- Admin sets expiry date (auto-unpins after) or unpins manually
- Max 1 active announcement at a time — keeps the feed clean
- Dismissable by the user (once dismissed, doesn't show again for that user)
- Stored as a regular `bn_posts` row with `type = 'announcement'` and a site-wide pin flag

---

## Feed Behavior

- Free ordering: chronological, connections-first weighting
- Pro ordering: AI ranking by engagement + relationship strength
- Pagination: cursor-based (stable for real-time)
- "X new posts" bar at top — click to load, no auto-scroll hijack
- Infinite scroll

---

## Addon Behavior

### WPMediaVerse
- Standalone: own `mvs_activity` feed
- BuddyNext mode: media uploaded → bridge creates `bn_posts` row (type: `media`). No `mvs_activity` row written.

### Jetonomy
- Standalone: own discussion feed
- BuddyNext mode: discussion created → bridge creates `bn_posts` row (type: `discussion`)

### Career Board
- BuddyNext mode: job posted → bridge creates `bn_posts` row (type: `job`)

### WBGamification
Points awarded via bridge on `buddynext_post_created`. No feed changes.

---

## Data Stored

| Table | Schema highlights |
|-------|------------------|
| `bn_posts` | id, user_id, space_id, type, content, privacy, media_ids (JSON — WPMediaVerse refs), link_url, link_meta (JSON), is_pinned, is_announcement, site_pin_expires_at, scheduled_at, edited_at, reaction_count, comment_count, share_count, created_at |
| `bn_poll_options` | id, post_id, option_text, display_order, vote_count |
| `bn_poll_votes` | id, post_id, option_id, user_id, voted_at — UNIQUE(post_id, user_id) |
| `bn_bookmarks` | id, user_id, post_id, created_at — private saved posts |
| `bn_shares` | id, user_id, post_id, note, created_at — reposts with optional note |

Reactions and comments in shared tables (see `08-reactions-comments.md`).

---

## Integration Points

| Feature | How feed uses it |
|---------|-----------------|
| Social Graph | Follow + connection status → home feed query + privacy filter |
| Spaces | space_id scoping |
| WPMediaVerse | Media cards via bridge |
| Jetonomy | Discussion cards via bridge |
| Career Board | Job cards via bridge |
| WBGamification | Points on post_created |
| Search | Posts indexed async in `bn_search_index` |
| Notifications | Fires on mention, comment, reaction |

---

## Gaps / Open Questions

- None — fully locked
