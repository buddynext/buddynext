# BuddyNext Pro — Real-time (WebSocket)

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Upgrades the free polling-based real-time approximation to true WebSocket connections. Instant updates for all live surfaces.

---

## Upgraded Surfaces

| Feature | Free (polling) | Pro (WebSocket) |
|---------|---------------|-----------------|
| Notification bell | Poll every 30s | Push on event |
| DM conversations | Poll every 5s | Instant delivery + typing indicator |
| Feed new posts bar | Poll every 60s | Push count |
| Online presence | Poll every 2min | Real-time presence events |
| Space live activity | None | Real-time post/reaction events |

---

## Architecture

- WebSocket server: Ratchet (self-hosted) or Soketi (managed, drop-in Pusher compatible)
- Swap via `buddynext_messaging_transport` filter — same service layer, different transport
- Free tier gracefully degrades to polling when Pro is not active (no broken features, just slower)

---

## Online Presence

- Last active timestamp updated on WebSocket heartbeat
- Online indicator on member cards, DM participant list, space member list
- "Online now" directory filter (reads from real-time presence)

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| DM | Instant message delivery + typing indicator |
| Notifications | Push on event instead of polling |
| Activity Feed | Real-time "new posts" count |
| Member Directory | Online now filter accuracy |
| Spaces | Live activity indicators |

---

## Gaps / Open Questions

- None — fully locked
