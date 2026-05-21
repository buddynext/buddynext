# BuddyNext — Modular architecture

**Every functionality is prepared in modular way, grouped by component and core.** This is the long-term shape of the codebase. New features land inside the layers below; cross-layer dependencies follow the direction arrow.

```
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 4  Composition (hub templates)                                │
│   templates/{hub}/index.php  →  composes Layer 3 + Layer 2           │
│   shell-owned chrome (Layer 0); inner template owns content only.    │
└──────────────────────────────────────────────────────────────────────┘
                              ↑
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 3  UI components                                              │
│   templates/parts/*.php       reusable partials with hook + filter   │
│   assets/css/bn-{feature}.css per-feature stylesheet                 │
│   assets/js/{feature}/store.js Interactivity API store               │
└──────────────────────────────────────────────────────────────────────┘
                              ↑
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 2  Feature modules                                            │
│   includes/{Feature}/Service.php       business logic                │
│   includes/{Feature}/Controller.php    REST endpoints                │
│   includes/{Feature}/Listener.php      WordPress action hooks        │
│   includes/{Feature}/Cache.php         cache layer + bust hooks      │
└──────────────────────────────────────────────────────────────────────┘
                              ↑
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 1  Bridges (cross-plugin adapters)                            │
│   includes/Bridges/{Plugin}Bridge.php          adapter               │
│   includes/Bridges/{Plugin}BridgeListener.php  hook registrar        │
└──────────────────────────────────────────────────────────────────────┘
                              ↑
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 0  Core                                                       │
│   includes/Core/Plugin.php       bootstrap + container wiring        │
│   includes/Core/Container.php    DI container                        │
│   includes/Core/Installer.php    DB schema                           │
│   includes/Core/AssetService.php asset registration                  │
│   includes/Core/PageRouter.php   hub routing + shell                 │
│   includes/Core/PermissionService.php  buddynext_can()               │
│   includes/Core/IconService.php  SVG icon rendering                  │
└──────────────────────────────────────────────────────────────────────┘
```

## Layer rules

### Layer 0 — Core

- Single-instance services. Used by every Layer 2 feature.
- Cannot depend on any Layer 2 feature (no upward dependency).
- Examples: Container, AssetService, PageRouter, PermissionService, IconService, Installer.
- New core service: file lives in `includes/Core/`, class is bound in `Plugin::bind_services()`.

### Layer 1 — Bridges

- Adapters to external plugins (Jetonomy, WPMediaVerse, WBGamification, Career Board).
- Optional — bridge bails via `class_exists` if its external plugin is absent.
- Naming: `{Plugin}Bridge.php` for the adapter, `{Plugin}BridgeListener.php` for the hook registrar.
- Lives in `includes/Bridges/`.

### Layer 2 — Feature modules

Each feature is a self-contained folder under `includes/{Feature}/` with four canonical files:

| File | Purpose | Required? |
|---|---|---|
| `{Feature}Service.php` | Business logic + DB queries. Public methods are the feature's API. | Yes |
| `{Feature}Controller.php` | REST endpoints. Thin wrappers around the Service. | If the feature surfaces REST |
| `{Feature}Listener.php` | `add_action` registrations for cross-feature events. Implements `Contracts\ListenerInterface`. | If the feature reacts to other features' hooks |
| `{Feature}Cache.php` | `wp_cache_*` get/set/bust + invalidation hook registrations. | If any hot read benefits from caching |

Examples in tree today:

```
includes/
  Feed/
    FeedService.php
    PostService.php
    PostController.php
    PollController.php
    ...
  SocialGraph/
    FollowService.php
    ConnectionService.php
    BlockService.php
    FollowController.php
    ...
  Profile/
    ProfileService.php
    MemberDirectoryService.php
    ProfileController.php
  Spaces/
    SpaceService.php
    SpaceMemberService.php
    SpaceController.php
  Notifications/
    NotificationService.php
    NotificationListener.php
    NotificationController.php
  Moderation/
    ModerationService.php
    ModerationListener.php
    ModerationController.php
  Outbound/
    OutboundWebhookService.php
    OutboundWebhookListener.php
    OutboundWebhookController.php
  Sidebar/                    <- NEW: introduces the Cache file pattern
    WidgetService.php
    WidgetCache.php
```

Rules:
- Service knows nothing about templates. Templates know about the Service (Layer 3 → Layer 2 only).
- Listener never holds state. It only registers hooks and delegates to the Service.
- Cache is owned by the Feature, not by Core. Cache key prefixes match the feature folder name (lowercased).

### Layer 3 — UI components

- `templates/parts/*.php` — reusable partials with `do_action('buddynext_part_{name}_before|_after')` + `apply_filters('buddynext_part_{name}_args', $args)` per the contract in `docs/specs/TEMPLATE-PARTS.md`.
- `assets/css/bn-{feature}.css` — one stylesheet per feature. Token-only (`--bn-*`). Registered in `AssetService::register_assets()`.
- `assets/js/{feature}/store.js` — Interactivity API ES module. Registered as a Script Module in `AssetService::register_script_modules()`.

### Layer 4 — Composition

Hub templates (`templates/{hub}/index.php` or `templates/{hub}/{view}.php`):
- Do not render their own chrome. The shell provides topbar + rail + main + auto-detected right sidebar.
- Compose Layer 3 partials + call Layer 2 services for data.
- Register sidebar widgets via `add_action('buddynext_right_sidebar', ...)` — the shell auto-renders the column.
- Fire `do_action('buddynext_{hub}_before|_after')` at top/bottom for extension.

## How to add a new feature

1. Create `includes/{NewFeature}/` folder.
2. Write `Service.php`. Public methods are the feature API. Pure functions where possible; inject `$wpdb` / dependencies via constructor.
3. If the feature is REST-facing: write `Controller.php`. Register in `REST/Router.php`.
4. If the feature reacts to other features: write `Listener.php` implementing `Contracts\ListenerInterface`. Wire in `Plugin::init()`.
5. If the feature has hot reads: write `Cache.php` with key constants, get/set wrappers, and `add_action` invalidation hooks. Wire registration in `Plugin::init()`.
6. If the feature has a UI:
   - Add `assets/css/bn-{feature}.css` + register in AssetService.
   - Add `assets/js/{feature}/store.js` if interactive.
   - Add `templates/parts/{name}.php` for reusable bits.
7. If the feature renders a hub view, add the inner template in `templates/{hub}/`.
8. Document the public hooks + filters in `docs/specs/HOOKS.md`.
9. Document any new tables in `docs/specs/features/19-database-scale.md`.
10. Write PHPUnit tests at `tests/{Feature}/`.

## Cross-feature communication

Features never call each other directly. They communicate via:

- **Hooks** — Feature A fires `do_action('buddynext_x_happened', ...)`. Feature B listens via its Listener.
- **Filters** — Feature A applies `apply_filters('buddynext_x_data', ...)`. Feature B hooks to modify.
- **Container resolution** — `buddynext_service('x')` to get Feature X's service.
- **REST** — Feature A calls Feature B's REST endpoint (rarely needed; most cases use a Service lookup).

If two features tightly couple, that's a sign they should merge OR a third feature should mediate.

## Cross-layer rules

- Layer 0 (Core) never imports from Layer 2+.
- Layer 1 (Bridges) imports from Layer 2 (calls Feature services).
- Layer 2 (Features) imports from Layer 0 (Core) and other Layer 2 via Listeners/hooks only.
- Layer 3 (UI) imports from Layer 2 (calls services to fetch data).
- Layer 4 (Hub templates) imports from Layer 3 (parts) + Layer 2 (services).

Static analysis check: a Service must NOT contain `<?php echo` template-rendering output. A template must NOT contain `$wpdb->get_results()`. If you find one violating, file it as a refactor task.

## Caching contract

Every Feature with a hot read has a `{Feature}Cache.php` companion. The cache:

- Defines cache group constants (e.g., `const GROUP = 'buddynext_sidebar_widgets'`).
- Exposes `get( $key, callable $miss )` / `set( $key, $value, $ttl )` / `delete( $key )` wrappers around `wp_cache_*`.
- Registers cache-bust hooks on relevant events (e.g., `add_action( 'buddynext_post_created', [ $this, 'invalidate_trending' ] )`).
- TTL constants live as class consts (e.g., `const TTL_TRENDING = 60`).

This makes cache invalidation reviewable as PHP code, not as scattered `wp_cache_delete` calls in random handlers.

## The contract in one sentence

**Core stays small. Features are self-contained folders with one canonical file each (Service / Controller / Listener / Cache). UI consumes Features. Hub templates compose UI. Nothing reaches across layers without a hook or a filter.**

## Plug-and-play model

Every Layer 2 feature is opt-out via a filter. Minimal mode = Core only + Bridges. Full mode = every feature enabled. Mid-range = pick the features that matter for the deployment.

### How a feature opts in / out

Each feature checks a filter before binding to the container:

```php
// In Plugin::register_services()
if ( apply_filters( 'buddynext_feature_sidebar', true ) ) {
    $container->bind( 'sidebar_cache', fn() => new \BuddyNext\Sidebar\WidgetCache() );
    $container->bind( 'sidebar_widgets', fn( $c ) => new \BuddyNext\Sidebar\WidgetService( $c->get( 'sidebar_cache' ) ) );
}
```

And the listener is gated on the container binding:

```php
// In Plugin::init()
if ( $container->has( 'sidebar_widgets' ) ) {
    ( new \BuddyNext\Sidebar\WidgetListener( $container->get( 'sidebar_cache' ) ) )->register();
}
```

Templates use the `Container::has()` check (Layer 3 → Layer 2) and degrade gracefully:

```php
$widgets = function_exists( 'buddynext_service' ) && \BuddyNext\Core\Container::instance()->has( 'sidebar_widgets' )
    ? buddynext_service( 'sidebar_widgets' )
    : null;
if ( null !== $widgets ) {
    $trending = $widgets->trending_hashtags( 5 );
} else {
    $trending = array(); // Feature disabled — graceful degradation.
}
```

### Disabling a feature

Site-owner can toggle via a Settings UI (Settings → Features). Code:

```php
add_filter( 'buddynext_feature_sidebar', '__return_false' );      // disable sidebar widgets
add_filter( 'buddynext_feature_hashtags', '__return_false' );     // disable hashtag indexing
add_filter( 'buddynext_feature_reactions', '__return_false' );    // disable post reactions
```

Filter naming: `buddynext_feature_{folder-lowercase}`. Default `true`.

### Minimal-mode contract

The "Core only + Bridges" baseline must produce a working community:
- Login / register / auth flow
- Posts (create / view / delete)
- Direct follow (no suggestions widget)
- Spaces (create / join / view)
- Basic notifications

Every other feature is enrichment. If disabling feature X breaks login/posting/spaces, X is wrongly placed in Layer 2 — promote it to Core.

## Extension approach

The architecture is extension-ready by design. Pro / bridges / child themes / third-party plugins extend through one of seven canonical surfaces:

### 1. New Feature module

Drop a folder under `includes/{NewFeature}/`. Bind in `Plugin::register_services()`. The feature is now plug-and-play with its own filter and Container key. Other features see it via `$container->has('newfeature')`. Documented in `docs/specs/HOOKS.md`.

### 2. Hooks + filters

Every Feature Service fires events at write boundaries (`do_action('buddynext_post_created', ...)`) and applies filters at read boundaries (`apply_filters('buddynext_feed_order_by', ...)`). Pro extends via these without modifying core. Free seams shipped to date: `buddynext_feed_order_by`, `buddynext_profile_field_render`, `buddynext_profile_field_validate`, `buddynext_search_query_args`, `buddynext_mod_queue_columns`, `buddynext_mod_queue_row_actions`, plus the action-only emissions on every domain event.

### 3. Container rebinding (Pro upscale)

Pro can rebind a service to a subclass with extended behaviour:

```php
// Pro: AiRankedFeedService extends FeedService
$container->bind( 'feed', static function () { return new AiRankedFeedService(); } );
```

The shape (method signatures) of the parent must not break. Pro's tests verify this.

### 4. Template parts override

Themes override partials by copying to `{theme}/buddynext/parts/{name}.php`. The `buddynext_get_template()` helper checks theme path first, plugin path second.

### 5. Right-sidebar action

`do_action('buddynext_right_sidebar', $hub)` fires inside the shell's right column. Any plugin can hook this to inject widget content. The shell auto-detects via `has_action()` and renders the column when anything is hooked.

### 6. Hub-level before/after hooks

Every hub template fires `do_action('buddynext_{hub}_before')` and `do_action('buddynext_{hub}_after')` bracketing its main content. Pro / bridges can inject content above or below without touching the hub template.

### 7. REST namespace separation

Free: `buddynext/v1`. Pro: `buddynext-pro/v1`. Bridges: their own namespace (`mvs/v1`, `jetonomy/v1`, etc.). Cross-namespace calls are explicit and versioned — never assume sibling-namespace stability.

## Adding a new layer (when the model needs to grow)

If a new layer is justified (e.g., a Layer 0.5 for cross-feature middleware), it lands above the layer it extends and:

1. Declares its own folder convention.
2. Documents its dependency arrow (which layers can it call, which can call it).
3. Has a canonical example feature obeying it.
4. Updates this document and the dependency diagram.

The 5-layer model holds today (Core / Bridges / Features / UI / Composition). Don't add layers casually — most new things fit Layer 2.

---

**Last reviewed**: 2026-05-21. Owner: BN core team. Update when the layer model changes.
