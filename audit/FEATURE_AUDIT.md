# BuddyNext Free - Feature Audit

**Generated:** 2026-05-20 | **Version:** 0.2.0 | **Branch:** master

This document is machine-generated from ground-truth grep enumeration. Refresh via the wp-plugin-onboard skill after non-trivial changes.

---

## Domain Index

1. [Feed](#1-feed)
2. [Social Graph (Follow / Connection / Block)](#2-social-graph-follow--connection--block)
3. [Spaces](#3-spaces)
4. [Profile](#4-profile)
5. [Search](#5-search)
6. [Hashtags](#6-hashtags)
7. [Notifications](#7-notifications)
8. [Moderation](#8-moderation)
9. [Member Types](#9-member-types)
10. [Auth / Verification](#10-auth--verification)
11. [Onboarding](#11-onboarding)
12. [Outbound Webhooks](#12-outbound-webhooks)
13. [PWA](#13-pwa)
14. [Admin](#14-admin)
15. [Blocks](#15-blocks)
16. [Shortcodes](#16-shortcodes)
17. [Bridges](#17-bridges)
18. [Gamification](#18-gamification)
19. [Realtime](#19-realtime)
20. [Core Infrastructure](#20-core-infrastructure)

---

## 1. Feed

**Services:** `FeedService`, `PostService`, `PollService`, `BookmarkService`, `ShareService`

**Key Tables:** `bn_posts`, `bn_feed_items`, `bn_poll_options`, `bn_poll_votes`, `bn_bookmarks`, `bn_shares`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/feed/home` | Required | Home feed (follows-based) |
| GET | `/buddynext/v1/feed/explore` | Public | Global explore feed |
| GET | `/buddynext/v1/users/{id}/feed` | Public | Profile feed |
| GET | `/buddynext/v1/spaces/{id}/feed` | Public | Space feed |
| POST | `/buddynext/v1/feed/announcements/{id}/dismiss` | Required | Dismiss announcement |
| POST | `/buddynext/v1/posts` | Required | Create post |
| GET | `/buddynext/v1/posts/{id}` | Public | Get single post |
| POST | `/buddynext/v1/posts/{id}/pin` | Required | Pin/unpin post (moderator) |
| POST | `/buddynext/v1/posts/{id}/vote` | Required | Vote on poll |
| GET | `/buddynext/v1/posts/{id}/poll` | Public | Get poll data |
| GET | `/buddynext/v1/posts/{id}/my-vote` | Required | Get own poll vote |
| POST | `/buddynext/v1/posts/{id}/bookmark` | Required | Toggle bookmark |
| GET | `/buddynext/v1/me/bookmarks` | Required | List bookmarks |
| POST | `/buddynext/v1/posts/{id}/share` | Required | Share a post |
| GET | `/buddynext/v1/me/shares` | Required | List own shares |

**Hooks Fired:**

- `buddynext_post_created` (action, args: post_id, user_id)
- `buddynext_post_deleted` (action, args: post_id)
- `buddynext_post_impression` (action, args: post_id, user_id)
- `buddynext_reaction_added` (action, args: post_id, user_id, type)
- `buddynext_reaction_removed` (action, args: post_id, user_id, type)
- `buddynext_comment_created` (action, args: comment_id, post_id)
- `buddynext_comment_updated` (action, args: comment_id, data)
- `buddynext_comment_deleted` (action, args: comment_id)

**Filters:**

- `buddynext_reaction_types` - override/extend available reaction types
- `buddynext_post_pin_limit` - max pinned posts per space
- `buddynext_safeguard_check` - pre-flight content safety hook

**Capabilities Required:**

- `buddynext-feed/create-post` (member role)
- `buddynext-feed/delete-own-post` (member role)
- `buddynext-feed/delete-any-post` (moderator role)
- `buddynext-feed/pin-post` (moderator role)
- `buddynext-feed/schedule-post` (member role)

---

## 2. Social Graph (Follow / Connection / Block)

**Services:** `FollowService`, `ConnectionService`, `BlockService`, `PrivacyService`

**Key Tables:** `bn_follows`, `bn_connections`, `bn_blocks`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/buddynext/v1/users/{id}/follow` | Required | Follow/unfollow user |
| GET | `/buddynext/v1/users/{id}/followers` | Public | List followers |
| GET | `/buddynext/v1/users/{id}/following` | Public | List following |
| GET | `/buddynext/v1/follow-suggestions` | Required | Suggested users to follow |
| POST | `/buddynext/v1/users/{id}/connect` | Required | Send/withdraw connection request |
| POST | `/buddynext/v1/users/{id}/connect/accept` | Required | Accept connection request |
| POST | `/buddynext/v1/users/{id}/connect/decline` | Required | Decline connection request |
| GET | `/buddynext/v1/me/connections` | Required | List own connections |
| GET | `/buddynext/v1/me/connection-requests` | Required | List pending requests |
| POST | `/buddynext/v1/users/{id}/block` | Required | Block/unblock user |
| POST | `/buddynext/v1/users/{id}/mute` | Required | Mute/unmute user |
| GET | `/buddynext/v1/me/blocked` | Required | List blocked users |
| GET | `/buddynext/v1/me/muted` | Required | List muted users |

**Hooks Fired:**

- `buddynext_user_followed` / `buddynext_user_unfollowed`
- `buddynext_connection_requested` / `buddynext_connection_accepted` / `buddynext_connection_declined` / `buddynext_connection_withdrawn` / `buddynext_connection_removed`
- `buddynext_block` / `buddynext_unblock`

**Capabilities Required:**

- `buddynext-connections/follow` (member role)
- `buddynext-connections/connect` (member role)

---

## 3. Spaces

**Services:** `SpaceService`, `SpaceMemberService`

**Key Tables:** `bn_spaces`, `bn_space_members`, `bn_space_categories`, `bn_space_bans`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/spaces` | Public | List spaces |
| POST | `/buddynext/v1/spaces` | Required | Create space |
| GET | `/buddynext/v1/spaces/{id}` | Public | Get space |
| PUT | `/buddynext/v1/spaces/{id}` | Required | Update space |
| DELETE | `/buddynext/v1/spaces/{id}` | Required | Delete space |
| GET | `/buddynext/v1/spaces/{id}/members` | Public | List members |
| GET | `/buddynext/v1/spaces/{id}/pending-requests` | Required | Pending join requests |
| POST | `/buddynext/v1/spaces/{id}/join` | Required | Join / request to join |
| POST | `/buddynext/v1/spaces/{id}/leave` | Required | Leave space |
| POST | `/buddynext/v1/spaces/{id}/invite` | Required | Invite member |
| POST | `/buddynext/v1/spaces/{id}/approve-request` | Required | Approve join request |
| POST | `/buddynext/v1/spaces/{id}/decline-request` | Required | Decline join request |
| POST | `/buddynext/v1/spaces/{id}/ban/{user_id}` | Required | Ban member |
| POST | `/buddynext/v1/spaces/{id}/unban/{user_id}` | Required | Unban member |
| POST | `/buddynext/v1/spaces/{id}/remove/{user_id}` | Required | Remove member |
| GET | `/buddynext/v1/me/spaces` | Required | Own spaces |
| GET | `/buddynext/v1/spaces/slug/{slug}` | Public | Get space by slug |
| GET | `/buddynext/v1/space-categories` | Public | List categories |
| POST | `/buddynext/v1/space-categories` | Required | Create category (admin) |

**Hooks Fired:**

- `buddynext_space_created` / `buddynext_space_updated` / `buddynext_space_deleted`
- `buddynext_space_join_requested` / `buddynext_space_join_approved` / `buddynext_space_join_declined`
- `buddynext_space_member_joined` / `buddynext_space_member_left` / `buddynext_space_member_invited` / `buddynext_space_member_removed`
- `buddynext_space_user_banned` / `buddynext_space_user_unbanned` / `buddynext_space_member_unbanned`
- `buddynext_member_removed_from_space`

**Filters:**

- `buddynext_can_join_space` - gate for joining (used for gated/paid spaces)
- `buddynext_space_tabs` - extend the tabs shown on a space page

**Capabilities Required:**

- `buddynext-spaces/create` (member role)
- `buddynext-spaces/join` (member role)
- `buddynext-spaces/join-gated` (explicit grant required)
- `buddynext-spaces/post` (member role)
- `buddynext-spaces/moderate` (moderator role)
- `buddynext-spaces/manage-settings` (moderator role)
- `buddynext-spaces/delete` (moderator role)
- `buddynext-moderate-space` (space-scoped, space_members role check)
- `buddynext-manage-space` (space-scoped, space_members role check)

---

## 4. Profile

**Services:** `ProfileService`, `AvatarService`, `MemberDirectoryService`

**Key Tables:** `bn_profile_fields`, `bn_profile_groups`, `bn_profile_values`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/users/{id}/profile` | Public | Get profile |
| PUT | `/buddynext/v1/users/{id}/profile` | Required | Update profile |
| POST | `/buddynext/v1/users/{id}/avatar` | Required | Upload avatar |
| POST | `/buddynext/v1/users/{id}/cover` | Required | Upload cover photo |
| POST | `/buddynext/v1/me/avatar` | Required | Upload own avatar |
| POST | `/buddynext/v1/me/cover` | Required | Upload own cover |
| GET | `/buddynext/v1/me/profile` | Required | Get own profile |
| GET | `/buddynext/v1/me/profile-slug` | Required | Get own slug |
| GET | `/buddynext/v1/profile-slug/check` | Required | Check slug availability |
| GET | `/buddynext/v1/profile-fields` | Public | List field definitions |
| GET | `/buddynext/v1/profile-groups` | Public | List field groups |
| PUT | `/buddynext/v1/profile-groups/{id}` | Required | Update group (admin) |
| POST | `/buddynext/v1/profile-groups/{id}/reorder` | Required | Reorder group (admin) |
| PUT | `/buddynext/v1/profile-fields/{id}` | Required | Update field (admin) |
| POST | `/buddynext/v1/profile-fields/{id}/reorder` | Required | Reorder field (admin) |

**Hooks Fired:**

- `buddynext_profile_viewed` / `buddynext_profile_completion_changed`

**Filters:**

- `buddynext_profile_extra_data` - append extra data to profile REST response
- `buddynext_profile_field_types` - extend available profile field types
- `buddynext_avatar_url` - override avatar URL resolution

**Capabilities Required:**

- `buddynext-profile/edit-own` (member role)
- `buddynext-profile/edit-any` (admin role)
- `buddynext-profile/view` (no role default - public)

**Avatar System:** BuddyNext overrides WordPress's `pre_get_avatar_data` filter. Priority order: (1) `bn_avatar` usermeta URL, (2) generated initials SVG (deterministic color by user_id mod 8), (3) WP/Gravatar fallback.

---

## 5. Search

**Services:** `SearchService`, `SearchIndexListener`

**Key Tables:** `bn_search_index` (FULLTEXT index on title+content)

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/search` | Public | Full-text search |
| POST | `/buddynext/v1/search/index/{type}` | Required | Trigger reindex (admin) |

**Hooks:**

- `buddynext_index_user` / `buddynext_jetonomy_post_indexed` / `buddynext_reindex_complete`
- `buddynext_search_results` (filter)

**Background indexing:** Uses Action Scheduler when available; falls back to synchronous inline indexing. Schedules `buddynext_reindex_all_cron` (single event, 30s delay) at activation if Action Scheduler absent.

---

## 6. Hashtags

**Services:** `HashtagService`, `HashtagListener`

**Key Tables:** `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/hashtags` | Public | List hashtags |
| GET | `/buddynext/v1/hashtags/trending` | Public | Trending hashtags |
| GET | `/buddynext/v1/hashtags/{slug}` | Public | Hashtag detail + recent posts |
| POST | `/buddynext/v1/hashtags/{slug}/follow` | Required | Follow/unfollow hashtag |

**Hooks:**

- `buddynext_hashtag_related_discussions` (filter) - bridge hook for Jetonomy discussions on hashtag page

**Cron:** `buddynext_trending_hashtags` runs every 30 minutes to refresh trending scores.

---

## 7. Notifications

**Services:** `NotificationService`, `NotificationPrefService`, `EmailSender`, `EmailDispatchListener`, `NotificationListener`

**Key Tables:** `bn_notifications`, `bn_notification_prefs`, `bn_email_log`, `bn_email_templates`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/notifications` | Required | List notifications |
| GET | `/buddynext/v1/notifications/unread-count` | Required | Unread count |
| POST | `/buddynext/v1/notifications/mark-read` | Required | Mark read |
| DELETE | `/buddynext/v1/notifications/{id}` | Required | Delete notification |
| GET | `/buddynext/v1/notification-prefs` | Required | Get preferences |
| PUT | `/buddynext/v1/notification-prefs` | Required | Update preferences |

**Hooks Fired:**

- `buddynext_notification_created`

**Filters:**

- `buddynext_notification_should_send` - gate for skipping notification delivery
- `buddynext_notification_send_at` - schedule delivery time
- `buddynext_email_payload` - modify email before send

**Email Templates:** 22 system templates seeded at activation (welcome, email_verify, digest, all event types).

**Cron:** Daily digest at `daily`, weekly digest at `weekly`.

---

## 8. Moderation

**Services:** `ModerationService`, `ModerationLogService`, `SafeguardService`, `ModerationListener`

**Key Tables:** `bn_reports`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_mod_log`, `bn_space_bans`

**REST Endpoints:** (22 total in ModerationController)

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/buddynext/v1/reports` | Required | Submit report |
| GET | `/buddynext/v1/reports/queue` | Required | Moderation queue |
| POST | `/buddynext/v1/reports/{id}/dismiss` | Required | Dismiss report |
| PUT | `/buddynext/v1/reports/{id}/escalate` | Required | Escalate report |
| PUT | `/buddynext/v1/reports/{id}/resolve` | Required | Resolve report |
| GET | `/buddynext/v1/users/{id}/strikes` | Required | List strikes |
| POST | `/buddynext/v1/users/{id}/strikes/{sid}/reverse` | Required | Reverse strike |
| POST | `/buddynext/v1/users/{id}/suspend` | Required | Suspend user |
| DELETE | `/buddynext/v1/users/{id}/suspend` | Required | Lift suspension |
| GET | `/buddynext/v1/users/{id}/suspension` | Required | Suspension details |
| POST | `/buddynext/v1/appeals` | Required | Submit appeal |
| GET | `/buddynext/v1/appeals` | Required | List appeals (moderator) |
| POST | `/buddynext/v1/appeals/{id}/resolve` | Required | Resolve appeal |
| PUT | `/buddynext/v1/appeals/{id}/approve` | Required | Approve appeal |
| PUT | `/buddynext/v1/appeals/{id}/deny` | Required | Deny appeal |
| POST | `/buddynext/v1/users/{id}/warn` | Required | Issue warning |
| POST | `/buddynext/v1/users/{id}/shadow-ban` | Required | Shadow-ban user |
| POST | `/buddynext/v1/me/appeals` | Required | Submit own appeal |
| GET | `/buddynext/v1/spaces/{id}/bans` | Required | List space bans |
| DELETE | `/buddynext/v1/spaces/{id}/bans/{user_id}` | Required | Remove space ban |
| GET | `/buddynext/v1/posts/{id}/content-warning` | Public | Get content warning |

**Hooks Fired:**

- `buddynext_report_created` / `buddynext_content_removed`
- `buddynext_strike_issued` / `buddynext_user_suspended` / `buddynext_member_suspended`
- `buddynext_user_warned` / `buddynext_user_shadow_banned` / `buddynext_user_shadow_ban_removed`
- `buddynext_appeal_submitted` / `buddynext_appeal_resolved`
- `buddynext_user_unsuspended` / `buddynext_member_unsuspended`

**Filters:**

- `buddynext_safeguard_check` - pre-flight safety gate on all content creation
- `buddynext_moderation_auto_actions` - configure automatic actions on report thresholds

**Capabilities Required:**

- `buddynext-moderation/report` (member role)
- `buddynext-moderation/review-queue` (moderator role)
- `buddynext-moderation/issue-strike` (moderator role)
- `buddynext-moderation/suspend-user` (admin role)

---

## 9. Member Types

**Services:** `MemberTypeService`

**Key Tables:** `bn_member_types`, `bn_member_type_assignments`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/member-types` | Public | List member types |
| POST | `/buddynext/v1/member-types` | Required | Create (admin) |
| GET | `/buddynext/v1/member-types/{id}` | Public | Get member type |
| PUT | `/buddynext/v1/member-types/{id}` | Required | Update (admin) |
| DELETE | `/buddynext/v1/member-types/{id}` | Required | Delete (admin) |
| PUT | `/buddynext/v1/users/{id}/member-type` | Required | Assign to user (admin) |
| DELETE | `/buddynext/v1/users/{id}/member-type` | Required | Remove from user (admin) |

---

## 10. Auth / Verification

**Services:** `VerificationService`, `VerificationListener`

**Key Tables:** `bn_verify_tokens`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/buddynext/v1/pwa/register-push` | Required | Register push subscription |
| POST | `/buddynext/v1/pwa/unregister-push` | Required | Remove push subscription |

**Hooks Fired:**

- `buddynext_email_verified` / `buddynext_user_verified` / `buddynext_send_verification_email`

---

## 11. Onboarding

**Services:** `OnboardingService`, `InviteService`, `SetupWizard`, `OnboardingListener`

**Key Tables:** `bn_invites`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/buddynext/v1/invites` | Required | Send community invite |

**AJAX:**

- `bn_check_slug` - profile slug uniqueness check

**Hooks Fired:**

- `buddynext_onboarding_completed` / `buddynext_setup_complete`

**Cron:** `bn_onboarding_nudge_24h` and `bn_onboarding_nudge_72h` (single events, scheduled per new user).

---

## 12. Outbound Webhooks

**Services:** `OutboundWebhookService`, `OutboundWebhookListener`

**Key Tables:** `bn_outbound_webhooks`, `bn_outbound_webhook_log`, `bn_webhook_log`

**REST Endpoints:**

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/buddynext/v1/webhooks` | Required | List webhooks (admin) |
| POST | `/buddynext/v1/webhooks` | Required | Create webhook (admin) |
| PUT | `/buddynext/v1/webhooks/{id}` | Required | Update webhook (admin) |
| DELETE | `/buddynext/v1/webhooks/{id}` | Required | Delete webhook (admin) |
| POST | `/buddynext/v1/webhooks/access` | Public | Inbound access-check (gated content) |

**Filters:**

- `buddynext_outbound_webhook_limit` - max webhooks per site

**Cron:** `buddynext_webhook_retry` every 5 minutes.

---

## 13. PWA

**Services:** `PwaService`

**REST Endpoints:** `/buddynext/v1/pwa/register-push` and `/buddynext/v1/pwa/unregister-push`

Provides PWA manifest JSON and Service Worker registration. Push subscription storage for future push notifications.

---

## 14. Admin

**Admin Pages (8):**

| Slug | Title | Capability | File |
|------|-------|------------|------|
| `buddynext` | BuddyNext (top-level menu) | `manage_options` | Admin/Settings.php |
| `buddynext` (submenu) | Settings | `manage_options` | Admin/Settings.php |
| `buddynext-members` | Members | `edit_users` | Admin/Members.php |
| `buddynext-spaces` | Spaces | `manage_options` | Admin/Spaces.php |
| `buddynext-nav` | Navigation | `manage_options` | Admin/NavManager.php |
| `buddynext-hub` | Integration Hub | `manage_options` | Admin/IntegrationHub.php |
| `buddynext-email` | Email Editor | `manage_options` | Admin/EmailEditor.php |
| `buddynext-setup` | Setup Wizard | `manage_options` | Onboarding/SetupWizard.php |

**Custom WP Nav Meta Box:** "BuddyNext Pages" in Appearance > Menus lists Feed, Explore, Members, Spaces, Notifications, Messages, Search, Leaderboard plus conditional Jetonomy/WPMediaVerse pages.

---

## 15. Blocks

17 Gutenberg blocks registered under `blocks/` via `BlockRegistrar::init()`:

| Block Name | Purpose |
|------------|---------|
| `bn-activity-feed` | Social feed embed |
| `bn-connection-button` | Connect/disconnect UI |
| `bn-follow-button` | Follow/unfollow UI |
| `bn-login-form` | Community login form |
| `bn-member-card` | Single member card |
| `bn-member-directory` | Paginated member directory |
| `bn-my-spaces` | Current user's spaces list |
| `bn-notification-bell` | Notification bell with badge |
| `bn-post-composer` | Post composer embed |
| `bn-profile-completion-bar` | Profile completeness bar |
| `bn-profile-fields` | Profile fields display |
| `bn-profile-header` | Full profile header |
| `bn-registration-form` | Community registration form |
| `bn-search-bar` | Community search bar |
| `bn-space-card` | Single space card |
| `bn-space-directory` | Paginated space directory |
| `bn-trending-hashtags` | Trending hashtags widget |

---

## 16. Shortcodes

| Tag | Purpose |
|-----|---------|
| `[buddynext_activity]` | Social activity feed |
| `[buddynext_people]` | Member directory |
| `[buddynext_spaces]` | Space directory |
| `[buddynext_messages]` | Messages UI |
| `[buddynext_notifications]` | Notifications list |
| `[buddynext_auth]` | Login/registration forms |
| `[buddynext_community_admin]` | Front-end community admin |

---

## 17. Bridges

Bridges load at `plugins_loaded:25` (after Free:15 and Pro:20) via `buddynext_load_bridges` action.

| Bridge | Paired Plugin | What It Wires |
|--------|---------------|---------------|
| `BuddyXBridge` | BuddyX theme | Avatar, nav, template overrides |
| `WPMediaVerseBridge` | WPMediaVerse | Media pages in BuddyNext nav |
| `GamificationBridge` | Gamification plugin | Fires `wb_gamification_event` for community actions |
| `GamificationBridgeListener` | Gamification plugin | Listens for badge/level-up events, creates BuddyNext notifications |
| `JetonomyBridge` | Jetonomy | Discussions tab in BuddyNext nav and context |
| `JetonomyBridgeListener` | Jetonomy | Indexes Jetonomy posts into BuddyNext search |
| `CareerBoardBridge` | Career Board | Career Board nav item |

All bridges use `class_exists()` guards at hook time and are no-ops when the companion plugin is inactive.

---

## 18. Gamification

**Services:** `CounterService` (credits)

**Key Storage:** `bn_credits` user_meta (integer balance per user)

Credit balance tracked via `wp_usermeta` under the `bn_credits` key (see `RoleService::CREDITS_META`). Fires `buddynext_credits_spent` on debit. Bridges `wb_gamification_event` for badge/level actions.

---

## 19. Realtime

**Services:** `TransportFactory` (bound as `realtime`)

Transport abstraction: polling fallback or WebSocket when configured. Extensible via `buddynext_realtime_transport` filter for third-party WebSocket providers.

---

## 20. Core Infrastructure

### Permission Model (4 layers)

1. WP `manage_options` - site admin bypass
2. Community role hierarchy (`owner > admin > moderator > member`)
3. Explicit grants as `bn_ability_{slug}` user_meta entries (with optional expiry)
4. Developer filter `buddynext_user_can` - can override in either direction

### Container (DI)

`BuddyNext\Core\Container::instance()` - 48 bindings registered at `plugins_loaded:15`. Global helper: `buddynext_service($key)`.

### Assets

`AssetService::init()` - registers all frontend JS/CSS. JS uses store-per-domain pattern (15 store files). 16 CSS files organized by domain.

### Cron Schedules Added

| Interval | Key |
|----------|-----|
| 1 minute | `buddynext_1min` |
| 5 minutes | `buddynext_5min` |
| 30 minutes | `buddynext_30min` |

### Template System

`TemplateLoader` - supports theme overrides. Fires `buddynext_before_template` / `buddynext_after_template`.

### Theme Tokens

`TokenService` - emits CSS custom properties on `wp_head` for BuddyNext color tokens. Extensible via `buddynext_css_vars` and `buddynext_css_vars_dark` filters.

### Page Routing

`PageRouter` - URL rewrite rules for community pages (Feed, Explore, Members, Spaces, Notifications, Messages, Search, Leaderboard).
