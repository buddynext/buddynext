# Journey: Jetonomy (Forums / Discussions) Bridge

**Bridge feature**: `includes/Bridges/JetonomyBridge.php` + `includes/Bridges/JetonomyBridgeListener.php` (opt-in — only attach hooks when the **Jetonomy** plugin is active)
**Requires**: Jetonomy plugin active (`class_exists( 'Jetonomy\Jetonomy' )`). Both `JetonomyBridge::init()` (`JetonomyBridge.php:38`) and `JetonomyBridgeListener::register()` (`JetonomyBridgeListener.php:49`) bail immediately and register nothing when Jetonomy is absent.
**Partner plugin**: Jetonomy (forums / Q&A / ideas), `wp_jt_*` tables, `/community/` route. **Partner-owned** behaviour (discussion CRUD, replies, votes, spaces, leaderboard, its own REST + its own notification UI/emails) is NOT documented here except where the bridge consumes it.
**Partner hooks consumed** (fired by Jetonomy, listened by the bridge): `jetonomy_after_create_post` (2 args: `$post_id, $space_id`), `jetonomy_post_deleted` (3 args: `$post_id, $space_id, $user_id`), `jetonomy_after_create_reply` (**bridge consumes 1 arg**: `$reply_id`), `jetonomy_notification_created` (7 args, via the listener), and the read-time filter `option_jetonomy_pro_extensions` (BN suppresses Jetonomy Pro's private-messaging extension so BN owns the `/messages/` route).
**BuddyNext seams the bridge injects into** (filters/actions): `buddynext_rail_items` (personal "my Discussions" rail link → the viewer's own profile Discussions tab), `buddynext_register_nav` (the unified **Nav API** — registers a Discussions tab on BOTH the `profile` and `space` surfaces; this **replaced** the retired `buddynext_space_tabs` + `buddynext_profile_extra_data` seams), `buddynext_context_nav` (Level-2 Discussion sub-nav), `buddynext_hashtag_related_discussions` (hashtag ↔ tag cross-link). The bridge also registers a BN REST route (`POST buddynext/v1/spaces/{id}/forum`) and a `template_redirect` (p5) handler for on-demand forum provisioning.
**Actions the bridge fires**: `buddynext_user_mentioned` (3 args: `$mentioned_id, $author_id, $post_id`), `buddynext_jetonomy_post_indexed` (5 args: `$post_id, $space_id, $author_id, $title, $content`); plus the gate filter `buddynext_jetonomy_discussion_activity` (2 args, default `true`).
**DB tables touched by the bridge**: writes/deletes `wp_bn_search_index` (always-on); writes/removes a `discussion` activity card in `wp_bn_posts` (via `Feed\IntegrationActivity`, **default-ON, public-only**); writes `wp_bn_notifications` (a `bn.jetonomy_reply` row via `NotificationService` on reply, plus a `jt.notification` mirror row per Jetonomy notification via the listener). Reads partner tables `wp_jt_posts`, `wp_jt_spaces`, `wp_jt_replies`. Stores the per-space link option `bn_space_{space_id}_jetonomy_forum_id`.
**Estimated time**: 14 min manual

## Site-owner expectation

When a community owner activates Jetonomy alongside BuddyNext, they expect **plug-and-play forums inside the BN community** — surfaced through BuddyNext's own navigation, while Jetonomy's own pages are left untouched:

- **A personal "Discussions" shortcut** appears in the BuddyNext left navigation rail (in the personal "You" group), pointing at the **viewer's own** profile Discussions tab (the discussions they authored). It only shows when logged in.
- **Discussions tabs on profiles and spaces** — every member profile and every space gets a **Discussions** tab via the unified Nav API. The profile tab carries a live count of that member's published discussions; the space tab links to the space's forum (provisioning it on demand the first time a member opens it).
- **Search includes discussions** — typing in BuddyNext unified search returns forum discussions alongside posts, members, and spaces.
- **Public discussions flow into the feed** — a new discussion in a *public* space appears as a `discussion` activity card in the home feed / Explore (default-on; the owner can turn it off).
- **Cross-surface notifications** — replying to someone's discussion notifies them through BuddyNext's notification system, and every Jetonomy notification (replies, mentions, accepted answers, …) is also mirrored into BuddyNext's notification center.
- **BuddyNext owns messaging** — when BN messaging is available the bridge suppresses Jetonomy Pro's private-messaging extension at read time so BN's own `/messages/` route is not hijacked.
- **Jetonomy's own pages are left alone** — by owner rule the bridge does **not** inject BN nav into Jetonomy's `/community/` pages or suppress Jetonomy's own community nav. The link *into* discussions lives entirely on BuddyNext's own surfaces (rail + profile/space tabs).

All of this is delivered **by the bridge**, with zero configuration beyond activating Jetonomy. The one toggle is `buddynext_jetonomy_feed_sync` (**default ON** — `RecommendedDefaults.php:52`), surfaced in admin as Settings → Integrations → "Jetonomy Feed Sync"; turning it off stops mirroring public discussions into the activity feed.

## Preconditions

- BuddyNext Free + **Jetonomy active** on http://buddynext-dev.local/ (LocalWP dev site). Confirmed active locally: `jetonomy/jetonomy.php` (and `jetonomy-pro`) in `active_plugins`.
- Partner tables present: `wp_jt_posts`, `wp_jt_spaces`, `wp_jt_replies`, `wp_jt_tags`, `wp_jt_post_tags` (and the rest of the `wp_jt_*` set).
- Forum base slug = `community` (the local `jetonomy_settings` option has no `base_slug` key, so the bridge falls back to `community`; the forum home is `/community/`).
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it).
- Member users: `member1` / `password`, `member2` / `password`.
- At least one Jetonomy space exists to post into. Use the partner tooling to create one if needed (the Jetonomy MCP `jetonomy_create_space` / `jetonomy_list_spaces`, or the forum UI at `/community/`). Note the partner space ID (referred to as `JT_SPACE_ID`).

> All discussion/reply CRUD below is **partner-owned**. We trigger it via the Jetonomy MCP (or the `/community/` UI) only to fire the partner hooks the bridge listens to. The assertions are all on **BuddyNext-side** state (`wp_bn_search_index`, `wp_bn_posts`, `wp_bn_notifications`, rail/nav injection, the BN REST route).

## Happy-path steps

### Part 1: Create a discussion → bridge indexes it into BN search + feed

1. As `member1`, create a Jetonomy discussion in `JT_SPACE_ID`. Via the Jetonomy MCP:

   ```
   jetonomy_create_post(space_id=JT_SPACE_ID, title="Journey Bridge Discussion",
                         content="Testing the bridge indexing. cc @member2")
   ```

   (Or post through the forum UI at `http://buddynext-dev.local/community/` while logged in as `member1`.)

   - Expected: discussion created; note the returned discussion ID (`JT_POST_ID`). Creating it fires the partner action `jetonomy_after_create_post( JT_POST_ID, JT_SPACE_ID )` (2 args), which the bridge's `on_post_created()` handles (`JetonomyBridge.php:126`).

2. Verify the bridge indexed the discussion into BuddyNext search. The bridge reads `author_id, title, content_plain, is_private, status` from `wp_jt_posts` and calls `SearchService::index( 'discussion', JT_POST_ID, title, content, author_id, 'public', JT_SPACE_ID )` (`JetonomyBridge.php:147`):

   ```sql
   SELECT object_type, object_id, title, author_id, space_id, visibility
   FROM wp_bn_search_index
   WHERE object_type = 'discussion' AND object_id = JT_POST_ID;
   ```

   - Expected: 1 row, `object_type = discussion`, `object_id = JT_POST_ID`, `space_id = JT_SPACE_ID`, `visibility = public`, `author_id = member1 ID`.

3. Confirm the discussion is returned by the unified search REST endpoint (the bridge does not add a search route — it feeds the existing BN search index, and `grouped_search()` discovers the new `discussion` type dynamically):

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=Journey%20Bridge%20Discussion"
   ```

   - Expected: 200. Response includes a `discussion` group containing `object_id = JT_POST_ID`.

4. Confirm the `@mention` in the body fired `buddynext_user_mentioned`. The bridge collects unique `@logins`, resolves them in one `get_users()` query, and fires `do_action( 'buddynext_user_mentioned', member2_ID, member1_ID, JT_POST_ID )` (3 args: `$mentioned_id, $author_id, $post_id` — `JetonomyBridge.php:184`). Mentions route through BuddyNext's notification pipeline (`NotificationListener::on_user_mentioned`), so check for a mention notification to `member2`:

   ```sql
   SELECT recipient_id, sender_id, type, object_type, object_id
   FROM wp_bn_notifications
   WHERE recipient_id = MEMBER2_ID AND object_id = JT_POST_ID
   ORDER BY id DESC LIMIT 5;
   ```

   - Expected: a mention notification row for `member2` (the action firing with the resolved mentioned-user ID is the bridge's responsibility and is what this step validates).

5. Confirm the public discussion surfaced as a **feed activity** card. Feed sync is ON by default (`get_option( 'buddynext_jetonomy_feed_sync', '1' )` — `JetonomyBridge.php:200`); for a discussion in a **public** Jetonomy space (`is_public_discussion()` — public space visibility + non-private topic + `publish` status, `JetonomyBridge.php:270`), the bridge calls `IntegrationActivity::publish( author_id, 'started a discussion', discussion_url, title, 'discussion', excerpt )` (`JetonomyBridge.php:205`):

   ```sql
   SELECT id, user_id, type, privacy, link_url
   FROM wp_bn_posts
   WHERE type = 'discussion'
   ORDER BY id DESC LIMIT 5;
   ```

   - Expected: 1 row — `type = discussion`, `privacy = public`, `user_id = member1 ID`, `link_url = base/s/{space_slug}/t/{post_slug}/` (the public Jetonomy permalink built by `discussion_url()` — `JetonomyBridge.php:298`). A discussion in a **private/secret** space, a private topic, or a non-`publish` post produces **no** feed card. The card is idempotent (`PostService::exists_by_link`) — re-firing the hook does not duplicate it.

### Part 2: Personal Discussions rail link (your own discussions)

6. Confirm the bridge injects the personal **Discussions** rail item via `buddynext_rail_items`. Unlike a global forum link, this points at the **logged-in viewer's own** profile Discussions tab (`inject_discussions_nav_item()` — `JetonomyBridge.php:328`). Load any BuddyNext hub page as `member1` and inspect the left rail, or evaluate the filter directly:

   ```bash
   wp eval '$items = apply_filters("buddynext_rail_items", array()); foreach($items as $i){ if(($i["key"] ?? "")==="discussions"){ var_export($i); } }'
   ```

   - Expected: a `discussions` rail item with `label = "Discussions"`, `icon = list`, `group = you`, `order = 206`, `show = true`, and `url = http://buddynext-dev.local/members/member1/discussions/` (the viewer's own profile Discussions tab, via `PageRouter::profile_url()`). The `active` flag is true only when the current `REQUEST_URI` starts with that profile-tab path. As a **guest** (logged out) the item is absent — there is no "my discussions" for a guest.

### Part 3: Discussions tab on the space surface (Nav API + on-demand forum)

The space Discussions tab is registered through the unified Nav API (`buddynext_register_nav` → `register_nav_items()` — `JetonomyBridge.php:556`), **not** the retired `buddynext_space_tabs` filter. The space tab is a clean URL (`/spaces/{id}/discussions/`) with a count badge of the linked forum's published threads.

7. Pick an existing BN space ID (`BN_SPACE_ID`). It does **not** need a forum yet — the tab provisions one on demand. (If you want to pre-link an existing Jetonomy forum, set the link option the bridge reads:)

   ```bash
   # Optional pre-link to an existing Jetonomy forum (otherwise it is provisioned on demand):
   wp option update bn_space_BN_SPACE_ID_jetonomy_forum_id JT_SPACE_ID --autoload=no
   ```

8. Confirm the space Discussions count the tab badges, via the bridge helper (the tab itself is registered in the Nav registry and rendered on the space page — verify it in the browser by loading the space and looking for a **Discussions** tab):

   ```bash
   wp eval '$b = new BuddyNext\Bridges\JetonomyBridge(); echo $b->space_discussion_count(BN_SPACE_ID)."\n";'
   ```

   - Expected: an integer count of `publish` discussions in the linked Jetonomy forum (`space_discussion_count()` maps `BN_SPACE_ID` → the forum id stored in `bn_space_{id}_jetonomy_forum_id`, then counts `wp_jt_posts` by that forum's `space_id` — `JetonomyBridge.php:727`). For a space with no forum linked yet, the count is `0` and the tab's empty state offers the on-demand provision trigger.

9. (On-demand provisioning) For a space with no forum, the Discussions tab's empty state links to a provision-trigger URL (`/spaces/?bn_provision_forum={id}&_bnpf={nonce}` — `provision_forum_url()`, `JetonomyBridge.php:757`). Visiting it as a space owner/moderator runs `maybe_provision_and_redirect()` on `template_redirect` priority 5 (`JetonomyBridge.php:438`): it verifies the per-space nonce (`bn_provision_forum_{id}`), checks the `buddynext-moderate-space` capability, creates a paired Jetonomy forum (`Jetonomy\Models\Space::create`), stores `bn_space_{id}_jetonomy_forum_id`, and redirects to the new forum.

   ```sql
   -- After provisioning, the link option exists:
   SELECT option_value FROM wp_options WHERE option_name = 'bn_space_BN_SPACE_ID_jetonomy_forum_id';
   ```

   - Expected: a non-zero Jetonomy forum id. The provisioning is idempotent — a second visit returns the existing forum, never a duplicate.

### Part 4: Discussions tab on the profile surface (Nav API + count badge)

The profile Discussions tab is registered through the same Nav API call (`register_nav_items()` — `JetonomyBridge.php:556`), surface `profile`, with a live count badge of the member's published discussions. This **replaced** the retired `buddynext_profile_extra_data` seam.

10. Confirm the profile Discussions count the tab badges, via the bridge helper (and verify the **Discussions** tab renders on `member1`'s profile in the browser):

    ```bash
    wp eval '$b = new BuddyNext\Bridges\JetonomyBridge(); echo $b->discussion_count(MEMBER1_ID)."\n";'
    ```

    - Expected: an integer matching the count of `publish` discussions authored by `member1` (`discussion_count()` runs `SELECT COUNT(*) FROM wp_jt_posts WHERE author_id = %d AND status = 'publish'` — `JetonomyBridge.php:605`). After Part 1, this is at least `1` (assuming the post is `publish` status).

    Cross-check against the partner table:

    ```sql
    SELECT COUNT(*) AS n FROM wp_jt_posts
    WHERE author_id = MEMBER1_ID AND status = 'publish';
    ```

    - Expected: the badge value and this count agree. The tab's panel content is fed by `user_discussions()` (`JetonomyBridge.php:632`), which the template calls instead of reaching into `wp_jt_*` itself.

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals)**: deactivate Jetonomy, then load BuddyNext pages and run the rail eval from Part 2.

  ```bash
  wp plugin deactivate jetonomy jetonomy-pro
  wp eval '$items = apply_filters("buddynext_rail_items", array()); echo count(array_filter($items, fn($i)=>($i["key"]??"")==="discussions"))."\n";'   # 0
  curl -s -o /dev/null -w "%{http_code}\n" "http://buddynext-dev.local/members/member1/"                 # 200, no fatal
  wp plugin activate jetonomy jetonomy-pro
  ```

  - Expected: both `JetonomyBridge::init()` and `JetonomyBridgeListener::register()` return early at the `class_exists( 'Jetonomy\Jetonomy' )` guard, so **none** of their filters/actions are registered — no Discussions rail item, no profile/space Discussions tab, no feed sync, no mirrored notifications, and no PHP fatals anywhere. The bridge adds zero overhead on sites without Jetonomy.

- **Reply triggers a BN notification to the post author**: as `member2`, reply to `member1`'s discussion (`JT_POST_ID`).

  ```
  jetonomy_create_reply(post_id=JT_POST_ID, content="Replying from the journey test")
  ```

  This fires `jetonomy_after_create_reply( reply_id, JT_SPACE_ID )`. The bridge consumes **1 arg** (`notify_discussion_reply( $reply_id )` — `JetonomyBridge.php:792`), loads the reply + parent post (`Jetonomy\Models\Reply::find`, `Jetonomy\Models\Post::find`) and calls `NotificationService::create()` for the post author.

  ```sql
  SELECT recipient_id, sender_id, type, object_type, object_id, message
  FROM wp_bn_notifications
  WHERE recipient_id = MEMBER1_ID AND type = 'bn.jetonomy_reply'
  ORDER BY id DESC LIMIT 1;
  ```

  - Expected: 1 row — `recipient_id = member1` (post author), `sender_id = member2` (replier), `type = bn.jetonomy_reply`, `object_type = jetonomy_post`, `object_id = JT_POST_ID`, message "member2 replied to your discussion". **Self-reply guard**: if `member1` replies to their own post, no notification is created (`reply_author_id === post_author_id` short-circuit — `JetonomyBridge.php:811`).

- **Every Jetonomy notification is mirrored into the BN center**: any Jetonomy notification fires `jetonomy_notification_created( id, user_id, type, object_type, object_id, message, url )` (7 args), which `JetonomyBridgeListener::on_notification()` (`JetonomyBridgeListener.php:94`) mirrors into a single BN type `jt.notification`. The mirror is block-aware (skips when either party blocked the other) and deduped on `group_key = jt_{type}_{object_id}`.

  ```sql
  SELECT recipient_id, sender_id, type, object_type, object_id, group_key
  FROM wp_bn_notifications
  WHERE type = 'jt.notification'
  ORDER BY id DESC LIMIT 5;
  ```

  - Expected: one `jt.notification` row per mirrored Jetonomy notification. The type renders straight from the stored `data.message` / `data.url` via the three render seams (`buddynext_notification_message/url/meta` — `JetonomyBridgeListener.php:56-58`) and is **collect-only**: the prefs catalogue marks it `can_email = false` (`JetonomyBridgeListener.php:78`) because Jetonomy sends its own emails — BN never double-emails.

- **Discussion delete cleans the index and the feed card**: delete `JT_POST_ID` via the partner (`jetonomy_delete_post`), firing `jetonomy_post_deleted` (3 args). The bridge's `on_post_deleted()` (`JetonomyBridge.php:235`) removes the search-index row and calls `IntegrationActivity::remove( discussion_url, 'discussion' )` to drop the feed card.

  ```sql
  SELECT
    (SELECT COUNT(*) FROM wp_bn_search_index WHERE object_type='discussion' AND object_id=JT_POST_ID) AS idx_rows,
    (SELECT COUNT(*) FROM wp_bn_posts WHERE type='discussion' AND link_url LIKE '%/t/%') AS feed_rows;
  ```

  - Expected: `idx_rows = 0` after delete (Jetonomy soft-deletes to `trash`, so the `jt_*` rows still exist and the URL resolves for the removal).

- **Hashtag ↔ tag cross-link**: tag a discussion with a slug that also exists as a BN hashtag, then render that hashtag feed. The bridge's `get_related_discussions()` (filter `buddynext_hashtag_related_discussions`, 2 args — `JetonomyBridge.php:880`) returns up to 5 Jetonomy posts sharing the tag slug.

  ```bash
  wp eval '$d = apply_filters("buddynext_hashtag_related_discussions", array(), "TAGSLUG"); echo count($d)."\n"; var_export($d);'
  ```

  - Expected: array of discussion entries (each with `source => jetonomy`, `id`, `title`, `slug`, `url`, `author_id`, `author_name`, `reply_count`, `vote_score`, `created_at`). Empty when `Jetonomy\Models\Tag` is absent or the slug does not exist.

- **App coverage: provision a space forum over REST**: the bridge registers `POST /wp-json/buddynext/v1/spaces/{id}/forum` (`register_rest_routes()` — `JetonomyBridge.php:509`). It provisions (or fetches) the space's forum and returns its URL.

  ```bash
  curl -s -X POST "http://buddynext-dev.local/wp-json/buddynext/v1/spaces/BN_SPACE_ID/forum" -u owner:password
  ```

  - Expected: 200 with `{ "forum_id": N, "forum_url": "…/community/s/{slug}/" }`. The permission gate (`rest_provision_permission` — `JetonomyBridge.php:493`) requires login + the `buddynext-moderate-space` capability; a non-moderator gets 403, a guest 401.

## What this validates

Bridge seams (every assertion is BuddyNext-side; partner CRUD is only the trigger):

- `JetonomyBridge::init()` (`:38`) and `JetonomyBridgeListener::register()` (`:49`) gate on `class_exists( 'Jetonomy\Jetonomy' )` — register nothing when the partner is inactive.
- `on_post_created()` (on `jetonomy_after_create_post`, 2 args) reads `wp_jt_posts` and calls `SearchService::index( 'discussion', ... )` → row in `wp_bn_search_index`; parses `@mentions` → fires `buddynext_user_mentioned` (3 args); publishes a public `discussion` card to `wp_bn_posts` via `IntegrationActivity::publish()` when feed sync is on and `is_public_discussion()` passes; fires `buddynext_jetonomy_post_indexed` (5 args).
- `on_post_deleted()` (on `jetonomy_post_deleted`, 3 args) deletes the `discussion` index row and removes the feed card via `IntegrationActivity::remove()`.
- `inject_discussions_nav_item()` (on `buddynext_rail_items`) appends a personal **Discussions** rail item (group `you`, order `206`) pointing at the viewer's OWN profile Discussions tab; hidden for guests.
- `register_nav_items()` (on `buddynext_register_nav`) registers a Discussions tab on BOTH the `profile` surface (priority 60, count = `discussion_count()`) and the `space` surface (priority 35, URL `/spaces/{id}/discussions/`, count = `space_discussion_count()`) — the **current** seam that replaced the retired `buddynext_profile_extra_data` + `buddynext_space_tabs`.
- `maybe_provision_and_redirect()` (on `template_redirect` p5) provisions a space forum on demand behind a per-space nonce + `buddynext-moderate-space` gate, then redirects to it; `provision_space_forum()` is idempotent.
- `rest_provision_forum()` / `register_rest_routes()` expose `POST buddynext/v1/spaces/{id}/forum` for the app.
- `notify_discussion_reply()` (on `jetonomy_after_create_reply`, 1 arg consumed) creates a `bn.jetonomy_reply` notification for the post author via `NotificationService::create()`, with a self-reply guard.
- `JetonomyBridgeListener::on_notification()` (on `jetonomy_notification_created`, 7 args) mirrors every Jetonomy notification into a single block-aware, deduped, collect-only `jt.notification` type, rendered via the `buddynext_notification_message/url/meta` seams and catalogued `can_email = false`.
- `inject_discussion_context_nav()` (on `buddynext_context_nav`, 2 args) adds Home / Search / Leaderboard items only when `$section === 'discussions'`.
- `get_related_discussions()` (filter `buddynext_hashtag_related_discussions`, 2 args) returns up to 5 tag-matched Jetonomy posts, gated on `Jetonomy\Models\Tag`.
- `suppress_jetonomy_messaging()` (filter `option_jetonomy_pro_extensions`) drops Jetonomy Pro's `private-messaging` extension at read time on the front end when BN messaging is available, so BN owns `/messages/`.

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

-- Public discussion feed cards the bridge created (default-on feed sync):
SELECT id, user_id, type, privacy, link_url, created_at
FROM wp_bn_posts
WHERE type = 'discussion'
ORDER BY id DESC;

-- Reply notifications the bridge created (bridge's own type):
SELECT recipient_id, sender_id, type, object_type, object_id, created_at
FROM wp_bn_notifications
WHERE type = 'bn.jetonomy_reply'
ORDER BY id DESC;

-- Mirrored Jetonomy notifications (listener):
SELECT recipient_id, sender_id, type, object_type, object_id, group_key, created_at
FROM wp_bn_notifications
WHERE type = 'jt.notification'
ORDER BY id DESC;

-- Partner discussion count for a user (cross-check the profile tab badge):
SELECT author_id, COUNT(*) AS n
FROM wp_jt_posts
WHERE status = 'publish'
GROUP BY author_id;

-- Per-space forum link option (Part 3):
SELECT option_name, option_value FROM wp_options
WHERE option_name LIKE 'bn_space_%_jetonomy_forum_id';

-- Feed-sync toggle state (expected: absent / '1' = on by default):
SELECT option_name, option_value FROM wp_options
WHERE option_name = 'buddynext_jetonomy_feed_sync';
```

## REST surface walked

The bridge registers **one** route of its own — the on-demand space-forum provisioning endpoint for the app — and otherwise feeds existing BN surfaces (search index, feed, nav, notifications):

```
POST /wp-json/buddynext/v1/spaces/{id}/forum         -- provision/fetch a space's Jetonomy forum URL (login + buddynext-moderate-space)
GET  /wp-json/buddynext/v1/search?q={term}           -- 200, grouped results incl. a "discussion" group (public); the bridge feeds the index, does not own this route
GET  /wp-json/buddynext/v1/search/members?q={term}   -- 200, member results (unaffected by the bridge; listed for completeness)
```

Forum content itself is served by **Jetonomy's own route** at `/community/` (partner-owned, not a BN REST endpoint). The space Discussions tab links to `/community/s/{slug}/`; the per-discussion permalink is `/community/s/{space_slug}/t/{post_slug}/`.

> Re-confirm BN search reflects discussions live (partner active): `curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=test" | python3 -m json.tool | grep -i discussion`

## Bridge contract & partner gate

*(Item 11, bridge form. The arg-counts below are load-bearing — a past bug came from registering with the wrong count; verify them against the partner's `do_action` signatures.)*

| Direction | Hook (exact arg count) | Handler | Guard |
|---|---|---|---|
| Jetonomy → BN | `jetonomy_after_create_post(post_id, space_id)` **2 args** | `JetonomyBridge::on_post_created` (`:43`) | bails if `! class_exists('Jetonomy\Jetonomy')` (`JetonomyBridge:38`) |
| Jetonomy → BN | `jetonomy_post_deleted(post_id, space_id, user_id)` **3 args** | `on_post_deleted` (`:46`) | same |
| Jetonomy → BN | `jetonomy_after_create_reply(reply_id, ...)` **bridge consumes 1 arg** | `JetonomyBridge::notify_discussion_reply` (`:64`) → BN notification | same |
| Jetonomy → BN | `jetonomy_notification_created(...)` **7 args** | `JetonomyBridgeListener::on_notification` (`Listener:53`) → `jt.notification` mirror | bails if `! class_exists('Jetonomy\Jetonomy')` (`Listener:49`) |
| Jetonomy → BN (filter) | `option_jetonomy_pro_extensions` | `JetonomyBridge::suppress_jetonomy_messaging` (`:91`) → drop `private-messaging` so BN owns `/messages/` | same (read-time, front-end only, not persisted) |
| BN ← bridge (Nav API) | `buddynext_register_nav` | `JetonomyBridge::register_nav_items` (`:70`) → profile + space Discussions tabs (replaces retired `buddynext_space_tabs` + `buddynext_profile_extra_data`) | same |

**Frontend cross-namespace call:** the hashtag/feed Jetonomy upvote button posts to a **partner** route, not BN: `assets/js/hashtags/store.js:139` → `POST jetonomy/v1/posts/{id}/vote` (nonce `ctx.restNonce`). Verify that route exists in the **jetonomy** namespace (`curl -s http://buddynext-dev.local/wp-json/jetonomy/v1 | grep vote`), not buddynext/v1.

**Retired seams (do NOT expect these — removed before 1.0.3):** `jetonomy_before_content` / `jetonomy_after_content` (BN no longer injects a nav shell into Jetonomy pages — owner rule, see `JetonomyBridge.php:58-61`), `jetonomy_show_community_nav → false` (Jetonomy's own nav is no longer suppressed), `buddynext_space_tabs` and `buddynext_profile_extra_data` (both replaced by the Nav API `buddynext_register_nav`). A grep for these in `includes/` returns only stale comments, no live consumer.

**Verify this run (`jetonomy` IS active here):**
1. Create a discussion in a public Jetonomy space → confirm it surfaces in BN `GET /search?q=` under a `discussion` group AND as a `type = discussion` feed card in `wp_bn_posts` (feed sync default-on).
2. **Graceful no-op:** deactivate Jetonomy → BN loads with no fatal, the Discussions rail link + profile/space Discussions tabs hide, search returns no discussion group, no feed cards, no mirrored notifications. Reactivate.

## Admin-config → member-effect

*(Item 12.)*

- **Jetonomy bridge feature** (Settings → Platform → Features → "Jetonomy forums bridge"; `FeatureRegistry.php:459`, condition = `class_exists('Jetonomy\Jetonomy')` at `:289`): describes the integration; the bridge itself loads whenever Jetonomy is active.
- **Jetonomy Feed Sync** (Settings → Integrations → "Jetonomy Feed Sync"; option `buddynext_jetonomy_feed_sync`, **default `'1'` / ON** — `RecommendedDefaults.php:52`, gate at `JetonomyBridge.php:200`): ON → new public discussions appear as `discussion` feed cards; OFF (`'0'`) → search + nav still work, but discussions do NOT post into the activity feed. Always gated to public spaces / public topics / published posts regardless of this toggle.
- **BN messaging availability** (`BuddyNext\Messages\MessagesData::available()`): when true and Jetonomy Pro is active, the bridge suppresses Jetonomy Pro's `private-messaging` extension on the front end so the BN `/messages/` route is not hijacked. Reverts automatically if BN messaging is disabled; the wp-admin Jetonomy extensions screen still shows/saves the real setting.

## Cleanup

```sql
-- Remove indexed discussion rows created during this journey:
DELETE FROM wp_bn_search_index WHERE object_type = 'discussion';

-- Remove discussion feed cards created by the bridge:
DELETE FROM wp_bn_posts WHERE type = 'discussion';

-- Remove reply notifications + mirrored Jetonomy notifications created by the bridge:
DELETE FROM wp_bn_notifications WHERE type IN ('bn.jetonomy_reply', 'jt.notification');
```

```bash
# Remove the per-space forum link option set / provisioned in Part 3:
wp option delete bn_space_BN_SPACE_ID_jetonomy_forum_id

# Ensure the feed-sync toggle is left at its default (ON):
wp option delete buddynext_jetonomy_feed_sync   # falls back to the '1' default

# Delete the partner discussion/reply created for the run (partner-owned):
#   jetonomy_delete_post(post_id=JT_POST_ID)   (also fires the bridge index + feed cleanup)
```

> Partner data (`wp_jt_posts`, `wp_jt_replies`, `wp_jt_spaces`) is owned by Jetonomy — delete discussions/replies through the partner (`jetonomy_delete_post` / `jetonomy_delete_reply` / the forum UI), not by raw SQL against `wp_jt_*`, so the partner's own counters and the bridge's delete hooks stay consistent. A forum provisioned on demand (Part 3) is a real Jetonomy space — delete it through Jetonomy if you need a pristine slate.

## Known limitations

- **Feed sync is now ON by default and public-only** (`buddynext_jetonomy_feed_sync`, default `'1'`). Only discussions in PUBLIC spaces, with non-private topics, in `publish` status produce a public feed card (`is_public_discussion()`); private/secret spaces and private topics never leak into the feed regardless of the toggle. Turning the toggle off stops all feed cards but leaves search + nav intact.
- **Two reply-notification paths coexist.** The bridge fires its own `bn.jetonomy_reply` on `jetonomy_after_create_reply`, AND the listener mirrors Jetonomy's central `jetonomy_notification_created` reply notification as `jt.notification`. They are distinct rows with distinct types; if your Jetonomy build fires both, a post author can see both a `bn.jetonomy_reply` and a `jt.notification` for the same reply. This is by current design (the listener is the general mirror; the bridge row is the BN-native typed notification).
- **`buddynext_user_mentioned` only delivers a notification if a listener is attached.** The bridge's job ends at firing the action with the resolved mentioned-user ID; whether `member2` actually receives a mention notification depends on BuddyNext's `NotificationListener::on_user_mentioned`, not the bridge.
- **`inject_discussion_context_nav()` depends on the caller passing `$section`.** It only injects when the active section equals `discussions`; the section value is supplied by whatever renders `buddynext_context_nav`, so context-nav items will not appear unless the surface sets that section.
- **`base_slug` fallback.** The local `jetonomy_settings` option has no `base_slug`, so the forum base resolves to `community`. On a site that customises the Jetonomy base slug, every URL the bridge builds (space tab, context nav, provisioned forum) follows that custom slug automatically. The per-discussion permalink instead prefers `Jetonomy\base_url()` when available.
- **Profile/space/related-discussion lookups read partner tables/models directly** (`wp_jt_posts`, `wp_jt_spaces`, `Jetonomy\Models\Tag`, `Jetonomy\Models\Post`, `Jetonomy\Models\Reply`, `Jetonomy\Models\Space`). If Jetonomy changes those table columns or model APIs, these bridge methods are the coupling points to update. The templates never touch `jt_*` themselves — they go through the bridge helpers (`user_discussions`, `space_discussions`, `space_forum_context`).
- **Self-reply produces no notification** (by design — the `reply_author_id === post_author_id` guard in `notify_discussion_reply()`).
- **BN does not theme Jetonomy's own pages** (owner rule). The retired `jetonomy_before_content` shell-injection and `jetonomy_show_community_nav` suppression are gone; `/community/` renders as Jetonomy's own default. The BN entry points into discussions are the rail link + profile/space Discussions tabs only.

## Automation notes

- Partner CRUD is automatable via the **Jetonomy MCP** (`jetonomy_create_space`, `jetonomy_create_post`, `jetonomy_create_reply`, `jetonomy_delete_post`) — use it as the trigger, then assert on BN-side state.
- All BN-side assertions are DB queries (`wp_bn_search_index`, `wp_bn_posts`, `wp_bn_notifications`) plus filter/helper evals (`wp eval 'apply_filters(...)'`, `new JetonomyBridge()->discussion_count()` / `space_discussion_count()`). The profile/space Discussions *tabs* themselves are registered through the Nav API and best confirmed in the browser (load a profile / space, look for the Discussions tab + count badge).
- Collect `JT_POST_ID` and `JT_SPACE_ID` from the create responses; do not hardcode IDs.
- The "bridge inert when partner inactive" edge case is the highest-value automated check — deactivate Jetonomy, assert no `discussions` rail item, no `discussion` feed cards, and HTTP 200 (no fatal), reactivate. It guards the opt-in contract.
- The reply-notification path requires the Jetonomy model classes (`Jetonomy\Models\Reply` / `Post`) to be loadable; the bridge guards on `class_exists` and silently no-ops if they are absent, so assert presence before expecting the `bn.jetonomy_reply` row.
- Feed-sync is ON by default — to test the OFF branch, `wp option update buddynext_jetonomy_feed_sync 0`, create a discussion, assert no `type = discussion` row in `wp_bn_posts`, then restore.
