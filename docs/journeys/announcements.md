# Journey: Announcements

**Free feature**: `includes/Feed/` (PostService announcement type, FeedService site-wide pin + dismissal). Registered as `announcements` in `FeatureRegistry` (tier: default-on, group `community`, depends on `feed`).
**Actions / filters fired**: `buddynext_post_created` (fires with `$type = 'announcement'`), `buddynext_post_before_save` (filter), `buddynext_user_mentioned` (if the announcement body @mentions someone)
**DB tables / columns touched**: `bn_posts` (`type = 'announcement'`, `is_announcement = 1`, `site_pin_expires_at`), `wp_usermeta` (`bn_dismissed_announcements` per-user dismissal list)
**Estimated time**: 8 min manual

## Site-owner expectation

A community owner expects **announcements** to be the one message they can push to the top of *every* member's feed — a banner-style post that surfaces above all normal activity until each member dismisses it (or it expires). Out of the box:

- **Only site administrators** (`manage_options`) can create an announcement. Space owners, space moderators, and ordinary members cannot. There is no per-space announcement and no delegated "announcer" capability.
- An announcement is a normal feed post with `type = 'announcement'` / `is_announcement = 1`. It is automatically **prepended to the first page of every member's home feed** (`GET /feed/home`), regardless of who they follow.
- A member can **dismiss** an announcement; once dismissed it never reappears in their feed (tracked in their user_meta). Dismissal is per-user — it does not delete the announcement for anyone else.
- The owner expects to be able to set an expiry so the announcement self-retires. The expiry column exists and is honoured on read, but there is **no create/REST path that sets it** (see Known limitations) — out of the box an announcement stays pinned until each member dismisses it.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- `announcements` feature enabled (default-on; it depends on `feed`)
- Admin user `admin` / `password` (or autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`
- No pre-existing announcements required; this journey creates one
- Collect the member IDs you will reference:

  ```bash
  wp user get member1 --field=ID   # → MEMBER1_ID
  wp user get member2 --field=ID   # → MEMBER2_ID
  ```

## Happy-path steps

### Part 1: Admin creates an announcement

1. As `admin`, create an announcement post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{
       "type": "announcement",
       "content": "Journey announcement: scheduled maintenance Sunday 02:00 UTC.",
       "privacy": "public"
     }'
   ```

   - Expected: 201 response with the created post object. Note the returned `id` (referred to as `ANNOUNCEMENT_ID`). The response includes `"type": "announcement"` and `"is_announcement": 1`.

2. Verify the row was written with the announcement flag:

   ```sql
   SELECT id, user_id, type, is_announcement, status, site_pin_expires_at, created_at
   FROM wp_bn_posts
   WHERE id = ANNOUNCEMENT_ID;
   ```

   - Expected: 1 row, `type = announcement`, `is_announcement = 1`, `status = published`, `site_pin_expires_at = NULL`.

### Part 2: Announcement surfaces at the top of a member's feed

3. As `member1`, fetch the home feed (first page, no cursor):

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/feed/home \
     -u member1:password
   ```

   - Expected: 200. The **first** item in `items[]` is the announcement (`id = ANNOUNCEMENT_ID`, `type = announcement`, `is_announcement = 1`) — it is prepended ahead of normal feed activity, even though `member1` does not follow `admin`. `FeedService::home_feed()` only prepends it on the first page (when `cursor` is null).

4. As `member2`, fetch the home feed:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/feed/home \
     -u member2:password
   ```

   - Expected: 200. Same announcement prepended for `member2`. Confirms the announcement is site-wide, not follow-scoped.

### Part 3: Member dismisses the announcement

5. As `member1`, dismiss the announcement:

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" -X POST \
     http://buddynext-dev.local/wp-json/buddynext/v1/feed/announcements/ANNOUNCEMENT_ID/dismiss \
     -u member1:password
   ```

   - Expected: `204` (no content). The post ID is appended to `member1`'s `bn_dismissed_announcements` user_meta.

6. Verify the dismissal is recorded:

   ```bash
   wp user meta get MEMBER1_ID bn_dismissed_announcements
   ```

   - Expected: a serialized PHP array containing `ANNOUNCEMENT_ID`.

7. As `member1`, re-fetch the home feed:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/feed/home \
     -u member1:password
   ```

   - Expected: 200. The announcement is **no longer** the first item — `active_announcement()` excludes IDs in the user's dismissal list. `member2`'s feed (step 4) still shows it, confirming dismissal is per-user.

8. Dismiss again as `member1` (idempotency check):

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" -X POST \
     http://buddynext-dev.local/wp-json/buddynext/v1/feed/announcements/ANNOUNCEMENT_ID/dismiss \
     -u member1:password
   ```

   - Expected: `204` again. The user_meta array is de-duplicated (`array_unique`), so the ID appears only once.

## Edge cases to also verify

- **Non-privileged user cannot create an announcement**: As `member1`, attempt the create call from step 1 (`type: "announcement"`). Expected: `403` with error code `forbidden` and message "Only administrators can create announcements." `PostService::create()` gates on `user_can( $user_id, 'manage_options' )` before any DB write — no row is inserted.

  ```bash
  curl -s -o /dev/null -w "%{http_code}\n" -X POST \
    http://buddynext-dev.local/wp-json/buddynext/v1/posts \
    -u member1:password -H "Content-Type: application/json" \
    -d '{"type":"announcement","content":"Should be rejected."}'
  ```

- **Dismissing a non-announcement post 404s**: Take any normal post ID (`type != announcement`) and POST to the dismiss route. Expected: `404` `not_found` ("Announcement not found.") — the controller verifies `is_announcement = 1 AND type = 'announcement'` before recording a dismissal.

- **Only the newest announcement is pinned**: As `admin`, create a second announcement. As a fresh member (one who has dismissed neither), fetch `/feed/home`. Expected: only the **most recent** announcement is prepended — `active_announcement()` orders by `created_at DESC LIMIT 1`. Older announcements remain in the feed as ordinary posts but are not site-pinned.

- **Expiry is honoured on read (manual set)**: Set `site_pin_expires_at` to a past timestamp directly, then fetch `/feed/home` as a member who has not dismissed it. Expected: the announcement is no longer prepended — the query filters `site_pin_expires_at IS NULL OR site_pin_expires_at > NOW()`. (Note: no REST/create path sets this column; see Known limitations.)

  ```sql
  UPDATE wp_bn_posts SET site_pin_expires_at = '2000-01-01 00:00:00' WHERE id = ANNOUNCEMENT_ID;
  ```

## What this validates

- `PostService::create()` accepts `type = 'announcement'`, sets `is_announcement = 1`, and fires `buddynext_post_created( $post_id, $user_id, 'announcement' )`.
- The `manage_options` capability gate in `PostService::create()` blocks non-admins from creating announcements (403, no DB write).
- `FeedService::home_feed()` prepends `active_announcement()` on the first page (cursor === null) for every authenticated viewer, independent of the follow graph.
- `FeedService::active_announcement()` selects the newest published, unexpired announcement not in the viewer's dismissal list.
- `FeedController::dismiss_announcement()` validates the post is a real announcement, then calls `FeedService::dismiss_announcement()` to persist a per-user `bn_dismissed_announcements` user_meta entry (idempotent, de-duplicated).
- `PostService::hydrate()` surfaces `is_announcement` and `site_pin_expires_at` on the post object returned by REST.

## Verification queries

```sql
-- All announcements created in this journey:
SELECT id, user_id, type, is_announcement, status, site_pin_expires_at, created_at
FROM wp_bn_posts
WHERE is_announcement = 1
ORDER BY created_at DESC;

-- Confirm the announcement is published and currently active (unexpired):
SELECT id, content, site_pin_expires_at
FROM wp_bn_posts
WHERE is_announcement = 1
  AND type = 'announcement'
  AND status = 'published'
  AND (site_pin_expires_at IS NULL OR site_pin_expires_at > NOW())
ORDER BY created_at DESC
LIMIT 1;

-- Per-user dismissals (user_meta):
SELECT user_id, meta_value
FROM wp_usermeta
WHERE meta_key = 'bn_dismissed_announcements';
```

## REST surface walked

```
POST   /buddynext/v1/posts                                  -- 201, created post (type=announcement); 403 for non-admins
GET    /buddynext/v1/posts/{id}                             -- 200, single post (is_announcement=1 in payload)
GET    /buddynext/v1/feed/home                              -- 200, announcement prepended to items[] on first page
POST   /buddynext/v1/feed/announcements/{id}/dismiss        -- 204, per-user dismissal; 404 if not an announcement
```

There is no dedicated "create announcement" endpoint — announcements are created through the standard `POST /posts` route by passing `type: "announcement"`. There is no list-announcements endpoint; announcements are discovered only via the home-feed prepend.

## Cleanup

```bash
# Clear the per-user dismissal meta written during the journey:
wp user meta delete MEMBER1_ID bn_dismissed_announcements
wp user meta delete MEMBER2_ID bn_dismissed_announcements
```

```sql
-- Remove the announcement post(s) created in this journey:
DELETE FROM wp_bn_posts
WHERE is_announcement = 1
  AND content LIKE 'Journey announcement:%';
```

## Known limitations

- **No expiry on the create path.** `site_pin_expires_at` is read (the active-announcement query filters on it) and hydrated onto the post object, but neither `PostService::create()` nor the `POST /posts` request maps any param to it. Out of the box an announcement is pinned forever (until each member dismisses it). Setting an expiry requires a direct SQL `UPDATE`.
- **No admin UI / dedicated endpoint.** Announcements are created by hand-posting `type: "announcement"` to `POST /posts`. There is no admin screen, no "Announcements" management list, and no REST route to list/edit/un-pin an announcement as an announcement.
- **No `unpin`/`recall` for announcements.** A normal post has `POST /posts/{id}/pin` + `DELETE /posts/{id}/pin` (the `is_pinned` column), but that is a *separate* mechanism from `is_announcement`. To stop pinning an announcement site-wide, an admin must delete the post or set `site_pin_expires_at` to a past time via SQL.
- **Capability is hard-coded to `manage_options`.** There is no filter to delegate announcement creation to space moderators or a custom role; the gate is a literal `user_can( $user_id, 'manage_options' )` check.
- **No dedicated announcement action hook.** The only signal that an announcement was created is `buddynext_post_created` firing with `$type = 'announcement'`; listeners must inspect the type argument to distinguish announcements from ordinary posts.
- **Single active announcement.** `active_announcement()` returns `LIMIT 1` (newest). If multiple announcements are live, only the most recent is site-pinned; older ones silently drop to ordinary feed posts.

## Automation notes

- All steps are curl-automatable with basic auth; capture `ANNOUNCEMENT_ID` from the create response — do not hardcode it.
- The 403 non-admin edge case is the core capability assertion; assert on both the status code and the `forbidden` error code.
- The dismiss route returns `204` (no body) — assert on status code, not response shape.
- Verify the per-user nature of dismissal by checking that `member2`'s feed still shows the announcement after `member1` dismisses it (do not rely solely on the user_meta row).
- The expiry edge case requires a direct SQL `UPDATE`; gate it behind a flag in scripted runs since there is no API to set or reset `site_pin_expires_at`.
```