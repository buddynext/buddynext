# Feature Groups and Tiers

BuddyNext is plug-and-play: every Layer 2 feature is a self-contained module that the site owner can turn on or off, and a developer can override by filter. This page documents the tier system that governs which features are active, the ~20 feature groups and their tiers, and how toggling a feature removes its routes, templates, options, and admin pages together.

The source of truth is `BuddyNext\Core\FeatureRegistry` (`includes/Core/FeatureRegistry.php`), resolved through the `features` container key.

## The three tiers

| Tier | Default state | Can the owner disable it? | How it resolves |
|---|---|---|---|
| `mandatory` | On | No - no toggle, no disable filter | `is_enabled()` returns `true` immediately. |
| `default_on` | On | Yes (Settings -> Features) | Tier default `true`, overridable by the stored option, then by a per-feature filter. |
| `opt_in` | Off | Yes - owner must enable it | Tier default `false`, then the stored option / filter can turn it on. |

`FeatureRegistry::is_enabled( $slug )` resolves in this order (first match wins):

1. Mandatory tier -> always `true`.
2. Any unmet dependency in `depends_on` -> forced `false`.
3. For a bridge feature, an absent partner plugin -> forced `false` (`presence_met()`).
4. Tier default (`default_on` = true, `opt_in` = false), overridden by the stored `buddynext_features` option if the owner set it.
5. The per-feature filter `buddynext_feature_{slug}` returns the final boolean.

```php
// Programmatic overrides (the filter wins over the stored option).
add_filter( 'buddynext_feature_sidebar', '__return_false' );   // force-disable
add_filter( 'buddynext_feature_webhooks', '__return_true' );   // force-enable

// Resolve a feature's state in code.
if ( buddynext_service( 'features' )->is_enabled( 'hashtags' ) ) {
    // hashtag indexing is active
}
```

Mandatory features cannot be persisted off: `clean_state()` drops them from any saved toggle map. Dependencies cascade - `hashtags`, `reactions`, `comments`, and `announcements` all depend on `feed`, and `verification` depends on `auth`, so disabling a dependency forces its dependents off too.

## The feature groups

The registry catalogs 20 features, organized into display groups (`core`, `community`, `bridges`, `integrations`) for the Settings -> Features UI. Tiers below are read straight from the registry and the audit manifest's `featureGroups`.

| Feature slug | Tier | Display group | Depends on | What it covers |
|---|---|---|---|---|
| `feed` | mandatory | core | - | Posts, comments, reactions, polls, shares - the heart of the community. |
| `profile` | mandatory | core | - | Per-member profile pages (cover, avatar, bio, custom fields). |
| `social_graph` | mandatory | core | - | Follows, connections, blocks - the relationships layer. |
| `notifications` | mandatory | core | - | In-app notifications for follows, reactions, comments, mentions, moderation. |
| `auth` | mandatory | core | - | Custom login + registration pages and the email-verification handshake. |
| `search` | mandatory | core | - | Unified FULLTEXT index across posts, users, spaces, hashtags. |
| `moderation` | mandatory | core | - | Reports, strikes, suspensions, appeals - the integrity layer. |
| `spaces` | default_on | community | - | Topic-scoped sub-communities with their own posts, members, settings. |
| `hashtags` | default_on | community | feed | Extract #tags, build trending lists, per-tag feeds. |
| `reactions` | default_on | community | feed | Emoji reactions on posts and comments. |
| `comments` | default_on | community | feed | Threaded comments on posts. |
| `sidebar` | default_on | community | - | Right-column hub widgets (trending, suggested people, your spaces). |
| `onboarding` | default_on | community | - | Multi-step welcome flow for new members. |
| `verification` | default_on | community | auth | Send a verification link on registration; gate actions on verified status. |
| `announcements` | default_on | community | feed | Pin an announcement to the top of every member's feed. |
| `gamification` | default_on* | bridges | - | Bridges WBGamification (points, badges, leaderboard). |
| `jetonomy` | default_on* | bridges | feed | Surfaces Jetonomy forum activity in BuddyNext feeds. |
| `wpmediaverse` | default_on* | bridges | - | Bridges WPMediaVerse for direct messages. |
| `career_board` | default_on* | bridges | feed | Surfaces Career Board job posts as activity. |
| `webhooks` | opt_in | integrations | - | Outbound signed HTTPS POSTs to external endpoints on community events. |

\* Bridge features are `default_on` in the registry so an installed integration works out of the box, but `presence_met()` forces them off whenever the partner plugin is absent (`WPMediaVerse\Core\Plugin`, `Jetonomy\Jetonomy`, `wb_gam_submit_event()`, `WCB_VERSION`). The Settings -> Features UI renders such a toggle disabled with a "Requires X" notice. The audit manifest's `featureGroups` lists these four plus `webhooks` as the opt_in / bridge set; the registry is the canonical tier source.

> **Note:** The manifest groups routes/templates/options by feature, while the registry groups features for the admin UI. The `social_graph` registry feature has no `featureGroups` routes of its own in the manifest because its endpoints are filed under the feature surfaces that consume them (feed, profile). Trust the registry for tiers and the manifest for the route/template/option inventory.

## What a feature group binds

Each feature group ties together four kinds of surface. The audit manifest (`features.featureGroups`) records exactly which routes, templates, options, and admin pages belong to each group. Examples from the current manifest:

- **Routes** - REST endpoints under `buddynext/v1`. `feed` owns 11 (`/feed/home`, `/feed/explore`, `/feed/announcements/{id}/dismiss`, ...); `spaces` owns 28; `webhooks` owns 5. A disabled feature's controller is never registered, so its routes 404.
- **Templates** - the hub templates and partials the feature renders. `spaces` ships 28 templates, `profile` 24, `sidebar` 12. A disabled feature's templates are never reached because the route or the `Container::has()` guard short-circuits first.
- **Options** - the settings the feature persists. Examples: `spaces` -> `buddynext_space_creation_role`, `buddynext_space_max_sub_spaces`, `buddynext_notif_default_space_join`; `reactions` -> `buddynext_enabled_reactions`; `hashtags` -> `buddynext_banned_hashtags`; `comments` -> `buddynext_notif_default_comment`; `webhooks` -> `buddynext_webhook_secret`; `feed`/`jetonomy` -> `buddynext_jetonomy_feed_sync`.
- **Admin pages** - the dedicated wp-admin screens. Only `spaces` registers one in the manifest (`buddynext-spaces`); the other features expose their settings inside the shared BuddyNext settings tabs rather than a standalone page.

The wiring lives in `Plugin::register_services()` and `Plugin::init()`. A feature binds its services only when the registry reports it enabled, and its listener is wired only when the binding exists:

```php
// In register_services(): bind the Service + Cache only when enabled.
if ( $features->is_enabled( 'sidebar' ) ) {
    $container->bind( 'sidebar_cache', fn() => new \BuddyNext\Sidebar\WidgetCache() );
    $container->bind( 'sidebar_widgets', fn( $c ) =>
        new \BuddyNext\Sidebar\WidgetService( $c->get( 'sidebar_cache' ) ) );
}

// In init(): wire the listener only when the binding exists.
if ( $container->has( 'sidebar_widgets' ) ) {
    ( new \BuddyNext\Sidebar\WidgetListener( $container->get( 'sidebar_cache' ) ) )->register();
}
```

## How toggling a feature removes its UI and REST surface

Turning a feature off has to remove the whole surface at once - the REST endpoints, the nav links, and the hub routes - so a member never lands on a half-disabled page. BuddyNext enforces this at three points:

1. **Service + listener never bind.** When `is_enabled()` is false the feature's container keys are never registered, so nothing downstream can resolve them.
2. **REST routes never register.** Each feature's controller is registered by `REST/Router` only while the feature is bound, so a disabled feature's routes return 404 rather than an empty 200.
3. **Hub routes redirect.** `PageRouter::dispatch_hub_template()` re-checks the registry for the toggleable hubs and bounces visitors away from a disabled surface. For example, when `spaces` is off, `/spaces/` redirects to the activity hub; the same guard protects `onboarding`, the `hashtags` per-tag feed, and (via `MessagesData::entry_enabled()`) the `wpmediaverse`-backed messages hub.

Templates that optionally use a feature follow the plug-and-play degradation pattern - check `Container::has()`, and fall back to an empty result when the feature is absent:

```php
$widgets = function_exists( 'buddynext_service' )
    && \BuddyNext\Core\Container::instance()->has( 'sidebar_widgets' )
        ? buddynext_service( 'sidebar_widgets' )
        : null;

$trending = ( null !== $widgets ) ? $widgets->trending_hashtags( 5 ) : array();
```

The minimal-mode contract is the floor: with every `default_on` and `opt_in` feature disabled, Core plus the mandatory features must still deliver a working community - login/register, posts, direct follow, basic notifications, and search. If disabling a feature breaks any of those, that feature was misplaced in Layer 2 and belongs in Core.

## Extending the catalog

Third-party plugins register their own features under the same contract via the `buddynext_features` filter. Each entry uses the registry's shape (`slug`, `tier`, `group`, `depends_on`, and a translatable `label`/`description` supplied at the same time), after which it participates in the Settings -> Features UI, the `buddynext_feature_{slug}` override filter, and the same enable/disable resolution as the built-in features.

## Notes

- The Pro plugin (`BuddyNextPro`) layers its own modules on top of these free features - Membership, AI, Realtime, Push, Analytics, White-label, and enhancements to Reactions/Feed/Moderation/Members/Profile/Search. Pro modules are not part of the free `FeatureRegistry` catalog; they boot at `plugins_loaded:20` and extend free through the documented hooks and container rebinding rather than appearing as free feature toggles.
- Read tiers from `FeatureRegistry::catalog()`, and read the per-feature route/template/option/admin-page inventory from `audit/manifest.json` -> `features.featureGroups`. Keep both in sync when adding a feature.
