# BuddyNext — Direct Messaging + Chat

**Status:** Locked
**Last updated:** 2026-03-20 (DM engine owned by WPMediaVerse; BuddyNext is UI layer only)

---

## What It Does

BuddyNext provides the DM inbox UI and messaging experience. WPMediaVerse owns the DM engine — tables, API, transport. BuddyNext Pro bundles WPMediaVerse Pro, so every BuddyNext Pro user automatically gets advanced DM without any separate purchase or activation step.

---

## Ownership

| Layer | Owner | Notes |
|-------|-------|-------|
| 1:1 DM engine (conversations, messages, participants, reactions) | **WPMediaVerse free** | `mvs_*` tables |
| Group DM + WebSocket transport + read receipts | **WPMediaVerse Pro** | Bundled in BuddyNext Pro |
| DM inbox UI, message composer, conversation list | **BuddyNext** | Pure UI — no own DM tables |
| Media attachments in messages | **WPMediaVerse always** | Upload + storage + delivery |

BuddyNext has **no own DM tables**. All messaging data lives in WPMediaVerse (`mvs_conversations`, `mvs_messages`, `mvs_conversation_participants`, `mvs_message_reactions`).

---

## Free Tier — 1:1 Direct Message

Powered by WPMediaVerse free. Available when WPMediaVerse (free) is active alongside BuddyNext.

- Send / receive text messages
- Media attachments (upload + storage via WPMediaVerse)
- Threaded replies (reply to a specific message, quoted)
- Emoji reactions on messages
- Emoji picker (native emoji, no third-party GIF service)
- Typing indicator (polling approximation — 3s)
- Message delete (own, within window) + unsend for all
- Mute, pin, archive conversations
- Unread count badge on bell / nav
- Request inbox (unknown senders land in requests — accept / decline / block)
- Rate limiting (30 msg/min, 10 convos/hour)
- Polling-based real-time (5s refresh)

Access control (per user):
- everyone / followers / mutual / nobody
- Global default configurable by admin

**Without WPMediaVerse free:** DM tab does not appear in BuddyNext. Documented as soft dependency.

---

## Pro Tier — Group DM + Real-time

Powered by WPMediaVerse Pro. Bundled in every BuddyNext Pro license — no separate activation needed.

- **Group DM** — conversations with 2–49 members
- Group name + avatar
- Admin role (creator / longest-remaining member)
- Add / remove members, leave group
- **Read receipts** — sent / delivered / read status per message
- **WebSocket transport** (Ratchet or Soketi) — instant delivery, real typing indicator, online presence
- Inherits all free DM features
- Real-time feed "X new posts" push via same WebSocket connection

Transport controlled by WPMediaVerse Pro's `mvs_messaging_transport` filter.

---

## What the Bridge Does

BuddyNext bridge (`includes/Bridges/WPMediaVerse.php`):

- Renders BuddyNext DM inbox UI using WPMediaVerse REST API (`mvs/v1/conversations`, `mvs/v1/messages`)
- Routes DM notifications through `bn_notifications` (new message → `bn.new_message` on-site notification + email per pref)
- Checks `bn_blocks` on every send — blocked users cannot DM
- Injects DM link on member profiles and member directory cards
- Unread count badge pulled from WPMediaVerse and shown in BuddyNext nav

---

## Moderation

Users can report individual DM messages (reason: harassment, spam, threats).
Reported DMs enter BuddyNext admin-only moderation queue (privacy respected — only admin can view message metadata).
Admin actions: warn, strike, suspend sender.
Reporter notified when resolved (action details not revealed).
Blocked users: checked via `bn_blocks` on every send — cannot DM if blocked.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Social Graph | `bn_blocks` prevents DM; follow/connection status controls access gates |
| Notifications | New message → `bn.new_message` on-site notification + email per pref |
| WPMediaVerse | DM engine — all tables, API, transport |
| WPMediaVerse Pro | Group DM + WebSocket + read receipts — bundled in BuddyNext Pro |
| Moderation | DMs reportable; admin review queue (private) |

---

## Gaps / Open Questions

- None — fully locked
