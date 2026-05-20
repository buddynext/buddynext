# Journey: Activity Feed

**Free feature**: `includes/Feed/` (PostService, FeedService, PollService, BookmarkService, ShareService), `includes/Reactions/ReactionService`, `includes/Comments/CommentService`
**Actions / filters fired**: `buddynext_post_created`, `buddynext_post_deleted`, `buddynext_reaction_added`, `buddynext_reaction_removed`, `buddynext_comment_created`, `buddynext_comment_deleted`, `buddynext_post_bookmarked`, `buddynext_post_unbookmarked`, `buddynext_post_shared`, `buddynext_poll_voted`, `buddynext_reaction_types` (filter)
**DB tables touched**: `bn_posts`, `bn_poll_options`, `bn_poll_votes`, `bn_reactions`, `bn_comments`, `bn_shares`, `bn_bookmarks`
**Estimated time**: 12 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: 1 open space seeded (record its `id` — referred to as `SPACE_ID` below)
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Create a text post

1. Log in as `member1`. Create a text post in the seeded space:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Hello from member1! #test", "space_id": SPACE_ID, "privacy": "public"}'
   ```

   - Expected: 201 response. `id` field in response is the new post ID (referred to as `POST_ID` below). `type = text`.

2. Verify the post row:

   ```sql
   SELECT id, user_id, space_id, type, content, status, privacy, created_at
   FROM wp_bn_posts
   WHERE id = POST_ID;
   ```

   - Expected: 1 row, `status = published`, `privacy = public`.

### Part 2: Create a poll post

3. Create a poll post as `member1`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "content": "What is your favourite season?",
       "type": "poll",
       "space_id": SPACE_ID,
       "privacy": "public",
       "poll_options": ["Spring", "Summer", "Autumn", "Winter"]
     }'
   ```

   - Expected: 201 response. Note the returned post `id` (referred to as `POLL_POST_ID`).

4. Verify poll options were created:

   ```sql
   SELECT id, post_id, option_text, display_order, vote_count
   FROM wp_bn_poll_options
   WHERE post_id = POLL_POST_ID
   ORDER BY display_order;
   ```

   - Expected: 4 rows, `vote_count = 0` on all.

5. As `member2`, vote on the poll:

   ```bash
   # Get option ID for "Summer":
   wp db query "SELECT id FROM wp_bn_poll_options WHERE post_id = POLL_POST_ID AND option_text = 'Summer';"

   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts/POLL_POST_ID/vote \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"option_id": OPTION_ID}'
   ```

   - Expected: 200. `vote_count` incremented to 1 on that option.

6. Verify the vote row:

   ```sql
   SELECT post_id, option_id, user_id, voted_at
   FROM wp_bn_poll_votes
   WHERE post_id = POLL_POST_ID AND user_id = MEMBER2_ID;
   ```

   - Expected: 1 row.

### Part 3: Create a link post

7. Create a link post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "content": "Check out this article",
       "type": "link",
       "link_url": "https://example.com/article",
       "space_id": SPACE_ID,
       "privacy": "public"
     }'
   ```

   - Expected: 201. `type = link`, `link_url` stored in the post row.

### Part 4: React with emoji

8. As `member2`, react to the text post with a "love" emoji:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"object_type": "post", "object_id": POST_ID, "emoji": "love"}'
   ```

   - Expected: 200. Row inserted into `wp_bn_reactions`. `reaction_count` on the post incremented.

9. Verify the reaction row:

   ```sql
   SELECT user_id, object_type, object_id, emoji, created_at
   FROM wp_bn_reactions
   WHERE object_type = 'post' AND object_id = POST_ID;
   ```

   - Expected: 1 row, `emoji = love`.

10. Toggle the reaction off (same request removes it):

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"object_type": "post", "object_id": POST_ID, "emoji": "love"}'
    ```

    - Expected: 200. Row removed from `wp_bn_reactions`. `reaction_count` decremented.

### Part 5: Comment on a post

11. As `member2`, comment on the text post:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/comments \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"object_type": "post", "object_id": POST_ID, "content": "Great post!"}'
    ```

    - Expected: 201. Row inserted into `wp_bn_comments`. `comment_count` on the post incremented. Note the returned `id` (referred to as `COMMENT_ID`).

12. Verify the comment row:

    ```sql
    SELECT id, user_id, object_type, object_id, content, is_deleted, created_at
    FROM wp_bn_comments
    WHERE id = COMMENT_ID;
    ```

    - Expected: 1 row, `is_deleted = 0`.

### Part 6: Share a post

13. As `member2`, share the text post:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts/POST_ID/share \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"content": "Sharing this!"}'
    ```

    - Expected: 201. Row inserted into `wp_bn_shares`. `share_count` on the original post incremented.

14. Verify the share row:

    ```sql
    SELECT id, user_id, post_id, content, created_at
    FROM wp_bn_shares
    WHERE post_id = POST_ID AND user_id = MEMBER2_ID;
    ```

    - Expected: 1 row.

### Part 7: Bookmark a post

15. As `member2`, bookmark the text post:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts/POST_ID/bookmark \
      -u member2:password -H "Content-Type: application/json"
    ```

    - Expected: 200 with `{"bookmarked": true}`. Row inserted into `wp_bn_bookmarks`.

16. Verify the bookmark:

    ```sql
    SELECT user_id, post_id, created_at
    FROM wp_bn_bookmarks
    WHERE user_id = MEMBER2_ID AND post_id = POST_ID;
    ```

    - Expected: 1 row.

## Edge cases to also verify

- **Invalid emoji**: React with an emoji slug not in `buddynext_reaction_types` (e.g. `"emoji": "custom"`). Expected: 422 — validation rejects unknown types. Call `GET /buddynext/v1/reactions` first to confirm the allowed list.
- **Duplicate poll vote**: Vote twice on the same poll. Expected: second vote returns error — UNIQUE KEY `one_vote_per_user` on `bn_poll_votes` prevents duplicates.
- **Delete comment**: `DELETE /buddynext/v1/comments/COMMENT_ID` as comment author. Expected: 200. `is_deleted = 1` set in `bn_comments`, `comment_count` on post decremented. Row is soft-deleted, not physically removed.
- **Anonymous feed**: `GET /buddynext/v1/feed/explore` without credentials. Expected: 200 with public posts; no private or space-members-only posts included.
- **Home feed empty state**: Log in as a fresh user with no follows. `GET /buddynext/v1/feed/home`. Expected: 200 with empty or curated explore-style items — not a 404.

## What this validates

- `PostService::create()` inserts into `bn_posts` and fires `buddynext_post_created(int $post_id, int $user_id, string $type)`.
- `PollService` inserts `bn_poll_options` rows on poll post creation and records votes in `bn_poll_votes` with UNIQUE constraint.
- `ReactionService::toggle()` inserts / deletes `bn_reactions` row and adjusts `bn_posts.reaction_count` via `CounterService`.
- `CommentService::create()` inserts into `bn_comments` and fires `buddynext_comment_created`.
- `ShareService::share()` inserts into `bn_shares` and adjusts `bn_posts.share_count`.
- `BookmarkService::toggle()` inserts / deletes `bn_bookmarks` row and fires `buddynext_post_bookmarked` / `buddynext_post_unbookmarked`.

## Verification queries

```sql
-- Most recent posts for the space:
SELECT id, user_id, type, status, reaction_count, comment_count, share_count, created_at
FROM wp_bn_posts
WHERE space_id = SPACE_ID
ORDER BY created_at DESC
LIMIT 10;

-- Poll options for the poll post:
SELECT id, option_text, display_order, vote_count
FROM wp_bn_poll_options
WHERE post_id = POLL_POST_ID;

-- Reactions on the text post:
SELECT user_id, emoji, created_at
FROM wp_bn_reactions
WHERE object_type = 'post' AND object_id = POST_ID;

-- Comments on the text post:
SELECT id, user_id, content, is_deleted, created_at
FROM wp_bn_comments
WHERE object_type = 'post' AND object_id = POST_ID;

-- Shares of the text post:
SELECT id, user_id, content, created_at
FROM wp_bn_shares
WHERE post_id = POST_ID;

-- Bookmarks on the text post:
SELECT user_id, created_at
FROM wp_bn_bookmarks
WHERE post_id = POST_ID;
```

## REST surface walked

```
POST /buddynext/v1/posts                             -- 201, created post object
GET  /buddynext/v1/posts/{id}                        -- 200, single post
GET  /buddynext/v1/feed/home                         -- 200, paginated home feed (logged-in)
GET  /buddynext/v1/feed/explore                      -- 200, paginated explore feed (public)
GET  /buddynext/v1/spaces/{id}/feed                  -- 200, paginated space feed (public)
POST /buddynext/v1/posts/{id}/vote                   -- 200, poll vote response
GET  /buddynext/v1/posts/{id}/poll                   -- 200, poll data
GET  /buddynext/v1/posts/{id}/my-vote                -- 200, current user's vote
GET  /buddynext/v1/reactions                         -- 200, array of allowed reaction types
POST /buddynext/v1/reactions/toggle                  -- 200, { "active": bool }
POST /buddynext/v1/comments                          -- 201, created comment object
DELETE /buddynext/v1/comments/{id}                   -- 200, { "deleted": true }
POST /buddynext/v1/posts/{id}/share                  -- 201, share object
GET  /buddynext/v1/me/shares                         -- 200, array of shares
POST /buddynext/v1/posts/{id}/bookmark               -- 200, { "bookmarked": bool }
GET  /buddynext/v1/me/bookmarks                      -- 200, array of bookmarked posts
```

## Cleanup

```sql
-- Remove test posts (cascades are not enforced at DB level; clean child tables first):
DELETE FROM wp_bn_poll_votes WHERE post_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_poll_options WHERE post_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_reactions WHERE object_type = 'post' AND object_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_comments WHERE object_type = 'post' AND object_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_shares WHERE post_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_bookmarks WHERE post_id IN (SELECT id FROM wp_bn_posts WHERE user_id = MEMBER1_ID);
DELETE FROM wp_bn_posts WHERE user_id = MEMBER1_ID AND space_id = SPACE_ID;
```

## Known limitations

- `link_meta` (Open Graph data) is populated asynchronously; in this journey it may be `null` at creation time.
- Poll vote toggling (changing a vote) is not exposed as a distinct endpoint; the current schema enforces one vote per user per poll.

## Automation notes

- All REST calls are curl-automatable with basic auth.
- Poll creation requires `"type": "poll"` in the request body alongside a `poll_options` array.
- The bookmark toggle pattern returns `{"bookmarked": bool}` — assert the value, not just the HTTP status.
