# BuddyNext — WPMediaVerse Bridge

**Status:** Locked
**Last updated:** 2026-03-20 (DM ownership clarified: WPMediaVerse owns engine, BuddyNext is UI layer)

---

## What It Does

Connects WPMediaVerse's media engine to BuddyNext's social layer. In BuddyNext mode, WPMediaVerse defers its social features and contributes media content into BuddyNext surfaces.

---

## What WPMediaVerse Always Owns

Media upload, storage (local / S3 / BunnyCDN), playback, AI moderation, watermarking, video chapters, captions, transcoding, quota management. These never defer — WPMediaVerse is the media authority.

---

## What Defers in BuddyNext Mode

| WPMediaVerse feature | Defers to |
|---------------------|----------|
| Follow graph | BuddyNext social graph (`bn_follows`) |
| Notification bell | BuddyNext bell (`bn_notifications`) |
| Activity feed | BuddyNext feed (`bn_posts`) |
| Profile avatar + cover | BuddyNext profile system |
| REST follow endpoints | `buddynext/v1/follows` |

## DM Ownership (Never Defers)

WPMediaVerse owns the full DM engine in all modes — standalone and BuddyNext mode.

| Tier | Owner | Features |
|------|-------|----------|
| WPMediaVerse free | DM engine | 1:1 messaging, `mvs_conversations`, `mvs_messages`, `mvs_conversation_participants`, `mvs_message_reactions` |
| WPMediaVerse Pro | Advanced DM | Group DM (2–49), WebSocket transport, read receipts |

BuddyNext renders the DM inbox UI using WPMediaVerse REST endpoints (`mvs/v1/conversations`, `mvs/v1/messages`). BuddyNext has **no own DM tables**.

**Required for BuddyNext DM to function:**
- Basic 1:1 DM: WPMediaVerse free active
- Group DM + real-time: WPMediaVerse Pro active (bundled in BuddyNext Pro — always available for Pro users)

**What WPMediaVerse must expose for BuddyNext:**
- `mvs/v1/conversations` — list, create, get
- `mvs/v1/conversations/{id}/messages` — list, send, delete
- `mvs/v1/conversations/{id}/participants` — list, add, remove (Pro: group DM)
- `mvs/v1/messages/{id}/reactions` — add, remove
- `mvs_messaging_transport` filter — BuddyNext Pro hooks to verify WebSocket availability
- `mvs_conversation_created`, `mvs_message_sent` actions — BuddyNext hooks to fire `bn_notifications`
- `mvs_block_check` filter — BuddyNext injects `bn_blocks` check before message send

---

## What the Bridge Does

**Content → BuddyNext Feed**
- `mvs_media_uploaded` → creates `bn_posts` entry (type: `media`) with media card
- `mvs_media_deleted` → removes the `bn_posts` entry

**Content → Search Index**
- Media uploaded/updated → `bn_search_index` entry (type: `media`)

**Notifications**
- `mvs_reaction_added` → `NotificationService::create()` (type: `mvs.media_reaction`) — fires on both new reactions and reaction-type changes; bridge must skip notification if user already reacted (only notify on first reaction per user per media)
- `mvs_comment_created` → `NotificationService::create()` (type: `mvs.media_comment`)
- `mvs_favorite_toggled` (action: `added`) → `NotificationService::create()` (type: `mvs.media_favorite`) — note: WPMediaVerse fires `mvs_favorite_toggled`, not `mvs_favorite_added`; also fix needed in WPMediaVerse `NotificationService` which currently listens on the wrong hook
- `mvs_mentions_created` → `NotificationService::create()` (type: `mvs.media_mention`)

**Profile Avatar + Cover**
- When user updates avatar/cover in BuddyNext profile: bridge routes upload through WPMediaVerse storage
- Falls back to WP media library if WPMediaVerse is not active

**UI Injection**
- Adds Media tab inside BuddyNext spaces via `buddynext_space_tabs` filter
- Media uploaded in space context tagged to that space in `bn_posts`

---

## Standalone Mode

When BuddyNext is not active, WPMediaVerse runs its own social layer (follow graph, notifications, activity feed, profiles). The bridge is not loaded.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Media cards appear in `bn_posts` |
| Notifications | WPMediaVerse events → `bn_notifications` |
| Search | Media indexed in `bn_search_index` |
| Spaces | Media tab injected into spaces |
| Profiles | Avatar/cover upload routed through MVS storage |
| Reactions + Comments | Media uses `bn_reactions` + `bn_comments` in BuddyNext mode |
| DM — UI suppression | Bridge sets `mvs_buddynext_active` → `true` on `plugins_loaded:15`; WPMediaVerse suppresses its own chat panel and nav link |
| DM — block check | Bridge hooks `mvs_can_send_message` → checks `bn_blocks`; returns `false` if sender is blocked by recipient |
| DM — notifications | Bridge hooks `mvs_message_sent` → creates `bn_notifications` entry (type: `bn.new_message`) |
| DM — WebSocket (Pro) | BuddyNext Pro hooks `mvs_messaging_transport` to verify WebSocket availability; channel naming: `mvs-dm-{conversation_id}` |

---

## Gaps / Open Questions

- None — fully locked
