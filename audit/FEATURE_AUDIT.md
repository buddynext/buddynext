# BuddyNext Free - Feature Audit

**Generated:** 2026-06-07 | **Version:** 0.2.0 | **Branch:** master

This document is machine-generated from ground-truth grep enumeration in `audit/manifest.json` (135 REST endpoints, 548 plugin-own hooks fired, 55 services, 21 Abilities-API capabilities, 39 tables, 7 admin pages, 17 blocks, 7 shortcodes). Refresh via the wp-plugin-onboard skill after non-trivial changes.

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
18. [Engagement / Realtime](#18-engagement--realtime)
19. [Theme / Tokens](#19-theme--tokens)
20. [Core Infrastructure](#20-core-infrastructure)

---

## 1. Feed

**Services:** `Feed\PostService` (post_service), `Feed\FeedService` (feed), `Feed\FeedCache` (feed_cache), `Feed\PollService` (polls), `Feed\BookmarkService` (bookmarks), `Feed\ShareService` (shares), `Reactions\ReactionService` (reactions), `Comments\CommentService` (comments)

**Key Tables:** `bn_posts`, `bn_feed_items`, `bn_poll_options`, `bn_poll_votes`, `bn_bookmarks`, `bn_shares`, `bn_reactions`, `bn_comments`

**REST Endpoints** (controllers: PostController 4, FeedController 9, PollController 3, BookmarkController 2, ShareController 2, ComposerDraftController 1, ReactionController 3, CommentController 3):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| POST | `/posts` | require_auth | PostController::create_post |
| GET/PUT/DELETE | `/posts/{id}` | public/require_auth | PostController::get_post\|update_post\|delete_post |
| POST/DELETE | `/posts/{id}/pin` | require_auth | PostController::pin_post\|unpin_post |
| PUT | `/posts/{id}/content-warning` | require_auth | PostController::update_content_warning |
| GET | `/feed/home` | require_auth | FeedController::home_feed |
| GET | `/feed/home/page` | require_auth | FeedController::home_feed_page |
| GET | `/feed/counts` | require_auth | FeedController::feed_counts |
| GET | `/feed/new-count` | require_auth | FeedController::feed_new_count |
| GET | `/feed/explore` | public | FeedController::explore_feed |
| GET | `/feed/explore/page` | public | FeedController::explore_feed_page |
| GET | `/users/{id}/feed` | public | FeedController::profile_feed |
| GET | `/spaces/{id}/feed` | public | FeedController::get_space_feed |
| POST | `/feed/announcements/{id}/dismiss` | require_auth | FeedController::dismiss_announcement |
| POST | `/posts/{id}/poll` | require_auth | PollController::vote |
| GET | `/posts/{id}/vote` | require_auth | PollController::my_vote |
| GET | `/posts/{id}/my-vote` | require_auth | PollController::my_vote |
| POST/DELETE | `/posts/{id}/bookmark` | require_auth | BookmarkController::bookmark\|unbookmark |
| GET | `/me/bookmarks` | require_auth | BookmarkController::get_bookmarks |
| POST | `/posts/{id}/share` | require_auth | ShareController::share |
| GET | `/me/shares` | require_auth | ShareController::get_shares |
| POST/GET | `/me/drafts` | require_auth | ComposerDraftController::save\|read |
| POST | `/reactions/toggle` | require_auth | ReactionController::toggle |
| GET | `/reactions` | public | ReactionController::get_count |
| GET | `/reactions/list` | public | ReactionController::list_reactors |
| POST/GET | `/comments` | require_auth/public | CommentController::create\|list_comments |
| PUT/DELETE | `/comments/{id}` | require_auth | CommentController::update\|delete |
| POST/DELETE | `/comments/{id}/pin` | require_moderator | CommentController::pin\|unpin |

**Hooks Fired:**

- `buddynext_post_created` (action, args:3) - includes/Feed/PostService.php:198 (also fired on scheduled-publish from includes/Core/CronService.php:316)
- `buddynext_post_deleted` (action, args:2) - includes/Feed/PostService.php:375
- `buddynext_post_impression` (action, args:3) - includes/Feed/FeedService.php:305
- `buddynext_post_bookmarked` (action, args:2) - includes/Feed/BookmarkService.php:66
- `buddynext_post_unbookmarked` (action, args:2) - includes/Feed/BookmarkService.php:103
- `buddynext_post_shared` (action, args:3) - includes/Feed/ShareService.php:114
- `buddynext_poll_voted` (action, args:3) - includes/Feed/PollService.php:127
- `buddynext_reaction_added` (action, args:4) - includes/Reactions/ReactionService.php:81
- `buddynext_reaction_removed` (action, args:3) - includes/Reactions/ReactionService.php:163
- `buddynext_post_reaction_received` (action, args:4) - includes/Reactions/ReactionService.php:105
- `buddynext_comment_created` (action, args:4) - includes/Comments/CommentService.php:103
- `buddynext_comment_updated` (action, args:2) - includes/Comments/CommentService.php:228
- `buddynext_comment_deleted` (action, args:2) - includes/Comments/CommentService.php:284
- `buddynext_post_comment_received` (action, args:4) - includes/Comments/CommentService.php:126

**Filters:**

- `buddynext_feed_order_by` (args:4) - includes/Feed/FeedService.php:249
- `buddynext_post_pin_limit` (args:3) - includes/Feed/PostService.php:406
- `buddynext_reaction_types` (args:1) - includes/Reactions/ReactionService.php:201
- `buddynext_safeguard_check` (args:4) - includes/Moderation/SafeguardService.php:75 (pre-write safety gate, invoked from PostService::create)

**Capabilities Required:**

- `buddynext-feed/create-post` (member) - includes/Core/Abilities.php:28
- `buddynext-feed/delete-own-post` (member) - includes/Core/Abilities.php:29
- `buddynext-feed/delete-any-post` (moderator) - includes/Core/Abilities.php:30
- `buddynext-feed/pin-post` (moderator) - includes/Core/Abilities.php:31
- `buddynext-feed/schedule-post` (member) - includes/Core/Abilities.php:32

**Cron:** `buddynext_publish_scheduled` (buddynext_1min, PostService) publishes scheduled posts; `buddynext_recount_stats` (buddynext_5min, CounterService) recounts denormalised counters.

---

## 2. Social Graph (Follow / Connection / Block)

**Services:** `SocialGraph\FollowService` (follows), `SocialGraph\ConnectionService` (connections), `SocialGraph\BlockService` (blocks), `SocialGraph\PrivacyService` (privacy)

**Key Tables:** `bn_follows`, `bn_connections`, `bn_blocks`

**REST Endpoints** (FollowController 7, ConnectionController 5, BlockController 6):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| POST | `/users/{id}/follow` | require_auth | FollowController::toggle_follow |
| GET | `/users/{id}/followers` | public | FollowController::get_followers |
| GET | `/users/{id}/following` | public | FollowController::get_following |
| GET | `/follow-suggestions` | require_auth | FollowController::get_suggestions |
| GET | `/me/follow-requests` | require_auth | FollowController::get_follow_requests |
| POST | `/me/follow-requests/{follower_id}/approve` | require_auth | FollowController::approve_request |
| POST | `/me/follow-requests/{follower_id}/reject` | require_auth | FollowController::reject_request |
| POST | `/users/{id}/connect` | require_auth | ConnectionController::request_connection |
| POST | `/users/{id}/connect/accept` | require_auth | ConnectionController::accept_request |
| POST | `/users/{id}/connect/decline` | require_auth | ConnectionController::decline_request |
| GET | `/me/connections` | require_auth | ConnectionController::get_connections |
| GET | `/me/connection-requests` | require_auth | ConnectionController::get_requests |
| POST | `/users/{id}/block` | require_auth | BlockController::toggle_block |
| POST | `/users/{id}/mute` | require_auth | BlockController::toggle_mute |
| POST | `/users/{id}/restrict` | require_auth | BlockController::toggle_restrict |
| GET | `/me/blocked` | require_auth | BlockController::get_blocked |
| GET | `/me/muted` | require_auth | BlockController::get_muted |
| GET | `/me/restricted` | require_auth | BlockController::get_restricted |

**Hooks Fired:**

- `buddynext_user_followed` (action, args:2) - includes/SocialGraph/FollowService.php:143
- `buddynext_user_unfollowed` (action, args:2) - includes/SocialGraph/FollowService.php:215
- `buddynext_user_followed_first_time` (action, args:2) - includes/SocialGraph/FollowService.php:178
- `buddynext_follower_gained` (action, args:2) - includes/SocialGraph/FollowService.php:155
- `buddynext_follow_requested` (action, args:2) - includes/SocialGraph/FollowService.php:131
- `buddynext_follow_request_approved` (action, args:2) - includes/SocialGraph/FollowService.php:571
- `buddynext_follow_request_rejected` (action, args:2) - includes/SocialGraph/FollowService.php:611
- `buddynext_connection_requested` (action, args:4) - includes/SocialGraph/ConnectionService.php:123
- `buddynext_connection_accepted` (action, args:3) - includes/SocialGraph/ConnectionService.php:178
- `buddynext_connection_declined` (action, args:3) - includes/SocialGraph/ConnectionService.php:229
- `buddynext_connection_withdrawn` (action, args:3) - includes/SocialGraph/ConnectionService.php:278
- `buddynext_connection_removed` (action, args:2) - includes/SocialGraph/ConnectionService.php:325
- `buddynext_block` (action, args:2) - includes/SocialGraph/BlockService.php:76
- `buddynext_unblock` (action, args:2) - includes/SocialGraph/BlockService.php:110
- `buddynext_user_restricted` (action, args:2) - includes/SocialGraph/BlockService.php:214
- `buddynext_user_unrestricted` (action, args:2) - includes/SocialGraph/BlockService.php:247

**Capabilities Required:**

- `buddynext-connections/follow` (member) - includes/Core/Abilities.php:40
- `buddynext-connections/connect` (member) - includes/Core/Abilities.php:41

---

## 3. Spaces

**Services:** `Spaces\SpaceService` (spaces), `Spaces\SpaceMemberService` (space_members)

**Key Tables:** `bn_spaces`, `bn_space_members`, `bn_space_categories`, `bn_space_bans`

**REST Endpoints** (SpaceController 18, SpaceCategoryController 2):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET/POST | `/spaces` | public/require_auth | SpaceController::list_spaces\|create_space |
| GET/PUT/DELETE | `/spaces/{id}` | public/require_auth | SpaceController::get_space\|update_space\|delete_space |
| GET | `/spaces/{id}/members` | public | SpaceController::get_space_members |
| GET | `/spaces/{id}/pending-requests` | require_auth | SpaceController::get_pending_requests |
| POST/DELETE | `/spaces/{id}/join` | require_auth | SpaceController::join_space\|leave_space |
| POST | `/spaces/{id}/leave` | require_auth | SpaceController::leave_space |
| POST | `/spaces/{id}/invite` | require_auth | SpaceController::invite_member |
| POST | `/spaces/{id}/members/{user_id}/approve` | require_auth | SpaceController::approve_request |
| POST | `/spaces/{id}/members/{user_id}/decline` | require_auth | SpaceController::decline_request |
| POST | `/spaces/{id}/approve-request` | require_auth | SpaceController::approve_request (legacy) |
| POST | `/spaces/{id}/ban` | require_auth | SpaceController::ban_member (legacy) |
| POST/DELETE | `/spaces/{id}/ban/{user_id}` | require_auth | SpaceController::ban_user\|unban_user |
| PUT | `/spaces/{id}/members/{user_id}/role` | require_auth | SpaceController::change_member_role |
| DELETE | `/spaces/{id}/members/{user_id}` | require_auth | SpaceController::remove_member |
| POST | `/spaces/{id}/transfer-ownership` | require_auth | SpaceController::transfer_ownership |
| POST | `/spaces/{id}/transfer` | require_auth | SpaceController::transfer_ownership (alias) |
| GET/POST | `/spaces/{id}/notification-pref` | require_auth | SpaceController::get_notification_pref\|set_notification_pref |
| PUT | `/spaces/{id}/permissions` | require_auth | SpaceController::update_permissions |
| GET/POST | `/space-categories` | public/require_manage_options | SpaceCategoryController::list_categories\|create_category |
| DELETE | `/space-categories/{id}` | require_manage_options | SpaceCategoryController::delete_category |

**Hooks Fired:**

- `buddynext_space_created` (action, args:2) - includes/Spaces/SpaceService.php:205
- `buddynext_space_updated` (action, args:3) - includes/Spaces/SpaceService.php:342
- `buddynext_space_deleted` (action, args:2) - includes/Spaces/SpaceService.php:396
- `buddynext_space_member_joined` (action, args:3) - includes/Spaces/SpaceMemberService.php:145
- `buddynext_space_member_left` (action, args:2) - includes/Spaces/SpaceMemberService.php:568
- `buddynext_space_member_invited` (action, args:3) - includes/Spaces/SpaceMemberService.php:293
- `buddynext_space_member_removed` (action, args:3) - includes/Spaces/SpaceController.php:1222
- `buddynext_member_removed_from_space` (action, args:3) - includes/Spaces/SpaceMemberService.php:836
- `buddynext_space_join_requested` (action, args:2) - includes/Spaces/SpaceMemberService.php:223
- `buddynext_space_join_approved` (action, args:3) - includes/Spaces/SpaceMemberService.php:354
- `buddynext_space_join_declined` (action, args:3) - includes/Spaces/SpaceMemberService.php:422
- `buddynext_space_join_request_cancelled` (action, args:2) - includes/Spaces/SpaceMemberService.php:708
- `buddynext_space_user_banned` (action, args:4) - includes/Spaces/SpaceController.php:1069
- `buddynext_space_user_unbanned` (action, args:3) - includes/Spaces/SpaceController.php:1136
- `buddynext_space_member_unbanned` (action, args:3) - includes/Spaces/SpaceMemberService.php:1102
- `buddynext_space_notification_pref_updated` (action, args:3) - includes/Spaces/SpaceMemberService.php:643

**Filters:**

- `buddynext_can_join_space` (args:4) - includes/Spaces/SpaceMemberService.php:77 (gate for gated/paid spaces; also checked at request_join, line 178)
- `buddynext_space_join_denied_data` (args:5) - includes/Spaces/SpaceMemberService.php:1295

**Capabilities Required:**

- `buddynext-spaces/create` (member) - includes/Core/Abilities.php:33
- `buddynext-spaces/join` (member, context:space_id) - includes/Core/Abilities.php:34
- `buddynext-spaces/join-gated` (explicit grant only, context:space_id) - includes/Core/Abilities.php:35
- `buddynext-spaces/post` (member, context:space_id) - includes/Core/Abilities.php:36
- `buddynext-spaces/moderate` (moderator, context:space_id) - includes/Core/Abilities.php:37; also enforced at PermissionService.php:169
- `buddynext-spaces/manage-settings` (moderator, context:space_id) - includes/Core/Abilities.php:38
- `buddynext-spaces/delete` (moderator) - includes/Core/Abilities.php:39
- `buddynext-moderate-space` (space-scoped, resolved from bn_space_members role) - PermissionService::can_moderate_space
- `buddynext-manage-space` (space-scoped, owner only) - PermissionService::can_manage_space

> Space-banned users are hard-denied all `buddynext-spaces/*` capabilities at PermissionService.php:89 before any role check.

---

## 4. Profile

**Services:** `Profile\ProfileService` (profiles), `Profile\AvatarService` (avatars), `Profile\MemberDirectoryService` (member_directory)

**Key Tables:** `bn_profile_fields`, `bn_profile_groups`, `bn_profile_values`

**REST Endpoints** (ProfileController 15, MemberDirectoryController 1, SlugCheckController 1):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET | `/users/{id}/profile` | public | ProfileController::get_profile |
| PUT | `/users/{id}/profile` | require_admin | ProfileController::admin_update_profile |
| POST | `/users/{id}/avatar` | require_admin | ProfileController::admin_upload_avatar |
| POST/DELETE | `/users/{id}/cover` | require_admin | ProfileController::admin_upload_cover\|admin_delete_cover |
| POST/DELETE | `/me/avatar` | require_auth | ProfileController::upload_avatar\|delete_avatar |
| POST/DELETE | `/me/cover` | require_auth | ProfileController::upload_cover\|delete_cover |
| GET/PUT | `/me/profile` | require_auth | ProfileController::get_own_profile\|update_profile |
| GET/PUT | `/me/profile-slug` | require_auth | ProfileController::get_profile_slug\|update_profile_slug |
| GET | `/profile-slug/check` | require_auth | ProfileController::check_slug_availability |
| GET/POST | `/profile-fields` | public/require_admin | ProfileController::list_fields\|create_field |
| PUT/DELETE | `/profile-fields/{id}` | require_admin | ProfileController::update_field\|delete_field |
| POST | `/profile-fields/{id}/reorder` | require_admin | ProfileController::reorder_field |
| GET/POST | `/profile-groups` | public/require_admin | ProfileController::list_groups\|create_group |
| PUT/DELETE | `/profile-groups/{id}` | require_admin | ProfileController::update_group\|delete_group |
| POST | `/profile-groups/{id}/reorder` | require_admin | ProfileController::reorder_group |
| GET | `/members` | public | MemberDirectoryController::list_members |
| GET | `/admin/slug-check` | permission_check | SlugCheckController::check_slug |

**Hooks Fired:**

- `buddynext_profile_viewed` (action, args:2) - includes/Profile/ProfileController.php:492
- `buddynext_profile_completion_changed` (action, args:2) - includes/Profile/ProfileService.php:805
- `buddynext_index_user` (action, args:1) - includes/Profile/ProfileController.php:646

**Filters:**

- `buddynext_avatar_url` (args:2) - includes/Profile/AvatarService.php:99
- `buddynext_field_types` (args:1) - includes/Profile/FieldType.php:156
- `buddynext_field_render_input` (args:4) - includes/Profile/FieldType.php:278
- `buddynext_field_render_display` (args:3) - includes/Profile/FieldType.php:503
- `buddynext_field_sanitize` (args:3) - includes/Profile/FieldType.php:654
- `buddynext_profile_labels` (args:2) - includes/Profile/ProfileService.php:743

**Capabilities Required:**

- `buddynext-profile/edit-own` (member) - includes/Core/Abilities.php:25
- `buddynext-profile/edit-any` (admin) - includes/Core/Abilities.php:26
- `buddynext-profile/view` (null - public by default) - includes/Core/Abilities.php:27

**Avatar System:** `AvatarService` resolves avatars via the `buddynext_avatar_url` filter and overrides WordPress avatar resolution. Order: stored `bn_avatar`, generated initials SVG, then WP/Gravatar fallback.

**AJAX:** `bn_check_slug` (nonce `bn_slug_nonce`, cap `is_user_logged_in`) - profile-slug uniqueness check.

---

## 5. Search

**Services:** `Search\SearchService` (search), `Search\SearchIndexListener` (search_index_listener)

**Key Tables:** `bn_search_index` (FULLTEXT key on title+content)

**REST Endpoints** (SearchController 2):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET | `/search` | public | SearchController::search |
| GET | `/search/members` | public | SearchController::list_members |

**Hooks Fired:**

- `buddynext_reindex_complete` (action, args:0) - includes/Search/SearchIndexListener.php:341

**Filters:**

- `buddynext_search_results` (args:5) - includes/Search/SearchService.php:350

**Background indexing:** Uses Action Scheduler when available, falling back to inline indexing. `buddynext_reindex_all_cron` (single event) runs a one-time full reindex after activation when Action Scheduler is absent.

---

## 6. Hashtags

**Services:** `Hashtags\HashtagService` (hashtags), plus `Hashtags\HashtagListener` (event indexer)

**Key Tables:** `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`

**REST Endpoints** (HashtagController 4):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET | `/hashtags/{slug}` | public | HashtagController::get_hashtag |
| POST | `/hashtags/{slug}/follow` | require_auth | HashtagController::toggle_follow |
| GET | `/hashtags/autocomplete` | public | HashtagController::autocomplete |
| GET | `/hashtags/trending` | public | HashtagController::trending |

**Hooks Fired:**

- `buddynext_hashtag_used` (action, args:3) - includes/Hashtags/HashtagListener.php:152

**Cron:** `buddynext_trending_hashtags` (buddynext_30min, HashtagService) refreshes trending scores every 30 minutes.

---

## 7. Notifications

**Services:** `Notifications\NotificationService` (notifications), `Notifications\NotificationPrefService` (notification_prefs), `Notifications\NotificationMessageService` (notification_message), `Notifications\NotificationPrefCatalogue` (notification_pref_catalogue), `Notifications\EmailSender` (email_sender)

**Key Tables:** `bn_notifications`, `bn_notification_prefs`, `bn_email_log`, `bn_email_templates`

**REST Endpoints** (NotificationController 8):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET | `/me/notifications` | require_auth | NotificationController::list_notifications |
| PUT | `/me/notifications/{id}` | require_auth | NotificationController::update_notification |
| POST | `/me/notifications/{id}/read` | require_auth | NotificationController::mark_read |
| POST | `/me/notifications/read-all` | require_auth | NotificationController::mark_all_read |
| GET | `/me/notifications/unread-count` | require_auth | NotificationController::get_unread_count |
| GET | `/me/notification-channels` | require_auth | NotificationController::list_channels |
| GET/PUT | `/me/notification-prefs` | require_auth | NotificationController::get_prefs\|update_prefs |
| GET | `/me/space-notification-prefs` | require_auth | NotificationController::get_space_prefs |

**Hooks Fired:**

- `buddynext_notification_created` (action, args:3) - includes/Notifications/NotificationService.php:168 (new) and :89 (dedupe path)
- `buddynext_queue_email_digest` (action, args:3) - includes/Notifications/EmailSender.php:70

**Filters:**

- `buddynext_notification_should_send` (args:2) - includes/Notifications/NotificationService.php:107
- `buddynext_notification_send_at` (args:2) - includes/Notifications/NotificationService.php:129
- `buddynext_notification_prefs` (args:2) - includes/Notifications/NotificationPrefService.php:167
- `buddynext_notification_prefs_catalogue` (args:1) - includes/Notifications/NotificationPrefCatalogue.php:367
- `buddynext_notification_message` (args:5) - includes/Notifications/NotificationMessageService.php:366
- `buddynext_email_payload` (args:3) - includes/Notifications/EmailSender.php:158

**Cron:** `buddynext_daily_digest` (daily) and `buddynext_weekly_digest` (weekly) via EmailDispatchListener; `buddynext_cleanup_notifications` (weekly) prunes old read notifications.

---

## 8. Moderation

**Services:** `Moderation\ModerationService` (moderation), `Moderation\ModerationLogService` (mod_log), `Moderation\SafeguardService` (safeguard)

**Key Tables:** `bn_reports`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_mod_log`, `bn_space_bans`, `bn_activity_log`

**REST Endpoints** (ModerationController 17):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| POST | `/reports` | require_auth | ModerationController::create_report |
| GET | `/reports/queue` | require_auth | ModerationController::get_queue |
| POST | `/reports/{id}/resolve` | require_auth | ModerationController::resolve_report |
| POST | `/reports/{id}/remove` | require_auth | ModerationController::remove_content |
| POST | `/reports/{id}/dismiss` | require_auth | ModerationController::dismiss_report |
| POST | `/reports/{id}/escalate` | require_auth | ModerationController::escalate_report |
| POST | `/users/{id}/warn` | require_auth | ModerationController::warn_user |
| GET | `/users/{id}/strikes` | require_auth | ModerationController::get_strikes |
| POST | `/users/{id}/strikes/{sid}/reverse` | require_auth | ModerationController::reverse_strike |
| POST | `/users/{id}/suspend` | require_auth | ModerationController::suspend_user |
| GET | `/users/{id}/suspension` | require_auth | ModerationController::get_suspension |
| POST | `/users/{id}/shadow-ban` | require_auth | ModerationController::toggle_shadow_ban |
| GET | `/me/appeals` | require_auth | ModerationController::get_appeals |
| POST | `/appeals` | require_auth | ModerationController::create_appeal |
| POST | `/appeals/{id}/approve` | require_auth | ModerationController::approve_appeal |
| POST | `/appeals/{id}/deny` | require_auth | ModerationController::deny_appeal |
| POST | `/appeals/{id}/resolve` | require_auth | ModerationController::resolve_appeal |

**Hooks Fired:**

- `buddynext_report_created` (action, args:4) - includes/Moderation/ModerationService.php:121
- `buddynext_content_removed` (action, args:3) - includes/Moderation/ModerationService.php:156
- `buddynext_strike_issued` (action, args:3) - includes/Moderation/ModerationService.php:324
- `buddynext_user_warned` (action, args:3) - includes/Moderation/ModerationService.php:169
- `buddynext_user_shadow_banned` (action, args:3) - includes/Moderation/ModerationService.php:715
- `buddynext_user_shadow_ban_removed` (action, args:2) - includes/Moderation/ModerationService.php:740
- `buddynext_appeal_submitted` (action, args:4) - includes/Moderation/ModerationService.php:880
- `buddynext_appeal_resolved` (action, args:3) - includes/Moderation/ModerationService.php:937
- `buddynext_user_suspended` (action, args:4) - includes/Admin/Members.php:303
- `buddynext_member_suspended` (action, args:2) - includes/Admin/Members.php:311
- `buddynext_user_unsuspended` (action, args:1) - includes/Admin/Members.php:350
- `buddynext_member_unsuspended` (action, args:1) - includes/Admin/Members.php:357

**Filters:**

- `buddynext_safeguard_check` (args:4) - includes/Moderation/SafeguardService.php:75 (pre-flight gate on all content creation)
- `buddynext_moderation_auto_actions` (args:2) - includes/Moderation/ModerationService.php:138

**Capabilities Required:**

- `buddynext-moderation/report` (member) - includes/Core/Abilities.php:42
- `buddynext-moderation/review-queue` (moderator) - includes/Core/Abilities.php:43
- `buddynext-moderation/issue-strike` (moderator) - includes/Core/Abilities.php:44
- `buddynext-moderation/suspend-user` (admin) - includes/Core/Abilities.php:45

**Cron:** `buddynext_daily_queue_check` (daily, ModerationListener) - moderation queue health check and alert email.

---

## 9. Member Types

**Services:** `MemberTypes\MemberTypeService` (member_types)

**Key Tables:** `bn_member_types`, `bn_member_type_assignments`

**REST Endpoints** (MemberTypeController 5):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET/POST | `/member-types` | public/require_admin | MemberTypeController::list_types\|create_type |
| PUT/DELETE | `/member-types/{slug}` | require_admin | MemberTypeController::update_type\|delete_type |
| GET | `/users/{id}/member-type` | public | MemberTypeController::get_user_type |
| PUT | `/users/{id}/member-type` | can_set_user_type | MemberTypeController::set_user_type |
| DELETE | `/users/{id}/member-type` | require_admin | MemberTypeController::remove_user_type |

**Hooks Fired:**

- `buddynext_member_type_created` (action, args:2) - includes/MemberTypes/MemberTypeService.php:200
- `buddynext_member_type_deleted` (action, args:2) - includes/MemberTypes/MemberTypeService.php:323
- `buddynext_member_type_assigned` (action, args:3) - includes/MemberTypes/MemberTypeService.php:448
- `buddynext_member_type_removed` (action, args:2) - includes/MemberTypes/MemberTypeService.php:302

---

## 10. Auth / Verification

**Services:** `Auth\VerificationService` (verification), plus `Auth\AuthController` and `Auth\VerificationListener`

**Key Tables:** `bn_verify_tokens`

**REST Endpoints** (AuthController 7):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| POST | `/auth/login` | public | AuthController::login |
| POST | `/auth/register` | public | AuthController::register |
| POST | `/auth/verify/resend` | require_auth | AuthController::resend_verification |
| GET | `/auth/verify/status` | require_auth | AuthController::verification_status |
| POST | `/auth/change-password` | require_auth | AuthController::change_password |
| POST | `/auth/change-email` | require_auth | AuthController::change_email |
| POST | `/auth/sign-out-everywhere` | require_auth | AuthController::sign_out_everywhere |

**Hooks Fired:**

- `buddynext_send_verification_email` (action, args:2) - includes/Auth/VerificationService.php:59
- `buddynext_user_verified` (action, args:1) - includes/Auth/VerificationService.php:126
- `buddynext_email_verified` (action, args:1) - includes/Auth/VerificationListener.php:92
- `buddynext_email_change_requested` (action, args:2) - includes/Auth/AuthController.php:269
- `buddynext_email_changed` (action, args:2) - includes/Auth/VerificationListener.php:308

**Cron:** `buddynext_cleanup_tokens` (daily, CronScheduler) purges expired verification tokens.

---

## 11. Onboarding

**Services:** `Onboarding\OnboardingService` (onboarding), `Onboarding\InviteService` (invite), `Onboarding\SetupWizard` (setup_wizard)

**Key Tables:** `bn_invites`

**REST Endpoints** (OnboardingController 3, InviteController 1):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| PUT | `/me/onboarding/step` | require_auth | OnboardingController::update_step |
| POST | `/me/onboarding/skip` | require_auth | OnboardingController::skip |
| POST | `/me/onboarding/complete` | require_auth | OnboardingController::complete |
| POST | `/invites/import-csv` | require_auth | InviteController::import_csv |

**Hooks Fired:**

- `buddynext_onboarding_completed` (action, args:1) - includes/Onboarding/OnboardingService.php:231
- `buddynext_setup_complete` (action, args:0) - includes/Onboarding/SetupWizard.php:168

**Cron:** `bn_onboarding_nudge_24h` and `bn_onboarding_nudge_72h` (single events scheduled per new user, OnboardingListener).

---

## 12. Outbound Webhooks

**Services:** `Outbound\OutboundWebhookService` (webhooks), plus `Outbound\AccessWebhookController`

**Key Tables:** `bn_outbound_webhooks`, `bn_outbound_webhook_log`, `bn_webhook_log`

**REST Endpoints** (OutboundWebhookController 4, AccessWebhookController 1):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| GET/POST | `/webhooks` | require_auth | OutboundWebhookController::list\|create |
| GET/PUT/DELETE | `/webhooks/{id}` | require_auth | OutboundWebhookController::get\|update\|delete |
| GET | `/webhooks/{id}/log` | require_auth | OutboundWebhookController::get_log |
| POST | `/webhooks/{id}/test` | require_auth | OutboundWebhookController::test |
| POST | `/webhook/access` | public | AccessWebhookController::verify_access |

**Hooks Fired:**

- `buddynext_ability_granted` (action, args:3) - includes/Outbound/AccessWebhookController.php:197
- `buddynext_ability_revoked` (action, args:2) - includes/Outbound/AccessWebhookController.php:228

**Filters:**

- `buddynext_outbound_webhook_limit` (args:1) - includes/Admin/Settings.php:1206

**Cron:** `buddynext_webhook_retry` (buddynext_5min, OutboundWebhookService) retries failed deliveries.

---

## 13. PWA

**Services:** `PWA\PwaService` (pwa)

**Filters:**

- `buddynext_pwa_register_sw` (args:1) - includes/PWA/PwaService.php:109

Provides the PWA manifest JSON and Service Worker registration. (No standalone REST routes are present for PWA in the current manifest; push registration is folded into the PWA service surface.)

---

## 14. Admin

**Admin Pages (7):**

| Slug | Title | Type | Capability | File |
|------|-------|------|------------|------|
| `buddynext` | BuddyNext (top-level menu) | menu | manage_options | includes/Admin/AdminHub.php:363 |
| `buddynext` | Settings (submenu) | submenu | manage_options | includes/Admin/Settings.php:255 |
| `buddynext-members` | Members | submenu | manage_options | includes/Admin/Members.php:105 |
| `buddynext-spaces` | Spaces | submenu | manage_options | includes/Admin/Spaces.php:79 |
| `buddynext-nav` | Navigation | submenu | manage_options | includes/Admin/NavManager.php:192 |
| `buddynext-integrations` | Integrations | submenu | manage_options | includes/Admin/IntegrationHub.php:70 |
| `buddynext-email` | Email Templates | submenu | manage_options | includes/Admin/EmailEditor.php:72 |

**Admin Services:** admin_settings, admin_members, admin_spaces, admin_nav, admin_hub (IntegrationHub), admin_email_editor.

**Hooks Fired (admin scope):**

- `bn_admin_hub_sections` (filter, args:1) - includes/Admin/AdminHub.php:186
- `buddynext_settings_tab_subtitles` (filter, args:1) - includes/Admin/Settings.php:205
- `buddynext_admin_member_profile_saved` (action, args:3) - includes/Admin/Members.php:676
- `buddynext_before_edit_member_form` (action, args:2) - includes/Admin/Members/MemberEditForm.php:167
- `buddynext_after_edit_member_form` (action, args:2) - includes/Admin/Members/MemberEditForm.php:404
- `buddynext_edit_member_sections` (action, args:2) - includes/Admin/Members/MemberEditForm.php:390
- `buddynext_profile_field_types` (filter, args:1) - includes/Admin/Members/ProfileFieldsManager.php:194
- `buddynext_profile_field_type_labels` (filter, args:1) - includes/Admin/Members/ProfileFieldsManager.php:1118
- `buddynext_profile_field_type_options` (action, args:2) - includes/Admin/Members/ProfileFieldsManager.php:1415
- `buddynext_profile_field_updated` (action, args:1) - includes/Admin/Members/ProfileFieldsManager.php:911

---

## 15. Blocks

17 Gutenberg blocks registered under `blocks/` (each `blocks/{name}/block.json`):

| Block Name | Purpose |
|------------|---------|
| `bn-activity-feed` | Embeds the social feed in any page or post |
| `bn-connection-button` | Connect/disconnect button for a user |
| `bn-follow-button` | Follow/unfollow button for a user |
| `bn-login-form` | Community login form block |
| `bn-member-card` | Single member card widget |
| `bn-member-directory` | Paginated member directory |
| `bn-my-spaces` | Current user's space list |
| `bn-notification-bell` | Notification bell icon with unread count badge |
| `bn-post-composer` | Post composer embedded in page |
| `bn-profile-completion-bar` | Profile completeness progress bar |
| `bn-profile-fields` | Displays user profile fields |
| `bn-profile-header` | Full profile header with avatar and cover photo |
| `bn-registration-form` | Community registration form block |
| `bn-search-bar` | Community search input bar |
| `bn-space-card` | Single space card widget |
| `bn-space-directory` | Paginated space directory |
| `bn-trending-hashtags` | Trending hashtags sidebar widget |

---

## 16. Shortcodes

7 shortcodes registered via `Shortcodes\ShortcodeService` (shortcodes service):

| Tag | Handler | Where | Purpose |
|-----|---------|-------|---------|
| `[buddynext_activity]` | ShortcodeService::render_activity | includes/Shortcodes/ShortcodeService.php:39 | Render the social activity feed |
| `[buddynext_people]` | ShortcodeService::render_people | includes/Shortcodes/ShortcodeService.php:40 | Render the member directory |
| `[buddynext_spaces]` | ShortcodeService::render_spaces | includes/Shortcodes/ShortcodeService.php:41 | Render the spaces directory |
| `[buddynext_messages]` | ShortcodeService::render_messages | includes/Shortcodes/ShortcodeService.php:42 | Render the messages UI |
| `[buddynext_notifications]` | ShortcodeService::render_notifications | includes/Shortcodes/ShortcodeService.php:43 | Render the notifications list |
| `[buddynext_auth]` | ShortcodeService::render_auth | includes/Shortcodes/ShortcodeService.php:44 | Render login/registration forms |
| `[buddynext_community_admin]` | ShortcodeService::render_community_admin | includes/Shortcodes/ShortcodeService.php:45 | Render front-end community admin panel |

---

## 17. Bridges

Bridges load at `plugins_loaded:25` via `do_action('buddynext_load_bridges')` (fired from includes/Core/Plugin.php:286). All bridges guard with `class_exists()` and no-op when the companion plugin is inactive.

| Bridge | What It Wires | Evidence |
|--------|---------------|----------|
| `Bridges\JetonomyBridge` | Discussions tab + indexes Jetonomy posts into BN search; mentions | `buddynext_jetonomy_post_indexed` (includes/Bridges/JetonomyBridge.php:165), `buddynext_user_mentioned` (:127) |
| `Bridges\WPMediaVerseBridge` | Media / direct-message integration | `buddynext_dm_sent` (includes/Bridges/WPMediaVerseBridge.php:407), `buddynext_dm_received` (:447) |
| `Bridges\BuddyXBridge` | BuddyX theme avatar/nav/template overrides | class_exists-guarded bridge |
| `Bridges\GamificationBridge` + Listener | Routes community actions to `wb_gamification_event`; listens for badge/level events to create BN notifications | bridge (no BN-fired hook) |
| `Bridges\CareerBoardBridge` | Career Board nav item | class_exists-guarded bridge |

---

## 18. Engagement / Realtime

**Services:** `Realtime\TransportFactory::current` (realtime)

**REST Endpoints** (RealtimeController 1):

| Method | Route | Auth | Handler |
|--------|-------|------|---------|
| POST | `/me/presence/heartbeat` | require_auth | RealtimeController::heartbeat |

**Hooks Fired:**

- `buddynext_user_session_started` (action, args:1) - includes/Engagement/SessionTracker.php:106
- `buddynext_user_daily_login` (action, args:2) - includes/Engagement/SessionTracker.php:128
- `buddynext_presence_stamped` (action, args:1) - includes/Realtime/PresenceService.php:134

**Filters:**

- `buddynext_realtime_transport` (args:1) - includes/Realtime/TransportFactory.php:60 (swap in a third-party WebSocket transport; default is polling)

---

## 19. Theme / Tokens

**Filters:**

- `buddynext_css_vars` (args:1) - includes/Theme/TokenService.php:233
- `buddynext_css_vars_dark` (args:1) - includes/Theme/TokenService.php:245

`Theme\TokenService` emits CSS custom properties on `wp_head` for BuddyNext color tokens (light + dark), extensible via the two filters above.

---

## 20. Core Infrastructure

**Core Services:** permissions (PermissionService), roles (RoleService), cache (CacheService), counters (CounterService), abilities (Abilities), template_loader (TemplateLoader), assets (AssetService), features (FeatureRegistry), rest_router (REST\Router).

### Permission Model (4 layers)

Resolved in `PermissionService::can($user_id, $capability, $context)`:

1. WP `manage_options` site admin -> always granted (PermissionService.php:95).
2. Community role hierarchy from `bn_community_role` user_meta vs `ROLE_MAP` minimum (PermissionService.php:132-153). Space-scoped capabilities resolve the user's role from `bn_space_members` instead.
3. Explicit grant `bn_ability_{slug}` user_meta with expiry (`has_active_grant`, PermissionService.php:321; `0` = never expires).
4. Developer filter `buddynext_user_can` (filter, args:4) - includes/Core/PermissionService.php:121.

Space-banned users are hard-denied all `buddynext-spaces/*` capabilities up front (PermissionService.php:89, via `is_space_banned` checking `bn_space_bans` and `bn_space_members.status='banned'`).

### Core Hooks Fired

- `buddynext_loaded` (action, args:0) - includes/Core/Plugin.php:312
- `buddynext_load_bridges` (action, args:0) - includes/Core/Plugin.php:286
- `buddynext_before_template` / `buddynext_after_template` (action, args:2) - includes/Core/TemplateLoader.php:93 / :105
- `buddynext_before_hub` (action, args:2) - includes/Core/PageRouter.php:439
- `buddynext_role_changed` (action, args:2) - includes/Core/RoleService.php:80
- `buddynext_credits_spent` (action, args:3) - includes/Core/RoleService.php:171
- `buddynext_features` (filter, args:1) - includes/Core/FeatureRegistry.php:17
- `buddynext_brand_name` (filter, args:1) - includes/Core/Plugin.php:732
- `buddynext_brand_logo_url` (filter, args:1) - includes/Core/Plugin.php:757
- `buddynext_user_can` (filter, args:4) - includes/Core/PermissionService.php:121

### Template Hooks

The remaining majority of the 548 fired hooks are template extension points (`*_before` / `*_after` actions and display filters) emitted from `templates/**` (e.g. `buddynext_feed_home_before`, `buddynext_feed_explore_after`, `buddynext_context_nav`). These are stable third-party extension surfaces, not domain logic.

### Cron Schedules Added

| Interval | Key |
|----------|-----|
| 1 minute | `buddynext_1min` |
| 5 minutes | `buddynext_5min` |
| 30 minutes | `buddynext_30min` |

### Full Cron Catalogue (12)

| Hook | Schedule | Handler | Purpose |
|------|----------|---------|---------|
| `buddynext_daily_digest` | daily | EmailDispatchListener | Daily email digest |
| `buddynext_weekly_digest` | weekly | EmailDispatchListener | Weekly email digest |
| `buddynext_cleanup_tokens` | daily | CronScheduler | Purge expired verify tokens |
| `buddynext_cleanup_notifications` | weekly | CronScheduler | Prune old read notifications |
| `buddynext_trending_hashtags` | buddynext_30min | HashtagService | Refresh trending scores |
| `buddynext_recount_stats` | buddynext_5min | CounterService | Recount denormalised counters |
| `buddynext_publish_scheduled` | buddynext_1min | PostService | Publish scheduled posts |
| `buddynext_webhook_retry` | buddynext_5min | OutboundWebhookService | Retry failed webhook deliveries |
| `bn_onboarding_nudge_24h` | single | OnboardingListener | 24h onboarding nudge |
| `bn_onboarding_nudge_72h` | single | OnboardingListener | 72h onboarding nudge |
| `buddynext_reindex_all_cron` | single | SearchService::reindex_all_cron | One-time full search reindex post-activation |
| `buddynext_daily_queue_check` | daily | ModerationListener | Moderation queue health check |

### Container (DI)

`BuddyNext\Core\Container` registers 55 service bindings (see manifest `services[]`). Global helper: `buddynext_service($key)`. Bootstrap is `BuddyNext\Core\Plugin::init` at `plugins_loaded:15`.
