# Journey: Comments

**Free feature**: `includes/Comments/` (CommentService, CommentController)
**Actions / filters fired**: `buddynext_comment_created`, `buddynext_post_comment_received`, `buddynext_comment_updated`, `buddynext_comment_deleted` (actions); `buddynext_comment_before_save`, `buddynext_comment_author_meta_html` (PHP filters); `buddynext.comment`, `buddynext.commentNode` (JS `wp.hooks` filters in `assets/js/feed/store.js`)
**DB tables touched**: `bn_comments` (and `bn_posts.comment_count` when `object_type = 'post'`)
**Estimated time**: 12 min manual

## Site-owner expectation

Out of the box, a community owner expects comments to "just work" on every activity post: any logged-in member can comment, reply to other comments to form threads, edit or delete their own comments, and have those changes reflected immediately. Post authors expect a running comment count on their posts, and moderators expect to be able to pin one important comment to the top of a thread and remove abusive ones.

What the owner actually configures: essentially nothing at the comment-feature level — there are no admin settings screens for comments. Behaviour is governed by adjacent systems the owner already configures elsewhere: Moderation (a suspended user is blocked from commenting), the per-user Restrict list (a restricted commenter is hidden from other viewers on the owner's own posts), and the `manage_options` capability (who can pin/unpin and moderate any comment). Threading depth is fixed at 5 levels in code (`CommentService::MAX_REPLY_DEPTH`, mirrored by `COMMENT_MAX_DEPTH` in `store.js`), not a setting. Developers extend behaviour through the documented hooks rather than an options page.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: at least one activity post owned by a member to comment on. This journey creates one via the activity feed REST API. Note its `id` as `POST_ID`.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`
- Resolve user IDs up front:

  ```bash
  wp user get member1 --field=ID   # MEMBER1_ID
  wp user get member2 --field=ID   # MEMBER2_ID
  ```

## Happy-path steps

### Part 0: Create a post to comment on

1. As `member1`, create an activity post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Journey comments host post"}'
   ```

   - Expected: 201. Note the returned `id` as `POST_ID`. Its `comment_count` starts at 0.

### Part 1: Create a top-level comment

2. As `member2`, comment on the post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/comments \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{
       "object_type": "post",
       "object_id": POST_ID,
       "content": "Journey top-level comment"
     }'
   ```

   - Expected: 201. Response is the enriched comment object: `id`, `user_id`, `parent_id: null`, `content`, plus UI fields `author_name`, `author_avatar_url`, `like_count: 0`, `viewer_liked: false`, `can_edit: true`, `can_delete: true`, `can_pin` (false for member2), `replies: []`, `is_pinned: false`, `author_meta_html`. Note the returned `id` as `COMMENT_ID`.

3. Verify the comment row:

   ```sql
   SELECT id, user_id, object_type, object_id, parent_id, content, is_edited, is_deleted
   FROM wp_bn_comments
   WHERE id = COMMENT_ID;
   ```

   - Expected: 1 row, `object_type = post`, `parent_id IS NULL`, `is_edited = 0`, `is_deleted = 0`.

4. Verify the host post's comment counter incremented:

   ```sql
   SELECT id, comment_count FROM wp_bn_posts WHERE id = POST_ID;
   ```

   - Expected: `comment_count = 1`. (`CommentService::create()` does `comment_count = comment_count + 1` for `object_type = 'post'`.)

### Part 2: Reply to a comment (threading)

5. As `member1`, reply to `COMMENT_ID`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/comments \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "object_type": "post",
       "object_id": POST_ID,
       "content": "Journey reply",
       "parent_id": COMMENT_ID
     }'
   ```

   - Expected: 201. Note the returned `id` as `REPLY_ID`.

6. Verify the reply row links to its parent:

   ```sql
   SELECT id, parent_id, content FROM wp_bn_comments WHERE id = REPLY_ID;
   ```

   - Expected: `parent_id = COMMENT_ID`. (`comment_count` increments again to 2 — it counts all comments on the post, not just top-level.)

### Part 3: List comments (threaded tree)

7. List comments for the post (public, unauthenticated):

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/comments?object_type=post&object_id=POST_ID"
   ```

   - Expected: 200. `{ "items": [...], "total": N }`. `total` counts **top-level** comments only (`parent_id IS NULL AND is_deleted = 0`). `COMMENT_ID` appears as a top-level item with `REPLY_ID` nested inside its `replies` array. Every node (including nested replies) is enriched with `author_name`, `author_avatar_url`, `like_count`, `viewer_liked`, `can_edit`, `can_delete`, `can_pin`, `is_pinned`, `author_meta_html`.

### Part 4: Edit a comment

8. As `member2` (the author), edit `COMMENT_ID`:

   ```bash
   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/comments/COMMENT_ID \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Journey top-level comment (edited)"}'
   ```

   - Expected: 200. Returned object has updated `content` and `is_edited: true`.

9. Verify the edit flag:

   ```sql
   SELECT id, content, is_edited FROM wp_bn_comments WHERE id = COMMENT_ID;
   ```

   - Expected: `is_edited = 1`, updated content.

### Part 5: Pin / unpin a comment (moderator)

10. As `admin` (has `manage_options`), pin `COMMENT_ID`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/comments/COMMENT_ID/pin \
      -u admin:password -H "Content-Type: application/json"
    ```

    - Expected: 200, `{ "pinned": true }`. The pin is stored as a WP option `bn_pinned_comment_post_POST_ID` (not a DB table column).

11. Re-list comments (step 7). Expected: the pinned comment carries `is_pinned: true` and is prepended to the top of `items` if it was not already on the page.

12. As `admin`, unpin:

    ```bash
    curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/comments/COMMENT_ID/pin \
      -u admin:password -H "Content-Type: application/json"
    ```

    - Expected: 200, `{ "pinned": false }`. The `bn_pinned_comment_post_POST_ID` option is deleted.

### Part 6: Delete a comment (soft delete)

13. As `member1`, delete the reply `REPLY_ID`:

    ```bash
    curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/comments/REPLY_ID \
      -u member1:password -H "Content-Type: application/json"
    ```

    - Expected: 204 No Content.

14. Verify the soft delete:

    ```sql
    SELECT id, content, is_deleted FROM wp_bn_comments WHERE id = REPLY_ID;
    ```

    - Expected: `is_deleted = 1`, `content = ''`. The row is **not** removed — soft delete keeps thread shape intact. On re-list, the controller anonymizes it (`author_name = "Deleted user"`, `content = "[deleted]"`) so nested replies stay attached. `bn_posts.comment_count` is decremented (`GREATEST(0, comment_count - 1)`).

## Edge cases to also verify

- **Empty comment rejected**: `POST /comments` with `{"object_type":"post","object_id":POST_ID,"content":"   "}` (whitespace only). Expected: 400, error code `empty_content`. `CommentService::create()` runs `wp_kses_post(trim($content))` and rejects an empty string before any DB write.
- **Unauthenticated create rejected**: `POST /comments` with no auth. Expected: 401 (`require_auth` permission callback). Listing, by contrast, is public (`__return_true`).
- **Edit someone else's comment forbidden**: As `member1`, `PUT /comments/COMMENT_ID` (authored by member2). Expected: 403/`forbidden` — `update()` allows only the author or a `manage_options` user.
- **Non-moderator pin forbidden**: As `member2`, `POST /comments/COMMENT_ID/pin`. Expected: 403 (`require_moderator` rejects before the service; `CommentService::pin()` also re-checks `manage_options`).
- **Suspended user cannot comment**: Suspend `member2` via the Moderation feature, then attempt step 2 as `member2`. Expected: 403, error code `forbidden` ("Your account is suspended and cannot post comments.") — gated by `is_author_suspended()` before the DB write.
- **Reply nesting cap (depth 5)**: Build a chain of replies each pointing to the previous comment's id, 6+ levels deep. Expected: all rows store at their natural `parent_id` in the DB, but `list()` flattens everything beyond `MAX_REPLY_DEPTH = 5` back onto the deepest visible ancestor (Discord-style fold-back), sorted chronologically. The JS in `store.js` mirrors this with `COMMENT_MAX_DEPTH = 5`.

## What this validates

- `CommentService::create()` inserts into `bn_comments`, applies the `buddynext_comment_before_save` filter (which may rewrite or reject via `WP_Error`), increments `bn_posts.comment_count` for `post` objects, and fires `buddynext_comment_created(int $comment_id, string $object_type, int $object_id, int $user_id)`.
- For a `post` comment by someone other than the post author, `create()` additionally fires `buddynext_post_comment_received(int $comment_id, int $post_id, int $author_id, int $commenter_id)` — the recipient-side mirror consumed by `GamificationBridge`.
- `CommentService::update()` sets `is_edited = 1`, re-applies `buddynext_comment_before_save`, and fires `buddynext_comment_updated(int $comment_id, int $user_id)`. Author-or-admin gate enforced.
- `CommentService::delete()` soft-deletes (`is_deleted = 1`, blanked content), decrements `comment_count`, and fires `buddynext_comment_deleted(int $comment_id, int $user_id)`.
- `CommentService::list()` returns an N-deep threaded tree (top-level paginated; descendants attached up to `MAX_REPLY_DEPTH`), prepends the pinned comment, and applies the per-owner Restrict gate hiding restricted commenters from other viewers on `post` threads.
- `CommentService::pin()` / `unpin()` store/remove the `bn_pinned_comment_{object_type}_{object_id}` option; both require `manage_options`.
- The `buddynext_comment_author_meta_html` filter is applied (and `wp_kses_post`-escaped) on every comment in create/update/list responses, populating `author_meta_html` for role/badge markup.
- JS filters `buddynext.comment` (mutate comment data) and `buddynext.commentNode` (mutate the rendered DOM node) fire inside `buildCommentNode()` in `assets/js/feed/store.js`.

## Verification queries

```sql
-- All comments on the host post (top-level + replies):
SELECT id, user_id, parent_id, is_edited, is_deleted, LEFT(content, 40) AS snippet, created_at
FROM wp_bn_comments
WHERE object_type = 'post' AND object_id = POST_ID
ORDER BY created_at ASC;

-- Top-level (visible) comment count vs. denormalised post counter:
SELECT
  (SELECT COUNT(*) FROM wp_bn_comments
     WHERE object_type='post' AND object_id=POST_ID AND parent_id IS NULL AND is_deleted=0) AS top_level_visible,
  (SELECT comment_count FROM wp_bn_posts WHERE id=POST_ID) AS post_comment_count;

-- Soft-deleted rows (kept for thread integrity):
SELECT id, parent_id, is_deleted FROM wp_bn_comments
WHERE object_type='post' AND object_id=POST_ID AND is_deleted=1;

-- Pinned-comment option (exists only while a comment is pinned):
SELECT option_name, option_value FROM wp_options
WHERE option_name = 'bn_pinned_comment_post_POST_ID';
```

Service-layer spot check via WP-CLI:

```bash
wp eval 'var_dump( buddynext_service("comments")->list("post", POST_ID ) );'
```

## REST surface walked

```
POST   /buddynext/v1/comments                 -- 201, enriched comment object (auth required)
GET    /buddynext/v1/comments                 -- 200, { items: [tree], total: N } (public; object_type + object_id required)
PUT    /buddynext/v1/comments/{id}            -- 200, updated comment (author or admin)
DELETE /buddynext/v1/comments/{id}            -- 204, no body (author or admin)
POST   /buddynext/v1/comments/{id}/pin        -- 200, { "pinned": true }  (moderator / manage_options)
DELETE /buddynext/v1/comments/{id}/pin        -- 200, { "pinned": false } (moderator / manage_options)
```

Query/body params:
- `POST /comments` body: `object_type` (string, required), `object_id` (int ≥1, required), `content` (string, required), `parent_id` (int ≥1, optional — omit for top-level).
- `GET /comments` query: `object_type`, `object_id` (required); `per_page` (1–50, default 20), `page` (≥1, default 1).
- `PUT /comments/{id}` body: `content` (string, required).

> Re-confirm live: `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if '/comments' in r]"`

## Frontend action wiring

*(Item 11. Comment controls are vanilla-DOM handlers inside the post-card store — the journey verifies the routes, but only this layer catches a broken DOM binding.)*

| Control | Template (file) | JS store / handler | Live route + method | Nonce key |
|---|---|---|---|---|
| Add comment / reply | `templates/parts/post-comment-form.php` | `feed/store.js:683` | `POST /comments` | `ctx.reactNonce` (passed as the local `nonce` param) |
| Load comment tree | `templates/parts/post-comments-list.php` | `feed/store.js:817` | `GET /comments?object_type=post&object_id=` | `ctx.reactNonce` |
| Edit comment | `templates/parts/post-comments-list.php` | `feed/store.js:496` | `PUT /comments/{id}` | `nonce` |
| Delete comment | `templates/parts/post-comments-list.php` | `feed/store.js:541` | `DELETE /comments/{id}` | `nonce` |
| Pin / unpin comment | `templates/parts/post-comments-list.php` | `feed/store.js:569` | `POST/DELETE /comments/{id}/pin` | `nonce` |

**Verify this run:** post a comment as `alice` on a post (`POST /comments` 201), confirm it appears; edit and delete it; as admin pin it. Because these are DOM handlers (not Interactivity directives), a JS-binding regression passes every REST step but breaks the button — click them in the browser.

## Admin-config → member-effect

*(Item 12.)*

- **Comments feature toggle** (Settings → BuddyNext → Features → "Comments"): turn **OFF**, then as a member `POST /comments` → expect **403** (gate `CommentController::comments_enabled_gate()` at `CommentController.php:466,477`) and the comment form should not render. Turn **ON** → 201.

Restore the features option after.

## Cleanup

```sql
-- Remove the pinned-comment option if it survived a failed run:
DELETE FROM wp_options WHERE option_name = 'bn_pinned_comment_post_POST_ID';

-- Remove all test comments on the host post (hard delete for cleanup):
DELETE FROM wp_bn_comments WHERE object_type = 'post' AND object_id = POST_ID;

-- Remove the host post:
DELETE FROM wp_bn_posts WHERE id = POST_ID;
```

If `member2` was suspended during the edge-case run, lift the suspension afterwards (see moderation-report.md cleanup) so later journeys aren't blocked.

## Known limitations

- **No GET-single endpoint**: there is no `GET /comments/{id}` route. `CommentService::get()` exists but is only reachable internally / via `wp eval`; a single comment can only be observed through the threaded `GET /comments` list or SQL.
- **No edit/update hook for `before_save` rejection surfacing on REST**: a `WP_Error` returned by `buddynext_comment_before_save` propagates, but the create controller force-stamps `status => 400` on any create error — distinct service errors (e.g. suspension `403`) are flattened to `400` on the create route. Update/delete routes return the service error's own status.
- **Pin is single per object**: pinning a second comment overwrites the option; there is no multi-pin and no per-comment `is_pinned` column — pinned state lives entirely in the `bn_pinned_comment_*` option.
- **Pagination is top-level only**: `list()` fetches the entire descendant set for an object in one query (comment on the perf note in `CommentService::list()` — viral threads >1000 comments would need per-branch pagination, flagged as a separate sprint).
- **`comment_count` counts replies too**: the denormalised `bn_posts.comment_count` is incremented for every comment including replies, while `GET /comments` `total` counts only visible top-level comments — the two numbers legitimately differ on threaded posts.
- **No dedicated comment reaction route here**: comment likes (`like_count` / `viewer_liked`) are computed via `ReactionService` against `object_type = 'comment'`; reacting to a comment goes through the Reactions feature, not this controller.

## Automation notes

- All six routes are curl-automatable with basic auth; only listing is anonymous.
- Collect `POST_ID`, `COMMENT_ID`, and `REPLY_ID` from CREATE responses — never hardcode IDs.
- The suspended-user edge case requires a moderation side-effect; script it as suspend → attempt-comment → assert 403 → unsuspend so it self-cleans.
- Frontend rendering (the `buddynext.comment` / `buddynext.commentNode` JS filters and the depth-5 fold-back in `store.js`) is not exercisable via REST — assert it with a Playwright pass that posts a comment in the activity feed UI and inspects the rendered `buildCommentNode` output, or skip per the README note that no frontend assertions are required.
- The pinned-comment option name is deterministic (`bn_pinned_comment_{object_type}_{object_id}`), so pin/unpin can be asserted directly against `wp_options` without parsing the list payload.
