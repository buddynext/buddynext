# Coming from BuddyPress / BuddyBoss

A translation guide for developers who already know BuddyPress or BuddyBoss. BuddyNext solves the same problems - profiles, an activity stream, groups, connections, private messaging - but it is a standalone plugin with its own architecture, its own permission model, and a REST-first contract. Nothing from the `bp_*` / `BP_*` namespace exists at runtime. This page maps the concepts you know to the BuddyNext equivalents so you can port a customization without re-learning the whole codebase. Where a deep-dive page already covers a topic (the Nav API, child-theme overrides, roles and capabilities), this page summarizes and points you there.

## The one-paragraph mental model

There is no BuddyPress to detect, depend on, or extend. BuddyNext boots itself at `plugins_loaded:15` through `BuddyNext\Core\Plugin::init()` and fires `buddynext_loaded` when it is ready. Services live in a DI container reached through `buddynext_service( 'key' )`. Permissions flow through one function, `buddynext_can()`. The frontend is 100% REST under `buddynext/v1` - there is no `admin-ajax.php` surface. Navigation, templates, and capabilities are all registered declaratively through hooks and filters, the same seams BuddyNext Pro itself uses.

## 1. Architecture and bootstrapping

BuddyPress is a component framework: you call `bp_is_active( 'groups' )` to check a component, subclass `BP_Component` to add one, and bootstrap through `bp_loaded` / `bp_init`. BuddyNext has no component framework and no global `$bp` object. Features are folders under `includes/{Feature}/`, each binding a service into the container, and feature availability is a toggle resolved by `FeatureRegistry`.

| BuddyPress / BuddyBoss | BuddyNext | Notes |
|---|---|---|
| `bp_is_active( 'groups' )` | `buddynext_feature_enabled( 'spaces' )` | Global helper (`buddynext.php`). Or `buddynext_service( 'features' )->is_enabled( $slug )` from `FeatureRegistry`. |
| `buddypress()` / global `$bp` | `buddynext_service( 'key' )` | DI container lookup. Throws on an unbound key, so guard optional features with the container's `has()`. |
| `BP_Component` subclass | A feature folder `includes/{Feature}/` + a container binding | See the Architecture Overview page for the five-layer model and the canonical feature-module shape. |
| `bp_loaded` / `bp_init` | `buddynext_loaded` | Fired once in `Plugin::init()` (`includes/Core/Plugin.php`) after all services are registered and wired. |
| `bp_core_get_user_domain()` and friends | `PageRouter::profile_url()`, `people_url()`, `spaces_url()`, ... | Always build links with the `PageRouter::*_url()` helpers; hub slugs are admin-configurable. |
| `function_exists( 'buddypress' )` guard | none needed | BuddyNext has no soft dependency to detect. Your addon depends on BuddyNext directly. |

Two boot extension seams matter, both inside `register_services()`:

- `buddynext_register_services` fires before core bindings - use it to add a brand-new service. Core bindings registered afterward win over anything you bind here.
- `buddynext_services_registered` fires after all core bindings but before anything is resolved - rebind a key here to replace a core service with a subclass. The container resolves lazily, so the late rebind takes effect.

### There is no bp-custom.php

BuddyPress auto-loads `wp-content/plugins/bp-custom.php` very early so you can register components and override functions before the suite boots. BuddyNext has no equivalent auto-loaded file. Put your customizations in a small mu-plugin or your theme's `functions.php` and attach them to BuddyNext hooks. Because every seam fires at `plugins_loaded:15` or later, hook your registrations on `plugins_loaded` (or later) and they will be in place before any check runs:

```php
// mu-plugin or theme functions.php - the bp-custom.php replacement.
add_action( 'plugins_loaded', function () {
    if ( ! function_exists( 'buddynext_service' ) ) {
        return; // BuddyNext not active.
    }
    // Register nav items, capabilities, services, template overrides here.
}, 20 );
```

## 2. Navigation

BuddyPress builds member and group nav imperatively with `bp_core_new_nav_item()` and `bp_core_new_subnav_item()`, called inside a `bp_setup_nav` callback against the global `$bp` member. BuddyNext replaces this with a declarative Nav API: every surface (profile tabs, space tabs, the left rail, sub-nav, metric pills) registers items into a single `NavRegistry`, which gates, orders, dedupes, and nests them per request. You register on the one-time `buddynext_register_nav` action (you receive the registry) or filter a resolved surface (for example `buddynext_rail_items` for the left rail).

A profile tab - before (BuddyPress) and after (BuddyNext):

```php
// BEFORE - BuddyPress
function my_profile_tab() {
    bp_core_new_nav_item( array(
        'name'                => __( 'Events', 'my-textdomain' ),
        'slug'                => 'events',
        'position'            => 80,
        'screen_function'     => 'my_events_screen',
        'default_subnav_slug' => 'events',
    ) );
}
add_action( 'bp_setup_nav', 'my_profile_tab' );
```

```php
// AFTER - BuddyNext
add_action( 'buddynext_register_nav', function ( \BuddyNext\Nav\NavRegistry $registry ) {
    $registry->register( array(
        'id'       => 'events',
        'surface'  => 'profile',                       // profile | space
        'layer'    => 'primary',                       // primary | metric | rail | context
        'label'    => __( 'Events', 'my-textdomain' ),
        'tab'      => 'events',                         // the activeTab this tab selects
        'icon'     => 'calendar',                       // an icon slug from assets/icons/
        'priority' => 80,
        // Optional: gate visibility, badge a count - both lazy callables that
        // see the live NavContext (subject_id, viewer_id, is_self(), ...).
        'condition' => fn( \BuddyNext\Nav\NavContext $c ): bool => $c->is_self(),
        'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => my_event_count( $c->subject_id ),
    ) );
} );
```

A left-rail menu item - filter the resolved rail instead of registering on a surface:

```php
// AFTER - BuddyNext: inject a left-rail link.
add_filter( 'buddynext_rail_items', function ( array $items, string $hub ): array {
    $items[] = array(
        'id'       => 'help-center',
        'label'    => __( 'Help Center', 'my-textdomain' ),
        'icon'     => 'help-circle',
        'url'      => home_url( '/help/' ),
        'priority' => 90,
    );
    return $items;
}, 10, 2 );
```

Order is deterministic: items sort by `priority` then registration order, with optional `before` / `after` anchors against another item's `id`. Sub-nav is expressed by giving a child item a `parent` set to the parent's `id` (see `includes/Nav/Providers/ProfileNav.php` for the built-in `Network` tab with `Connections` / `Followers` / `Following` children). For the full item contract, the four layers, gating, count resolution, and the `buddynext_nav_items` / `buddynext_context_nav` filters, see the Nav API page.

## 3. Template overrides

BuddyPress ships templates under `bp-templates/`, resolved through `bp_get_template_part()` and the template stack so a theme can override any file from its own `buddypress/` directory. BuddyNext keeps the same theme-override idea with a three-tier loader (`BuddyNext\Core\TemplateLoader`) and the `buddynext_get_template()` helper.

| BuddyPress / BuddyBoss | BuddyNext |
|---|---|
| `bp_get_template_part( 'members/single/home' )` | `buddynext_get_template( 'feed/home.php', $vars )` |
| `{theme}/buddypress/{relative}.php` override | `{child-theme}/buddynext/{relative}.php` override |
| `bp_locate_template()` stack | `TemplateLoader::locate()` three-tier resolution |
| `bp_before_member_body` etc. template hooks | `buddynext_before_template` / `buddynext_after_template` (fired around every template) |

The resolution order is:

```text
1. {active-child-theme}/buddynext/{relative}.php
2. {parent-theme}/buddynext/{relative}.php
3. {plugin}/templates/{relative}.php   (the default)
```

So to override the home feed, copy `templates/feed/home.php` from the plugin to `{your-child-theme}/buddynext/feed/home.php` and edit the copy. Templates receive their data as pre-extracted variables (the loader imports only valid identifier keys) and must never run `$wpdb` queries - data comes from a service. For the full override workflow, the variable contract, and which templates are safe to override, see the Child Theme Template Overrides page.

A copied template is frozen at copy time — if BuddyNext's template markup changes in a future release, your override keeps the old HTML and drifts out of sync. For safer, update-compatible customizations that operate on the rendered output rather than replacing the source file, use the `buddynext_template_output` filter — see the *Transform rendered output with a filter* section of the Child Theme Overrides page. This approach also enables use of the WP HTML API for structural changes that would be fragile or impossible with string regex.

## 4. REST API

BuddyPress exposes routes under `buddypress/v1` and still leans on `admin-ajax.php` for much of its frontend. BuddyNext is REST-first and admin-ajax-free: every template, frontend script, admin script, and block view-script talks to `buddynext/v1` (free) or `buddynext-pro/v1` (pro). A CI gate (`bin/check-rest-boundary.sh`) enforces that no `wp_ajax_*` handler, `ajaxurl`, or `check_ajax_referer()` is used on the frontend.

| Concept | BuddyPress route | BuddyNext route(s) |
|---|---|---|
| Activity stream | `buddypress/v1/activity` | `buddynext/v1/feed/home`, `feed/explore`, `feed/counts`; CRUD on `buddynext/v1/posts`, `posts/{id}` |
| Members directory | `buddypress/v1/members` | `buddynext/v1/members`; `buddynext/v1/search/members` |
| Member relationship state | (friends component) | `buddynext/v1/users/{id}/follow/status`, `connect`, `block`, `account-type` |
| XProfile fields | `buddypress/v1/xprofile/fields` | `buddynext/v1/profile-fields`, `profile-fields/{id}`, `profile-groups`; `buddynext/v1/me/profile` |
| Groups | `buddypress/v1/groups` | `buddynext/v1/spaces`, `spaces/{id}`, `spaces/{id}/members`, `space-categories` |
| Private messages | `buddypress/v1/messages` | No `buddynext/v1` DM namespace - DM is the WPMediaVerse engine (see section 7) |
| Notifications | `buddypress/v1/notifications` | `buddynext/v1/me/notifications`, `me/notifications/{id}/read`, `me/notification-prefs` |
| Reactions / comments | (activity favorites / comments) | `buddynext/v1/reactions`, `reactions/toggle`; `buddynext/v1/comments`, `comments/{id}` |
| Hashtags | (no core equivalent) | `buddynext/v1/hashtags/{slug}`, `hashtags/trending`, `hashtags/autocomplete` |

Auth is the standard logged-in WordPress REST pattern: cookie plus the `wp_rest` nonce sent in the `X-WP-Nonce` header. There are no per-action nonce names and the nonce never travels in the query string. External (non-browser) integrations authenticate with Application Passwords over HTTP Basic. Every route declares a `permission_callback` and most reads/writes resolve `buddynext_can()` for authorization. The success body is the resource payload directly (no `success`/`data` wrapper); errors are a `WP_Error` carrying `code`, `message`, and `data.status`. For the full envelope, pagination model (cursor for timelines, page-numbered for bounded lists), and ceilings, see the REST Contract page and the per-resource REST pages.

## 5. Capabilities

BuddyPress authorizes with `bp_current_user_can()` plus WordPress role capabilities (and BuddyBoss layers its own member-type and moderation caps on top). BuddyNext registers no custom WordPress roles. Every authorization decision flows through one function:

```php
buddynext_can( int $user_id, string $capability, array $context = array() ): bool
```

| BuddyPress / BuddyBoss | BuddyNext |
|---|---|
| `bp_current_user_can( 'bp_moderate' )` | `buddynext_can( get_current_user_id(), 'buddynext-moderation/review-queue' )` |
| Group role check (`groups_is_user_admin()`) | `buddynext_can( $uid, 'buddynext-spaces/manage-settings', array( 'space_id' => $id ) )` |
| `add_role()` / mapped WP caps | Community role in user meta (`bn_community_role`) + per-space role in `bn_space_members` |
| Member types as roles | A community role is one of `member`, `moderator`, `admin`, `owner` (a hierarchy); member types (section 6) are a separate labeling system |

Never call `current_user_can()` against a BuddyNext capability or read `bn_community_role` directly - route it through `buddynext_can()` so all four resolution layers (WP admin, role map, explicit grant, the `buddynext_user_can` filter) and the space-ban short-circuit apply.

The free catalog holds 21 capabilities registered through the WordPress Abilities API (WP 6.9+), plus two space-scoped ones resolved by dedicated per-space methods:

```text
buddynext-profile/edit-own        buddynext-spaces/create          buddynext-connections/follow
buddynext-profile/edit-any        buddynext-spaces/join            buddynext-connections/connect
buddynext-profile/view            buddynext-spaces/join-gated      buddynext-moderation/report
buddynext-feed/create-post        buddynext-spaces/post            buddynext-moderation/review-queue
buddynext-feed/delete-own-post    buddynext-spaces/moderate        buddynext-moderation/issue-strike
buddynext-feed/delete-any-post    buddynext-spaces/manage-settings buddynext-moderation/suspend-user
buddynext-feed/pin-post           buddynext-spaces/delete
buddynext-feed/schedule-post
                                  (space-scoped, per-space methods)  buddynext-moderate-space
                                                                     buddynext-manage-space
```

To add a capability you filter `buddynext_abilities` (register the slug) and `buddynext_role_map` (give it a minimum role); to override one decision you filter `buddynext_user_can`. For the four-layer resolution model, the role hierarchy, explicit grants, and the filter seams, see the Roles and Capabilities page.

## 6. Hooks cheat-sheet

The action you hooked in BuddyPress has a BuddyNext counterpart with a different name and, in several cases, a different argument order. The signatures below are verified against the live source (`do_action` call sites in `includes/`); `audit/manifest.json` is the authoritative inventory of every hook the plugin fires. Trust these over any older curated list.

| BuddyPress / BuddyBoss | BuddyNext | Signature (verified) |
|---|---|---|
| `bp_activity_add` / `bp_activity_posted_update` | `buddynext_post_created` | `( $post_id, $user_id, $type )` |
| `bp_activity_deleted_activities` | `buddynext_post_deleted` | `( $post_id, $user_id )` |
| `friends_friendship_requested` | `buddynext_connection_requested` | `( $connection_id, $requester_id, $recipient_id, $note )` |
| `friends_friendship_accepted` | `buddynext_connection_accepted` | `( $connection_id, $requester_id, $recipient_id )` |
| `friends_friendship_rejected` | `buddynext_connection_declined` | `( $connection_id, $requester_id, $recipient_id )` |
| `friends_friendship_withdrawn` | `buddynext_connection_withdrawn` | `( $connection_id, $requester_id, $recipient_id )` |
| `friends_friendship_deleted` | `buddynext_connection_removed` | `( $connection_id, ... )` |
| (follow add-on) | `buddynext_user_followed` | `( $follower_id, $following_id )` |
| (follow add-on) | `buddynext_user_unfollowed` | `( $follower_id, $following_id )` |
| `groups_create_group` | `buddynext_space_created` | `( $space_id, $owner_id )` |
| `groups_join_group` | `buddynext_space_member_joined` | `( $space_id, $user_id, $role )` |
| `groups_leave_group` | `buddynext_space_member_left` | `( $space_id, $user_id )` |
| `groups_remove_member` | `buddynext_space_member_removed` | `( $space_id, $user_id, $removed_by )` |
| `bp_set_member_type` | `buddynext_member_type_assigned` | `( $user_id, $new_slug, $old_slug )` |
| `bp_remove_member_type` | `buddynext_member_type_removed` | `( $user_id, $removed_slug )` |
| (activity favorite) | `buddynext_reaction_added` | `( $object_type, $object_id, $user_id, $emoji )` |
| `bp_activity_comment_posted` | `buddynext_comment_created` | `( $comment_id, $object_type, $object_id, $user_id )` |
| `bp_moderation` report flow | `buddynext_report_created` | `( $report_id, $object_type, $object_id, $reporter_id )` |

Watch the argument order: several BuddyNext space and engagement hooks lead with the object id, not the user id. `buddynext_space_member_joined` is `( $space_id, $user_id, $role )`, `buddynext_reaction_added` is `( $object_type, $object_id, ... )`, and `buddynext_comment_created` / `buddynext_report_created` both carry an `$object_type` + `$object_id` pair (reactions and comments are polymorphic across posts and other objects). There are also member-type lifecycle hooks with no BuddyPress equivalent - `buddynext_member_type_created` and `buddynext_member_type_deleted`. The Hooks Overview and per-domain hooks pages list the complete set.

## 7. Where DM, forums, and gamification live (the companion model)

BuddyPress bundles messaging and leans on bbPress for forums and third-party plugins for points. BuddyNext keeps its core lean and delegates these to first-party companion plugins, each integrated through a Layer 1 bridge (`includes/Bridges/`). The bridge boots on the `buddynext_load_bridges` seam at `plugins_loaded:25`, gated on its feature toggle and self-guarded by `class_exists()`, so BuddyNext degrades gracefully when a companion is absent.

| Capability | Companion plugin | Where the engine lives |
|---|---|---|
| Direct messaging | WPMediaVerse | `WPMediaVerseBridge`. DM tables (`mvs_conversations`, `mvs_messages`, ...) and the DM REST engine belong to WPMediaVerse; BuddyNext is the UI layer only. There is no `buddynext/v1` DM namespace. |
| Forums / discussions | Jetonomy | `JetonomyBridge`. Surfaces a `Discussions` tab on the profile and space surfaces (via `buddynext_register_nav`) and a left-rail item (via `buddynext_rail_items`); Jetonomy owns the `jt_*` data. |
| Points, badges, levels | WB Gamification | `GamificationBridge`. Listens to WB Gamification award/level hooks and reflects them into BuddyNext (for example the profile `Achievements` tab). |

If you are porting a customization that touched BuddyPress messages, bbPress, or a points plugin, target the companion's own API for the data and use the relevant BuddyNext bridge hook for the integration touch-point. For how a bridge normalizes external data into BuddyNext and the full list of bridges (including the Pro-tier ones), see the Integration Bridges page; for how companions are installed, see The Companion Install Model.

## Notes and gotchas

- **No `$bp` global, no component detection.** Your addon depends on BuddyNext directly. Guard only on `function_exists( 'buddynext_service' )` if you ship a plugin that can run without it.
- **Build links with `PageRouter::*_url()`.** Hub slugs are admin-configurable; a hand-rolled `/members/{id}/` path can break on a renamed slug.
- **Hook on `plugins_loaded` or later.** Every seam (`buddynext_register_nav`, `buddynext_abilities`, the service seams) fires at `plugins_loaded:15`+, so register there. There is no early `bp-custom.php` load step.
- **Argument order changed.** Re-check every space, reaction, comment, and report hook signature against the table above before porting a listener - a wrong `accepted_args` or positional assumption fails silently.
- **Frontend is REST, not ajax.** Do not add a `wp_ajax_*` handler for a frontend feature; the REST boundary is CI-gated. Register a route under your own namespace or extend through the documented seams.
