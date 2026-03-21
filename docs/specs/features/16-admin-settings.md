# BuddyNext — Admin Settings

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Central admin panel for configuring all BuddyNext features. Clean tabbed UI. Settings stored in `wp_options` under `buddynext_` prefix.

---

## Settings Tabs

### General
- Site display name (used in emails)
- Logo (used in email templates)
- Brand color (used in Gutenberg blocks)
- BuddyNext page assignments (home feed page, directory page, spaces page, profile page, notifications page, DM page)

### Registration
- Open registration / invite-only / admin approval required
- Email verification on/off
- Invite code management (invite-only mode)
- Allowed email domains whitelist (optional)
- New member post restriction (first N posts need approval)

### Social
- Default post privacy for new members
- Allow/disallow poll creation
- Link preview unfurl on/off
- Post edit window (minutes — 0 = unlimited)
- Follow-back required for DM (or connections-only or everyone)
- Emoji picker on/off

### Spaces
- Admin approval required to create a space (or anyone can create)
- Space creation restricted to roles (admin only / any member)
- Max sub-spaces per space

### Notifications
- Site-wide default notification preferences (per type, per channel)
- Lock any notification type on (cannot be turned off by user)
- Admin alert: email address for site-wide alerts (new pending users, reports)

### Email
→ Redirects to the Email Editor (see `06-notifications-email.md`)

### Moderation
- Auto-hide threshold (X reports → auto-hide)
- Strike thresholds (warn / suspend / permanent ban)
- Global banned word list
- Rate limits (posts per minute per user)
- New member post restriction (same as Registration tab — synced)

### Integrations
- Shows status of each addon (WPMediaVerse, Jetonomy, WBGamification, Career Board)
- Shows which features are deferred in BuddyNext mode
- Link to each addon's own settings
- **Jetonomy**: "Surface new discussions in activity feed" toggle (default: off) — per-space override available in Space Settings

### Privacy + Data
- Google indexing: choose which scopes are indexed (public posts / profiles / spaces / all off)
- Data export: member can request their data (GDPR)
- Data deletion: member can request account deletion
- Cookie consent mode (if applicable)

### Webhooks

**Inbound (Access Grants)**
- Webhook secret key (HMAC signing)
- Webhook log viewer (last 100 calls, status, payload)
- Test webhook button

**Outbound (Event Push)**
- Register external URLs to receive BuddyNext events
- Per-endpoint: URL, secret key, which events to subscribe to
- Events available:

| Event | Fired when |
|-------|-----------|
| `member.registered` | New user registers |
| `member.verified` | Email verified |
| `member.suspended` | Account suspended |
| `post.created` | Post published to feed |
| `post.deleted` | Post removed |
| `space.joined` | User joins a space |
| `space.left` | User leaves a space |
| `connection.accepted` | Two users connect |
| `user.followed` | User follows another |
| `reaction.added` | Reaction added to any content |
| `comment.created` | Comment added to any content |
| `ability.granted` | Ability granted to user |
| `ability.revoked` | Ability revoked from user |

- Delivery: signed POST JSON, `X-BuddyNext-Event` header identifies event type
- Retry: 3 attempts with exponential backoff via Action Scheduler
- Log: per-endpoint delivery log (last 50 calls, response code, latency)
- Disable endpoint on N consecutive failures (re-enable manually)

Works natively as a Zapier / Make trigger. No custom app needed — standard HTTP webhook.

---

### Pro License
- License key entry
- Active features list
- Link to Pro settings (separate Pro settings tab added by Pro plugin)

---

## Developer Extension

Additional settings tabs registered via filter. Each tab gets a section in the admin panel with its own settings group.

---

## Integration Points

| Feature | Settings connection |
|---------|-------------------|
| Notifications + Email | Email editor accessible from settings |
| Registration + Onboarding | Registration mode, verification toggle |
| Moderation | Strike thresholds, auto-hide |
| Activity Feed | Post privacy defaults, edit window |
| Spaces | Creation permissions |
| Blocks | Page assignments |
| Addons | Integration status panel |

---

## Gaps / Open Questions

- None — fully locked
