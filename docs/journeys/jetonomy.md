# Journey: Jetonomy (Forums / Discussions) Bridge

**Bridge feature**: `includes/Bridges/JetonomyBridge.php` (opt-in — only attaches hooks when the **Jetonomy** plugin is active)
**Requires**: Jetonomy plugin active (`class_exists( 'Jetonomy\Jetonomy' )`). The bridge `init()` bails immediately and registers nothing when Jetonomy is absent.
**Partner plugin**: Jetonomy (forums / Q&A / ideas), `wp_jt_*` tables, `/community/` route. **Partner-owned** behaviour (discussion CRUD, replies, votes, spaces, leaderboard, its own REST) is NOT documented here except where the bridge consumes it.
**Partner hooks consumed** (fired by Jetonomy, listened by the bridge): `jetonomy_after_create_post` (2 args: `$post_id, $space_id`), `jetonomy_post_deleted` (3 args: `$post_id, $space_id, $user_id`), `jetonomy_after_create_reply` (2 args: `$reply_id, $space_id`), `jetonomy_before_content`, `jetonomy_after_content`, `jetonomy_show_community_nav` (filter, forced `false`)
**BuddyNext seams the bridge injects into** (filters/actions): `buddynext_rail_items` (Discussions rail link — CURRENT correct seam; replaced the dead `buddynext_nav_items`), `buddynext_space_tabs` (space Forum tab), `buddynext_context_nav` (Level-2 Discussion sub-nav), `buddynext_profile_extra_data` (profile discussion count), `buddynext_hashtag_related_discussions` (hashtag ↔ tag cross-link)
**Actions the bridge fires**: `buddynext_user_mentioned` (@mention in a discussion body), `buddynext_jetonomy_post_indexed` (after search-index write)
**DB tables touched by the bridge**: writes/deletes `wp_bn_search_index` (always-on); writes/deletes `wp_bn_posts` (opt-in only, `buddynext_jetonomy_feed_sync`); writes `wp_bn_notifications` (via `NotificationService` on reply). Reads partner tables `wp_jt_posts`, `wp_jt_spaces`.
**Estimated time**: 12 min manual

## Site-owner expectation

When a community owner activates Jetonomy alongside BuddyNext, they expect **plug-and-play forums inside the BN community** — not a bolted-on, separate-looking plugin:

- **Forums appear inside the community** — a "Discussions" link shows up in the BuddyNext left navigation rail, pointing at the forum home (`/community/`).
- **Unified navigation** — on forum pages, BuddyNext's nav renders above the forum content and Jetonomy's own community nav is suppressed, so there is one consistent nav across both surfaces (no double sidebar — Jetonomy keeps its own right column).
- **Search includes discussions** — typing in BuddyNext unified search returns forum discussions alongside posts, members, and spaces.
- **Profiles & spaces are aware of forums** — a member's profile shows a Discussions count, and a BuddyNext space that is linked to a forum gets a Discussions tab.
- **Cross-surface notifications** — replying to someone's discussion notifies them through BuddyNext's notification system.

All of this is delivered **by the bridge**, with zero configuration beyond activating Jetonomy. The one toggle is `buddynext_jetonomy_feed_sync` (default off) — mirroring forum posts into the activity feed, which is intentionally opt-in to avoid feed noise.

## Preconditions

- BuddyNext Free + **Jetonomy active** on http://buddynext-dev.local/ (LocalWP dev site). Confirmed active locally: `jetonomy/jetonomy.php` (and `jetonomy-pro`) in `active_plugins`.
- Partner tables present: `wp_jt_posts`, `wp_jt_spaces`, `wp_jt_replies`, `wp_jt_tags`, `wp_jt_post_tags` (and the rest of the `wp_jt_*` set).
- Forum base slug = `community` (the local `jetonomy_settings` option has no `base_slug` key, so the bridge falls back to `community`; the forum home is `/community/`).
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`.
- At least one Jetonomy space exists to post into. Use the partner tooling to create one if needed (the Jetonomy MCP `jetonomy_create_space` / `jetonomy_list_spaces`, or the forum UI at `/community/`). Note the partner space ID (referred to as `JT_SPACE_ID`).

> All discussion/reply CRUD below is **partner-owned**. We trigger it via the Jetonomy MCP (or the `/community/` UI) only to fire the partner hooks the bridge listens to. The assertions are all on **BuddyNext-side** state (`wp_bn_search_index`, `wp_bn_notifications`, rail/tab/profile injection).

## Happy-path steps

### Part 1: Create a discussion → bridge indexes it into BN search

1. As `member1`, create a Jetonomy discussion in `JT_SPACE_ID`. Via the Jetonomy MCP:

   ```
   jetonomy_create_post(space_id=JT_SPACE_ID, title="Journey Bridge Discussion",
                         content="Testing the bridge indexing. cc @member2")
   ```

   (Or post through the forum UI at `http://buddynext-dev.local/community/` while logged in as `member1`.)

   - Expected: discussion created; note the returned discussion ID (`JT_POST_ID`). Creating it fires the partner action `jetonomy_after_create_post( JT_POST_ID, JT_SPACE_ID )`, which the bridge's `on_post_created()` handles.

2. Verify the bridge indexed the discussion into BuddyNext search. The bridge reads `author_id, title, content_plain` from `wp_jt_posts` and calls `SearchService::index( 'discussion', JT_POST_ID, title, content, author_id, 'public', JT_SPACE_ID )`:

   ```sql
   SELECT object_type, object_id, title, author_id, space_id, visibility
   FROM wp_bn_search_index
   WHERE object_type = 'discussion' AND object_id = JT_POST_ID;
   ```

   - Expected: 1 row, `object_type = discussion`, `object_id = JT_POST_ID`, `space_id = JT_SPACE_ID`, `visibility = public`, `author_id = member1 ID`.

3. Confirm the discussion is returned by the unified search REST endpoint (the bridge does not add a route — it feeds the existing BN search index, and `grouped_search()` discovers the new `discussion` type dynamically):

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=Journey%20Bridge%20Discussion"
   ```

   - Expected: 200. Response includes a `discussion` group containing `object_id = JT_POST_ID`.

4. Confirm the `@mention` in the body fired `buddynext_user_mentioned`. The bridge regex-matches `@member2` and fires `do_action( 'buddynext_user_mentioned', member2_ID, member1_ID, 'jetonomy_post', JT_POST_ID )`. Mentions route through BuddyNext's notification pipeline, so check for a mention notification to `member2`:

   ```sql
   SELECT recipient_id, sender_id, type, object_type, object_id
   FROM wp_bn_notifications
   WHERE recipient_id = MEMBER2_ID AND object_id = JT_POST_ID
   ORDER BY id DESC LIMIT 5;
   ```

   - Expected: a mention notification row for `member2` (provided a listener is attached to `buddynext_user_mentioned`; the action firing is the bridge's responsibility and is what this step validates).

### Part 2: Discussions rail link (unified nav)

5. Confirm the bridge injects the **Discussions** rail item via `buddynext_rail_items` (the CURRENT correct seam — this was recently fixed from the now-dead `buddynext_nav_items`). Load any BuddyNext hub page as `member1` and inspect the left rail, or evaluate the filter directly:

   ```bash
   wp eval '$items = apply_filters("buddynext_rail_items", array(), "home"); foreach($items as $i){ echo $i["key"]." => ".$i["url"]."\n"; }'
   ```

   - Expected: a `discussions` rail item with `label = "Discussions"`, `icon = list`, `url = http://buddynext-dev.local/community/`, `show = true`. The `active` flag is true only when the current `REQUEST_URI` starts with the forum path.

6. Visit the rail link target and confirm the unified nav wraps the forum:

   ```bash
   curl -s "http://buddynext-dev.local/community/" | grep -c "bn-jt-content"
   ```

   - Expected: the forum home renders with BuddyNext nav injected above content (the bridge's `open_hub_shell()` on `jetonomy_before_content` priority 5 prints `partials/nav.php` + `<div class="bn-jt-content">`), and Jetonomy's own community nav suppressed (`jetonomy_show_community_nav → false`). Jetonomy's own right sidebar is preserved — no double sidebar.

### Part 3: Space Forum tab (linked space)

7. Link a BuddyNext space to a Jetonomy forum space by setting the per-space option the bridge reads (`bn_space_{space_id}_jetonomy_forum_id`). Pick an existing BN space ID (`BN_SPACE_ID`) and point it at `JT_SPACE_ID`:

   ```bash
   wp option update bn_space_BN_SPACE_ID_jetonomy_forum_id JT_SPACE_ID
   ```

8. Confirm the bridge injects a **Discussions** tab into that space's tab map via `buddynext_space_tabs`. The bridge looks up the Jetonomy space slug from `wp_jt_spaces` and builds a URL under the forum base:

   ```bash
   wp eval '$tabs = apply_filters("buddynext_space_tabs", array(), BN_SPACE_ID); var_export($tabs);'
   ```

   - Expected: a `discussions` tab keyed entry: `['label' => 'Discussions', 'url' => 'http://buddynext-dev.local/community/s/{jt_slug}/']`. For a space with no link option set (`= 0`), the tab is absent.

### Part 4: Profile discussion count

9. Confirm the bridge injects the profile **Discussions** stat via `buddynext_profile_extra_data`. It counts `wp_jt_posts` rows authored by the profile user with `status = 'publish'`:

   ```bash
   wp eval '$extra = apply_filters("buddynext_profile_extra_data", array(), MEMBER1_ID); var_export($extra);'
   ```

   - Expected: an entry `['label' => 'Discussions', 'value' => N]` where `N` matches the publish-status discussion count for `member1`. After step 1, `N` should be at least 1 (assuming the post is `publish` status).

   Cross-check against the partner table:

   ```sql
   SELECT COUNT(*) AS n FROM wp_jt_posts
   WHERE author_id = MEMBER1_ID AND status = 'publish';
   ```

   - Expected: the rail value and this count agree.

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals)**: deactivate Jetonomy, then load BuddyNext pages and run the filter evals from Parts 2-4.

  ```bash
  wp plugin deactivate jetonomy jetonomy-pro
  wp eval '$items = apply_filters("buddynext_rail_items", array(), "home"); echo count($items)."\n";'   # no "discussions" item
  curl -s -o /dev/null -w "%{http_code}\n" "http://buddynext-dev.local/members/member1/"                 # 200, no fatal
  wp plugin activate jetonomy jetonomy-pro
  ```

  - Expected: `JetonomyBridge::init()` returns early at the `class_exists( 'Jetonomy\Jetonomy' )` guard, so **none** of its filters/actions are registered — no Discussions rail item, no space tab, no profile count, and no PHP fatals anywhere. The bridge adds zero overhead on sites without Jetonomy.

- **Reply triggers a BN notification to the post author**: as `member2`, reply to `member1`'s discussion (`JT_POST_ID`).

  ```
  jetonomy_create_reply(post_id=JT_POST_ID, content="Replying from the journey test")
  ```

  This fires `jetonomy_after_create_reply( reply_id, JT_SPACE_ID )`, handled by the bridge's `notify_discussion_reply()`, which loads the reply + parent post (`Jetonomy\Models\Reply::find`, `Jetonomy\Models\Post::find`) and calls `NotificationService::create()` for the post author.

  ```sql
  SELECT recipient_id, sender_id, type, object_type, object_id, message
  FROM wp_bn_notifications
  WHERE recipient_id = MEMBER1_ID AND type = 'bn.jetonomy_reply'
  ORDER BY id DESC LIMIT 1;
  ```

  - Expected: 1 row — `recipient_id = member1` (post author), `sender_id = member2` (replier), `type = bn.jetonomy_reply`, `object_type = jetonomy_post`, `object_id = JT_POST_ID`, message "member2 replied to your discussion". **Self-reply guard**: if `member1` replies to their own post, no notification is created (`reply_author_id === post_author_id` short-circuit).

- **Discussion delete cleans the index**: delete `JT_POST_ID` via the partner (`jetonomy_delete_post`), firing `jetonomy_post_deleted`. The bridge's `on_post_deleted()` removes the index row.

  ```sql
  SELECT COUNT(*) AS n FROM wp_bn_search_index
  WHERE object_type = 'discussion' AND object_id = JT_POST_ID;
  ```

  - Expected: `n = 0` after delete.

- **Hashtag ↔ tag cross-link**: tag a discussion with a slug that also exists as a BN hashtag, then render that hashtag feed. The bridge's `get_related_discussions()` (filter `buddynext_hashtag_related_discussions`) returns up to 5 Jetonomy posts sharing the tag slug.

  ```bash
  wp eval '$d = apply_filters("buddynext_hashtag_related_discussions", array(), "TAGSLUG"); echo count($d)."\n"; var_export($d);'
  ```

  - Expected: array of discussion entries (each with `source => jetonomy`, `id`, `title`, `slug`, `reply_count`, `vote_score`). Empty when `Jetonomy\Models\Tag` is absent or the slug does not exist.

## What this validates

Bridge seams (every assertion is BuddyNext-side; partner CRUD is only the trigger):

- `JetonomyBridge::init()` gates on `class_exists( 'Jetonomy\Jetonomy' )` — registers nothing when the partner is inactive.
- `on_post_created()` (on `jetonomy_after_create_post`) reads `wp_jt_posts` and calls `SearchService::index( 'discussion', ... )` → row in `wp_bn_search_index`; parses `@mentions` → fires `buddynext_user_mentioned`; fires `buddynext_jetonomy_post_indexed`; **opt-in only** writes a `forum_post` row to `wp_bn_posts` when `buddynext_jetonomy_feed_sync` is on.
- `on_post_deleted()` (on `jetonomy_post_deleted`) deletes the `discussion` index row (and the opt-in feed card when sync is on).
- `inject_discussions_nav_item()` appends a Discussions item to `buddynext_rail_items` — the **current correct seam** (replaced the dead `buddynext_nav_items`), URL derived from `jetonomy_settings['base_slug']` ?? `community` via `home_url()`.
- `open_hub_shell()` / `close_hub_shell()` (on `jetonomy_before_content` p5 / `jetonomy_after_content`) inject BN nav + `.bn-jt-content` wrapper; `jetonomy_show_community_nav → false` suppresses the partner nav. No `bn-hub-shell` wrapper (avoids double sidebar).
- `inject_space_forum_tab()` adds a Discussions tab to `buddynext_space_tabs` when `bn_space_{id}_jetonomy_forum_id` is set, resolving the slug from `wp_jt_spaces`.
- `inject_profile_discussion_count()` adds a Discussions stat to `buddynext_profile_extra_data`, counting `publish` posts in `wp_jt_posts` by author.
- `inject_discussion_context_nav()` adds Home / Search / Leaderboard items to `buddynext_context_nav` only when `$section === 'discussions'`.
- `notify_discussion_reply()` (on `jetonomy_after_create_reply`) creates a `bn.jetonomy_reply` notification for the post author via `NotificationService::create()`, with a self-reply guard.
- `get_related_discussions()` (filter `buddynext_hashtag_related_discussions`) returns up to 5 tag-matched Jetonomy posts, gated on `Jetonomy\Models\Tag`.

## Verification queries

```sql
-- Discussion rows the bridge indexed:
SELECT object_type, object_id, title, author_id, space_id, visibility, created_at
FROM wp_bn_search_index
WHERE object_type = 'discussion'
ORDER BY object_id DESC;

-- Count of indexed discussions:
SELECT COUNT(*) AS discussions_indexed
FROM wp_bn_search_index
WHERE object_type = 'discussion';

-- Reply notifications the bridge created:
SELECT recipient_id, sender_id, type, object_type, object_id, created_at
FROM wp_bn_notifications
WHERE type = 'bn.jetonomy_reply'
ORDER BY id DESC;

-- Opt-in feed cards (only present if buddynext_jetonomy_feed_sync is on):
SELECT id, user_id, type, link_url, status
FROM wp_bn_posts
WHERE type = 'forum_post'
ORDER BY id DESC;

-- Partner discussion count for a user (cross-check the profile stat):
SELECT author_id, COUNT(*) AS n
FROM wp_jt_posts
WHERE status = 'publish'
GROUP BY author_id;

-- Feed-sync toggle state (expected: empty / 0 = off by default):
SELECT option_name, option_value FROM wp_options
WHERE option_name = 'buddynext_jetonomy_feed_sync';
```

## REST surface walked

The bridge registers **no routes of its own**. It feeds the existing BuddyNext search index, so the only BN-side route exercised is unified search (the `discussion` type is discovered dynamically by `grouped_search()`):

```
GET  /wp-json/buddynext/v1/search?q={term}            -- 200, grouped results incl. a "discussion" group (public)
GET  /wp-json/buddynext/v1/search/members?q={term}    -- 200, member results (unaffected by the bridge; listed for completeness)
```

Forum content itself is served by **Jetonomy's own route** at `/community/` (partner-owned, not a BN REST endpoint). The Discussions rail link and space Forum tab point at `/community/` and `/community/s/{slug}/` respectively.

> Re-confirm BN search reflects discussions live (partner active): `curl -s "http://buddynext.local/wp-json/buddynext/v1/search?q=test" | python3 -m json.tool | grep -i discussion`

## Bridge contract & partner gate

*(Item 11, bridge form. The arg-counts below are load-bearing — a past bug came from registering with the wrong count; verify them against the partner's `do_action` signatures.)*

| Direction | Hook (exact arg count) | Handler | Guard |
|---|---|---|---|
| Jetonomy → BN | `jetonomy_after_create_post(post_id, space_id)` **2 args** | `JetonomyBridge::on_post_created` (`:43`) | bails if `! class_exists('Jetonomy\Jetonomy')` (`JetonomyBridge:38`) |
| Jetonomy → BN | `jetonomy_post_deleted(post_id, space_id, user_id)` **3 args** | `on_post_deleted` (`:45`) | same |
| Jetonomy → BN | `jetonomy_after_create_reply(...)` | reply → BN notification (`JetonomyBridgeListener`) | bails if class missing (`Listener:49`) |
| Jetonomy → BN | `jetonomy_notification_created(...)` **7 args** | `on_notification` (`Listener:53`) | same |
| BN → Jetonomy | `jetonomy_before_content` (inject BN subnav), `jetonomy_show_community_nav → false` (suppress partner nav) | `JetonomyBridge` | same |

**Frontend cross-namespace call:** the hashtag/feed Jetonomy upvote button posts to a **partner** route, not BN: `assets/js/hashtags/store.js:139` → `POST jetonomy/v1/posts/{id}/vote` (nonce `ctx.restNonce`). Verify that route exists in the **jetonomy** namespace (`curl -s http://buddynext.local/wp-json/jetonomy/v1 | grep vote`), not buddynext/v1.

**Verify this run (`jetonomy` IS active here):**
1. Create a discussion in Jetonomy `/community/` → confirm it surfaces in BN `GET /search?q=` under a `discussion` group and (if wired) as feed activity.
2. **Graceful no-op:** deactivate Jetonomy → BN loads with no fatal, the Discussions rail link/Forum tab hide, search returns no discussion group. Reactivate.

## Admin-config → member-effect

*(Item 12.)*

- **Jetonomy feature toggle** (Settings → Features → "Jetonomy"): OFF → the bridge does not inject the unified nav / discussion activity even with the partner active; ON → restored.
- **Unified nav:** with the bridge active, loading a Jetonomy `/community/` page must show the BN subnav and NOT Jetonomy's own community nav (the `jetonomy_show_community_nav → false` suppression).

## Cleanup

```sql
-- Remove indexed discussion rows created during this journey:
DELETE FROM wp_bn_search_index WHERE object_type = 'discussion';

-- Remove reply notifications created by the bridge:
DELETE FROM wp_bn_notifications WHERE type = 'bn.jetonomy_reply';

-- Remove any opt-in feed cards (only if feed sync was enabled during the run):
DELETE FROM wp_bn_posts WHERE type = 'forum_post';
```

```bash
# Remove the per-space forum link option set in Part 3:
wp option delete bn_space_BN_SPACE_ID_jetonomy_forum_id

# Ensure the feed-sync toggle is left off (default):
wp option delete buddynext_jetonomy_feed_sync

# Delete the partner discussion/reply created for the run (partner-owned):
#   jetonomy_delete_post(post_id=JT_POST_ID)   (also fires the bridge index cleanup)
```

> Partner data (`wp_jt_posts`, `wp_jt_replies`, `wp_jt_spaces`) is owned by Jetonomy — delete discussions/replies through the partner (`jetonomy_delete_post` / `jetonomy_delete_reply` / the forum UI), not by raw SQL against `wp_jt_*`, so the partner's own counters and the bridge's delete hooks stay consistent.

## Known limitations

- **Feed sync is opt-in and off by default** (`buddynext_jetonomy_feed_sync`). With it off, discussions are searchable but do NOT appear as activity-feed cards in `wp_bn_posts` — this is intentional (avoids reply fragmentation / feed noise), not a gap.
- **`buddynext_user_mentioned` only delivers a notification if a listener is attached.** The bridge's job ends at firing the action with the resolved mentioned-user ID; whether `member2` actually receives a mention notification depends on BuddyNext's mention-notification subscriber, not the bridge.
- **`inject_discussion_context_nav()` depends on the caller passing `$section`.** It only injects when the active section equals `discussions`; the section value is supplied by whatever renders `buddynext_context_nav`, so context-nav items will not appear unless the forum surface sets that section.
- **`base_slug` fallback.** The local `jetonomy_settings` option has no `base_slug`, so the forum base resolves to `community`. On a site that customises the Jetonomy base slug, every URL the bridge builds (rail link, space tab, context nav) follows that custom slug automatically.
- **Profile/related-discussion lookups read partner tables/models directly** (`wp_jt_posts`, `Jetonomy\Models\Tag`, `Jetonomy\Models\Post`, `Jetonomy\Models\Reply`). If Jetonomy changes those table columns or model APIs, these bridge methods are the coupling points to update.
- **Self-reply produces no notification** (by design — the `reply_author_id === post_author_id` guard in `notify_discussion_reply()`).

## Automation notes

- Partner CRUD is automatable via the **Jetonomy MCP** (`jetonomy_create_space`, `jetonomy_create_post`, `jetonomy_create_reply`, `jetonomy_delete_post`) — use it as the trigger, then assert on BN-side state.
- All BN-side assertions are DB queries (`wp_bn_search_index`, `wp_bn_notifications`, `wp_bn_posts`) plus filter evals (`wp eval 'apply_filters(...)'`) — no frontend assertions required (design is not complete).
- Collect `JT_POST_ID` and `JT_SPACE_ID` from the create responses; do not hardcode IDs.
- The "bridge inert when partner inactive" edge case is the highest-value automated check — deactivate Jetonomy, assert no `discussions` rail item and HTTP 200 (no fatal), reactivate. It guards the opt-in contract.
- The reply-notification path requires the Jetonomy Pro model classes (`Jetonomy\Models\Reply` / `Post`) to be loadable; the bridge guards on `class_exists` and silently no-ops if they are absent, so assert presence before expecting the `bn.jetonomy_reply` row.
```
