# BuddyNext — Product Design Spec

**Date:** 2026-03-19
**Status:** Draft — pending implementation planning
**Domain:** buddynext.com
**Author:** Wbcom Designs

---

## 1. What We're Building

BuddyNext is the next-generation WordPress social community platform — a complete, modern alternative to BuddyPress, BuddyBoss, Circle.so, and Mighty Networks. It owns the social graph layer for WordPress, with Jetonomy (forums), WPMediaVerse (media), WBGamification (points/badges), and WP Career Board as optional integration modules.

**Core positioning:**
- vs BuddyBoss: Same power, modern stack, transparent pricing, no vendor lock-in
- vs Circle/Mighty Networks: Data ownership, SEO, WordPress flexibility, predictable annual cost (not growing SaaS fees)
- vs BuddyPress: Everything BuddyPress should have been — built for 2026+

---

## 2. Product Model

### Editions

| Edition | What's Included | Who It's For |
|---------|----------------|--------------|
| **BuddyNext Free** | Full social core — profiles, connections, feed, spaces, directory, notifications, messaging, moderation | Anyone running a community on WordPress |
| **BuddyNext Pro** | AI engine, Stripe membership tiers, gated spaces, real-time, mobile app, advanced analytics, white-label | Community builders monetizing their audience |

### Pro Bundles

| Bundle | Plugins Included |
|--------|-----------------|
| **Community Bundle** | BuddyNext Pro + Jetonomy Pro + WPMediaVerse Pro + WBGamification |
| **Complete Stack** | Community Bundle + WP Career Board Pro + BuddyX Pro |
| **Agency Stack** | Complete Stack + Reign Theme |

### What Is NOT in BuddyNext scope
- Courses/LMS → separate plugin, future roadmap
- Live streaming → YouTube/Zoom embed only
- Email sequence builder → FluentCRM/Mailchimp hooks provided
- PWA/offline mode → future roadmap

---

## 3. Technical Stack

Identical to Jetonomy and WPMediaVerse to enable shared maintenance and deep integration.

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.1+, WordPress 6.9+ |
| Architecture | DI Service Container (same pattern as WPMediaVerse) |
| Extension surface | WordPress Abilities API (WP 6.9+) — every feature is an ability |
| Frontend reactivity | WordPress Interactivity API |
| Embeddable components | Gutenberg blocks (12+ blocks) |
| Async jobs | Action Scheduler |
| Autoloader | Composer PSR-4 |
| REST API | Single source of truth — browser + mobile hit same endpoints |
| Real-time transport | Swappable via `buddynext_messaging_transport` filter (REST polling default → WebSocket Pro) |
| Mobile app | React Native / Expo (white-labelable, Pro only) |
| Payments | Stripe (built-in, no WooCommerce dependency) |

### Bootstrap Chain

```
plugins_loaded → BuddyNext\Core\Plugin::init() → fires buddynext_loaded
                                                            ↓
                                        buddynext-pro hooks in via buddynext_loaded
                                        jetonomy bridge (if jetonomy active)
                                        wpmediaverse bridge (if mvs active)
                                        wb-gamification bridge (if wb-gamification active)
                                        wp-career-board bridge (if career board active)
```

### REST Namespaces

| Namespace | Plugin |
|-----------|--------|
| `buddynext/v1` | Core (free) |
| `buddynext-pro/v1` | Pro features |

---

## 4. Free Core Features

Everything below ships in the free plugin — complete enough to run a real community without upgrading.

### 4.1 Social Graph
- Follow model (asymmetric) OR mutual connections (configurable per site)
- Block/mute users
- Connection suggestions (basic — algorithmic in Pro)
- `buddynext/v1/connections` REST endpoints

### 4.2 User Profiles
- Extended profile fields (bio, location, website, social links)
- Cover photo + avatar
- Profile visibility controls (public / logged-in / connections only)
- Public profiles indexed by Google (SEO advantage over SaaS)
- Profile completion progress indicator
- `buddynext/v1/profiles` REST endpoints

### 4.3 Activity Feed
- Chronological feed (algorithmic ranking in Pro)
- Post types: text, image, link preview, poll, check-in
- WPMediaVerse media cards (if active)
- Jetonomy discussion references (if active)
- Per-item privacy (public / connections / space members / private)
- `buddynext/v1/feed` REST endpoints

### 4.4 Spaces (Groups/Communities)
- Types: Open, Private (request to join), Secret (invite only)
- Space-scoped feed, member roster, roles (member / moderator / owner)
- Space cover photo + avatar
- BuddyPress group migration support
- `buddynext/v1/spaces` REST endpoints

### 4.5 Member Directory
- Searchable, filterable by profile fields
- Online status indicator
- Sort by: newest, most active, alphabetical
- `buddynext/v1/members` REST endpoints

### 4.6 Direct Messaging
- 1:1 and group conversations
- REST long-poll transport (default), WebSocket (Pro)
- Chat panel in `wp_footer` (same pattern as WPMediaVerse Pro)
- Full `/messages/` page
- Config via `window.bnMessagingConfig` in `wp_head`

### 4.7 Notifications
- In-app notification bell (Interactivity API)
- Email digest (daily/weekly, user configurable)
- Notification types: mention, reaction, follow, space invite, comment, connection request
- `buddynext/v1/notifications` REST endpoints

### 4.8 Reactions
- Configurable emoji set (admin sets available reactions)
- On: feed posts, comments, media, discussions
- Integrates with WBGamification points on receive

### 4.9 Moderation
- Report any object (post, comment, profile, space)
- Admin moderation queue
- Pre-moderation mode per space
- User block/mute (stored in social graph layer)
- AI spam scoring (Pro) — free tier: manual only

### 4.10 Onboarding
- Setup wizard (first-run admin config)
- New member profile completion flow
- Welcome email hook (connect to any email plugin)

### 4.11 Migration (WP-CLI)
```bash
wp buddynext import-buddypress    # BuddyPress → BuddyNext
wp buddynext import-buddyboss     # BuddyBoss → BuddyNext
wp buddynext import-peepso        # PeepSo → BuddyNext
```

---

## 5. Pro Features

### 5.1 Stripe Membership
- Unlimited membership tiers (free, paid, trial)
- Stripe Checkout + Customer Portal (no custom payment UI)
- Gated content: spaces, feed posts, profile sections
- Webhook-driven subscription lifecycle (activate → cancel → expire)
- No WooCommerce dependency — direct Stripe SDK

### 5.2 AI Engine (Silent — no AI branding in UI)
- Feed ranking (engagement signals → personalized order)
- Spam/toxicity scoring (auto-hold for review above threshold)
- Member recommendations (who to follow, which spaces to join)
- Churn detection (flags disengaging members to admin)
- Action Scheduler jobs — never blocks page load

### 5.3 Real-Time
- WebSocket transport swap via `buddynext_messaging_transport` filter
- Live feed updates (new posts appear without refresh)
- Typing indicators in DMs
- Online presence system

### 5.4 Mobile App
- React Native / Expo
- White-labelable (app name, icon, colors via config)
- Targets iOS + Android
- Same REST API as web — no separate mobile backend
- Push notifications (Expo Push Notification Service)

### 5.5 Advanced Analytics
- Member growth, retention, churn rates
- Content engagement heatmaps
- Space health scores
- CSV export
- Admin dashboard + REST endpoints

### 5.6 White-Label
- Remove all BuddyNext branding
- Custom plugin name in admin
- Agency license (deploy on multiple client sites)

### 5.7 Gated Spaces
- Membership-tier-gated space access
- Trial access (X days free, then paywall)
- Drip access (unlock content over time)

---

## 6. Integrations (Bridge Classes)

Each bridge is a standalone class that activates only when the target plugin is detected. No hard dependencies.

| Bridge | Detection | What It Enables |
|--------|-----------|-----------------|
| `JetonomyBridge` | `defined('JETONOMY_VERSION')` | Forum discussions appear in BuddyNext feed; space-scoped forums |
| `WPMediaVerseBridge` | `defined('MVS_VERSION')` | Media cards in feed; media tab on profiles and spaces |
| `WBGamificationBridge` | `defined('WB_GAM_VERSION')` | Earn points for BuddyNext actions; badges for community milestones |
| `CareerBoardBridge` | `defined('WP_CAREER_BOARD_VERSION')` | Job posts in feed; hiring status on profiles |
| `BuddyPressBridge` | `function_exists('buddypress')` | Migration only — not a runtime dependency |

---

## 7. WordPress Abilities API

Every BuddyNext feature is an ability — the primary extension surface for Pro bundles, themes, and third-party plugins.

### Core Abilities (Free)
| Category | Abilities |
|----------|-----------|
| `buddynext-profile` | view-profile, edit-profile, set-profile-visibility |
| `buddynext-connections` | send-connection-request, accept-connection, block-user |
| `buddynext-feed` | post-to-feed, delete-own-post, react-to-post |
| `buddynext-spaces` | create-space, join-space, manage-space-members |
| `buddynext-messaging` | send-message, list-conversations |
| `buddynext-moderation` | report-content, manage-moderation-queue |

### Pro Abilities
| Category | Abilities |
|----------|-----------|
| `buddynext-membership` | create-membership-tier, gate-content, manage-subscriptions |
| `buddynext-analytics` | view-analytics, export-analytics |
| `buddynext-ai` | configure-ai-engine, view-ai-insights |

---

## 8. Database Tables

All tables prefixed `wp_bn_*`.

| Table | Key Columns | Purpose |
|-------|-------------|---------|
| `bn_profiles` | user_id, bio, location, website, cover_url, visibility, completed_at | Extended profile data |
| `bn_connections` | id, initiator_id, recipient_id, status, created_at | Follow/connection graph |
| `bn_feed_items` | id, actor_id, verb, object_type, object_id, content, visibility, created_at | Activity feed (all verbs) |
| `bn_feed_votes` | feed_item_id, user_id, reaction_type, created_at | Reactions on feed items |
| `bn_spaces` | id, slug, name, type, owner_id, member_count, created_at | Communities/groups |
| `bn_space_members` | space_id, user_id, role, joined_at, notification_pref | Space membership |
| `bn_notifications` | id, recipient_id, type, actor_id, object_type, object_id, read_at, created_at | In-app notifications |
| `bn_reports` | id, reporter_id, object_type, object_id, reason, status, created_at | User reports |
| `bn_conversations` | id, type, created_by, last_message_at | DM threads |
| `bn_conversation_participants` | conversation_id, user_id, last_read_at, is_muted | DM membership |
| `bn_messages` | id, conversation_id, sender_id, content, created_at | DM messages |

**Pro tables** (buddynext-pro):

| Table | Purpose |
|-------|---------|
| `bn_membership_tiers` | Tier definitions + Stripe price IDs |
| `bn_subscriptions` | user_id, tier_id, status, stripe_subscription_id, expires_at |
| `bn_ai_signals` | Behavioral signals for feed ranking (user_id, signal_type, object_id, weight) |
| `bn_analytics_events` | Hourly-bucketed engagement stats per object |

---

## 9. Key Filters & Actions

| Hook | Type | Purpose |
|------|------|---------|
| `buddynext_loaded` | action | Plugin fully initialized — Pro + bridges hook here |
| `buddynext_register_object_type` | filter | Register custom feed object types |
| `buddynext_feed_query` | filter | Modify feed query (AI ranking hooks here in Pro) |
| `buddynext_can_view_profile` | filter | Custom profile visibility rules |
| `buddynext_messaging_transport` | filter | Swap messaging transport (REST → WebSocket in Pro) |
| `buddynext_membership_gate` | filter | Custom membership gating logic |
| `buddynext_ai_score` | filter | Override AI spam/toxicity score |
| `buddynext_space_roles` | filter | Add custom space roles |

---

## 10. Competitive Advantages (Ship These First)

1. **SEO** — Profiles and public feed posts are indexed by Google. Circle/Mighty are invisible to search.
2. **Data ownership** — One-click full export (JSON + CSV). SaaS platforms trap your data.
3. **Bundle value** — BuddyNext + Jetonomy + WPMediaVerse + WBGamification + Career Board = complete platform. No SaaS can match this at $299/yr.
4. **Pricing** — Flat annual renewal. No per-member fees. No surprise price hikes.
5. **Theme-agnostic** — Works with BuddyX, BuddyX Pro, Reign, Twenty Twenty-Six, any theme.

---

## 11. Out of Scope (Future Plugins)

| Feature | Future Plugin |
|---------|--------------|
| Courses / LMS | BuddyNext Courses (separate plugin) |
| Live streaming | BuddyNext Live (separate plugin) |
| Email sequences | Integration hooks — use FluentCRM/Mailchimp |
| Job board (advanced) | WP Career Board Pro handles this |

---

## 12. Open Questions (Pre-Implementation)

- [ ] Connection model: follow-only (asymmetric, like Twitter) OR mutual connections (like Facebook) OR both (configurable)?
- [ ] Free tier: include DM or gate it in Pro? (Circle includes it free — probably must too)
- [ ] Mobile app: ship with Pro v1 or v1.x?
- [ ] Gutenberg blocks: how many for free vs Pro?
- [ ] Feed: purely chronological in free, or lightweight ranking even in free?
