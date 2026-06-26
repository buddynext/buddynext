# Journey: Search

**Free feature**: `includes/Search/` (SearchService, SearchIndexListener, SearchController)
**Actions / filters fired**: `buddynext_post_created` (triggers indexing), `buddynext_reindex_all`, `buddynext_reindex_complete`, `buddynext_search_results` (filter), `buddynext_search_query_args` (filter), `buddynext_search_performed` (action, fired after results computed)
**DB tables touched**: `bn_search_index`, `bn_blocks`, `bn_user_suspensions`
**Estimated time**: 10 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: 1 open space seeded; `member1` and `member2` exist
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Create searchable content and verify indexing

1. As `member1`, create a post with a distinctive searchable phrase:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "content": "BuddyNextJourneyUniquePhrase community platform for WordPress",
       "space_id": SPACE_ID,
       "privacy": "public"
     }'
   ```

   - Expected: 201. Note the returned `id` (referred to as `POST_ID`). `SearchIndexListener` hooks `buddynext_post_created` and indexes the post.

2. Verify the post is in the search index:

   ```sql
   SELECT id, object_type, object_id, title, visibility, created_at
   FROM wp_bn_search_index
   WHERE object_type = 'post' AND object_id = POST_ID;
   ```

   - Expected: 1 row, `object_type = post`, `visibility = public`.

3. Verify the FULLTEXT index is populated (content column should contain the post content):

   ```sql
   SELECT object_id, LEFT(content, 100) AS content_preview
   FROM wp_bn_search_index
   WHERE object_type = 'post' AND object_id = POST_ID;
   ```

   - Expected: `content_preview` contains `BuddyNextJourneyUniquePhrase`.

### Part 2: Query via `/buddynext/v1/search`

4. Search for the distinctive phrase as a public (unauthenticated) viewer:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=BuddyNextJourneyUniquePhrase"
   ```

   - Expected: 200. Response contains a `posts` (or `items`) array with the post created in Step 1.

5. Search across all types (posts, users, spaces, hashtags):

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=BuddyNext"
   ```

   - Expected: 200. **Response shape (runtime-confirmed):** `{ "grouped": true, "results": { "types": [ {"type":"post","results":[...],"total":N}, {"type":"user",...} ] } }` — assert `results.types[].type`, NOT a top-level `posts`/`items` key (those don't exist on the grouped endpoint). The type-scoped form `?type=users` returns a different shape: `{ "items":[...], "total":N }`.
   - Type-scoped search accepts BOTH singular and plural values (1.0.3 normalization fix): `?type=user` and `?type=users` both work, as do `post`/`posts`, `space`/`spaces`, `hashtag`/`hashtags`.

6. Search for users by name:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=member1&type=users" \
     -u member1:password
   ```

   - Expected: 200. `member1` user appears in results.

### Part 3: Verify viewer-aware filtering (block list exclusion)

7. As `member1`, block `member2`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/block \
     -u member1:password -H "Content-Type: application/json"
   ```

8. As `member2`, create a post with the unique search phrase:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{
       "content": "BuddyNextJourneyUniquePhrase from member2",
       "space_id": SPACE_ID,
       "privacy": "public"
     }'
   ```

9. As `member1`, search for the phrase. Confirm `member2`'s post does not appear:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=BuddyNextJourneyUniquePhrase" \
     -u member1:password
   ```

   - Expected: Results include only member1's post. Member2's post is excluded due to the block relationship.

### Part 4: Verify shadow-banned user exclusion

10. Shadow-ban `member2` as admin:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/shadow-ban \
      -u admin:password -H "Content-Type: application/json"
    ```

11. As an anonymous viewer, search for the phrase:

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=BuddyNextJourneyUniquePhrase"
    ```

    - Expected: `member2`'s post excluded from anonymous results. Shadow-banned users' content is hidden from non-admin viewers.

### Part 5: Trigger a manual reindex (CLI / cron — NOT REST)

12. There is **no** `POST /search/index/{type}` REST route. Reindex runs via WP-CLI or cron only. As admin on the site shell, trigger it via WP-CLI:

    ```bash
    wp buddynext reindex post
    # (or the equivalent cron/Action Scheduler job — confirm the exact command in SearchService / the CLI registration)
    ```

    - Expected: the reindex job runs (synchronously for small datasets, or enqueued via Action Scheduler). There is no REST endpoint to call here.

13. Re-run the search after reindex and confirm results are consistent with Step 4:

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=BuddyNextJourneyUniquePhrase"
    ```

    - Expected: same results as Step 4 (member1's post; member2's post excluded for anonymous viewers).

## Edge cases to also verify

- **Empty query**: `GET /buddynext/v1/search?q=`. Expected: 400 or empty result set — not a 500.
- **Type filter**: `GET /buddynext/v1/search?q=BuddyNext&type=spaces`. Expected: only spaces in results, no posts or users.
- **Pagination**: `GET /buddynext/v1/search?q=community&per_page=2&page=1`. Expected: at most 2 results per page with a `total` or `has_more` field in the response.
- **Private post not indexed publicly**: Create a post with `privacy = private`. Verify `bn_search_index.visibility = private` for that post. Confirm it does not appear in unauthenticated search results.
- **Deleted post removed from index**: Delete a post and verify the `bn_search_index` row is either removed or updated. Expected: the deleted post should not appear in subsequent searches.

## What this validates

- `SearchIndexListener` hooks `buddynext_post_created(int $post_id, int $user_id, string $type)` and upserts a row in `bn_search_index`.
- `bn_search_index` FULLTEXT key on `(title, content)` enables MySQL FULLTEXT matching.
- `SearchService::search()` applies viewer-aware exclusions: blocks from `bn_blocks`, shadow bans from usermeta / `bn_user_suspensions`.
- `SearchController::search()` accepts `q`, `type` (singular OR plural — normalized in 1.0.3), `per_page`, `page` params.
- Reindex is CLI/cron-only (no REST route); only `GET /search` and `GET /search/members` are registered.
- `buddynext_search_query_args` filter is applied before SQL is built.
- `buddynext_search_results` filter is applied to the result set before returning.

## Verification queries

```sql
-- Search index rows for posts from this journey:
SELECT id, object_type, object_id, LEFT(title, 60) AS title_preview, visibility, updated_at
FROM wp_bn_search_index
WHERE object_type = 'post'
  AND object_id IN (POST_ID)
ORDER BY updated_at DESC;

-- Full-text search directly in MySQL (verify FULLTEXT is working):
SELECT object_type, object_id, visibility
FROM wp_bn_search_index
WHERE MATCH(title, content) AGAINST ('BuddyNextJourneyUniquePhrase' IN BOOLEAN MODE);

-- All search index rows for member2's content (to verify shadow-ban exclusion):
SELECT object_type, object_id, visibility
FROM wp_bn_search_index
WHERE author_id = MEMBER2_ID;
```

## REST surface walked

```
GET  /buddynext/v1/search                            -- 200, search results (public; viewer-aware)
GET  /buddynext/v1/search?type=users                 -- 200, user results only
GET  /buddynext/v1/search?type=posts                 -- 200, post results only
GET  /buddynext/v1/search?type=spaces                -- 200, space results only
GET  /buddynext/v1/search?type=hashtags              -- 200, hashtag results only
GET  /buddynext/v1/search/members                    -- 200, dedicated member search
```

> **Verified live (1.0.3):** only `GET /search` and `GET /search/members` are registered. There is NO `POST /search/index/{type}` reindex REST route — reindex runs via WP-CLI / cron only. Re-confirm: `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if 'search' in r]"`

## Frontend action wiring

*(Item 11.)*

| Control | Template (file) | JS store / action | Live route + method | Source |
|---|---|---|---|---|
| Search box (type/submit) | `templates/search/results.php` | `buddynext/search` (`store.js`) | `GET /search?q=&type=` | `ctx.restNonce` |
| Follow from result | `templates/search/results.php` | `store.js:25` | `POST/DELETE /users/{id}/follow` | `ctx.restNonce` |
| Join/leave space from result | `templates/search/results.php` | `store.js:46` | `POST /spaces/{id}/join` · `/leave` | `ctx.restNonce` |
| Save current search | `templates/search/results.php` | `store.js:84` saveCurrent | injected `ctx.savedSearchUrl` (POST) | `ctx.restNonce` |

> **Injected-URL note:** saved-search posts to `ctx.savedSearchUrl` (not a fixed `buddynext/v1` path). Confirm the template emits it (`curl ... | grep savedSearchUrl`) or Save no-ops silently.

**Verify this run:** type a query → `GET /search?q=` fires and results render per tab (users/posts/spaces/hashtags); assert each result item's shape (id + permission fields), not just count.

## Admin-config → member-effect

*(Item 12.)*

- **Public explore / search** (`buddynext_public_explore`, `PageRouter.php:261`): OFF → an anonymous `GET /search` (and the explore/search page) becomes members-only / redirects to login; ON → public. Verify logged-out in both states.
- **Search feature toggle** (if gated in Features): OFF → search route 403 + nav item gone.

Restore options after.

## Cleanup

```sql
-- Remove search index rows for test posts:
DELETE FROM wp_bn_search_index
WHERE object_type = 'post'
  AND object_id IN (
    SELECT id FROM wp_bn_posts
    WHERE content LIKE '%BuddyNextJourneyUniquePhrase%'
  );

-- Remove test posts:
DELETE FROM wp_bn_posts WHERE content LIKE '%BuddyNextJourneyUniquePhrase%';

-- Remove block placed in this journey:
DELETE FROM wp_bn_blocks
WHERE blocker_id = MEMBER1_ID AND blocked_id = MEMBER2_ID;

-- Lift shadow ban on member2:
UPDATE wp_usermeta
SET meta_value = '0'
WHERE user_id = MEMBER2_ID AND meta_key = 'bn_shadow_banned';
```

## Known limitations

- FULLTEXT index cannot be created on temporary tables (used in the PHPUnit test suite). Tests use `LIKE`-based fallback queries; the FULLTEXT path is only tested against a real MySQL instance.
- Shadow-ban storage should be confirmed against `ModerationService::shadow_ban()` — the exclusion may use usermeta `bn_shadow_banned` or a flag in `bn_user_suspensions`. Verify the actual column before asserting DB state in this journey.
- The `buddynext_search_performed` action fires after results are computed; it is a Pro extension point not consumed by Free.

## Automation notes

- All REST search calls are curl-automatable.
- Reindex is CLI/cron-only (no REST endpoint) and may be asynchronous (Action Scheduler). After triggering it via WP-CLI/cron, add a brief wait or poll the `bn_search_index` table for consistency before asserting search results.
- Block and shadow-ban setup must complete before search assertions are made; run in strict sequential order.
