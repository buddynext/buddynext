# BuddyNext REST Surface Inventory

Auto-generated from `grep register_rest_route` on 2026-05-31.

This file is the source of truth for the BuddyNext REST surface across **both**
repos. Pair with [REST-FRONTEND-CONTRACT.md](REST-FRONTEND-CONTRACT.md) — the
contract that says the BuddyNext frontend is 100% REST and admin-ajax is
forbidden.

Two parallel versioned namespaces ship:

- `buddynext/v1` — the Free plugin (`buddynext/includes/`).
- `buddynext-pro/v1` — the Pro add-on (`buddynext-pro/includes/`). Additive and
  separable; Pro never registers into the Free namespace.

Regenerate after adding a new endpoint:

```bash
grep -rn register_rest_route ../../includes/ --include="*.php"
grep -rn register_rest_route ../../../buddynext-pro/includes/ --include="*.php"
```

## `buddynext/v1` (Free)

All routes below are registered under the `buddynext/v1` namespace.

The canonical member directory is `GET /members`, owned by
`Profile/MemberDirectoryController`. `Search/SearchController` exposes its
cursor-paginated member listing at the distinct path `GET /search/members` so
one path = one schema = one documented handler.

| Route | Methods | Permission | Source |
|---|---|---|---|
| `/admin/slug-check` | GET | $this->permission_check() | [Admin/SlugCheckController.php:52](../../includes/Admin/SlugCheckController.php#L52) |
| `/appeals` | POST | $this->require_auth() | [Moderation/ModerationController.php:258](../../includes/Moderation/ModerationController.php#L258) |
| `/appeals` | GET | $this->require_admin() | [Moderation/ModerationController.php:420](../../includes/Moderation/ModerationController.php#L420) |
| `/appeals/(?P<id>[\d]+)/approve` | POST/PUT/PATCH | $this->require_admin() | [Moderation/ModerationController.php:446](../../includes/Moderation/ModerationController.php#L446) |
| `/appeals/(?P<id>[\d]+)/deny` | POST/PUT/PATCH | $this->require_admin() | [Moderation/ModerationController.php:469](../../includes/Moderation/ModerationController.php#L469) |
| `/appeals/(?P<id>[\d]+)/resolve` | POST | $this->require_admin() | [Moderation/ModerationController.php:280](../../includes/Moderation/ModerationController.php#L280) |
| `/auth/verify/resend` | POST |  | [Auth/AuthController.php:30](../../includes/Auth/AuthController.php#L30) |
| `/auth/verify/status` | GET |  | [Auth/AuthController.php:40](../../includes/Auth/AuthController.php#L40) |
| `/comments` | POST | $this->require_auth() | [Comments/CommentController.php:33](../../includes/Comments/CommentController.php#L33) |
| `/comments/(?P<id>[\d]+)` | POST/PUT/PATCH | $this->require_auth() | [Comments/CommentController.php:94](../../includes/Comments/CommentController.php#L94) |
| `/feed/announcements/(?P<id>[\d]+)/dismiss` | POST |  | [Feed/FeedController.php:78](../../includes/Feed/FeedController.php#L78) |
| `/feed/explore` | GET |  | [Feed/FeedController.php:48](../../includes/Feed/FeedController.php#L48) |
| `/feed/home` | GET |  | [Feed/FeedController.php:38](../../includes/Feed/FeedController.php#L38) |
| `/follow-suggestions` | GET |  | [SocialGraph/FollowController.php:69](../../includes/SocialGraph/FollowController.php#L69) |
| `/hashtags/(?P<slug>[a-zA-Z0-9_-]+)` | GET | public | [Hashtags/HashtagController.php:113](../../includes/Hashtags/HashtagController.php#L113) |
| `/hashtags/(?P<slug>[a-zA-Z0-9_-]+)/follow` | POST | 'is_user_logged_in' | [Hashtags/HashtagController.php:82](../../includes/Hashtags/HashtagController.php#L82) |
| `/hashtags/autocomplete` | GET | public | [Hashtags/HashtagController.php:57](../../includes/Hashtags/HashtagController.php#L57) |
| `/hashtags/trending` | GET | public | [Hashtags/HashtagController.php:37](../../includes/Hashtags/HashtagController.php#L37) |
| `/invites/import-csv` | POST |  | [Onboarding/InviteController.php:28](../../includes/Onboarding/InviteController.php#L28) |
| `/me/appeals` | POST | $this->require_auth() | [Moderation/ModerationController.php:400](../../includes/Moderation/ModerationController.php#L400) |
| `/me/appeals` | GET | $this->require_auth() | [Moderation/ModerationController.php](../../includes/Moderation/ModerationController.php) |
| `/me/avatar` | POST | $this->require_auth() | [Profile/ProfileController.php:124](../../includes/Profile/ProfileController.php#L124) |
| `/me/blocked` | GET |  | [SocialGraph/BlockController.php:67](../../includes/SocialGraph/BlockController.php#L67) |
| `/me/bookmarks` | GET |  | [Feed/BookmarkController.php:48](../../includes/Feed/BookmarkController.php#L48) |
| `/me/connection-requests` | GET |  | [SocialGraph/ConnectionController.php:80](../../includes/SocialGraph/ConnectionController.php#L80) |
| `/me/connections` | GET |  | [SocialGraph/ConnectionController.php:70](../../includes/SocialGraph/ConnectionController.php#L70) |
| `/me/cover` | POST | $this->require_auth() | [Profile/ProfileController.php:141](../../includes/Profile/ProfileController.php#L141) |
| `/me/drafts` | POST/GET/DELETE | $this->require_auth() | [Feed/ComposerDraftController.php:49](../../includes/Feed/ComposerDraftController.php#L49) |
| `/me/muted` | GET |  | [SocialGraph/BlockController.php:77](../../includes/SocialGraph/BlockController.php#L77) |
| `/me/notification-prefs` | GET | $this->require_auth() | [Notifications/NotificationController.php:101](../../includes/Notifications/NotificationController.php#L101) |
| `/me/notifications` | GET |  | [Notifications/NotificationController.php:35](../../includes/Notifications/NotificationController.php#L35) |
| `/me/notifications/(?P<id>[\d]+)` | DELETE |  | [Notifications/NotificationController.php:91](../../includes/Notifications/NotificationController.php#L91) |
| `/me/notifications/(?P<id>[\d]+)/read` | PUT | $this->require_auth() | [Notifications/NotificationController.php:73](../../includes/Notifications/NotificationController.php#L73) |
| `/me/notifications/read-all` | PUT | $this->require_auth() | [Notifications/NotificationController.php:55](../../includes/Notifications/NotificationController.php#L55) |
| `/me/notifications/unread-count` | GET |  | [Notifications/NotificationController.php:45](../../includes/Notifications/NotificationController.php#L45) |
| `/me/onboarding/step` | POST | $this->require_auth() | [Onboarding/OnboardingController.php:46](../../includes/Onboarding/OnboardingController.php#L46) |
| `/me/onboarding/skip` | POST | $this->require_auth() | [Onboarding/OnboardingController.php:62](../../includes/Onboarding/OnboardingController.php#L62) |
| `/me/onboarding/complete` | POST | $this->require_auth() | [Onboarding/OnboardingController.php:72](../../includes/Onboarding/OnboardingController.php#L72) |
| `/me/profile` | GET | $this->require_auth() | [Profile/ProfileController.php:158](../../includes/Profile/ProfileController.php#L158) |
| `/me/profile-slug` | GET | $this->require_auth() | [Profile/ProfileController.php:175](../../includes/Profile/ProfileController.php#L175) |
| `/me/shares` | GET | $this->require_auth() | [Feed/ShareController.php:48](../../includes/Feed/ShareController.php#L48) |
| `/member-types` | GET |  | [MemberTypes/MemberTypeController.php:47](../../includes/MemberTypes/MemberTypeController.php#L47) |
| `/member-types` | POST |  | [MemberTypes/MemberTypeController.php:57](../../includes/MemberTypes/MemberTypeController.php#L57) |
| `/member-types/(?P<slug>[a-z0-9-]+)` | PUT | $this->require_admin() | [MemberTypes/MemberTypeController.php:67](../../includes/MemberTypes/MemberTypeController.php#L67) |
| `/member-types/(?P<slug>[a-z0-9-]+)` | DELETE | $this->require_admin() | [MemberTypes/MemberTypeController.php:84](../../includes/MemberTypes/MemberTypeController.php#L84) |
| `/members` | GET | public | [Profile/MemberDirectoryController.php:49](../../includes/Profile/MemberDirectoryController.php#L49) |
| `/posts` | POST |  | [Feed/PostController.php:38](../../includes/Feed/PostController.php#L38) |
| `/posts/(?P<id>[\d]+)` | GET | public | [Feed/PostController.php:48](../../includes/Feed/PostController.php#L48) |
| `/posts/(?P<id>[\d]+)/bookmark` | POST | $this->require_auth() | [Feed/BookmarkController.php:31](../../includes/Feed/BookmarkController.php#L31) |
| `/posts/(?P<id>[\d]+)/content-warning` | GET | public | [Moderation/ModerationController.php:558](../../includes/Moderation/ModerationController.php#L558) |
| `/posts/(?P<id>[\d]+)/my-vote` | GET |  | [Feed/PollController.php:51](../../includes/Feed/PollController.php#L51) |
| `/posts/(?P<id>[\d]+)/pin` | POST | $this->require_auth() | [Feed/PostController.php:70](../../includes/Feed/PostController.php#L70) |
| `/posts/(?P<id>[\d]+)/poll` | GET |  | [Feed/PollController.php:41](../../includes/Feed/PollController.php#L41) |
| `/posts/(?P<id>[\d]+)/share` | POST | $this->require_auth() | [Feed/ShareController.php:31](../../includes/Feed/ShareController.php#L31) |
| `/posts/(?P<id>[\d]+)/vote` | POST |  | [Feed/PollController.php:31](../../includes/Feed/PollController.php#L31) |
| `/profile-fields` | GET | public | [Profile/ProfileController.php:216](../../includes/Profile/ProfileController.php#L216) |
| `/profile-fields/(?P<id>[\d]+)` | PUT | $this->require_admin() | [Profile/ProfileController.php:379](../../includes/Profile/ProfileController.php#L379) |
| `/profile-fields/(?P<id>[\d]+)/reorder` | POST | $this->require_admin() | [Profile/ProfileController.php:436](../../includes/Profile/ProfileController.php#L436) |
| `/profile-groups` | GET | public | [Profile/ProfileController.php:267](../../includes/Profile/ProfileController.php#L267) |
| `/profile-groups/(?P<id>[\d]+)` | PUT | $this->require_admin() | [Profile/ProfileController.php:314](../../includes/Profile/ProfileController.php#L314) |
| `/profile-groups/(?P<id>[\d]+)/reorder` | POST | $this->require_admin() | [Profile/ProfileController.php:358](../../includes/Profile/ProfileController.php#L358) |
| `/profile-slug/check` | GET | $this->require_auth() | [Profile/ProfileController.php:199](../../includes/Profile/ProfileController.php#L199) |
| `/reactions` | GET | public | [Reactions/ReactionController.php:59](../../includes/Reactions/ReactionController.php#L59) |
| `/reactions/toggle` | POST | $this->require_auth() | [Reactions/ReactionController.php:31](../../includes/Reactions/ReactionController.php#L31) |
| `/reports` | POST | $this->require_auth() | [Moderation/ModerationController.php:55](../../includes/Moderation/ModerationController.php#L55) |
| `/reports/(?P<id>[\d]+)/dismiss` | POST |  | [Moderation/ModerationController.php:145](../../includes/Moderation/ModerationController.php#L145) |
| `/reports/(?P<id>[\d]+)/escalate` | POST/PUT/PATCH |  | [Moderation/ModerationController.php:155](../../includes/Moderation/ModerationController.php#L155) |
| `/reports/(?P<id>[\d]+)/resolve` | POST/PUT/PATCH |  | [Moderation/ModerationController.php:165](../../includes/Moderation/ModerationController.php#L165) |
| `/reports/queue` | GET | $this->require_queue_access() | [Moderation/ModerationController.php:109](../../includes/Moderation/ModerationController.php#L109) |
| `/search` | GET | public | [Search/SearchController.php:31](../../includes/Search/SearchController.php#L31) |
| `/search/members` | GET | public | [Search/SearchController.php:66](../../includes/Search/SearchController.php#L66) |
| `/space-categories` | GET | public | [Spaces/SpaceCategoryController.php:31](../../includes/Spaces/SpaceCategoryController.php#L31) |
| `/space-categories/(?P<id>[\d]+)` | PUT/PATCH | $this->require_manage_options() | [Spaces/SpaceCategoryController.php](../../includes/Spaces/SpaceCategoryController.php) |
| `/space-categories/(?P<id>[\d]+)` | DELETE | $this->require_manage_options() | [Spaces/SpaceCategoryController.php:67](../../includes/Spaces/SpaceCategoryController.php#L67) |
| `/spaces` | GET | public | [Spaces/SpaceController.php:46](../../includes/Spaces/SpaceController.php#L46) |
| `/spaces/(?P<id>[\d]+)` | GET | public | [Spaces/SpaceController.php:63](../../includes/Spaces/SpaceController.php#L63) |
| `/spaces/(?P<id>[\d]+)/approve-request` | POST |  | [Spaces/SpaceController.php:164](../../includes/Spaces/SpaceController.php#L164) |
| `/spaces/(?P<id>[\d]+)/ban` | POST |  | [Spaces/SpaceController.php:174](../../includes/Spaces/SpaceController.php#L174) |
| `/spaces/(?P<id>[\d]+)/ban/(?P<user_id>[\d]+)` | POST | $this->require_auth() | [Spaces/SpaceController.php:184](../../includes/Spaces/SpaceController.php#L184) |
| `/spaces/(?P<id>[\d]+)/bans` | GET | $this->require_space_owner_or_admin() | [Moderation/ModerationController.php:493](../../includes/Moderation/ModerationController.php#L493) |
| `/spaces/(?P<id>[\d]+)/bans/(?P<user_id>[\d]+)` | DELETE | $this->require_space_owner_or_admin() | [Moderation/ModerationController.php:535](../../includes/Moderation/ModerationController.php#L535) |
| `/spaces/(?P<id>[\d]+)/feed` | GET |  | [Feed/FeedController.php:68](../../includes/Feed/FeedController.php#L68) |
| `/spaces/(?P<id>[\d]+)/invite` | POST |  | [Spaces/SpaceController.php:132](../../includes/Spaces/SpaceController.php#L132) |
| `/spaces/(?P<id>[\d]+)/join` | POST | $this->require_auth() | [Spaces/SpaceController.php:105](../../includes/Spaces/SpaceController.php#L105) |
| `/spaces/(?P<id>[\d]+)/join/cancel` | POST | $this->require_auth() | [Spaces/SpaceController.php](../../includes/Spaces/SpaceController.php) |
| `/spaces/(?P<id>[\d]+)/leave` | POST |  | [Spaces/SpaceController.php:122](../../includes/Spaces/SpaceController.php#L122) |
| `/spaces/(?P<id>[\d]+)/members` | GET |  | [Spaces/SpaceController.php:85](../../includes/Spaces/SpaceController.php#L85) |
| `/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)` | DELETE |  | [Spaces/SpaceController.php:211](../../includes/Spaces/SpaceController.php#L211) |
| `/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/approve` | POST |  | [Spaces/SpaceController.php:143](../../includes/Spaces/SpaceController.php#L143) |
| `/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/decline` | POST |  | [Spaces/SpaceController.php:153](../../includes/Spaces/SpaceController.php#L153) |
| `/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/role` | PUT |  | [Spaces/SpaceController.php:201](../../includes/Spaces/SpaceController.php#L201) |
| `/spaces/(?P<id>[\d]+)/pending-requests` | GET |  | [Spaces/SpaceController.php:95](../../includes/Spaces/SpaceController.php#L95) |
| `/spaces/(?P<id>[\d]+)/transfer-ownership` | POST |  | [Spaces/SpaceController.php:221](../../includes/Spaces/SpaceController.php#L221) |
| `/users/(?P<id>[\d]+)/avatar` | POST | $this->require_admin() | [Profile/ProfileController.php:77](../../includes/Profile/ProfileController.php#L77) |
| `/users/(?P<id>[\d]+)/avatar` | DELETE | $this->require_admin() | [Profile/ProfileController.php](../../includes/Profile/ProfileController.php) |
| `/users/(?P<id>[\d]+)/block` | POST | $this->require_auth() | [SocialGraph/BlockController.php:33](../../includes/SocialGraph/BlockController.php#L33) |
| `/users/(?P<id>[\d]+)/connect` | POST | $this->require_auth() | [SocialGraph/ConnectionController.php:33](../../includes/SocialGraph/ConnectionController.php#L33) |
| `/users/(?P<id>[\d]+)/connect/accept` | POST |  | [SocialGraph/ConnectionController.php:50](../../includes/SocialGraph/ConnectionController.php#L50) |
| `/users/(?P<id>[\d]+)/connect/decline` | POST |  | [SocialGraph/ConnectionController.php:60](../../includes/SocialGraph/ConnectionController.php#L60) |
| `/users/(?P<id>[\d]+)/cover` | POST | $this->require_admin() | [Profile/ProfileController.php:93](../../includes/Profile/ProfileController.php#L93) |
| `/users/(?P<id>[\d]+)/feed` | GET |  | [Feed/FeedController.php:58](../../includes/Feed/FeedController.php#L58) |
| `/users/(?P<id>[\d]+)/follow` | POST | $this->require_auth() | [SocialGraph/FollowController.php:32](../../includes/SocialGraph/FollowController.php#L32) |
| `/users/(?P<id>[\d]+)/followers` | GET |  | [SocialGraph/FollowController.php:49](../../includes/SocialGraph/FollowController.php#L49) |
| `/users/(?P<id>[\d]+)/following` | GET |  | [SocialGraph/FollowController.php:59](../../includes/SocialGraph/FollowController.php#L59) |
| `/users/(?P<id>[\d]+)/member-type` | GET | public | [MemberTypes/MemberTypeController.php:103](../../includes/MemberTypes/MemberTypeController.php#L103) |
| `/users/(?P<id>[\d]+)/member-type` | PUT | $this->can_set_user_type() | [MemberTypes/MemberTypeController.php:120](../../includes/MemberTypes/MemberTypeController.php#L120) |
| `/users/(?P<id>[\d]+)/member-type` | DELETE | $this->require_admin() | [MemberTypes/MemberTypeController.php:142](../../includes/MemberTypes/MemberTypeController.php#L142) |
| `/users/(?P<id>[\d]+)/mute` | POST | $this->require_auth() | [SocialGraph/BlockController.php:50](../../includes/SocialGraph/BlockController.php#L50) |
| `/users/(?P<id>[\d]+)/profile` | GET |  | [Profile/ProfileController.php:50](../../includes/Profile/ProfileController.php#L50) |
| `/users/(?P<id>[\d]+)/profile` | PUT | $this->require_admin() | [Profile/ProfileController.php:60](../../includes/Profile/ProfileController.php#L60) |
| `/users/(?P<id>[\d]+)/shadow-ban` | POST | $this->require_admin() | [Moderation/ModerationController.php:332](../../includes/Moderation/ModerationController.php#L332) |
| `/users/(?P<id>[\d]+)/shadow-ban` | GET | $this->require_admin() | [Moderation/ModerationController.php](../../includes/Moderation/ModerationController.php) |
| `/users/(?P<id>[\d]+)/shadow-ban` | DELETE | $this->require_admin() | [Moderation/ModerationController.php](../../includes/Moderation/ModerationController.php) |
| `/users/(?P<id>[\d]+)/strikes` | GET | $this->require_admin() | [Moderation/ModerationController.php:176](../../includes/Moderation/ModerationController.php#L176) |
| `/users/(?P<id>[\d]+)/strikes/(?P<sid>[\d]+)/reverse` | POST |  | [Moderation/ModerationController.php:213](../../includes/Moderation/ModerationController.php#L213) |
| `/users/(?P<id>[\d]+)/suspend` | POST | $this->require_admin() | [Moderation/ModerationController.php:224](../../includes/Moderation/ModerationController.php#L224) |
| `/users/(?P<id>[\d]+)/suspend` | DELETE | $this->require_admin() | [Moderation/ModerationController.php:364](../../includes/Moderation/ModerationController.php#L364) |
| `/users/(?P<id>[\d]+)/suspension` | GET | $this->require_admin() | [Moderation/ModerationController.php:382](../../includes/Moderation/ModerationController.php#L382) |
| `/users/(?P<id>[\d]+)/suspensions` | GET | $this->require_admin() | [Moderation/ModerationController.php](../../includes/Moderation/ModerationController.php) |
| `/users/(?P<id>[\d]+)/warn` | POST | $this->require_admin() | [Moderation/ModerationController.php:309](../../includes/Moderation/ModerationController.php#L309) |
| `/users/(?P<id>[\d]+)/warnings` | GET | $this->require_admin() | [Moderation/ModerationController.php](../../includes/Moderation/ModerationController.php) |
| `/webhook/access` | POST |  | [Outbound/AccessWebhookController.php:42](../../includes/Outbound/AccessWebhookController.php#L42) |
| `/webhooks` | GET | $this->require_admin() | [Outbound/OutboundWebhookController.php:50](../../includes/Outbound/OutboundWebhookController.php#L50) |
| `/webhooks/(?P<id>[\d]+)` | DELETE | $this->require_admin() | [Outbound/OutboundWebhookController.php:89](../../includes/Outbound/OutboundWebhookController.php#L89) |
| `/webhooks/(?P<id>[\d]+)/log` | GET | $this->require_admin() | [Outbound/OutboundWebhookController.php:108](../../includes/Outbound/OutboundWebhookController.php#L108) |
| `/webhooks/(?P<id>[\d]+)/test` | POST | $this->require_admin() | [Outbound/OutboundWebhookController.php:140](../../includes/Outbound/OutboundWebhookController.php#L140) |

## `buddynext-pro/v1` (Pro)

All routes below are registered under the `buddynext-pro/v1` namespace by
controllers in `buddynext-pro/includes/`. Paths are shown fully resolved
(`$this->rest_base` / `self::BASE` constants expanded). 46 `register_rest_route`
calls; method groups split onto one row each, mirroring the Free table above.

| Route | Methods | Permission | Source |
|---|---|---|---|
| `/admin/tiers/(?P<slug>[a-z0-9-]+)/test-checkout` | POST | $this->require_admin() | [Membership/Controllers/AdminTierTestCheckoutController.php:61](../../../buddynext-pro/includes/Membership/Controllers/AdminTierTestCheckoutController.php#L61) |
| `/admin/whitelabel/preview` | POST | $this->require_admin() | [WhiteLabel/Controllers/PreviewController.php:45](../../../buddynext-pro/includes/WhiteLabel/Controllers/PreviewController.php#L45) |
| `/ai/classify` | POST | $this->require_admin() | [AI/Controllers/AiModerationController.php:65](../../../buddynext-pro/includes/AI/Controllers/AiModerationController.php#L65) |
| `/ai/reply-suggestions` | POST | $this->require_commenter() | [AI/Controllers/ReplyController.php:70](../../../buddynext-pro/includes/AI/Controllers/ReplyController.php#L70) |
| `/analytics/content/top` | GET | $this->require_admin() | [Analytics/Controllers/AnalyticsController.php:90](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L90) |
| `/analytics/me/profile-views` | GET | $this->require_logged_in() | [Analytics/Controllers/AnalyticsController.php:162](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L162) |
| `/analytics/members/top` | GET | $this->require_admin() | [Analytics/Controllers/AnalyticsController.php:117](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L117) |
| `/analytics/overview` | GET | $this->require_admin() | [Analytics/Controllers/AnalyticsController.php:80](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L80) |
| `/analytics/spaces/(?P<space_id>\d+)/health` | GET | $this->require_admin() | [Analytics/Controllers/AnalyticsController.php:139](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L139) |
| `/analytics/users/(?P<user_id>\d+)/profile-views` | GET | $this->require_admin() | [Analytics/Controllers/AnalyticsController.php:173](../../../buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php#L173) |
| `/broadcasts` | GET/POST | $this->admin_permission() | [Email/Controllers/BroadcastController.php:74](../../../buddynext-pro/includes/Email/Controllers/BroadcastController.php#L74) |
| `/broadcasts/(?P<id>[\d]+)` | GET/PUT/PATCH/DELETE | $this->admin_permission() | [Email/Controllers/BroadcastController.php:110](../../../buddynext-pro/includes/Email/Controllers/BroadcastController.php#L110) |
| `/broadcasts/(?P<id>[\d]+)/dispatch` | POST | $this->admin_permission() | [Email/Controllers/BroadcastController.php:156](../../../buddynext-pro/includes/Email/Controllers/BroadcastController.php#L156) |
| `/drip-sequences` | GET/POST | $this->admin_permission() | [Email/Controllers/DripController.php:71](../../../buddynext-pro/includes/Email/Controllers/DripController.php#L71) |
| `/drip-sequences/(?P<id>[\d]+)` | GET/PUT/PATCH | $this->admin_permission() | [Email/Controllers/DripController.php:106](../../../buddynext-pro/includes/Email/Controllers/DripController.php#L106) |
| `/drip-sequences/(?P<id>[\d]+)/steps` | POST | $this->admin_permission() | [Email/Controllers/DripController.php:136](../../../buddynext-pro/includes/Email/Controllers/DripController.php#L136) |
| `/drip-sequences/(?P<id>[\d]+)/enroll` | POST | $this->admin_permission() | [Email/Controllers/DripController.php:170](../../../buddynext-pro/includes/Email/Controllers/DripController.php#L170) |
| `/labels` | GET/POST | public read / $this->require_manage_options() write | [Members/Controllers/LabelsController.php:89](../../../buddynext-pro/includes/Members/Controllers/LabelsController.php#L89) |
| `/labels/(?P<id>[\d]+)` | GET/PUT/PATCH/DELETE | public read / $this->require_manage_options() write | [Members/Controllers/LabelsController.php:107](../../../buddynext-pro/includes/Members/Controllers/LabelsController.php#L107) |
| `/me/checkout` | POST | $this->require_logged_in() | [Membership/Controllers/CheckoutController.php:71](../../../buddynext-pro/includes/Membership/Controllers/CheckoutController.php#L71) |
| `/me/email-preferences` | GET/POST | $this->logged_in_permission() | [Email/Controllers/EmailUnsubscribeController.php:68](../../../buddynext-pro/includes/Email/Controllers/EmailUnsubscribeController.php#L68) |
| `/me/portal` | POST | $this->require_logged_in_with_customer() | [Membership/Controllers/CheckoutController.php:98](../../../buddynext-pro/includes/Membership/Controllers/CheckoutController.php#L98) |
| `/me/push-prefs` | GET/PUT/PATCH | $this->require_logged_in() | [Push/Controllers/PushPrefsController.php:73](../../../buddynext-pro/includes/Push/Controllers/PushPrefsController.php#L73) |
| `/me/push-tokens` | GET/POST | $this->require_logged_in() | [Push/PushController.php:78](../../../buddynext-pro/includes/Push/PushController.php#L78) |
| `/me/push-tokens/(?P<id>[\d]+)` | DELETE | $this->require_logged_in() | [Push/PushController.php:96](../../../buddynext-pro/includes/Push/PushController.php#L96) |
| `/me/push-tokens/test` | POST | $this->require_manage_options() | [Push/PushController.php:115](../../../buddynext-pro/includes/Push/PushController.php#L115) |
| `/me/saved-searches` | GET/POST | $this->require_logged_in() | [Search/Controllers/SavedSearchController.php:74](../../../buddynext-pro/includes/Search/Controllers/SavedSearchController.php#L74) |
| `/me/saved-searches/(?P<id>\d+)` | GET/PUT/PATCH/DELETE | $this->require_logged_in() | [Search/Controllers/SavedSearchController.php:106](../../../buddynext-pro/includes/Search/Controllers/SavedSearchController.php#L106) |
| `/me/saved-searches/(?P<id>\d+)/run` | POST | $this->require_logged_in() | [Search/Controllers/SavedSearchController.php:157](../../../buddynext-pro/includes/Search/Controllers/SavedSearchController.php#L157) |
| `/me/scheduled-posts` | GET | $this->logged_in_permission() | [Feed/Controllers/ScheduledPostsController.php:105](../../../buddynext-pro/includes/Feed/Controllers/ScheduledPostsController.php#L105) |
| `/me/subscriptions` | GET | $this->require_logged_in() | [Membership/Controllers/SubscriptionsController.php:54](../../../buddynext-pro/includes/Membership/Controllers/SubscriptionsController.php#L54) |
| `/mod-rules` | GET/POST | $this->require_admin() | [Moderation/Controllers/ModRulesController.php:58](../../../buddynext-pro/includes/Moderation/Controllers/ModRulesController.php#L58) |
| `/mod-rules/(?P<id>[\d]+)` | GET/PUT/PATCH/DELETE | $this->require_admin() | [Moderation/Controllers/ModRulesController.php:82](../../../buddynext-pro/includes/Moderation/Controllers/ModRulesController.php#L82) |
| `/mod-rules/(?P<id>[\d]+)/toggle` | POST | $this->require_admin() | [Moderation/Controllers/ModRulesController.php:105](../../../buddynext-pro/includes/Moderation/Controllers/ModRulesController.php#L105) |
| `/moderation/bulk` | POST | $this->require_admin() | [Moderation/Controllers/BulkModerationController.php:66](../../../buddynext-pro/includes/Moderation/Controllers/BulkModerationController.php#L66) |
| `/posts/(?P<id>[\d]+)/schedule` | POST/DELETE | $this->post_owner_permission() | [Feed/Controllers/ScheduledPostsController.php:64](../../../buddynext-pro/includes/Feed/Controllers/ScheduledPostsController.php#L64) |
| `/posts/scheduled` | GET | $this->admin_permission() | [Feed/Controllers/ScheduledPostsController.php:116](../../../buddynext-pro/includes/Feed/Controllers/ScheduledPostsController.php#L116) |
| `/realtime/auth` | POST | $this->require_logged_in() | [Realtime/AuthController.php:63](../../../buddynext-pro/includes/Realtime/AuthController.php#L63) |
| `/realtime/test-connection` | POST | $this->require_admin() | [Realtime/AuthController.php:87](../../../buddynext-pro/includes/Realtime/AuthController.php#L87) |
| `/spaces/(?P<id>\d+)/brand` | GET/POST | $this->require_space_brand_manager() | [WhiteLabel/Controllers/SpaceBrandController.php:58](../../../buddynext-pro/includes/WhiteLabel/Controllers/SpaceBrandController.php#L58) |
| `/stripe/webhook` | POST | public (in-callback signature auth) | [Stripe/WebhookController.php:103](../../../buddynext-pro/includes/Stripe/WebhookController.php#L103) |
| `/tiers` | GET/POST | public read / $this->require_admin() write | [Membership/Controllers/TiersController.php:56](../../../buddynext-pro/includes/Membership/Controllers/TiersController.php#L56) |
| `/tiers/(?P<id>\d+)` | GET/DELETE | public read / $this->require_admin() write | [Membership/Controllers/TiersController.php:97](../../../buddynext-pro/includes/Membership/Controllers/TiersController.php#L97) |
| `/users/(?P<user_id>[\d]+)/labels` | GET | public | [Members/Controllers/LabelsController.php:138](../../../buddynext-pro/includes/Members/Controllers/LabelsController.php#L138) |
| `/users/(?P<user_id>[\d]+)/labels/(?P<slug>[a-z0-9-]+)` | POST/DELETE | $this->require_manage_options() | [Members/Controllers/LabelsController.php:155](../../../buddynext-pro/includes/Members/Controllers/LabelsController.php#L155) |
| `/users/(?P<id>\d+)/subscriptions` | GET | $this->require_admin() | [Membership/Controllers/SubscriptionsController.php:64](../../../buddynext-pro/includes/Membership/Controllers/SubscriptionsController.php#L64) |
</content>
</invoke>
