# BuddyNext — Spec Index

**Last updated:** 2026-03-20

---

## Core Principle

> Minimal by default, extensible by design.
> Ship the essential feature. Cover it with hooks. Let the ecosystem add layers.

---

## Feature Specs

| # | File | Status |
|---|------|--------|
| 00 | [Master Architecture](features/00-architecture.md) | ✅ Locked |
| 01 | [Social Graph](features/01-social-graph.md) | ✅ Locked |
| 02 | [Activity Feed](features/02-activity-feed.md) | ✅ Locked |
| 03 | [Spaces](features/03-spaces.md) | ✅ Locked |
| 04 | [Member Directory + Search](features/04-member-directory-search.md) | ✅ Locked |
| 05 | [User Profiles](features/05-user-profiles.md) | ✅ Locked |
| 06 | [Notifications + Email System](features/06-notifications-email.md) | ✅ Locked |
| 07 | [Direct Messaging](features/07-direct-messaging.md) | ✅ Locked — V1 |
| 08 | [Reactions + Comments](features/08-reactions-comments.md) | ✅ Locked |
| 09 | [Moderation](features/09-moderation.md) | ✅ Locked |
| 10 | [Onboarding + Setup Wizard](features/10-onboarding-setup-wizard.md) | ✅ Locked |
| 11 | [Gutenberg Blocks](features/11-gutenberg-blocks.md) | ✅ Locked |
| 12 | [WBGamification Bridge](features/12-wbgamification-bridge.md) | ✅ Locked |
| 13 | [Jetonomy Bridge](features/13-jetonomy-bridge.md) | ✅ Locked |
| 14 | [WPMediaVerse Bridge](features/14-wpmediaverse-bridge.md) | ✅ Locked |
| 15 | [Career Board Bridge](features/15-career-board-bridge.md) | ✅ Locked |
| 16 | [Admin Settings](features/16-admin-settings.md) | ✅ Locked |
| 17 | [Roles + Permissions](features/17-roles-permissions.md) | ✅ Locked |
| 18 | [Hashtags](features/18-hashtags.md) | ✅ Locked |
| 19 | [Database + Scale](features/19-database-scale.md) | ✅ Locked |

## Integration Requirements (Other Plugins)

| # | File | Status |
|---|------|--------|
| INT-1 | [WPMediaVerse DM Integration Requirements](WPMediaVerse-DM-Integration-Requirements.md) | ✅ Locked |

## Hook Reference

| # | File | Status |
|---|------|--------|
| H-1 | [Action Hook Reference](HOOKS.md) | ✅ Locked — complete buddynext_* action list + WPMediaVerse integration hooks |

---

## Free vs Pro Classification

| # | File | Status |
|---|------|--------|
| FP | [Free vs Pro](features/FREE-VS-PRO.md) | ✅ Locked |

## Pro Feature Specs

| # | File | Status |
|---|------|--------|
| P1 | [Membership / Access Gating](features/P1-stripe-membership.md) | ✅ Locked |
| P2 | [AI Engine](features/P2-ai-engine.md) | ✅ Locked |
| P3 | [Real-time (WebSocket)](features/P3-realtime-websocket.md) | ✅ Locked |
| P4 | [Mobile App (React Native)](features/P4-mobile-app.md) | ✅ Locked |
| P5 | [Advanced Analytics](features/P5-analytics.md) | ✅ Locked |
| P6 | [White-label](features/P6-white-label.md) | ✅ Locked |

---

## DB Tables Master List

| Table | Feature |
|-------|---------|
| `bn_follows` | Social Graph |
| `bn_connections` | Social Graph |
| `bn_blocks` | Social Graph |
| `bn_posts` | Activity Feed — media_ids JSON refs WPMediaVerse for photo/file |
| `bn_poll_options` | Activity Feed — poll choices |
| `bn_poll_votes` | Activity Feed — poll responses |
| `bn_bookmarks` | Activity Feed |
| `bn_shares` | Activity Feed |
| `bn_spaces` | Spaces |
| `bn_space_members` | Spaces |
| `bn_space_categories` | Spaces |
| `bn_hashtags` | Hashtags — registry |
| `bn_post_hashtags` | Hashtags — post pivot |
| `bn_hashtag_follows` | Hashtags — user follows hashtag |
| `bn_search_index` | Search |
| `bn_profile_fields` | Profiles |
| `bn_profile_values` | Profiles |
| `bn_notifications` | Notifications |
| `bn_notification_prefs` | Notifications |
| `bn_email_templates` | Email |
| `bn_email_log` | Email (digest dedup) |
| `bn_verify_tokens` | Registration |
| `mvs_conversations` | Direct Messaging — owned by WPMediaVerse, BuddyNext bridges |
| `mvs_conversation_participants` | Direct Messaging — owned by WPMediaVerse |
| `mvs_messages` | Direct Messaging — owned by WPMediaVerse |
| `mvs_message_reactions` | Direct Messaging — owned by WPMediaVerse |
| `bn_reactions` | Reactions |
| `bn_comments` | Comments |
| `bn_reports` | Moderation |
| `bn_mod_log` | Moderation |
| `bn_user_strikes` | Moderation |
| `bn_user_abilities` | Roles — WP Abilities API grant/revoke log |
| `bn_activity_log` | Platform — user action audit (login, post, join, follow) pruned 90d |
| `bn_feed_items` | Platform — pre-computed feed cache (enabled above 1M members) |
| `bn_membership_tiers` | Pro: Membership |
| `bn_subscriptions` | Pro: Membership |
| `bn_ai_signals` | Pro: AI |
| `bn_analytics_events` | Pro: Analytics |
| `bn_email_campaigns` | Pro: Broadcast Email |
| `bn_campaign_recipients` | Pro: Broadcast Email |
| `bn_drip_sequences` | Pro: Drip Welcome Sequences |
| `bn_drip_enrollments` | Pro: Drip Welcome Sequences |
| `bn_mod_rules` | Pro: Advanced Moderation |
| `bn_mod_appeals` | Pro: Advanced Moderation |
| `bn_member_labels` | Pro: Custom Member Labels |
| `bn_member_label_assignments` | Pro: Custom Member Labels |
