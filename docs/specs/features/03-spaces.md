# BuddyNext — Spaces

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Sub-communities within the platform. Each space has its own feed, members, roles, and optional paywall.

---

## Space Types

| Type | Join model | Visible in directory |
|------|-----------|---------------------|
| Open | Anyone joins instantly | Yes |
| Private | Request to join — moderator approves | Yes |
| Secret | Invite only | No |

Extensible via filter.

---

## Sub-spaces (one level deep)

```
Photography (Space)
├── Portraits     (Sub-space)
├── Landscapes    (Sub-space)
└── Gear Talk     (Sub-space)
```

- Own feed + member roster (subset of parent)
- Inherits parent privacy + paywall by default — can override
- Two levels max, no deeper nesting

---

## Categories

Admin-defined categories for discovery (Photography, Tech, Fitness, etc.). No tags — keep it clean. Extensible via filter.

---

## Roles

| Role | Permissions |
|------|------------|
| Owner | Full control, delete space, transfer ownership |
| Moderator | Manage members, approve requests, delete + pin posts |
| Member | Post, react, comment |

---

## Space Management

Settings sections: General (name, slug, description, category, avatar, cover), Privacy (type, who can post), Members (invite, remove, ban, promote), Moderation (pre-mod, banned words), Notifications (default prefs for new members), Integrations (see below), Danger zone (archive, delete).

**Integrations section:**
- **Linked Forum** (Jetonomy): dropdown to pick an existing Jetonomy space/forum, or "Create new forum", or leave unlinked. Controls Forum tab visibility, Hot Topics block scoping, and feed sync for this space.
- **Discussion feed sync**: per-space toggle — "Show discussions from linked forum in feed" (overrides site-wide default)
- **Media tab** (WPMediaVerse): on/off toggle for media tab in this space

**Pro additions:**
- Analytics tab (space-level analytics dashboard)
- **Post approval queue** — toggle "Require post approval" per space. When enabled, new posts land in `status = 'pending'` and are invisible to other members until a space moderator or owner approves them. Approved → visible + triggers notifications. Rejected → author notified with optional reason. Pending count shown on moderator badge. Uses existing `bn_posts.status` column.
- Webhook on new member/post
- CSV member export for this space

---

## Paywall (Pro only)

**Tier-gated**: requires a BuddyNext membership tier
**Space-own price**: independent Stripe price per space (one-time or recurring)

Both support: free trial, grandfathered members.

---

## Addon Behavior

### Jetonomy
- Standalone: Jetonomy owns its forum/channel structure (spaces-as-forums)
- BuddyNext mode: BuddyNext owns spaces. Space admin manually links a Jetonomy forum via Space Settings → Integrations. Linked forum gets a Forum tab + Hot Topics block in the space.

### WPMediaVerse
- BuddyNext mode: adds Media tab inside spaces. Media uploaded in space context tagged to that space in `bn_posts`.

### WBGamification
Points on joining a space and posting in a space. Active in both modes.

---

## Data Stored

`bn_spaces` — space definitions, type, parent_id (sub-spaces), paywall fields (Pro), `require_post_approval` flag (Pro)
`bn_space_members` — membership, roles, notification prefs
`bn_space_categories` — admin-defined category list

---

## Integration Points

| Feature | How spaces use it |
|---------|-----------------|
| Activity Feed | space_id scoping on `bn_posts` |
| Social Graph | `bn_blocks` filtered from space roster |
| WBGamification | Points for joining + posting |
| Jetonomy | Forum tab via filter |
| WPMediaVerse | Media tab via filter |
| Search | Spaces indexed in `bn_search_index` |
| Notifications | Join request, approval, new post notifications |
| Pro Membership | Tier-gated join |

---

## Gaps / Open Questions

- None — fully locked
