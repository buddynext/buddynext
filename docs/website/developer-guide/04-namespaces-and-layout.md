# Namespaces and Directory Layout

This page maps BuddyNext's directory tree to its PHP namespaces and lists the container service keys you resolve at runtime. Use it to decide where a new file belongs and which `buddynext_service()` key returns the service you need.

![The admin dashboard whose screens resolve services from the container keys mapped on this page](../images/admin-overview.png)

![The front-end feed surface produced by the namespaced feature modules described here](../images/community-activity-feed.png)

## Overview

BuddyNext autoloads PSR-4: the `BuddyNext\` namespace prefix maps to the `includes/` directory. A class at `includes/Feed/PostService.php` is `BuddyNext\Feed\PostService`. The Pro plugin mirrors this with `BuddyNextPro\` mapped to its own `includes/`.

Every feature domain owns its full vertical stack in one folder - Service, Controller, Listener, and optional Cache - rather than splitting by file type. A REST controller lives next to the service it wraps, not in a shared `REST/` folder.

## Layer-to-directory map

| Layer | Directory | Namespace | Responsibility |
|---|---|---|---|
| 0 - Core | `includes/Core/` | `BuddyNext\Core` | Single-instance services every feature uses: `Plugin` (bootstrap), `Container` (DI), `Installer` (schema), `AssetService`, `PageRouter`, `PermissionService`, `IconService`, `FeatureRegistry`, `CronScheduler`. |
| 1 - Bridges | `includes/Bridges/` | `BuddyNext\Bridges` | Adapters to external plugins. `{Plugin}Bridge` (adapter) + `{Plugin}BridgeListener` (hook registrar). Each self-guards via `class_exists`. |
| 2 - Features | `includes/{Feature}/` | `BuddyNext\{Feature}` | Self-contained feature modules - e.g. `Feed/`, `SocialGraph/`, `Profile/`, `Spaces/`, `Notifications/`, `Moderation/`, `Search/`, `Hashtags/`, `Reactions/`, `Comments/`, `Auth/`, `Onboarding/`, `Outbound/`, `Sidebar/`. |
| 3 - UI | `templates/`, `assets/` | (not PHP-namespaced) | `templates/parts/*.php` partials, `assets/css/bn-{feature}.css`, `assets/js/{feature}/store.js`. |
| 4 - Composition | `templates/{hub}/` | (not PHP-namespaced) | Hub templates that compose Layer 3 parts and call Layer 2 services. |
| Admin | `includes/Admin/` | `BuddyNext\Admin` | Admin pages and settings UI. See the Admin sub-namespace rules below. |
| Blocks | `includes/Blocks/` | `BuddyNext\Blocks` | `BlockRegistrar` + dynamic-block render classes for the Gutenberg blocks. |
| Theme | `includes/Theme/` | `BuddyNext\Theme` | `TokenService` (injects `--bn-*` CSS custom properties), `Appearance` (front-end branding). |

Other feature-adjacent namespaces in the tree follow the same rule (folder name = namespace segment): `BuddyNext\Nav`, `BuddyNext\Realtime`, `BuddyNext\Engagement`, `BuddyNext\Media`, `BuddyNext\Privacy`, `BuddyNext\PWA`, `BuddyNext\Shortcodes`, `BuddyNext\Widgets`, `BuddyNext\MemberTypes`, `BuddyNext\ActivityLog`, `BuddyNext\Contracts`, `BuddyNext\REST`.

### Admin sub-namespace rules

Admin pages use a thin-controller + subdirectory pattern so no admin file grows past roughly 400 lines.

| Path | Namespace |
|---|---|
| `includes/Admin/{PageName}.php` | `BuddyNext\Admin` |
| `includes/Admin/{PageName}/*.php` | `BuddyNext\Admin\{PageName}` |
| `includes/Admin/Helpers/*.php` | `BuddyNext\Admin\Helpers` |

The thin controller (`Members.php`) registers the menu and routes; its sub-handlers (`Members/ProfileFieldsManager.php`, `Members/MemberEditForm.php`) own one domain each and are instantiated and `register()`ed by the controller - they never self-register.

## File placement quick reference

When a new file's name starts with a domain prefix, it goes in that domain's folder. When it doesn't, pick the folder whose responsibility best matches.

| File type | Belongs in | Example |
|---|---|---|
| REST controller for a domain | Same folder as its Service | `MemberTypeController` -> `MemberTypes/` |
| Outbound webhook service/controller/listener | `Outbound/` | `OutboundWebhookService` |
| Content moderation logic (banned words, rate limits) | `Moderation/` | `SafeguardService` |
| Bridge adapter | `Bridges/` with `Bridge` suffix | `JetonomyBridge.php` |
| Bridge hook registrar | `Bridges/` with `BridgeListener` suffix | `JetonomyBridgeListener.php` |
| Directory/listing service querying `WP_User_Query` | `Profile/` | `MemberDirectoryService` |
| Directory/listing service querying `bn_search_index` | `Search/` | - |
| Cron job runner | `Core/CronService.php` | - |

Naming conventions: classes are PascalCase, one per file (`FollowService.php`); templates are kebab-case (`home-feed.php`); partials are `partial-*.php`; assets are kebab-case (`bn-feed.css`); REST controllers are `{Feature}Controller.php`; tests are `{Class}Test.php` and mirror the source path.

## Container service keys

These are the keys bound in `Plugin::register_services()`. Resolve any of them with `buddynext_service( 'key' )` (or `Container::instance()->get( 'key' )`). Keys marked conditional are only bound when their feature tier resolves enabled - guard them with `Container::has()` before resolving.

### Core and permissions

| Key | Class |
|---|---|
| `permissions` | `Core\PermissionService` |
| `roles` | `Core\RoleService` |
| `cache` | `Core\CacheService` |
| `counters` | `Core\CounterService` |
| `abilities` | `Core\Abilities` |
| `features` | `Core\FeatureRegistry` |
| `rest_router` | `REST\Router` |
| `template_loader` | `Core\TemplateLoader` |
| `assets` | `Core\AssetService` |
| `asset_isolation` | `Core\AssetIsolation` |
| `plugin_isolation` | `Core\PluginIsolation` |

### Social graph and feed

| Key | Class |
|---|---|
| `follows` | `SocialGraph\FollowService` |
| `connections` | `SocialGraph\ConnectionService` |
| `blocks` | `SocialGraph\BlockService` |
| `privacy` | `SocialGraph\PrivacyService` |
| `post_service` | `Feed\PostService` |
| `feed` | `Feed\FeedService` |
| `feed_cache` | `Feed\FeedCache` |
| `polls` | `Feed\PollService` |
| `bookmarks` | `Feed\BookmarkService` |
| `shares` | `Feed\ShareService` |
| `reactions` | `Reactions\ReactionService` |
| `comments` | `Comments\CommentService` |
| `hashtags` | `Hashtags\HashtagService` |

### Profiles, spaces, search

| Key | Class |
|---|---|
| `profiles` | `Profile\ProfileService` |
| `avatars` | `Profile\AvatarService` |
| `member_directory` | `Profile\MemberDirectoryService` |
| `member_types` | `MemberTypes\MemberTypeService` |
| `spaces` | `Spaces\SpaceService` |
| `space_members` | `Spaces\SpaceMemberService` |
| `search` | `Search\SearchService` |
| `search_index_listener` | `Search\SearchIndexListener` |

### Notifications, email, moderation

| Key | Class |
|---|---|
| `notifications` | `Notifications\NotificationService` |
| `notification_prefs` | `Notifications\NotificationPrefService` |
| `notification_message` | `Notifications\NotificationMessageService` |
| `notification_pref_catalogue` | `Notifications\NotificationPrefCatalogue` |
| `email_sender` | `Notifications\EmailSender` |
| `moderation` | `Moderation\ModerationService` |
| `mod_log` | `Moderation\ModerationLogService` |
| `safeguard` | `Moderation\SafeguardService` |
| `activity_log` | `ActivityLog\ActivityLogService` |

### Auth, onboarding, platform

| Key | Class |
|---|---|
| `verification` | `Auth\VerificationService` |
| `onboarding` | `Onboarding\OnboardingService` |
| `invite` | `Onboarding\InviteService` |
| `setup_wizard` | `Onboarding\SetupWizard` |
| `realtime` | resolved via `Realtime\TransportFactory::current()` |
| `shortcodes` | `Shortcodes\ShortcodeService` |
| `widgets` | `Widgets\WidgetService` |
| `pwa` | `PWA\PwaService` |
| `webhooks` | `Outbound\OutboundWebhookService` |

### Admin (bound, resolved in `is_admin()` context)

| Key | Class |
|---|---|
| `admin_settings` | `Admin\Settings` |
| `admin_members` | `Admin\Members` |
| `admin_spaces` | `Admin\Spaces` |
| `admin_nav` | `Admin\NavManager` |
| `admin_email_editor` | `Admin\EmailEditor` |

### Conditional keys (guard with `Container::has()`)

| Key | Class | Bound when |
|---|---|---|
| `sidebar_cache` | `Sidebar\WidgetCache` | `sidebar` feature enabled |
| `sidebar_widgets` | `Sidebar\WidgetService` | `sidebar` feature enabled |

## Notes

- `buddynext_service()` is the global accessor for everything outside the bootstrap. Inside a factory callback, the container instance is passed in (`fn( $c ) => ...`) so dependencies are resolved through `$c->get()`.
- `Container::get()` throws on an unknown key. For a feature that may be disabled, call `Container::has( $key )` first - that is how the plug-and-play model degrades gracefully.
- To replace a core service with your own subclass, rebind its key on the `buddynext_services_registered` action (it fires after core bindings, before any resolution). See the Architecture Overview bootstrap section.
