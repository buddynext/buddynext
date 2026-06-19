# Flow Audit Report

- Generated: 2026-06-19T09:38:31.577Z
- Repos: /Users/vapvarun/dev/repos/buddynext, /Users/vapvarun/dev/repos/buddynext-pro

**1 errors, 0 warnings** across 8 checks.

## dup-function — 1 error(s), 1 finding(s)

- [error] **Duplicate implementation across 2 symbols** `includes/Feed/FeedService.php:1095`
  - Identical body in: BuddyNext\Feed\FeedService::decode_cursor (includes/Feed/FeedService.php:1095), BuddyNext\Hashtags\HashtagService::decode_feed_cursor (includes/Hashtags/HashtagService.php:275). Consolidate to one canonical helper.
  - _Fix:_ Keep one (recommend the one in the most general location) and route the others through it.
  - _id:_ `dup:fdbefde71d49883b6517a2f3e15d245b1c024480`

## orphan — 0 error(s), 1 finding(s)

- [info] **120 symbols are unreferenced within the codebase (dead-code is not statically confirmable)** `:0`
  - These functions/methods have no static call/registration/load and no name reference. This is NOT a confirmed dead-code list — PHP dynamic dispatch ($this->{$var}(), call_user_func, variable hooks) and external consumers (themes/app/other plugins) are invisible to static analysis, so the list contains both real dead code and live code. Confirm with runtime coverage before removing. Candidates (first 40): buddynext_spend_credits, buddynext_nav_move, buddynext_nav_remove, buddynext_nav_set, buddynext_register_profile_field, buddynext_create_space_url, buddynext_emoji, buddynext_header_notification_bell, buddynext_header_messages_bell, buddynext_header_user_menu, BuddyNext\Messages\MessagesData::open_with, BuddyNext\Core\FeatureRegistry::persist, BuddyNext\Core\CounterService::recount_hashtag_posts, BuddyNext\Core\CounterService::recount_hashtag_followers, BuddyNext\Core\PageRouter::followers_url, BuddyNext\Core\PageRouter::following_url, BuddyNext\Core\Plugin::brand_logo_url, BuddyNext\Core\RoleService::is_moderator, BuddyNext\Core\CacheService::get_notification_count, BuddyNext\Core\CacheService::set_notification_count, BuddyNext\Core\CacheService::invalidate_notification_count, BuddyNext\Core\CacheService::get_trending_hashtags, BuddyNext\Core\CacheService::set_trending_hashtags, BuddyNext\Core\CacheService::invalidate_trending_hashtags, BuddyNext\Core\CacheService::get_space_member_count, BuddyNext\Core\CacheService::set_space_member_count, BuddyNext\Core\CacheService::invalidate_space_member_count, BuddyNext\Core\CacheService::get_follow_counts, BuddyNext\Core\CacheService::set_follow_counts, BuddyNext\Core\CacheService::invalidate_follow_counts, BuddyNext\Core\CacheService::get_hashtag_autocomplete, BuddyNext\Core\CacheService::set_hashtag_autocomplete, BuddyNext\Core\CacheService::invalidate_hashtag_autocomplete, BuddyNext\Core\CronScheduler::clear_events, BuddyNext\Reactions\ReactionService::toggle_reaction, BuddyNext\Spaces\SpaceService::get_categories_full, BuddyNext\Spaces\SpaceService::get_pending_join_requests, BuddyNext\Spaces\SpaceService::count_pending_joins, BuddyNext\Spaces\SpaceMemberService::unban, BuddyNext\Admin\Spaces::get_title +80 more.
  - _Fix:_ Do not bulk-delete. Confirm each against a seeded runtime/coverage run; remove only those proven unexecuted.
  - _id:_ `orphan:summary`

## rest-flow — 0 error(s), 1 finding(s)

- [info] **193 routes have unresolved response shapes (key-level flow not checked)** `:0`
  - These routes return a dynamic/hydrated shape; key-level flow cannot be checked statically. Routes (first 30): GET /buddynext/v1/admin/slug-check, POST /buddynext/v1/auth/login, POST /buddynext/v1/auth/2fa, POST /buddynext/v1/auth/2fa/email-code, POST /buddynext/v1/auth/register, POST /buddynext/v1/auth/approve/(?P<id>[\d]+), POST /buddynext/v1/auth/verify/resend, GET /buddynext/v1/auth/verify/status, POST /buddynext/v1/auth/change-password, POST /buddynext/v1/auth/change-email, POST /buddynext/v1/auth/sign-out-everywhere, GET /buddynext/v1/account/2fa, POST /buddynext/v1/account/2fa/setup, POST /buddynext/v1/account/2fa/confirm, POST /buddynext/v1/account/2fa/disable, POST /buddynext/v1/account/2fa/backup, POST /buddynext/v1/spaces/(?P<id>\d+)/forum, POST /buddynext/v1/comments, POST /buddynext/v1/comments/(?P<id>[\d]+), POST /buddynext/v1/comments/(?P<id>[\d]+)/pin, POST /buddynext/v1/posts/(?P<id>[\d]+)/bookmark, GET /buddynext/v1/me/bookmarks, POST /buddynext/v1/me/drafts, GET /buddynext/v1/feed/home, GET /buddynext/v1/feed/counts, GET /buddynext/v1/feed/new-count, GET /buddynext/v1/feed/explore, GET /buddynext/v1/users/(?P<id>[\d]+)/feed, GET /buddynext/v1/spaces/(?P<id>[\d]+)/feed, GET /buddynext/v1/feed/home/page +163 more.
  - _Fix:_ (no action) or return a literal array to enable key checks.
  - _id:_ `rest-flow:shape-unresolved-summary`

## canonical-usage — 0 error(s), 0 finding(s)


## template-usage — 0 error(s), 1 finding(s)

- [info] **14 templates have no statically-detectable loader** `templates/parts/profile-edit-danger-zone.php:1`
  - These template parts have no buddynext_get_template/require/load-helper reference found by static scan. This is NOT a confirmed dead-template list — templates loaded by computed name (e.g. buddynext_get_template($var), "panel-{$tab}.php") are invisible to static analysis. Confirm with runtime coverage (which parts never render) before removing. Candidates (first 40): templates/parts/profile-edit-danger-zone.php, templates/parts/profile-edit-notif-row.php, templates/parts/profile-field-group.php, templates/parts/profile-field.php, templates/parts/space-settings-panel-branding.php, templates/parts/space-settings-panel-danger.php, templates/parts/space-settings-panel-general.php, templates/parts/space-settings-panel-integrations.php, templates/parts/space-settings-panel-members.php, templates/parts/space-settings-panel-moderation.php, templates/parts/space-settings-panel-notifications.php, templates/parts/space-settings-panel-permissions.php, templates/parts/space-settings-panel-privacy.php, templates/parts/stat-strip.php.
  - _Fix:_ Do not bulk-delete. Confirm against a seeded runtime render before removing.
  - _id:_ `tpl-usage:unloaded-summary`

## logic-flow — 0 error(s), 1 finding(s)

- [info] **216 route(s) have no documented journey** `:0`
  - 216 route(s) not covered by journeys.json: /buddynext/v1/admin/slug-check, /buddynext/v1/auth/login, /buddynext/v1/auth/2fa, /buddynext/v1/auth/2fa/email-code, /buddynext/v1/auth/register, /buddynext/v1/auth/approve/(?P<id>[\d]+), /buddynext/v1/auth/verify/resend, /buddynext/v1/auth/verify/status, /buddynext/v1/auth/change-password, /buddynext/v1/auth/change-email, /buddynext/v1/auth/sign-out-everywhere, /buddynext/v1/me/social/(?P<provider>[a-z0-9_-]+), /buddynext/v1/account/2fa, /buddynext/v1/account/2fa/setup, /buddynext/v1/account/2fa/confirm, /buddynext/v1/account/2fa/disable, /buddynext/v1/account/2fa/backup, /buddynext/v1/spaces/(?P<id>\d+)/forum, /buddynext/v1/comments, /buddynext/v1/comments/(?P<id>[\d]+), /buddynext/v1/comments/(?P<id>[\d]+)/pin, /buddynext/v1/posts/(?P<id>[\d]+)/bookmark, /buddynext/v1/me/bookmarks, /buddynext/v1/me/drafts, /buddynext/v1/feed/home, /buddynext/v1/feed/counts, /buddynext/v1/feed/new-count, /buddynext/v1/feed/explore, /buddynext/v1/users/(?P<id>[\d]+)/feed, /buddynext/v1/spaces/(?P<id>[\d]+)/feed, +186 more. Consider adding journeys + cert oracles for go-live coverage.
  - _Fix:_ Add to journeys.json for any user-facing route.
  - _id:_ `logic-flow:undocumented-summary`

## template-contract — 0 error(s), 0 finding(s)


## rest-js-contract — 0 error(s), 0 finding(s)

