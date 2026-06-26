# Journey: Reactions

**Free feature**: `includes/Reactions/` (ReactionService, ReactionController)
**Actions / filters fired**: `buddynext_reaction_added`, `buddynext_reaction_removed`, `buddynext_post_reaction_received`, `buddynext_reaction_types` (filter)
**DB tables touched**: `bn_reactions` (and `bn_posts.reaction_count` denormalised counter when `object_type = post`)
**Estimated time**: 9 min manual

## Site-owner expectation

A community owner expects reactions to work the moment BuddyNext is active — no setup screen, no toggle to flip. Out-of-the-box behaviour:

- Members can react to any activity post (and any reactable object) with one of the canonical six emoji: `like`, `love`, `haha`, `wow`, `sad`, `angry`.
- A member has **at most one** reaction per object — reacting again with the same emoji removes it, reacting with a different emoji swaps it. This is the Facebook-style "one reaction, switchable" model, enforced at the DB level, not in policy.
- Reaction counts are visible to everyone (public read), and a "who reacted" list is available for the popover.
- Reactions feed downstream automatically: the post author gets a notification, gamification points are awarded to the author, and outbound webhooks fire — all without the owner wiring anything.

What the owner configures: **nothing in Free.** There is no admin settings page for reactions and no per-feature enable/disable gate in the Free codebase. The only extension point is for developers: Pro (or custom code) can add reaction types via the `buddynext_reaction_types` filter — each new slug needs a matching `assets/icons/reaction-{slug}.svg` and a `--bn-reaction-{slug}` CSS token or the picker breaks.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: at least one activity post must exist so it has a numeric `object_id`. This journey uses the Activity Feed to seed one. If you already have a post id, substitute it for `POST_ID` below.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

Seed a post as `member1` (note the returned `id` as `POST_ID`):

```bash
curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
  -u member1:password \
  -H "Content-Type: application/json" \
  -d '{"content": "Journey reactions seed post"}'
```

Collect the member user ids for the SQL queries:

```bash
wp user get member1 --field=ID   # -> MEMBER1_ID
wp user get member2 --field=ID   # -> MEMBER2_ID
```

## Happy-path steps

### Part 1: Add a reaction

1. As `member2`, react to the seed post with `love`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"object_type": "post", "object_id": POST_ID, "emoji": "love"}'
   ```

   - Expected: 200 response, body `{ "has_reacted": true, "emoji": "love", "count": 1 }`. Fires `buddynext_reaction_added('post', POST_ID, MEMBER2_ID, 'love')` and, because the reactor is not the author, `buddynext_post_reaction_received(POST_ID, MEMBER1_ID, MEMBER2_ID, 'love')`.

2. Verify the reaction row:

   ```sql
   SELECT user_id, object_type, object_id, emoji, created_at
   FROM wp_bn_reactions
   WHERE object_type = 'post' AND object_id = POST_ID AND user_id = MEMBER2_ID;
   ```

   - Expected: 1 row, `emoji = love`.

3. Verify the denormalised counter on the post incremented:

   ```sql
   SELECT id, reaction_count FROM wp_bn_posts WHERE id = POST_ID;
   ```

   - Expected: `reaction_count = 1`.

### Part 2: Read the count (public)

4. As an unauthenticated client, read the count:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/reactions?object_type=post&object_id=POST_ID"
   ```

   - Expected: 200, body `{ "count": 1 }`. No `has_reacted`/`emoji` keys because the caller is anonymous (those keys are only added when `is_user_logged_in()`).

### Part 3: Swap the reaction (replace, not add)

5. As `member2`, toggle again with a different emoji (`haha`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"object_type": "post", "object_id": POST_ID, "emoji": "haha"}'
   ```

   - Expected: 200, body `{ "has_reacted": true, "emoji": "haha", "count": 1 }`. The existing row is **updated in place** (count stays 1, no new `buddynext_reaction_added` fires on a pure swap — `toggle()` calls `$wpdb->update` directly for the replace path).

6. Verify the row was updated, not duplicated:

   ```sql
   SELECT COUNT(*) AS rows_for_user, MAX(emoji) AS emoji
   FROM wp_bn_reactions
   WHERE object_type = 'post' AND object_id = POST_ID AND user_id = MEMBER2_ID;
   ```

   - Expected: `rows_for_user = 1`, `emoji = haha`.

### Part 4: Second reactor + per-emoji counts

7. As `member1` (the author), react with `like`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"object_type": "post", "object_id": POST_ID, "emoji": "like"}'
   ```

   - Expected: 200, body `count: 2`. Fires `buddynext_reaction_added`. `buddynext_post_reaction_received` does **not** fire here because the reactor (`member1`) is the post author (the self-reaction guard `$author_id !== $user_id`).

8. Confirm per-emoji counts via the service layer (no REST endpoint returns the per-emoji map; `get_counts()` is service-only):

   ```bash
   wp eval 'print_r( buddynext_service("reactions")->get_counts( "post", POST_ID ) );'
   ```

   - Expected: `Array ( [haha] => 1 [like] => 1 )`.

### Part 5: Who-reacted list (hydrated)

9. Read the reactor list:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/reactions/list?object_type=post&object_id=POST_ID"
   ```

   - Expected: 200, body `{ "items": [ {user_id, display_name, avatar_url, emoji, created_at}, ... ], "total": 2 }`. Ordered newest-first by `created_at`. `display_name` and `avatar_url` are hydrated server-side for direct UI consumption.

### Part 6: Remove a reaction

10. As `member2`, toggle the **same** emoji again (`haha`) to remove it:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"object_type": "post", "object_id": POST_ID, "emoji": "haha"}'
    ```

    - Expected: 200, body `{ "has_reacted": false, "emoji": null, "count": 1 }`. Fires `buddynext_reaction_removed('post', POST_ID, MEMBER2_ID, 'haha')` (the removed emoji is passed as the 4th arg). The `bn_posts.reaction_count` decrements (floored at 0 via `GREATEST(1, reaction_count) - 1`, which avoids an UNSIGNED underflow when the counter is already 0).

11. Verify the row is gone and the counter decremented:

    ```sql
    SELECT
      (SELECT COUNT(*) FROM wp_bn_reactions WHERE object_type='post' AND object_id=POST_ID AND user_id=MEMBER2_ID) AS member2_rows,
      (SELECT reaction_count FROM wp_bn_posts WHERE id=POST_ID) AS reaction_count;
    ```

    - Expected: `member2_rows = 0`, `reaction_count = 1`.

## Edge cases to also verify

- **Explicit removal via empty emoji**: As `member1`, send the toggle with `"emoji": ""`. `toggle()` short-circuits to `unreact()` regardless of the current emoji. Expected: 200, `has_reacted: false`, and `member1`'s row is deleted.

  ```bash
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
    -u member1:password \
    -H "Content-Type: application/json" \
    -d '{"object_type": "post", "object_id": POST_ID, "emoji": ""}'
  ```

  Note: the REST `emoji` arg has `default = like`, so omitting the key entirely yields `like`, not removal — an explicit empty string is required to force-remove.

- **Unauthenticated toggle is rejected**: Call `POST /reactions/toggle` with no auth. Expected: 401 (`rest_forbidden`, "You must be logged in.") — `require_auth()` gates the write route while reads stay public.

  ```bash
  curl -s -o /dev/null -w "%{http_code}\n" -X POST \
    http://buddynext-dev.local/wp-json/buddynext/v1/reactions/toggle \
    -H "Content-Type: application/json" \
    -d '{"object_type": "post", "object_id": POST_ID, "emoji": "like"}'
  ```

- **Missing required param**: Call `GET /reactions?object_type=post` with no `object_id`. Expected: 400 (`rest_missing_callback_param`) — `object_id` is `required`.

- **Self-reaction does not fire recipient hook**: When the post author reacts to their own post (Part 4, step 7), `buddynext_reaction_received` must NOT fire — verify no gamification award / notification row is created for the author reacting to self. (Walk the Notifications journey to confirm no `bn_notifications` row appears for that event.)

## What this validates

- `ReactionService::toggle()` adds a reaction when none exists, replaces in place when a different emoji is sent, and removes when the same emoji (or empty string) is sent — the single-reaction-per-object invariant enforced by the composite PRIMARY KEY `(user_id, object_type, object_id)`.
- `ReactionService::react()` inserts via `INSERT IGNORE`, increments `bn_posts.reaction_count` for posts, and fires `buddynext_reaction_added(object_type, object_id, user_id, emoji)`.
- `ReactionService::react()` fires the recipient-side mirror `buddynext_post_reaction_received(post_id, author_id, reactor_id, emoji)` only when the reactor is not the author.
- `ReactionService::unreact()` deletes the row, decrements the counter (floored at 0), and fires `buddynext_reaction_removed(object_type, object_id, user_id, emoji)`.
- `ReactionService::get_counts()` returns the per-emoji map; `count()` returns the total; both are cache-backed (`buddynext_reactions` group, 300s TTL) and invalidated on every write.
- `ReactionService::get_reactors()` returns the newest-first reactor list (capped 100), applying the block/restrict gate on post objects (restricted reactors hidden from everyone except self, owner, admin) while leaving the raw count untouched.
- `buddynext_reaction_added` is consumed by `NotificationListener::on_reaction_added` and `OutboundWebhookListener::on_webhook_reaction_added`; `buddynext_post_reaction_received` is consumed by `GamificationBridge::on_reaction_received` — confirming the plug-and-play downstream wiring.

## Verification queries

```sql
-- All reactions on the seed post:
SELECT user_id, object_type, object_id, emoji, created_at
FROM wp_bn_reactions
WHERE object_type = 'post' AND object_id = POST_ID
ORDER BY created_at DESC;

-- Per-emoji counts (mirrors get_counts()):
SELECT emoji, COUNT(*) AS cnt
FROM wp_bn_reactions
WHERE object_type = 'post' AND object_id = POST_ID
GROUP BY emoji;

-- Total count (mirrors count()):
SELECT COUNT(*) AS total
FROM wp_bn_reactions
WHERE object_type = 'post' AND object_id = POST_ID;

-- Denormalised counter sanity check (should equal total above):
SELECT id, reaction_count FROM wp_bn_posts WHERE id = POST_ID;
```

## REST surface walked

```
POST /buddynext/v1/reactions/toggle   -- 200, { has_reacted, emoji|null, count } (auth required; 401 if anon)
GET  /buddynext/v1/reactions          -- 200, { count } (+ has_reacted, emoji when logged in) (public)
GET  /buddynext/v1/reactions/list     -- 200, { items:[{user_id,display_name,avatar_url,emoji,created_at}], total } (public)
```

All three are registered under the `buddynext/v1` namespace by `ReactionController::register_routes()`, which is invoked from `includes/REST/Router.php`.

## Frontend action wiring

*(Item 11. Reactions are one-tap and ubiquitous — verify the picker + reactor popover wiring on the card.)*

| Control | Template (file) | JS store / action | Live route + method | Nonce key |
|---|---|---|---|---|
| React / swap / un-react (emoji picker) | `templates/parts/post-reaction-summary.php`, `post-actions.php` | `buddynext/post-card` setReaction (`assets/js/feed/store.js:385`) | `POST /reactions/toggle` | `ctx.reactNonce` |
| "Who reacted" popover | `templates/parts/post-reaction-summary.php` | reactor-list store (`feed/store.js:747`) | `GET /reactions/list` | `ctx.reactNonce` |
| Count read (public/anon) | rendered server-side + refresh | post-card | `GET /reactions` | none (public) |

**Verify this run:** card renders `reactNonce` (`curl -s -b /tmp/bn.txt -L http://buddynext.local/activity/ | grep -c reactNonce`); `POST /reactions/toggle` exists with POST (live index); anon `GET /reactions?object_type=post&object_id=N` returns `{count}` only.

## Admin-config → member-effect

*(Item 12. Reactions have no per-feature settings page, but they ARE gated by the FeatureRegistry toggle.)*

- **Reactions feature toggle** (Settings → BuddyNext → Features → "Reactions"): turn **OFF**, then as a member `POST /reactions/toggle` → expect **403** (the write gate is `ReactionService`/`ReactionController::reactions_enabled_gate()` at `ReactionController.php:119,155`) and the emoji bar should not render on the card. Turn back **ON** → 200. Reads stay public either way. This proves the toggle actually disables the control — the contract no prior journey checked.

Restore (`wp option delete` the features option / re-enable in the form).

## Cleanup

```sql
-- Remove all reactions on the seed post:
DELETE FROM wp_bn_reactions WHERE object_type = 'post' AND object_id = POST_ID;

-- Reset the denormalised counter on the seed post:
UPDATE wp_bn_posts SET reaction_count = 0 WHERE id = POST_ID;
```

If the seed post was created only for this journey, also remove it — see the Activity Feed journey cleanup, or:

```sql
DELETE FROM wp_bn_posts WHERE id = POST_ID;
```

Flush the reaction cache group if a persistent object cache is active:

```bash
wp cache flush
```

## Known limitations

- **No per-emoji map over REST.** The only way to read the per-emoji breakdown (`{like: n, love: n, ...}`) is the service method `get_counts()`. The public `GET /reactions` endpoint returns only the scalar total. The reaction picker UI must compute its breakdown from `/reactions/list` items or call the service directly.
- **No admin settings / feature gate in Free.** There is no enable/disable toggle for reactions and no per-space or per-object-type policy. Any authenticated user can react to any `object_type`/`object_id` pair the route accepts; the service does not validate that the object exists (only `post` objects get a counter update and the author-side hooks).
- **Emoji validation is by sanitisation, not allow-list, at the write path.** `react()`/`toggle()` `sanitize_key()` the emoji but do not reject slugs outside the canonical six — `reaction_types()` / the `buddynext_reaction_types` filter is advisory for the UI, not enforced server-side. An arbitrary sanitised slug will be stored.
- **`get_reactors()` defaults to a cap of 100**, adjustable via the `buddynext_reactors_limit` filter (`ReactionService.php` applies it: `$max = apply_filters( 'buddynext_reactors_limit', 100, $object_type, $object_id )`). Note the REST route still clamps the `limit` arg at `'maximum' => 100`, so raising the cap above 100 takes effect for internal callers, not via the public API.
- **Counter drift risk on non-post swaps.** `bn_posts.reaction_count` is only touched for `object_type = 'post'`. Reactions on `comment`/`message` objects have no denormalised counter and rely on live `COUNT(*)`.

## Automation notes

- All three endpoints are curl-automatable; only `/reactions/toggle` needs basic auth.
- `POST_ID`, `MEMBER1_ID`, `MEMBER2_ID` must be captured at runtime — do not hardcode.
- The add → swap → remove sequence (Parts 1, 3, 6) exercises all three `toggle()` branches in one pass; assert on the returned `count` and `emoji` at each step rather than re-querying for speed.
- To assert hook fires in an automated run, register temporary listeners via a mu-plugin (`buddynext_reaction_added`, `buddynext_post_reaction_received`, `buddynext_reaction_removed`) that write a marker option, then read it back with `wp option get`.
- The self-reaction guard (step 7) is the load-bearing assertion for gamification correctness — a script should explicitly verify the recipient hook count is 0 when reactor == author.
