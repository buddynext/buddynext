# Journey: WPMediaVerse Bridge (DM + Media)

**Bridge** (opt-in): `includes/Bridges/WPMediaVerseBridge.php` — BuddyNext *consumes* the WPMediaVerse DM/messaging engine + media layer. **Requires the `wpmediaverse` plugin active** (gated by `class_exists( 'WPMediaVerse\Core\Plugin' )` at hook time). If WPMediaVerse is inactive the bridge never attaches a single hook — it is completely inert.
**Filters consumed (BN → engine)**: `mvs_buddynext_active` (BN returns `__return_true` so the engine suppresses its own chat panel / messages page / notifications), `mvs_can_send_message` (BN block gate), `buddynext_rail_items` (BN-owned — Media link injected here)
**Actions consumed (engine → BN)**: `mvs_message_sent`, `mvs_favorite_toggled`, `mvs_comment_created`, `mvs_before_content`, `mvs_after_content`, `buddynext_render_messages` (BN-owned — engine chat UI rendered here)
**Actions fired by the bridge (BN-domain adapters)**: `buddynext_dm_sent`, `buddynext_dm_received`, `buddynext_comment_created`
**DB tables touched**: writes `wp_bn_notifications`, `wp_bn_comments`, `wp_bn_posts` (comment_count); reads `wp_bn_blocks`. Partner-owned (read for unread badge only): `wp_mvs_conversations`, `wp_mvs_conversation_participants`, `wp_mvs_messages`.
**Estimated time**: 12 min manual

## Site-owner expectation

When a community owner activates **WPMediaVerse** alongside BuddyNext they expect plug-and-play behaviour, no configuration:

- Members can **DM each other** and **share media** from inside the community shell — the engine's chat and media surfaces render *inside* BuddyNext's own nav/sidebar, not as a separate plugin UI.
- A **Media** link appears in the left rail; a **Messages** unread badge counts real unread conversations.
- New DMs raise a **BuddyNext bell notification** (and honour BN email/notification preferences) — there is no duplicate notification from the engine.
- **BuddyNext blocks and mutes are respected**: a blocked user cannot DM you, and a muted/restricted user's DM does not interrupt you (no bell, no feed signal).

All of that is delivered by the bridge — the owner installs both plugins and it "just works".

## Preconditions

- BuddyNext Free + **`wpmediaverse`** active on http://buddynext-dev.local/ (LocalWP dev site). Confirm:

  ```bash
  wp plugin list --status=active --field=name | grep -E '^(buddynext|wpmediaverse)$'
  ```

  Both must appear. (On the reference dev site `wpmediaverse` and `wpmediaverse-pro` are both active and the `wp_mvs_*` tables exist.)
- The engine's Explore/media landing page is mapped (`mvs_page_explore` option holds a page ID — `19` on the reference site). The Media rail link falls back to `/media/` when unmapped.
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`
- Capture IDs up front:

  ```bash
  wp user get member1 --field=ID   # MEMBER1_ID
  wp user get member2 --field=ID   # MEMBER2_ID
  ```

## Happy-path steps

This journey drives the **engine's** `mvs/v1` REST surface to send a DM, then verifies the **bridge's** BN-side effects (notification row, rail link, block gate). The DM transport itself is partner-owned; we exercise it only to trigger the bridge.

### Part 1: Start a conversation and send a DM (engine REST)

1. As `member1`, create (or resolve) a conversation with `member2`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/mvs/v1/conversations \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"participant_ids": [MEMBER2_ID]}'
   ```

   - Expected: 200/201 with a conversation object. Note the returned `id` (`CONV_ID`). *(Partner-owned route — exact request body shape is owned by `MessagingController::create_conversation`; if it differs, GET `/wp-json/mvs/v1/me/conversations` to discover the shape.)*

2. As `member1`, send a message into the conversation:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/mvs/v1/conversations/CONV_ID/messages \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Journey DM hello", "message_type": "text"}'
   ```

   - Expected: 200/201. The engine inserts into `wp_mvs_messages` and fires `do_action( 'mvs_message_sent', $message_id, $conversation_id, $sender_id, $recipient_ids )` (partner: `MessagingService.php:1151`).

### Part 2: Confirm the bridge raised a BuddyNext notification

3. The bridge's `on_message_sent()` (hooked `mvs_message_sent`, priority 10, 4 args) strips the sender, applies the BN restrict/mute gate per recipient, then writes one `bn.new_message` row per remaining recipient. Verify:

   ```sql
   SELECT id, recipient_id, sender_id, type, object_type, object_id, group_key
   FROM wp_bn_notifications
   WHERE type = 'bn.new_message'
     AND recipient_id = MEMBER2_ID
     AND sender_id = MEMBER1_ID
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row. `object_type = conversation`, `object_id = CONV_ID`, `group_key = dm_CONV_ID_MEMBER2_ID`. Delivery + email preferences are then handled by BuddyNext's own `NotificationService` (not the engine).

4. Confirm the engine did **not** double-notify: the bridge returns `__return_true` on `mvs_buddynext_active`, which makes the engine's `NotificationListener` (`wpmediaverse/includes/Messaging/NotificationListener.php:71`) skip its own message notification. There should be no engine-side `wp_mvs_notifications` row competing for the same event.

### Part 3: Media rail link points at the engine's media page

5. As `member2`, load any BuddyNext hub (e.g. the activity feed) and inspect the left rail. The bridge's `inject_media_nav_item()` (hooked `buddynext_rail_items`) appends a **Media** item:
   - `key = media`, `icon = image`, `label = Media`.
   - `url` = permalink of the page in option `mvs_page_explore`; falls back to `home_url('/media/')` when unmapped.

   ```bash
   wp option get mvs_page_explore        # -> page ID, e.g. 19
   wp post get $(wp option get mvs_page_explore) --field=guid   # resolve the media URL
   ```

   - Expected: the rail's Media link href matches the Explore page permalink. (`active` is set defensively from `REQUEST_URI` prefix-match against the media path.)

   > **Seam note**: this link is injected via **`buddynext_rail_items`** (the current, correct left-rail filter in `templates/shell/rail.php:128`). It was previously wired to a dead `buddynext_nav_items` hook; that seam no longer exists — `buddynext_rail_items` is the canonical one and is the same filter JetonomyBridge uses.

### Part 3b: Uploading public media creates a feed activity post (1.0.1)

5a. As `member1`, upload a public image through the WPMediaVerse upload surface (or fire `do_action( 'mvs_media_uploaded', $media_id, $file_data, $user_id, 'image' )`).

   - Expected: the bridge (`WPMediaVerseBridge::on_media_uploaded`) schedules `buddynext_mvs_media_activity`; on run, `publish_media_activity()` creates a `wp_bn_posts` row (`type = photo` for images, `media_ids = [media_id]`), so the upload appears in the activity feed.
   - Guard: media already attached to a composer post (its id present in some `wp_bn_posts.media_ids`) is **skipped** — no duplicate activity. Audio/video post an `IntegrationActivity` link, not an inline photo.

### Part 4: BN block prevents messaging a blocked user

6. As `member2`, block `member1` through BuddyNext's social-graph block API (writes `wp_bn_blocks` with `type = 'block'`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/blocks \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"user_id": MEMBER1_ID}'
   ```

   - Expected: 200. Confirm the row:

   ```sql
   SELECT blocker_id, blocked_id, type FROM wp_bn_blocks
   WHERE blocker_id = MEMBER2_ID AND blocked_id = MEMBER1_ID AND type = 'block';
   ```

7. As `member1`, attempt to DM `member2` again (repeat step 2). The engine applies `apply_filters( 'mvs_can_send_message', true, $sender_id, $recipient_id )` (partner: `MessagingService.php:59`) *before* writing the message. The bridge's `check_block()` queries `wp_bn_blocks` for a row where `blocker_id = recipient` AND `blocked_id = sender` AND `type = 'block'`, and returns `false` when found.

   - Expected: the send is **rejected by the engine** (error response, no new `wp_mvs_messages` row, no new `bn.new_message` notification). Re-run the SQL from step 3 and confirm **no new row** was added for this attempt.

8. Cleanup the block to restore the happy path:

   ```bash
   curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/blocks/MEMBER1_ID \
     -u member2:password -H "Content-Type: application/json"
   ```

## Edge cases to also verify

- **Bridge inert when partner inactive (no fatals)**: deactivate WPMediaVerse and load BuddyNext hubs + send a follow/post. Expected: no PHP fatals, no Media rail link, Messages badge absent, no `bn.new_message` rows. The `init()` guard `class_exists( 'WPMediaVerse\Core\Plugin' )` returns early so *none* of the bridge hooks attach.

  ```bash
  wp plugin deactivate wpmediaverse
  # load http://buddynext-dev.local/?autologin=1 and the activity feed — expect clean render
  wp plugin activate wpmediaverse
  ```

- **Blocked user cannot DM** (core of Part 4): with `member2` blocking `member1`, every send attempt from `member1 → member2` is gated off at `mvs_can_send_message`. No message row, no notification row.

- **Restricted/muted recipient is not interrupted**: if `member2` *restricts* (mutes) `member1` rather than hard-blocking, the engine still writes the message (sender is unaware), but the bridge's per-recipient loop calls `buddynext_service('blocks')->is_restricted( $recipient_id, $sender_id )` and **skips** the notification + skips firing `buddynext_dm_received` for that recipient. Expected: a `wp_mvs_messages` row exists but **no** `bn.new_message` row and **no** recipient-side adapter event.

- **Favourite a media item raises a BN notification**: when a different user favourites `member1`'s media, the engine fires `mvs_favorite_toggled($media_id, $user_id, 'add')` and the bridge writes a `bn.media_favorited` notification to the media owner — but only on `add`, never on `remove`, and never self-favourite.

## What this validates

- **Bridge boot guard**: `WPMediaVerseBridge::init()` attaches hooks only when `WPMediaVerse\Core\Plugin` exists; otherwise zero side effects.
- **Engine suppression seam**: `mvs_buddynext_active` → `__return_true` makes the engine cede chat panel, messages page, and notifications to BuddyNext (no duplicate notifications).
- **Notification wiring**: `mvs_message_sent` → `on_message_sent()` → one `bn.new_message` row per non-sender recipient in `wp_bn_notifications`, keyed `dm_{conv}_{recipient}`, delivered through BuddyNext's `NotificationService`.
- **BN block enforcement**: `mvs_can_send_message` → `check_block()` reads `wp_bn_blocks` and vetoes the send before the engine persists the message. This is the single enforcement point for DM blocking.
- **Restrict/mute gate**: per-recipient `blocks->is_restricted()` check suppresses the notification + `buddynext_dm_received` while letting the engine keep the message.
- **Rail seam**: Media link injected via the live `buddynext_rail_items` filter (not the retired `buddynext_nav_items`).
- **Messages UI seam**: `buddynext_render_messages` → `render_messages()` renders the engine's two-pane chat (`chat-list.php` / `chat-conversation.php`) inside BuddyNext's hub shell; engine pages are wrapped by `open_hub_shell()` / `close_hub_shell()` on `mvs_before_content` / `mvs_after_content`.
- **BN-domain adapters fired**: `buddynext_dm_sent` (once, sender perspective) and `buddynext_dm_received` (per delivered recipient) so gamification/analytics/webhooks can hook the BN namespace without depending on the `mvs_*` hooks.

## Verification queries

```sql
-- The DM notification the bridge created (Part 2):
SELECT id, recipient_id, sender_id, type, object_type, object_id, group_key, created_at
FROM wp_bn_notifications
WHERE type = 'bn.new_message' AND recipient_id = MEMBER2_ID
ORDER BY id DESC;

-- Block row enforcing the DM gate (Part 4):
SELECT blocker_id, blocked_id, type FROM wp_bn_blocks
WHERE blocker_id = MEMBER2_ID AND blocked_id = MEMBER1_ID;

-- Partner-owned conversation/message rows the DM created (engine internals — read only):
SELECT id, last_activity_at FROM wp_mvs_conversations WHERE id = CONV_ID;
SELECT id, conversation_id, sender_id, message_type FROM wp_mvs_messages
WHERE conversation_id = CONV_ID ORDER BY id DESC LIMIT 5;

-- Media-favourite notification (favourite edge case):
SELECT id, recipient_id, sender_id, type, object_id, group_key
FROM wp_bn_notifications WHERE type = 'bn.media_favorited' ORDER BY id DESC LIMIT 5;
```

## REST surface walked

BuddyNext exposes **no** new REST routes for this bridge — DM/media transport is the **engine's** `mvs/v1` namespace (partner-owned). Routes touched in this journey:

```
# Engine (mvs/v1) — partner-owned (wpmediaverse/includes/Messaging/MessagingController.php):
POST   /wp-json/mvs/v1/conversations                       -- create/resolve conversation
GET    /wp-json/mvs/v1/me/conversations                    -- list current user's conversations
POST   /wp-json/mvs/v1/conversations/{id}/messages         -- send a DM (fires mvs_message_sent)
GET    /wp-json/mvs/v1/conversations/{id}/messages         -- list messages in a conversation
POST   /wp-json/mvs/v1/conversations/{id}/read             -- mark read (drives unread badge)
GET    /wp-json/mvs/v1/me/messages/unread-count            -- engine unread count

# BuddyNext (buddynext/v1) — used only to set up / tear down the block gate:
POST   /wp-json/buddynext/v1/users/{id}/block             -- block a user (writes wp_bn_blocks)
DELETE /wp-json/buddynext/v1/users/{id}/block             -- remove the block
```

> **Block route corrected to the live index 2026-06-20** — it is `/users/{id}/block` (POST/DELETE), NOT `/blocks`. The `mvs/v1` shapes are partner-owned; the bridge depends on the **hooks** they fire, not the wire format.

## Bridge contract & partner gate

*(Item 11, bridge form. The DM UI is BN's, but every call targets the engine's `mvs/v1`. The journey's job is to confirm the BN-side filters/hooks fire — especially the block gate.)*

| Direction | Hook / filter | Effect | Guard |
|---|---|---|---|
| BN announces itself | `mvs_buddynext_active → __return_true` | engine uses BN profile/identity | `WPMediaVerseBridge::register()` bails if `! class_exists('WPMediaVerse\Core\Plugin')` (`:46`) |
| **Block gate** | `mvs_can_send_message` → `check_block` (3 args, `:55`) | blocked sender cannot DM (bn_blocks + recipient DM-privacy) | same |
| Denial reason | `mvs_dm_denial_reason` → `dm_denial_reason` (`:59`) | friendly reason string | same |
| Engine → BN notify | `mvs_message_sent` → `on_message_sent` (4 args, `:68`) | BN notification row for the recipient | same |
| Follow-graph mirror | `mvs_user_followed`/`buddynext_user_followed` ↔ mirrored (`:77-80`) | `mvs_follows` and `bn_follows` stay in sync | same |
| Connection note → DM | `buddynext_connection_requested` → `deliver_note_as_message_request` (`:117`) | a connection note arrives as a DM request | same |

**Frontend:** the messages screen (`assets/js/messages/store.js`) sends every action to `base: ctx.mvsRest` (`mvs/v1`) / `ctx.mvsProRest`. **It is never reached dead** — `PageRouter.php:268` bounces `/messages/` when `buddynext_enable_dm` is off OR the engine is absent. (A grep-only audit once called the messages screen "dead without WPMediaVerse"; the bounce guard makes that a false alarm.)

**Verify this run (`wpmediaverse` IS active here):**
1. As `alice` DM `bob` (engine `POST /mvs/v1/conversations/{id}/messages`) → confirm `bob` gets a BN notification row (the `mvs_message_sent` → `on_message_sent` bridge effect).
2. **Block gate:** `bob` blocks `alice` (`POST /users/{id}/block`) → `alice`'s next send is denied with the bridge's reason. Unblock.
3. **Graceful absence:** deactivate WPMediaVerse → loading `/messages/` bounces (no dead screen, no fatal). Reactivate.

## Admin-config → member-effect

*(Item 12. Two distinct gates — easy to confuse.)*

- **DM master switch** (`buddynext_enable_dm`): OFF → `/messages/` bounces for everyone, the rail Messages item hides. ON → restored.
- **WPMediaVerse feature toggle** (Platform → Features → "wpmediaverse"): OFF → **`WPMediaVerseBridge` never inits**, so BN's `bn_blocks` / DM-privacy / note→DM are **NOT** applied to DMs (the engine's own `_mvs_dm_access` still is). This is subtle: DMs can still work via the engine while BN's block gate silently does nothing. Verify the block gate is enforced ONLY when the feature is ON.
- **Default DM access** (`buddynext_default_dm_access`): set to "connections only" → confirm a non-connected sender is denied.

## Cleanup

```sql
-- Remove the DM notifications created by this journey:
DELETE FROM wp_bn_notifications
WHERE type IN ('bn.new_message', 'bn.media_favorited')
  AND (recipient_id = MEMBER2_ID OR sender_id = MEMBER1_ID);

-- Remove the test block (if not already removed via REST in step 8):
DELETE FROM wp_bn_blocks
WHERE blocker_id = MEMBER2_ID AND blocked_id = MEMBER1_ID AND type = 'block';
```

Partner-owned `wp_mvs_conversations` / `wp_mvs_messages` rows created during the walk are engine internals — leave them for the engine's own cleanup, or remove via the engine UI/REST if you need a pristine inbox:

```bash
# Optional: engine demo-data cleanup helper (partner-owned), if present:
wp eval-file wp-content/plugins/wpmediaverse/cleanup-demo-data.php
```

## Known limitations

- **DM + media core is owned upstream (WPMediaVerse).** BuddyNext only consumes the engine via hooks/REST and owns 100% of its own UX (the engine's JS/CSS is never enqueued on BuddyNext hub pages; BuddyNext renders its own media + lightbox). Any "messaging gap" is usually an engine matter or thin consumer wiring — trace into the `wpmediaverse` repo first.
- **`mvs_comment_created` arg-order mismatch (lightbox-comment sync).** The bridge hooks `mvs_comment_created` as `sync_lightbox_comment( int $comment_id, int $media_id )` at priority 10/2 args, but the partner fires `do_action( 'mvs_comment_created', $media_id, $user_id, $comment_id, $content, $source )` (`wpmediaverse/includes/Social/CommentService.php:79`) — the first two positional args are `$media_id, $user_id`, **not** `$comment_id, $media_id`. As wired, the bridge's lightbox→activity comment sync receives the wrong IDs and will not reliably thread a comment under the correct `wp_bn_posts` row. Flagged for a bridge fix (align the handler to the partner signature). Do not rely on lightbox-comment → activity-feed sync in this journey.
- **`mvs_message_sent` zero-id thread-create path.** The partner also fires `mvs_message_sent` with `$message_id = 0` when a conversation is first opened (`MessagingService.php:351`). The bridge still creates a `bn.new_message` row in that case with `object_id = conversation` and `data.message_id = 0`; harmless but means a notification can predate the first real message id.
- **Block is one-directional in the gate.** `check_block()` only blocks when the *recipient* has blocked the *sender* (`blocker_id = recipient`). A sender who blocked the recipient is not prevented from messaging them — by design.
- **No BuddyNext REST surface for DMs.** Everything message/media is the engine's `mvs/v1`; BuddyNext adds no endpoints, so this journey cannot be run with `buddynext/v1` alone.

## Automation notes

- The full walk is curl-automatable with basic auth except where the `mvs/v1` create-conversation body shape must be discovered (GET `/me/conversations` first, or read `MessagingController::create_conversation`).
- Do not hardcode `CONV_ID` / user IDs — resolve them from `wp user get` and the conversation-create response.
- The decisive bridge assertion is **DB-level**, not HTTP: after a send, assert exactly one new `wp_bn_notifications` row of type `bn.new_message` for the recipient; after a blocked send, assert **zero** new rows. This isolates the bridge from engine response formatting.
- To assert the engine-suppression seam without a browser, check that `apply_filters( 'mvs_buddynext_active', false )` returns `true` while both plugins are active:

  ```bash
  wp eval 'var_dump( apply_filters( "mvs_buddynext_active", false ) );'   # expect bool(true)
  ```

- For the inert-when-inactive case, wrap deactivate/reactivate around a hub load and grep the debug log for fatals (`wp-content/debug.log`).
```
