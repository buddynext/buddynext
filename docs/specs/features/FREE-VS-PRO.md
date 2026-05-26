# BuddyNext — Free vs Pro Classification

**Status:** Locked
**Last updated:** 2026-03-20

---

## Licensing Model

Site-based annual + lifetime licenses: Single / 5-site / Unlimited.
Same Pro feature set across all license tiers — only site count differs.
BuddyNext Pro is bundled with BuddyX / BuddyX Pro / Reign themes + all addons (Jetonomy, WPMediaVerse, WBGamification, Career Board).

**Buyer types served by Pro:** solo creator, business/brand, agency — all tiers, same features.

---

## Design Rule

> Free must be genuinely good — good enough to replace BuddyPress/BuddyBoss free and win on adoption.
> Pro must be genuinely necessary — features every growing community hits within 3–6 months.

---

## Free Features

### Social Graph
- Follow / unfollow
- Connect / disconnect (two-way)
- Block / unblock

### Activity Feed
- Post types: text, link preview, poll, photo/file (via WPMediaVerse)
- React, comment, share, bookmark, report
- Pin 1 post per space or profile
- Edit own posts (with "edited" label)
- Delete own posts
- Admin announcements (site-wide pinned post)
- Explore feed (public, no login required)
- Hashtag feed

### Spaces
- Unlimited spaces
- Space types: public, request-to-join
- Space feed, members tab, optional Forum tab (Jetonomy), optional Media tab (WPMediaVerse)
- Space settings: general, privacy, members, moderation, integrations

### Profiles
- Avatar + cover (via WPMediaVerse)
- Bio, social links
- Up to 5 custom fields — types: text, URL, select, checkbox

### Direct Messaging
- 1:1 private messaging (powered by WPMediaVerse free — soft dependency)
- Message requests (unknown senders)
- Mute, pin, archive conversations
- Emoji reactions on messages
- Quoted replies
- Polling-based real-time (5s)
- Rate limiting (30 msg/min)
- *Requires WPMediaVerse free active. DM tab hidden if WPMediaVerse not installed.*

### Notifications
- In-app notification bell + full /notifications/ page
- Transactional emails (follow, mention, comment, DM, space invite, moderation actions)
- Per-user notification preferences

### Reactions + Comments
- 6 standard emoji reactions
- Threaded comments (2 levels deep)
- Comment reactions

### Moderation
- Report button on all content types
- Manual admin review queue
- Dismiss / remove / warn / strike / suspend actions
- Moderation log
- Space-scoped moderation for space admins

### Search
- Full-text search: people, posts, spaces, hashtags
- Results from addon bridges (Jetonomy threads, WPMediaVerse media)

### Member Directory
- Filterable member grid
- Mutual connection indicators
- Online status (polling-based)

### Onboarding
- Member onboarding wizard (profile → interests → spaces → people)
- Admin setup wizard (first-run configuration)

### Admin
- Full admin settings panel
- Member management (invite, suspend, export basic)
- Space management
- Email template editor (transactional templates)
- Basic moderation queue
- Blocks & widgets library (core set)
- Nav manager (hook-based tab management)
- Integration hub (connect addons)

### Platform
- REST API (`buddynext/v1`) — all features API-first
- 1 outbound webhook endpoint
- All addon bridges: Jetonomy, WPMediaVerse, WBGamification, Career Board
- Gutenberg blocks (core set: Feed, Members, Spaces, Profile Card, Join CTA, Notification Bell)
- Sidebar widgets (core set)
- Shortcodes (core set)
- PWA (manifest + service worker — responsive web, add to home screen)

---

## Pro Features

### Content & Scheduling *(Creator, Business)*
- **Scheduled posts** — set future publish date/time per post
- **Recurring scheduled posts** — auto-repeat a post on a defined schedule (e.g. weekly check-in every Monday, monthly newsletter)
- Multiple pinned posts per space or profile (up to 10)
- Custom reaction emoji set — admin configures up to 20 reactions
- Post reach stats for authors — impressions, engagement rate per post

### Broadcast & Email Automation *(Creator, Business)*
- **Broadcast email campaigns** — admin/community manager sends newsletter to all members or a segment
- **Drip welcome sequences** — automated email series triggered on member join: day 1 welcome, day 3 space suggestions, day 7 activity digest, day 14 re-engagement, day 30 milestone. Admin configures templates + intervals per sequence.
- **Space digest emails** — auto-curated weekly (or monthly) email digest of top posts per space, sent to all space members. Space admins can enable/disable per space.
- Member segments for targeting (by space, by tag, by activity level, by join date)
- Campaign history + open rate / CTR stats
- Unsubscribe handling (CAN-SPAM / GDPR compliant)
- Template editor for broadcast emails (separate from transactional)
- DB: `bn_email_campaigns`, `bn_campaign_recipients`, `bn_drip_sequences`, `bn_drip_enrollments`

### Group Messaging + Real-time DM *(All Pro)*
- **Group DM** — conversations with 2–49 members (powered by WPMediaVerse Pro — bundled in BuddyNext Pro)
- Group name + avatar
- Admin role (creator / longest-remaining member)
- Add / remove members, leave group
- Inherits all DM features (reactions, quoted replies)
- **Read receipts** — sent / delivered / read status (WPMediaVerse Pro)
- **WebSocket real-time DM** — instant delivery, typing indicator, online presence (WPMediaVerse Pro)
- WPMediaVerse Pro is bundled in BuddyNext Pro — no separate purchase required

### Real-time *(All Pro)*
- **Real-time feed** — "X new posts" push via WebSocket (BuddyNext Pro)
- Online presence indicators across directory and space members (BuddyNext Pro)
- Space live activity events (BuddyNext Pro)
- Real-time DM (instant delivery, typing indicator, read receipts) — via WPMediaVerse Pro (bundled)
- Transport: `mvs_messaging_transport` filter on WPMediaVerse Pro; `buddynext_realtime_transport` filter on BuddyNext Pro feed/presence layer

### Advanced Moderation *(Business, Agency)*
- **Keyword blocklist** — auto-flag or auto-remove posts containing defined words/phrases
- **Auto-action rules** — if post flagged by X members within Y hours → auto-remove / auto-warn
- **Spam scoring** — ML-based score at submission, threshold configurable
- Bulk moderation actions (select multiple reports → bulk dismiss/remove/warn)
- IP + email domain blocklist (block registrations from known spam domains)
- Appeal system — suspended users can submit appeal, admin reviews
- Moderation team assignment (assign reports to specific moderators)
- DB: `bn_mod_rules`, `bn_mod_appeals`

### Analytics *(All)*
- **Full site analytics dashboard** — member growth, DAU/WAU/MAU, feed engagement, churn signals
- **Space-level analytics** — post activity, member growth, top contributors, join/leave rate (per-space view for space admins)
- Member self-analytics — profile views, post reach, follower growth
- Email performance — open rate, CTR per notification type
- Top content, top members, top spaces
- Export analytics data as CSV
- DB: `bn_analytics_events`

### Access & Spaces *(Business, Agency)*
- **Private spaces** — invite-only, no public request
- **Gated spaces** — requires an ability grant (hooks into any payment/membership system via webhook)
- **Post approval queue** — space admin must approve posts before they are visible. Per-space toggle. Posts land in a pending queue; approved posts publish, rejected posts notify author. Essential for curated/professional spaces.
- Paywall UI — blurred preview + configurable CTA button per space
- Member tiers — define tiers (Free Member, Premium, VIP), assign per-tier space access
- Per-space custom branding (cover image, accent colour, custom description layout)
- DB: uses `bn_ability_{slug}` user_meta + `bn_membership_tiers`; post approval uses `bn_posts.status = 'pending'`

### Member Management *(Business)*
- Advanced profile field types: date, location (map picker), file upload, multi-select, number, conditional (show field if other field = value)
- **Custom member labels** — admin-defined labels ("Verified", "Expert", "Staff", "Alumni") displayed on profiles and post bylines. Multiple labels per member. Different from gamification badges — these are editorial/role signals.
- Member segments + tags (manual or rule-based tagging)
- CSV member export (all fields including custom)
- Bulk member actions: message segment, change role, suspend batch, export selection
- Profile completeness score + prompts
- DB: `bn_member_labels`, `bn_member_label_assignments`

### AI Engine *(All)*
- AI feed ranking — personalised by engagement signals, relationship strength, content affinity
- AI content moderation — toxicity scoring at submission, configurable thresholds
- Smart notifications — suppress low-signal, optimal send-time delivery
- Discovery — "Spaces you might like", extended "People you might know"
- DB: `bn_ai_signals`

### Advanced Search *(Business)*
- Saved searches
- Filter by profile field value (e.g. "members in London")
- Filter by activity level (e.g. "posted in last 7 days")
- Filter by space membership
- Filter by member tier
- Filter by member label

### Developer & Integration *(Business, Agency)*
- Unlimited outbound webhook endpoints
- Full outbound event catalog (member registered, post created, space joined, etc.)
- REST namespace alias (remap `buddynext/v1` to custom namespace)
- Advanced Gutenberg blocks (Member Spotlight, Space Analytics Widget, Broadcast CTA)

### White-label *(Agency tier only — Unlimited license)*
- Remove BuddyNext branding from all user-facing surfaces
- Custom product name + logo in admin panel
- Custom email template footers (no BuddyNext mention)
- Gutenberg blocks renamed in editor
- REST namespace alias
- Mobile app: custom app name, icon, splash per site
- Not available on Single or 5-site licenses

### Mobile App *(All Pro customers — V3, free with Pro license)*
- React Native app for iOS + Android — ships as V3, free for all active Pro license holders
- White-labelable per site (custom app name, icon, splash screen)
- Consumes `buddynext/v1` REST API — no separate backend
- Push notifications (native iOS + Android)
- See: `P4-mobile-app.md`

---

## Feature Matrix Summary

| Feature | Free | Pro |
|---------|------|-----|
| Activity feed (text, poll, link) | ✅ | ✅ |
| Photo/file posts (via WPMediaVerse) | ✅ | ✅ |
| 1 pinned post per space | ✅ | ✅ |
| Scheduled posts | ❌ | ✅ |
| Recurring scheduled posts | ❌ | ✅ |
| Multiple pinned posts (up to 10) | ❌ | ✅ |
| Post reach stats per author | ❌ | ✅ |
| Unlimited spaces | ✅ | ✅ |
| Public + request-to-join spaces | ✅ | ✅ |
| Private spaces (invite-only) | ❌ | ✅ |
| Gated spaces (membership) | ❌ | ✅ |
| Post approval queue per space | ❌ | ✅ |
| 1:1 DM (via WPMediaVerse free) | ✅ | ✅ |
| Read receipts in DM | ❌ | ✅ via WPMediaVerse Pro (bundled) |
| Group DM up to 49 (via WPMediaVerse Pro) | ❌ | ✅ bundled |
| Real-time DM WebSocket (via WPMediaVerse Pro) | ❌ | ✅ bundled |
| Broadcast email to members | ❌ | ✅ |
| Drip welcome sequences | ❌ | ✅ |
| Space digest emails (weekly/monthly) | ❌ | ✅ |
| Real-time WebSocket | ❌ | ✅ |
| 6 standard reactions | ✅ | ✅ |
| Custom reaction emoji set | ❌ | ✅ |
| Basic moderation (report + review) | ✅ | ✅ |
| Auto-moderation (keyword, rules) | ❌ | ✅ |
| Bulk moderation actions | ❌ | ✅ |
| Basic profile fields (up to 5) | ✅ | ✅ |
| Advanced profile fields + conditional | ❌ | ✅ |
| Custom member labels (Verified, Expert…) | ❌ | ✅ |
| Member segments + CSV export | ❌ | ✅ |
| Basic admin stats (counts) | ✅ | ✅ |
| Full analytics dashboard | ❌ | ✅ |
| Space-level analytics | ❌ | ✅ |
| All addon bridges | ✅ | ✅ |
| 1 outbound webhook | ✅ | ✅ |
| Unlimited webhooks | ❌ | ✅ |
| AI feed ranking + moderation | ❌ | ✅ |
| White-label | ❌ | ✅ Agency (Unlimited license only) |
| Mobile app (React Native) | ❌ | ✅ V3 — free for all Pro license holders |

---

## Licensing Tier Breakdown

| Feature | Single | 5-site | Unlimited |
|---------|--------|--------|-----------|
| All Pro features | ✅ | ✅ | ✅ |
| White-label | ❌ | ❌ | ✅ |
| Mobile app (V3, when released) | ✅ | ✅ | ✅ |

---

## Gaps / Open Questions

- None — fully locked.
