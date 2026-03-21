# WPMediaVerse — DM Architecture + Integration Requirements

**Status:** Locked
**Last updated:** 2026-03-20
**Audience:** WPMediaVerse / WPMediaVerse Pro / BuddyNext developers

---

## Architecture Decision (Final)

**WPMediaVerse owns DM completely. BuddyNext is the UI layer only.**

WPMediaVerse DM is fully standalone — it works the same whether BuddyNext is active or not. The underlying user system is WordPress `wp_users` in both modes, so no user mapping, migration, or dependency on BuddyNext is needed.

| Mode | DM Engine | DM UI |
|------|-----------|-------|
| WPMediaVerse standalone | WPMediaVerse (owns everything) | WPMediaVerse chat panel + /messages/ page |
| WPMediaVerse + BuddyNext | WPMediaVerse (owns everything) | BuddyNext DM inbox (WPMediaVerse UI suppressed) |

**Single source of truth.** One set of tables (`mvs_*`), one codebase. No duplicate inbox, no split message history.

---

## Why This Works Without BuddyNext Dependency

WordPress users (`wp_users`) are the same table in both standalone and community mode. WPMediaVerse DM uses WordPress user IDs directly — it doesn't care whether BuddyNext is active. The same conversation and message rows work in both contexts without any migration.

BuddyNext's only additions when active:
- Provides the DM inbox UI (replaces WPMediaVerse's chat panel)
- Injects `bn_blocks` check via `mvs_can_send_message` filter
- Routes DM notifications through `bn_notifications` via `mvs_message_sent` hook

---

## Current State (WPMediaVerse Pro — MessagingService.php)

The full 1:1 DM engine already exists in WPMediaVerse Pro and is production-quality:

| Feature | Status |
|---------|--------|
| 1:1 conversation find/create | ✅ |
| Message requests (`request_pending` status) | ✅ |
| Mute / pin / archive conversations | ✅ |
| Unread count (cached 60s) | ✅ |
| Rate limiting (30 msg/min, 10 convos/hour) | ✅ |
| Emoji reactions on messages | ✅ |
| Quoted replies (`parent_id`) | ✅ |
| Message delete (own) + unsend for all (15 min window) | ✅ |
| Access control (everyone / followers / mutual / nobody) | ✅ |
| Typing indicator (transient-based) | ✅ |
| Online status (2 min threshold) | ✅ |
| Cursor pagination | ✅ |
| REST polling transport | ✅ |
| GDPR export/erase | ✅ |
| All integration hooks fired | ✅ |

**DB tables (currently created by Pro Migrator):**
```
mvs_conversations             — id, type (direct|group), title, created_by,
                                last_message_id, last_message_preview,
                                last_activity_at, created_at
mvs_conversation_participants — conversation_id, user_id, role, status,
                                last_read_at, is_muted, muted_until,
                                is_pinned, is_archived, joined_at
mvs_messages                  — id, conversation_id, sender_id, content,
                                message_type, attachment_id, media_id,
                                parent_id, metadata, is_deleted,
                                deleted_for_all, created_at
mvs_message_reactions         — message_id, user_id, emoji, created_at
```

`type` column on `mvs_conversations` already supports `'direct'` and `'group'` — Group DM requires no schema change.

---

## Required Changes

### WPMediaVerse Free — Move DM from Pro → Free

**Scope:** Move these from `WPMediaVersePro\Messaging` → `WPMediaVerse\Messaging`:

| Item | Action |
|------|--------|
| `MessagingService.php` | Move to free |
| `MessagingController.php` | Move to free — REST at `mvs/v1` (not `mvs-pro/v1`) |
| `RestPollingTransport.php` | Move to free |
| `TransportInterface.php` | Move to free |
| `NotificationListener.php` | Move to free |
| `templates/messages.php` | Move to free |
| `templates/partials/chat-*.php` | Move to free |
| `assets/css/messaging.css` | Move to free |
| `assets/js/messaging.js` | Move to free |
| 4 DB tables | Move to free `Activator` — create on WPMediaVerse free activation |

**REST namespace change:** `mvs-pro/v1/conversations` → `mvs/v1/conversations`

**What stays in Pro:** Group DM, WebSocket transport, read receipts.

---

### WPMediaVerse Free — Add 3 Integration Hooks

Small additions to support BuddyNext mode cleanly:

**1. `mvs_buddynext_active` filter**
Checked before WPMediaVerse renders its own DM UI. BuddyNext sets to `true` — WPMediaVerse suppresses its chat panel and nav link. REST endpoints stay active.

```php
// In chat panel template + NotificationListener:
if ( apply_filters( 'mvs_buddynext_active', false ) ) {
    return; // BuddyNext handles UI and notifications
}
```

**2. `mvs_can_send_message` filter**
BuddyNext hooks this to check `bn_blocks`. Add inside `can_message()` after existing block check:

```php
$allowed = apply_filters( 'mvs_can_send_message', true, $sender_id, $recipient_id );
if ( ! $allowed ) {
    return [ 'allowed' => false, 'reason' => 'blocked', 'is_request' => false ];
}
```

**3. All necessary actions already exist** (no new ones needed):
- `mvs_conversation_created( $conv_id, $creator_id, $participant_ids )` ✅
- `mvs_message_sent( $message_id, $conversation_id, $sender_id, $recipient_ids )` ✅
- `mvs_message_deleted( $message_id, $user_id, $deleted_for_all )` ✅
- `mvs_conversation_read( $conversation_id, $user_id )` ✅

---

### WPMediaVerse Pro — Add Group DM

Group DM is a **WPMediaVerse Pro feature** — standalone and BuddyNext mode both benefit.

`mvs_conversations.type = 'group'` already supported in schema. Add:

```
Messaging\GroupMessagingService
  create_group( $creator_id, $participant_ids, $name, $avatar_id )
  add_member( $conversation_id, $user_id, $added_by )
  remove_member( $conversation_id, $user_id, $removed_by )
  update_group( $conversation_id, $data )          — name, avatar
  transfer_admin( $conversation_id, $new_admin_id )
  — on creator leave: auto-promote longest-remaining member to admin

Messaging\GroupMessagingController                 — REST at mvs-pro/v1
```

Limits: 2–49 participants. Inherits all free DM features (reactions, quoted replies, delete, rate limits, etc.).

New actions to fire:
- `mvs_group_created( $conversation_id, $creator_id, $participant_ids )`
- `mvs_group_member_added( $conversation_id, $user_id, $added_by )`
- `mvs_group_member_removed( $conversation_id, $user_id, $removed_by )`

---

### WPMediaVerse Pro — Add Read Receipts

`last_read_at` on `mvs_conversation_participants` already tracks conversation-level reads. For per-message delivery status, store in `mvs_messages.metadata` JSON:

```json
{
  "delivery": {
    "read_by": [ { "user_id": 5, "read_at": "2026-03-20T10:01:00Z" } ]
  }
}
```

New REST endpoint: `GET mvs-pro/v1/messages/{id}/receipts`

New action: `do_action( 'mvs_message_read', $message_id, $user_id, $read_at )`

---

### WPMediaVerse Pro — Add WebSocket Transport

Current: `RestPollingTransport` (5s polling).
Add: `WebSocketTransport` implementing `TransportInterface`.

`mvs_messaging_transport` filter (already exists) returns `WebSocketTransport` when configured.

Channel naming: `mvs-dm-{conversation_id}` (separate from BuddyNext Pro's feed channels).

BuddyNext Pro also runs WebSocket for feed + presence — prefer shared Soketi server, different channels.

---

## BuddyNext Side — What the Bridge Does

BuddyNext bridge (`includes/Bridges/WPMediaVerse.php`):

1. Sets `mvs_buddynext_active` filter → `true` on `plugins_loaded:15`
2. Hooks `mvs_can_send_message` → checks `bn_blocks` table
3. Hooks `mvs_message_sent` → creates `bn_notifications` entry (`bn.new_message`)
4. Renders DM inbox UI using `mvs/v1/conversations` + `mvs/v1/messages` endpoints
5. Shows unread count badge in BuddyNext nav from WPMediaVerse unread count API
6. Injects DM link on member profiles and member directory cards

**Without WPMediaVerse free active:** BuddyNext DM tab is hidden with a notice pointing to WPMediaVerse.

---

## Work Summary

### WPMediaVerse Free
| Task | Effort |
|------|--------|
| Move DM engine (service, controller, transport, templates, JS/CSS) from Pro → Free | Medium |
| Move 4 DB tables to Free Activator | Small |
| Change REST namespace `mvs-pro/v1` → `mvs/v1` for DM endpoints | Small |
| Add `mvs_buddynext_active` filter (2 places: chat panel, NotificationListener) | Trivial |
| Add `mvs_can_send_message` filter in `can_message()` | Trivial |

### WPMediaVerse Pro
| Task | Effort |
|------|--------|
| Add Group DM (GroupMessagingService + GroupMessagingController) | Medium |
| Add read receipts | Small |
| Add WebSocket transport | Large |

### BuddyNext
| Task | Effort |
|------|--------|
| DM bridge (sets flags, hooks actions, renders inbox UI consuming `mvs/v1`) | Medium |
| No own DM tables | — |

---

## Gaps / Open Questions

- Shared vs separate Soketi server for BuddyNext Pro feed/presence + WPMediaVerse Pro WebSocket DM
