# Journey: Hashtags

**Free feature**: `includes/Hashtags/` (HashtagService, HashtagController), `includes/Search/SearchIndexListener` (HashtagListener is inline in SearchIndexListener)
**Actions / filters fired**: `buddynext_post_created` (trigger for hashtag indexing), `buddynext_index_hashtags`, `buddynext_hashtag_related_discussions` (filter)
**DB tables touched**: `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`
**Estimated time**: 8 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: 1 open space seeded (record its `id` as `SPACE_ID`)
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Create a post with hashtags

1. As `member1`, create a text post containing hashtags:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "content": "Excited to join this community! #wordpress #buddynext #opensource",
       "space_id": SPACE_ID,
       "privacy": "public"
     }'
   ```

   - Expected: 201. Note the returned `id` (referred to as `POST_ID`). Hashtag extraction fires inline or via Action Scheduler.

2. Verify the hashtags were auto-created in `bn_hashtags`:

   ```sql
   SELECT id, name, slug, post_count, created_at
   FROM wp_bn_hashtags
   WHERE slug IN ('wordpress', 'buddynext', 'opensource');
   ```

   - Expected: 3 rows. `post_count` may be 0 or 1 depending on whether the counter has been incremented synchronously.

3. Verify the join-table rows linking the post to the hashtags:

   ```sql
   SELECT ph.post_id, ph.object_type, h.slug, ph.created_at
   FROM wp_bn_post_hashtags ph
   INNER JOIN wp_bn_hashtags h ON h.id = ph.hashtag_id
   WHERE ph.post_id = POST_ID;
   ```

   - Expected: 3 rows, all with `object_type = post`.

### Part 2: Create more posts to drive trending

4. Create 4 more posts from `member1` and `member2` that use `#buddynext` (to boost its trending score):

   ```bash
   for i in 1 2; do
     curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
       -u member1:password \
       -H "Content-Type: application/json" \
       -d "{\"content\": \"Post $i about #buddynext\", \"space_id\": SPACE_ID, \"privacy\": \"public\"}"
   done

   for i in 1 2; do
     curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
       -u member2:password \
       -H "Content-Type: application/json" \
       -d "{\"content\": \"Member2 post $i about #buddynext\", \"space_id\": SPACE_ID, \"privacy\": \"public\"}"
   done
   ```

5. Verify `post_count` incremented on `#buddynext`:

   ```sql
   SELECT slug, post_count, follower_count
   FROM wp_bn_hashtags
   WHERE slug = 'buddynext';
   ```

   - Expected: `post_count >= 5`.

### Part 3: Follow a hashtag

6. As `member2`, follow the `#buddynext` hashtag:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/hashtags/buddynext/follow \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 with `{"following": true}`. Row inserted into `wp_bn_hashtag_follows`. `follower_count` incremented on `bn_hashtags`.

7. Verify the follow row:

   ```sql
   SELECT user_id, hashtag_id, created_at
   FROM wp_bn_hashtag_follows
   WHERE user_id = MEMBER2_ID
     AND hashtag_id = (SELECT id FROM wp_bn_hashtags WHERE slug = 'buddynext');
   ```

   - Expected: 1 row.

8. Verify `follower_count` incremented:

   ```sql
   SELECT slug, post_count, follower_count
   FROM wp_bn_hashtags
   WHERE slug = 'buddynext';
   ```

   - Expected: `follower_count = 1`.

9. Unfollow the hashtag (toggle):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/hashtags/buddynext/follow \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 with `{"following": false}`. Row removed from `wp_bn_hashtag_follows`.

10. Re-follow for subsequent steps:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/hashtags/buddynext/follow \
      -u member2:password -H "Content-Type: application/json"
    ```

### Part 4: Trending list

11. Retrieve the trending hashtag list:

    ```bash
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/hashtags/trending
    ```

    - Expected: 200, ordered array. `#buddynext` should appear near the top given it has the highest `post_count` from this journey.

12. List all hashtags (with optional search):

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/hashtags?q=buddy"
    ```

    - Expected: 200, results filtered to hashtags matching `buddy`.

### Part 5: Hashtag feed

13. Retrieve posts tagged with `#buddynext`:

    ```bash
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/hashtags/buddynext
    ```

    - Expected: 200. Response includes `hashtag` object (id, name, slug, post_count) and a `posts` or `feed` array containing the posts created in this journey.

## Edge cases to also verify

- **Hashtag auto-create idempotency**: Create two posts using `#buddynext` in quick succession. Expected: only 1 row in `bn_hashtags` for `slug = buddynext` (UNIQUE KEY enforced), with `post_count` incremented twice.
- **Case normalization**: Create a post with `#BuddyNext` (mixed case). Expected: matches the existing `buddynext` slug (slug is lowercased on insert). No duplicate row created.
- **Hashtag in deleted post**: Delete a post that contains `#buddynext`. Expected: `post_count` decremented on the hashtag (or orphaned, depending on implementation — verify actual behavior and note if cleanup is missing).
- **Follow non-existent hashtag**: Attempt `POST /buddynext/v1/hashtags/nonexistent999/follow`. Expected: 404.
- **Anonymous hashtag follow**: Attempt follow without credentials. Expected: 401.

## What this validates

- `HashtagService` (or `SearchIndexListener`) extracts `#tag` patterns from post content and auto-creates rows in `bn_hashtags`.
- `bn_post_hashtags` join rows are created linking each post to its hashtags.
- `HashtagService::toggle_follow()` inserts / deletes `bn_hashtag_follows` rows and adjusts `bn_hashtags.follower_count`.
- `HashtagController::trending()` returns hashtags ordered by post_count / trending score.
- `HashtagController::get()` returns the hashtag detail and its post feed.
- `buddynext_index_hashtags` action is fired by the bridge layer when addon content contains hashtags.

## Verification queries

```sql
-- All hashtags from this journey:
SELECT id, name, slug, post_count, follower_count, created_at
FROM wp_bn_hashtags
WHERE slug IN ('wordpress', 'buddynext', 'opensource')
ORDER BY post_count DESC;

-- Post-hashtag join rows for POST_ID:
SELECT ph.post_id, h.slug, ph.created_at
FROM wp_bn_post_hashtags ph
INNER JOIN wp_bn_hashtags h ON h.id = ph.hashtag_id
WHERE ph.post_id = POST_ID;

-- Hashtag follows for member2:
SELECT hf.user_id, h.slug, hf.created_at
FROM wp_bn_hashtag_follows hf
INNER JOIN wp_bn_hashtags h ON h.id = hf.hashtag_id
WHERE hf.user_id = MEMBER2_ID;

-- Trending: top 5 hashtags by post_count:
SELECT slug, post_count, follower_count
FROM wp_bn_hashtags
ORDER BY post_count DESC
LIMIT 5;
```

## REST surface walked

```
GET  /buddynext/v1/hashtags                          -- 200, hashtag list with optional ?q= search
GET  /buddynext/v1/hashtags/trending                 -- 200, ordered trending list
GET  /buddynext/v1/hashtags/{slug}                   -- 200, hashtag detail + post feed
POST /buddynext/v1/hashtags/{slug}/follow            -- 200, { "following": bool }
```

## Cleanup

```sql
-- Remove follow rows from this journey:
DELETE FROM wp_bn_hashtag_follows
WHERE hashtag_id IN (SELECT id FROM wp_bn_hashtags WHERE slug IN ('wordpress', 'buddynext', 'opensource'));

-- Remove post-hashtag join rows for posts created in this journey:
DELETE FROM wp_bn_post_hashtags
WHERE post_id IN (
  SELECT id FROM wp_bn_posts WHERE user_id IN (MEMBER1_ID, MEMBER2_ID) AND space_id = SPACE_ID
);

-- Remove posts created in this journey:
DELETE FROM wp_bn_posts
WHERE user_id IN (MEMBER1_ID, MEMBER2_ID)
  AND space_id = SPACE_ID
  AND content LIKE '%#buddynext%';

-- Remove hashtags created in this journey (if no other posts use them):
DELETE FROM wp_bn_hashtags WHERE slug IN ('wordpress', 'buddynext', 'opensource');
```

## Known limitations

- Hashtag extraction happens inline on `buddynext_post_created` in the current implementation. In a future Action Scheduler phase, this will move to async. Verify which path is active by checking `SearchIndexListener` and `HashtagService` for AS job registration.
- `buddynext_hashtag_related_discussions` filter is registered but no consumers are wired in Free — it is an extension point for Jetonomy bridge.

## Automation notes

- Post creation with hashtags is fully automatable via the REST API.
- The trending order depends on `post_count` — seed enough posts per hashtag to produce deterministic ordering.
- Use `wp eval` to call `HashtagService::get_trending()` directly for isolated unit testing.
