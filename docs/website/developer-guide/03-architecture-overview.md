# Architecture Overview

This page is the map of how BuddyNext is put together: the five layers, the order they boot in, the canonical shape of a feature module, the DI container, and the PageRouter hub lifecycle. Read it before adding a feature, a bridge, or an admin page - every later chapter assumes this model.

![The admin dashboard that the five-layer architecture and PageRouter hub lifecycle assemble](../images/admin-overview.png)

![A rendered feed hub - the canonical output of a feature module booted through the container and router](../images/community-activity-feed.png)

BuddyNext is namespaced `BuddyNext\*` (free) / `BuddyNextPro\*` (pro), autoloaded PSR-4 from `includes/`. It boots at `plugins_loaded:15` via `BuddyNext\Core\Plugin::init()`.

## The layered model

Every feature is grouped by component and core. Cross-layer dependencies only point downward (a higher layer may call a lower one; never the reverse). The arrows below show the allowed direction.

```text
+----------------------------------------------------------------------+
|  LAYER 4  Composition (hub templates)                                |
|   templates/{hub}/index.php  composes Layer 3 + Layer 2              |
|   the active theme owns get_header()/get_footer();                  |
|   the shell owns the .bn-app canvas; the inner template owns content |
+----------------------------------------------------------------------+
                              ^  calls UI parts + services
+----------------------------------------------------------------------+
|  LAYER 3  UI components                                              |
|   templates/parts/*.php        reusable partials (hook + filter)     |
|   assets/css/bn-{feature}.css  one stylesheet per feature            |
|   assets/js/{feature}/store.js Interactivity API store               |
+----------------------------------------------------------------------+
                              ^  fetches data from services
+----------------------------------------------------------------------+
|  LAYER 2  Feature modules  (includes/{Feature}/)                     |
|   {Feature}Service.php     business logic + DB                       |
|   {Feature}Controller.php  REST endpoints (thin)                     |
|   {Feature}Listener.php    WordPress action hooks                    |
|   {Feature}Cache.php       cache layer + bust hooks                  |
+----------------------------------------------------------------------+
                              ^  uses Core services, hooks between features
+----------------------------------------------------------------------+
|  LAYER 1  Bridges (cross-plugin adapters)  (includes/Bridges/)       |
|   {Plugin}Bridge.php          adapter                                |
|   {Plugin}BridgeListener.php  hook registrar                         |
+----------------------------------------------------------------------+
                              ^  calls feature services to normalize data
+----------------------------------------------------------------------+
|  LAYER 0  Core  (includes/Core/)                                     |
|   Plugin.php       bootstrap + container wiring                      |
|   Container.php    DI container                                      |
|   Installer.php    DB schema                                         |
|   AssetService.php asset registration                                |
|   PageRouter.php   hub routing + shell                               |
|   PermissionService.php  buddynext_can()                             |
|   IconService.php  SVG icon rendering                                |
|   FeatureRegistry.php  feature tier resolution                       |
+----------------------------------------------------------------------+
```

### Cross-layer rules

- Layer 0 (Core) never imports from Layer 2+. It is single-instance services used by everything above it.
- Layer 1 (Bridges) calls Layer 2 feature services to normalize external-plugin data into BuddyNext.
- Layer 2 (Features) imports Layer 0, and reaches other Layer 2 features only through hooks/filters or a container lookup - never a direct class call.
- Layer 3 (UI) calls Layer 2 services to fetch data. A template must never run `$wpdb->get_results()`.
- Layer 4 (Composition) composes Layer 3 parts and calls Layer 2 services. It renders no chrome of its own.

The one-sentence contract: Core stays small; features are self-contained folders with one canonical file each; UI consumes features; hub templates compose UI; nothing reaches across layers without a hook or a filter.

## The feature module shape (Layer 2)

Each feature is a folder under `includes/{Feature}/` with up to four canonical files. Only the Service is always required.

| File | Purpose | Required |
|---|---|---|
| `{Feature}Service.php` | Business logic + DB queries. Public methods are the feature's API. | Yes |
| `{Feature}Controller.php` | REST endpoints. Thin wrappers around the Service; extends `REST/BaseRestController`. | If the feature surfaces REST |
| `{Feature}Listener.php` | `add_action` registrations for cross-feature events. Implements `Contracts\ListenerInterface`. | If the feature reacts to other features' events |
| `{Feature}Cache.php` | `wp_cache_*` get/set/bust plus invalidation-hook registration. | If a hot read benefits from caching |

Rules that hold for every module:

- The Service knows nothing about templates and never echoes output.
- The Listener holds no state. It registers hooks and delegates to the Service. Its entry point is `register()`, not `init()`, and it implements `BuddyNext\Contracts\ListenerInterface`.
- The Cache is owned by the feature, not Core. Its cache-group constants and TTL constants live as class consts, so invalidation is reviewable as PHP, not scattered `wp_cache_delete()` calls.

Tests mirror the source tree: `includes/Feed/PostController.php` is tested by `tests/Feed/PostControllerTest.php`.

## The DI container

`BuddyNext\Core\Container` is a small singleton service locator. It stores factory callbacks and resolves each key once (singleton), caching the result. Rebinding a key clears its cached instance.

```php
// Bind a factory (receives the container, returns the service).
$container->bind( 'feed', fn( $c ) => new FeedService(
    $c->get( 'follows' ),
    $c->get( 'post_service' ),
    $c->get( 'feed_cache' )
) );

// Resolve (cached after first call).
$feed = $container->get( 'feed' );

// Plug-and-play guard: is a feature's service bound?
if ( $container->has( 'sidebar_widgets' ) ) { /* feature is enabled */ }

// Global helper used everywhere outside the bootstrap.
$feed = buddynext_service( 'feed' );
```

`Container::get()` throws a `RuntimeException` for an unbound key, so resolving an optional feature's service must be guarded by `Container::has()`. The named keys you resolve are listed in the Namespaces and Layout page.

## Bootstrap order

BuddyNext's own boot runs inside `Plugin::init()`. The plugin-family boot order around it is:

```text
plugins_loaded:10  first-party addons init in standalone mode
                   (WPMediaVerse, Jetonomy, WBGamification, Career Board)
plugins_loaded:15  BuddyNext\Core\Plugin::init()
                     1. Container::instance()
                     2. register_services( $container )
                          - fires buddynext_register_services (pre-bind seam)
                          - binds every core + feature service
                          - fires buddynext_services_registered (rebind seam)
                          - abilities->register()  (WP Abilities API)
                     3. wires listeners, REST router, assets, blocks,
                        cron, shortcodes, widgets, PWA, tokens, nav
                     4. registers PageRouter (rewrites + dispatch)
                     5. schedules bridge boot on plugins_loaded:25
                     6. fires buddynext_loaded
plugins_loaded:20  BuddyNext Pro::init() hooks in (extends free)
plugins_loaded:25  do_action( 'buddynext_load_bridges' )
                     - BuddyXBridge (always; theme bridge)
                     - WPMediaVerseBridge / GamificationBridge /
                       JetonomyBridge  (each gated on its feature toggle
                       + self-guarded by class_exists)
```

Addons initialize before BuddyNext so the bridge classes can inspect their state and set deferral flags before any `init` hook fires. Pro hooks `buddynext_loaded` and `buddynext_services_registered` to extend or rebind free services. Bridges run last (`:25`) so they fire after both free (`:15`) and Pro-tier partner plugins (`:20`).

Two extension seams matter inside `register_services()`:

- `buddynext_register_services` fires before the core bindings, so a binding added here is overridden by core. Use it to add a brand-new service.
- `buddynext_services_registered` fires after all core bindings but before anything is resolved. Rebind a key here to replace a core service with a subclass (the container resolves lazily, so the late rebind wins).

## The PageRouter hub lifecycle

`BuddyNext\Core\PageRouter` maps clean URLs to virtual "hub" pages (activity, people, spaces, messages, notifications, auth, onboarding, plus a single-post permalink). No backing WordPress pages exist - the router synthesizes a virtual page so the theme renders its normal full-page frame.

`PageRouter::init()` registers rewrite rules on `init`, auto-flushes them when the rule set version changes, suppresses the default `WP_Query` for hub requests, and hooks `dispatch_hub_template()` on `template_redirect`. A request to a hub slug then runs:

```text
template_redirect -> PageRouter::dispatch_hub_template()
  1. resolve the hub slug (bn_hub query var, or the assigned static
     front page); bail if not a BuddyNext route
  2. feature + access guards (Spaces/Onboarding/Hashtags toggles,
     public-explore gate, DM availability, login-required redirects)
  3. resolve the inner template + build the template context
  4. prime a virtual WP_Post so theme template tags + body_class()
     resolve; status_header( 200 ); set the document <title>
  5. apply wp_robots indexing policy + per-profile/space noindex
  6. enqueue_hub_assets( $hub ) BEFORE wp_head fires
  7. inject body classes (bn-page, bn-hub-{slug}, no-sidebar) and the
     data-bn-density attribute via language_attributes
  8. do_action( 'buddynext_before_hub', $hub, $template )
  9. render_shell_with_theme_chrome():
        get_header()                    <- theme emits DOCTYPE, head,
                                           wp_head, body, site header/nav
        buddynext_get_template( shell ) <- shell/hub-shell.php (or
                                           auth-shell.php) emits .bn-app
        get_footer()                    <- theme emits footer + wp_footer
 10. exit  (WordPress never renders its own page content)
```

Key points for developers building on the router:

- The active theme's `get_header()` IS the top navigation. BuddyNext renders no topbar of its own inside `.bn-app` - only the rail, main column, an auto-detected right sidebar, and (on mobile) the bottom tab bar. There is no shell-takeover mode and no opt-out filter; theme chrome is the only render mode.
- The shell template is chosen per hub: `shell/auth-shell.php` for the slim single-column auth and onboarding wizards, `shell/hub-shell.php` for every other hub.
- An `HX-Request` header returns only the inner template (a partial swap) instead of the full theme-wrapped document.
- The `.bn-app` canvas bursts to `100vw` in CSS so it goes edge-to-edge under any host theme, regardless of the theme's content-width container.

For URL builders (`PageRouter::activity_url()`, `people_url()`, `spaces_url()`, and so on) and the rewrite-rule details, see the Routing and URLs page.

## Notes and gotchas

- Always build links with the `PageRouter::*_url()` helpers. Hub slugs are admin-configurable, so a hand-rolled path can break on a renamed slug.
- A feature whose tier lets the owner disable it must be guarded everywhere it is consumed: the container binding, the listener wiring in `Plugin::init()`, and the template (via `Container::has()`), so the feature degrades gracefully when off. See Feature Groups and Tiers.
- Pro extends free through the same seams documented here (new feature folders, hooks/filters, container rebinding, template-part overrides, the right-sidebar action, hub before/after hooks, and REST namespace separation). It never forks a free template or copies a free service.
