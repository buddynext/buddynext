# BuddyNext — Social Graph

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Owns all user-to-user relationships. Powers feed filtering, content visibility, DM permissions, directory ordering, and space access.

---

## Three Relationship Types

**Follow (asymmetric)**
- Follow / unfollow anyone (unless blocked)
- Follow = access to their posts in your home feed
- "People You May Know" suggestions (v1 — people followed by people you follow)

**Connection (mutual, request → accept)**
- Send / accept / decline / withdraw request
- Pending inbox: received + sent requests
- Connection = private content access
- Mutual connections count shown in directory
- Connection degree (1st, 2nd) in directory

**Block / Mute**
- Block: hard stop — can't see your content, can't message you, gone from your feed
- Mute: soft — still connected/following but invisible in feed, they never know

---

## Privacy Model

| Level | Who sees it |
|-------|------------|
| `public` | Everyone — indexed by Google |
| `followers` | People who follow you |
| `connections` | Mutual connections only |
| `private` | Just you |

Applied to: feed posts, profile field groups, space membership.

---

## Addon Behavior

### WPMediaVerse
- Standalone: own `mvs_follows` table + follow REST endpoints
- BuddyNext mode: own follow system off, defers to `bn_follows`. Follow endpoints redirect to `buddynext/v1`.

### Jetonomy
- Standalone: own member profile store
- BuddyNext mode: member data = BuddyNext profiles. Jetonomy reads via BuddyNext profile API.

---

## Data Stored

`bn_follows` — follower/following pairs
`bn_connections` — connection requests + status
`bn_blocks` — blocks and mutes

---

## Integration Points

| Feature | Reads from graph |
|---------|-----------------|
| Activity Feed | `bn_follows` — home feed query |
| Notifications | `bn_connections` — notify on request |
| Direct Messaging | `bn_blocks` — blocks prevent messaging |
| Member Directory | `bn_connections` — filters + mutual count |
| Spaces | `bn_blocks` — filter blocked from roster |
| Search | `bn_blocks` — exclude blocked from results |
| Privacy check | `bn_follows` + `bn_connections` for visibility gating |

---

## Gaps / Open Questions

- None — fully locked
