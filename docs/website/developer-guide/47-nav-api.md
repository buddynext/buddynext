# Navigation API: add menus and tabs

How to add a menu item or a tab to BuddyNext from an addon or a theme. BuddyNext has two distinct navigation systems and they take different APIs: the declarative Nav registry that owns member-profile and space tabs, and the global left-rail filter. This page covers both, names the seams, and gives a working recipe for each.

![A member profile whose primary tabs are resolved through the Nav registry documented here](../images/member-profile.webp)

> **Runnable, tested snippets:** every recipe below has a copy-paste, live-verified version in [`buddynext/buddynext-snippets`](https://github.com/buddynext/buddynext-snippets) under `navigation/` (`add-profile-tab.php`, `add-space-tab.php`, `add-rail-item.php`, `relabel-remove-nav.php`). Drop one in `wp-content/mu-plugins/` and it works as-is.
>
> **Current API (important):** a profile OR space tab is a **single** `$registry->register([...])` call that carries both a lazy `url` and a **`render` callable** - `PanelRenderer` server-renders only the active tab's panel. The older two-step approach (`buddynext_profile_tab_panel_open()`/`_close()` helpers + a `buddynext_part_profile_tab_panel_after` action) has been **removed**; use the `render` key shown in the snippets.
>
> **Reorder / relabel and the admin overrides (tested):** `buddynext_nav_move()` and `buddynext_nav_set()` in a `buddynext_nav_items` filter DO reorder and relabel - verified live (e.g. `buddynext_nav_move( $items, 'about', array( 'priority' => 1 ) )` moves About to the front). The one precedence rule to know: if the **site owner** has relabeled or reordered a specific tab in the admin **Navigation** screen, that saved setting wins for that tab (the admin overrides apply at priority 20 - the intended precedence). Your filter change is honoured for every tab the owner has not customised. So a `nav_set`/`nav_move` that "does nothing" almost always means the owner has already pinned that tab in the admin.

## Overview / Contract

There are two navigation systems. Use the right one for the surface you are extending.

| System | Surfaces it owns | The seam | Where it renders |
| --- | --- | --- | --- |
| Nav registry (declarative, gated) | member-profile tabs, space tabs | `buddynext_register_nav` action -> `$registry->register([...])` | `templates/profile/view.php`, `templates/spaces/home.php`, `templates/parts/space-header.php` |
| Left rail (plain array) | the persistent global left-rail column | `buddynext_rail_items` filter | `templates/shell/rail.php` |

The registry is the modern, gated, ordered system: every item declares a capability and condition, the registry validates and orders it, and one renderer draws it. It is defined in `includes/Nav/` (`NavRegistry.php`, `NavItem.php`, `NavContext.php`, `ResolvedNav.php`, `PanelRenderer.php`, plus the core providers in `includes/Nav/Providers/`). A template resolves a surface with `buddynext_nav()` (defined in `buddynext.php:437`):

```php
$nav = buddynext_nav( new \BuddyNext\Nav\NavContext( 'profile', $user_id, $viewer_id ) );
// $nav is a ResolvedNav: $nav->layer( 'primary' ), $nav->layer( 'metric' ), $nav->has( 'metric' ).
```

### Gotcha: the registry declares more layers and surfaces than it uses

`NavItem::LAYERS` declares four layers (`primary`, `metric`, `rail`, `context`) and `NavItem::SURFACES` declares three surfaces (`global`, `profile`, `space`). In the live code, only two surfaces are ever resolved - `profile` and `space` - and only the `primary` and `metric` layers are read by a renderer. No caller constructs a `NavContext` with surface `global`, and no template reads `ResolvedNav::layer( 'rail' )` or `layer( 'context' )`.

The practical consequence: **do not try to register a `global`/`rail`/`context` item through the Nav registry expecting it to appear in the left rail. The global left rail is driven exclusively by the `buddynext_rail_items` filter.** Register rail links there, register profile and space tabs through the registry.

## Recipe: add a left-rail menu item

The left rail is the persistent vertical column in the hub shell. Add a link with the `buddynext_rail_items` filter. Each item is a plain array; the supported keys (from `templates/shell/rail.php`) are:

| Key | Type | Purpose |
| --- | --- | --- |
| `key` | `string` | Unique id. Drives active-state matching against the current hub. Required. |
| `label` | `string` | Already-translated link text. Required. |
| `url` | `string` | Destination URL. Required. |
| `icon` | `string` | A BuddyNext icon slug from `assets/icons/` (for example `list`, `bookmark`), NOT a raw `<svg>`. |
| `show` | `bool` | Must be truthy or the item is skipped. |
| `badge` | `int` | Optional unread count; renders a pill (clamped to `99+`). |
| `active` | `bool` | Optional. Force the highlighted state for a surface outside BuddyNext's own hubs (for example a bridged forum). |
| `group` | `string` | Optional. `'you'` places the item in the personal "You" section at the foot of the rail; otherwise it sits in the top community group. |
| `order` | `int` | Optional sort weight. The "You" group uses 200+. |

```php
add_filter( 'buddynext_rail_items', static function ( array $items ): array {
    $items[] = array(
        'key'   => 'leaderboard',
        'label' => __( 'Leaderboard', 'my-addon' ),
        'url'   => home_url( '/leaderboard/' ),
        'icon'  => 'list',
        'show'  => true,
    );
    return $items;
} );
```

The filter passes a second argument, the current hub slug: `apply_filters( 'buddynext_rail_items', $items, $hub )`. The admin Navigation overrides (hide / relabel / reorder / capability-gate, plus admin-created custom tabs) are applied by `BuddyNext\Nav\NavOverrides::apply_rail()`, hooked at **priority 20** - so register your item at the default priority and the site owner's overrides still win. `JetonomyBridge::inject_discussions_nav_item()` is a working reference for a rail item that also sets `active`, `group`, and `order`.

## Recipe: add a member-profile tab

Profile tabs are clean-URL tabs (`/members/{slug}/{tab}/`): the profile surface server-renders only the *active* tab's panel through `PanelRenderer` (`includes/Nav/PanelRenderer.php`), so cost stays flat no matter how many integrations add tabs. Adding one is a single `register()` call that declares both the tab's `url` (its clean route) and a `render` callable (its panel).

### 1. Register the tab

Hook `buddynext_register_nav` and call `$registry->register()` with a registration array. The validated keys (see `NavItem::from_array()` in `includes/Nav/NavItem.php`) are:

| Key | Type | Purpose |
| --- | --- | --- |
| `id` | `string` | Unique within a (surface, layer). Sanitized with `sanitize_key()`. Required. |
| `surface` | `string` | `'profile'` here. Required. |
| `layer` | `string` | `'primary'` for a content tab, `'metric'` for a hero count pill. Required. |
| `label` | `string` | Already-translated tab label. Required. |
| `url` | `string` or `callable(NavContext):string` | The tab's clean route, resolved lazily. A `primary` item needs `url` and/or `render`. |
| `render` | `callable(NavContext):void` | Echoes the tab's panel HTML (its screen). `PanelRenderer` invokes it for the active tab only. Owns its own escaping, like a template part. |
| `icon` | `string` | Lucide icon slug. |
| `count` | `int` or `callable(NavContext):int` | Badge / metric value, resolved lazily. Clamped to `>= 0`. |
| `count_label` | `callable(int $n):string` | Pluralized label for the resolved count (use `_n()` inside); overrides `label`. |
| `condition` | `callable(NavContext):bool` | Extra visibility gate, resolved against the live context. |
| `capability` | `string` | A `buddynext_can()` capability gate; space context is passed automatically on the space surface. |
| `parent` | `string` | Parent primary item id for a one-level sub-nav child. |
| `priority` | `int` | Default order, lower first. Default 50. |
| `before` / `after` | `string` | Order anchor: place before/after another item id (`after` wins if both set). |
| `hide_empty` | `bool` | Omit the item when its resolved count is 0 (only honoured when a `count` is supplied). |

```php
add_action( 'buddynext_register_nav', static function ( \BuddyNext\Nav\NavRegistry $registry ): void {
    $registry->register(
        array(
            'id'        => 'achievements',
            'surface'   => 'profile',
            'layer'     => 'primary',
            'label'     => __( 'Achievements', 'my-addon' ),
            'icon'      => 'award',
            'priority'  => 70,
            'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'achievements/',
            'condition' => static fn( \BuddyNext\Nav\NavContext $c ): bool => my_addon_has_badges( $c->subject_id ),
            'count'     => static fn( \BuddyNext\Nav\NavContext $c ): int => my_addon_badge_count( $c->subject_id ),
            'render'    => static function ( \BuddyNext\Nav\NavContext $c ): void {
                // ... your already-escaped panel markup, keyed off $c->subject_id ...
            },
        )
    );
} );
```

### 2. The `render` callable draws the panel

The `render` key in the registration array *is* the panel. It receives the same `NavContext` and echoes the panel's markup; `PanelRenderer` calls it for the active tab only (never the inactive ones), so the cost is one panel regardless of how many tabs exist. The callable owns its own escaping - the same contract as a template part - so escape everything you emit.

```php
'render' => static function ( \BuddyNext\Nav\NavContext $c ): void {
    $member_id = $c->subject_id;
    if ( $member_id <= 0 ) {
        return;
    }
    echo '<div class="my-achievements">';
    // ... your already-escaped panel markup, keyed off $member_id ...
    echo '</div>';
},
```

The older two-step approach (a `buddynext_profile_tab_panel_open()` / `buddynext_profile_tab_panel_close()` helper pair plus a `buddynext_part_profile_tab_panel_after` action) was removed once the surface moved to server-rendering only the active panel - do not use it. The canonical end-to-end example is `includes/Profile/GamificationAchievements.php` - it registers the tab (with `url` + `render`) in `register_nav()` and draws the panel in `render_panel()`, both gated on the member having gamification standing.

### NavContext: what your callables receive

Every `count`, `condition`, `count_label`, and lazy `url` callable receives a `NavContext` (`includes/Nav/NavContext.php`):

| Member | Meaning |
| --- | --- |
| `->surface` | `'profile'` or `'space'`. |
| `->subject_id` | The profile user ID, or the space ID. |
| `->viewer_id` | The current viewer's user ID (0 when logged out). |
| `->role` | The viewer's space role (`owner`/`moderator`/`member`/`''`), empty on non-space surfaces. |
| `->extra` | Free-form per-surface array for providers. |
| `->is_self()` | True when the viewer is looking at their own subject. |
| `->role_at_least( $role )` | True when the viewer holds at least the given space role (owner > moderator > member). |

## Recipe: add a space tab

Space tabs use the same `buddynext_register_nav` action and `register()` call, but with `surface => 'space'`. They work like profile tabs: each supplies a lazy `url` (a callable that builds the clean `/spaces/{slug}/{tab}/` route against the live space) and, when you want the surface to draw the panel, a `render` callable - `PanelRenderer` server-renders only the active tab's panel.

The example below is `url`-only: it links to a route you serve yourself (no `render`), so BuddyNext renders nothing for that tab and your own template owns the URL. Supply a `render` callable instead (or as well) to have the space surface draw the panel for you. See `includes/Nav/Providers/SpaceNav.php` for the core space tabs (Feed, Members, Media, About, Moderation) - each builds `/spaces/{slug}/{tab}/` and carries a `render` callable.

```php
add_action( 'buddynext_register_nav', static function ( \BuddyNext\Nav\NavRegistry $registry ): void {
    $registry->register(
        array(
            'id'        => 'leaderboard',
            'surface'   => 'space',
            'layer'     => 'primary',
            'label'     => __( 'Leaderboard', 'my-addon' ),
            'icon'      => 'list',
            'priority'  => 45,
            'url'       => static function ( \BuddyNext\Nav\NavContext $c ): string {
                return trailingslashit( \BuddyNext\Core\PageRouter::space_url( $c->subject_id ) ) . 'leaderboard/';
            },
            'condition' => static fn( \BuddyNext\Nav\NavContext $c ): bool => $c->role_at_least( 'member' ),
        )
    );
} );
```

`includes/Bridges/JetonomyBridge.php` is the dual-surface reference: its `register_nav_items()` registers a Discussions tab on **both** the profile surface (a `tab`-based reactive tab with a count badge) and the space surface (a `url`-based clean link), from one `buddynext_register_nav` handler. It is exactly the pattern to copy when your feature appears on both surfaces.

## Recipe: reorder, remove, or modify existing items

To change items the core providers (or other integrations) already registered, hook the `buddynext_nav_items` filter. It runs per surface and receives the raw registration arrays plus the `NavContext`. Three helpers (defined in `buddynext.php`) keep the mutation correct:

| Helper | Effect |
| --- | --- |
| `buddynext_nav_move( $items, $id, $anchor )` | Reposition an item. `$anchor` is one of `['before' => id]`, `['after' => id]`, `['priority' => int]`. |
| `buddynext_nav_remove( $items, $ids )` | Drop one item id or an array of ids. |
| `buddynext_nav_set( $items, $id, $changes )` | Merge field changes onto an item (relabel, re-gate, retarget). |

```php
add_filter( 'buddynext_nav_items', static function ( array $items, \BuddyNext\Nav\NavContext $ctx ): array {
    if ( 'profile' !== $ctx->surface ) {
        return $items;
    }
    $items = buddynext_nav_set( $items, 'likes', array( 'label' => __( 'Favourites', 'my-addon' ) ) );
    $items = buddynext_nav_move( $items, 'media', array( 'after' => 'posts' ) );
    $items = buddynext_nav_remove( $items, 'replies' );
    return $items;
}, 10, 2 );
```

The admin Navigation overrides (`BuddyNext\Nav\NavOverrides::apply_nav_items()`) also hook `buddynext_nav_items` at priority 20, mapping the site owner's saved hide/relabel/reorder onto the same registration arrays - so a site owner can override your additions, which is the intended precedence.

## Notes / gotchas

- **Register on `buddynext_register_nav`, not at an arbitrary time.** The registry fires this action once, lazily, the first time a surface is resolved (`NavRegistry::resolve()`), so count and condition callables see the live request. Registering outside the action is not guaranteed to be in place.
- **Ids are unique within a (surface, layer).** A duplicate `(layer, id)` registration keeps the first and calls `_doing_it_wrong()` in debug. Pick a namespaced id for your addon.
- **A `primary` item needs `url` and/or `render`; `rail`/`context` items need `url`.** An item failing its layer minimum is silently dropped by `NavItem::from_array()`, never rendered.
- **`count` is clamped to `>= 0`** and resolved lazily; a `metric` that shares an id with a top-level `primary` tab is deduped away (the tab's badge is the count's home).
- **Draw profile and space panels with the item's `render` callable.** `PanelRenderer` invokes it for the active tab only; a `primary` item with neither `url` nor `render` is dropped.
- **The left rail is the filter, the tabs are the registry.** Restating the top gotcha because it is the most common mistake: a `global`/`rail` registry item will not appear anywhere. Use `buddynext_rail_items`.

See also the template-part hook contract (Hooks: Template Parts) for the panel-after action family, and Roles and Capabilities for what a `capability` gate resolves through.
