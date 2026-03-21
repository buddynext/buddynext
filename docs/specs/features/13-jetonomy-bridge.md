# BuddyNext — Jetonomy Bridge

**Status:** Locked
**Last updated:** 2026-03-19 (audit: hook names corrected; feed sync default-off; hot topics added)

---

## What It Does

Connects Jetonomy's discussion engine to BuddyNext's social layer. In BuddyNext mode, Jetonomy defers its social features and contributes its content into BuddyNext surfaces.

---

## What Jetonomy Always Owns

Discussion engine, forum structure, voting, topic management, reply threading, moderation within discussions. These never defer — Jetonomy is the discussion authority.

---

## What Defers in BuddyNext Mode

| Jetonomy feature | Defers to |
|-----------------|----------|
| Member profiles | BuddyNext profiles |
| Notification bell | BuddyNext bell (`bn_notifications`) |
| Standalone notification emails | BuddyNext email system |

---

## Feed Sync (Default OFF)

Pushing discussions into the BuddyNext activity feed is **opt-in**. Default off for two reasons:
1. **Reply fragmentation**: if people comment on the feed card, those replies would need to sync back to Jetonomy — creating a bidirectional data problem with no clean owner. Discussions belong in Jetonomy; replies belong there too.
2. **Feed noise**: forums are intent-driven, social feeds are passive scroll. Mixing them crowds the feed without adding discovery value.

**Toggle locations:**
- Admin Settings → Integrations → Jetonomy → "Surface new discussions in activity feed" (site-wide default)
- Per-space override: Space Settings → "Show discussions from this forum in feed"

**When enabled:**
- `jetonomy_after_create_post` → creates `bn_posts` entry (type: `discussion`) with link card + excerpt
- Only the opening post is pushed — never replies (one card per discussion, not per activity update)
- Discussion deleted → removes the `bn_posts` entry

**When disabled (default):** No feed entries created. Notifications and search still work normally.

---

## What the Bridge Always Does

**Content → Search Index** (always-on)
- Discussion created/updated → `bn_search_index` entry (type: `discussion`)
- Ensures discussions are findable via BuddyNext unified search regardless of feed toggle

**Notifications** (always-on)
- `jetonomy_after_create_reply` → `NotificationService::create()` (type: `jt.discussion_reply`)
- `jetonomy_after_create_post` (content parsed for `@mentions`) → `NotificationService::create()` (type: `jt.discussion_mention`) — no native mention hook in Jetonomy core; bridge extracts mentions from post content
- `jetonomy_pro_reaction_toggled` (action: `added`) → `NotificationService::create()` (type: `jt.post_reaction`) — Pro reactions extension only; skipped if extension inactive

**UI Injection** (always-on)
- Adds Forum tab inside BuddyNext spaces via `buddynext_space_tabs` filter — tab links through to full Jetonomy forum
- **Hot Topics block** is a native Jetonomy block — the bridge makes it available inside BuddyNext space layouts and auto-scopes it to the space's linked forum. Space admins place it anywhere (sidebar, below feed, dedicated section).
- Jetonomy discussion count shown on profile via `buddynext_profile_extra_data`

---

## Space ↔ Forum Relationship

Linking is **manual, set at space level** — a BuddyNext site admin or space moderator picks which Jetonomy space to link in Space Settings. No auto-creation.

**Future:** space Owner role should be able to link/unlink their own forum without needing site admin access — requires space-level permission for integration management (not in v1).

**Space Settings → Linked Forum:**
- Dropdown: pick any existing Jetonomy space/forum
- Or: "Create new Jetonomy forum for this space" (one-click convenience)
- Or: leave unlinked (no Forum tab, no Hot Topics block in this space)

**When linked:**
- Forum tab appears in the space (links through to the Jetonomy forum)
- Hot Topics block auto-scopes to the linked forum
- Feed sync (if enabled) pushes discussions from the linked forum only

**When unlinked:** no Forum tab, no Hot Topics block, no feed sync for this space.

Space members ≠ forum members — bridge syncs membership on request, not automatically.

---

## Standalone Mode

When BuddyNext is not active, Jetonomy runs its own social layer (notifications, profiles, activity). The bridge is not loaded.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Discussion cards in `bn_posts` — opt-in, default off, toggleable per space |
| Notifications | Jetonomy events → `bn_notifications` (always-on) |
| Search | Discussions indexed in `bn_search_index` (always-on) |
| Spaces | Forum tab + Hot Topics widget injected; space↔forum relationship maintained |
| Profiles | Discussion count on profile; member data from BuddyNext |

---

## Gaps / Open Questions

- None — fully locked
