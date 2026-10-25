# Building an Addon

How to wrap a third-party plugin (LearnDash, a store, a forum) so its content and events show up inside the community. This page is early access: the API is stable and versioned, and the first official addon, `buddynext-learndash`, is built only from what is documented here.

## Overview / Contract

| Fact | Detail |
|---|---|
| What an addon is | A small plugin named `buddynext-<plugin>`. It holds only partner-specific code. |
| What BuddyNext does for it | The Integrations entry, the version floor and its "Update needed" badge, the owner's per-aspect switches, the wiring of each module, and a boundary that switches off a module that throws. |
| API version | `BUDDYNEXT_ADDON_API` (an integer, currently `1`). Adding is free. Changing or removing a public symbol bumps it. BuddyNext supports the current and the previous value. |
| What is public | Every class and interface in `BuddyNext\Addons` that is marked `@api`, plus every hook marked `@since`. Anything else is internal. |
| Depends on | BuddyNext Free only. |

## The shape of an addon

```php
use BuddyNext\Addons\AbstractAddon;
use BuddyNext\Addons\AddonRegistry;

final class My_Addon extends AbstractAddon {
	public function slug(): string          { return 'learndash'; }   // integration key, stable forever
	public function label(): string         { return 'LearnDash'; }
	public function partner_active(): bool  { return defined( 'LEARNDASH_VERSION' ); }
	public function partner_version(): string { return defined( 'LEARNDASH_VERSION' ) ? LEARNDASH_VERSION : ''; }
	public function min_version(): string   { return '4.10.0'; }
	public function tested_version(): string { return '4.21.0'; }

	public function modules(): array {
		return array( new Cards(), new Notices(), new CourseSearch() );
	}
}

add_action( 'buddynext_register_addons', static function ( AddonRegistry $addons ): void {
	$addons->register( new My_Addon() );
} );
```

`buddynext_register_addons` fires at `buddynext_load_bridges` (priority 20 of `plugins_loaded:25`), after the first-party bridges, so load order between BuddyNext, the partner and your addon never matters. The slug is `a-z`, `0-9`, `_`, starts with a letter, and is the key in the owner's Integrations switches.

If your addon targets a newer API than the installed BuddyNext, or the partner is below `min_version()`, the owner gets one notice and nothing fatals. Below the floor the addon still appears in Integration Settings with an "Update needed" badge.

## Modules

An addon uses only the interfaces it needs. Labels may be translated: modules are wired on `init` (priority 0), never before.

| Interface | Owner switch | You supply | BuddyNext does |
|---|---|---|---|
| `FeedCardModule` | Post to the activity feed | `card_types()`: type, label, icon, optional renderer and Explore preview | Allows the post type, registers label and icon for the feed and Explore, renders the uniform integration card |
| `NotificationSource` | - | hook carrying the payload, prefix, icon, types, a visibility answer | Registers the source and adopts the notification contract, so the bell, push and realtime pick it up |
| `SearchSource` | Include in search | types, result link, next ids to re-index, index one | Runs the re-index when the owner switches search on, removes the index when it is switched off |
| `SettingsModule` | - | `Field` objects with option keys that start with `buddynext_<slug>_` | Renders them inside your card on Integration Settings, saves each through its own sanitiser, lists them for search and defaults |
| `ProfilePanelModule` | Show in navigation | `panels( $member_id )`: key, label, icon, count, items, `cta`, `owner_cta`, `owner_only` | Adds a sub-tab under the member's Portfolio tab and serves the same data to the app at `GET /buddynext/v1/members/{id}/portfolio` |
| `SpaceLinkSource` | - | `link_types()`, `link_label()`, `link_roster()`, `link_grants_access()` | Lists your links on the space's Linked to tab, backfills the roster in batches, and answers who may be removed when access ends |
| `DiscoveryModule` | - | a banner for the space header, paths that must load in full | Shows the banner under the space header and adds your paths to the client-nav deny list |

### Feed cards

```php
final class Cards implements FeedCardModule {
	public function card_types(): array {
		return array( 'course' => array( 'label' => __( 'Course', 'buddynext-learndash' ), 'icon' => 'graduation-cap' ) );
	}
}

// When a course is published, after checking the owner's switch:
if ( $addon->feed_enabled() ) {
	\BuddyNext\Feed\IntegrationActivity::publish( $member_id, 'published a course', $url, $title, 'course' );
}
```

`IntegrationActivity` also has `withdraw`, `restore`, `refresh` and the `*_by_meta` forms, so a card follows its source. A type that is a built-in or already rendered by another bridge is refused.

### Notifications

Fire your hook with the contract payload as its **last** argument. Declare each type's `label`, `description`, `default_on`, `email` and `audience`. Rows are bell only unless a type sets `email`.

### Settings

### Profile panels

Panels are cached for "the member viewing their own profile" and "anyone else", not per viewer. A panel that is not `owner_only` must return the same data to every viewer; mark anything that depends on who is looking `owner_only`, and it is dropped for everyone else. When the member's data changes, call `do_action( 'buddynext_suite_panels_changed', $member_id )`.

### Space links

A space can be linked to an object in your plugin (a course, a group, a store). While it is linked, everyone the object grants access to is added to the space; the space's Linked to tab lists every link from every addon and lets the space owner remove one.

Declare a `SpaceLinkSource` module, then call `BuddyNext\Spaces\SpaceLinkService` from your own screens and events:

- `link( $space_id, $slug, $type, $object_id )` links and backfills the current roster. An object feeds at most one space.
- `create_and_link( $slug, $type, $object_id, $owner_id )` creates a private space named after the object and links it. Such a space is *managed*.
- `join_from_source( $space_id, $user_id )` adds a member who gained access. It passes the space's join gate for that one join, honours bans and archives, and stays quiet: no owner notification and no webhook.
- `leave_from_source( $space_id, $user_id, $slug, $type, $object_id )` handles lost access. It removes the member only from a managed space, never the owner, and only if none of the space's other links still grants access. A hand-linked space never loses a member.
- `unlink( ... )` and `release_object( $slug, $type, $object_id )` (the object was deleted) keep the space and its members.

Removing an addon or the partner plugin changes nothing: the links stay, shown as "Plugin not active", and nobody is removed until the addon is back.

Do not add an admin tab. Declare fields and they appear in your card. Prefer a filter to a setting where an opinionated default will do.

## Hooks

| Hook | When |
|---|---|
| `buddynext_register_addons` (action, `AddonRegistry $registry`) | Register your addon here. Fires once, at `buddynext_load_bridges`. |
| `buddynext_member_suite_panels` (filter, `array $panels`, `int $member_id`) | Collects the Portfolio panels for a member. Prefer `ProfilePanelModule`; use the filter only from a plugin that is not an addon. |
| `buddynext_suite_panels_changed` (action, `int $member_id`) | Call it when a member's panel data changes so the cached panels are rebuilt. |
| `buddynext_suite_panels_ttl` (filter, `int $seconds`) | How long panels are cached. Default 5 minutes. |
| `buddynext_space_link_sources` (filter, `array<string,SpaceLinkSource> $sources`) | Offers objects a space can be linked to. A `SpaceLinkSource` module is added for you. |
| `buddynext_space_linked` (action, `int $space_id`, `string $source`, `string $type`, `int $object_id`) | A space was linked to an object. |
| `buddynext_space_unlinked` (action, `int $space_id`, `string $source`, `string $type`, `int $object_id`) | A link was removed. The space and its members are untouched. |
| `buddynext_space_link_backfill_chunk_size` (filter, `int $size`, `string $source`, `string $type`, `int $object_id`) | Members added per backfill run. Default 500, at least 1. |
| `buddynext_addon_module_failed` (action, `string $slug`, `string $module`, `Throwable $error`) | A module threw and has been switched off for the rest of the request. The error is also written to the PHP error log. |

## What the app sees

`GET /app/config` lists your addon under `integrations.<slug>` with `profile_panels: true` when it supplies a `ProfilePanelModule`, and your typed feed cards under `card_types`. Both keys are additive; an older server omits them.

## Keeping it stable

The public API is exactly these, and `tests/Addons/AddonApiContractTest.php` fails any change to their signatures:

| Class | What is public |
|---|---|
| `BuddyNext\Addons\*` interfaces and `AbstractAddon` | Every public method |
| `BuddyNext\Integrations\IntegrationRegistry` | Every public method |
| `BuddyNext\Spaces\SpaceLinkService` | Every public method |
| `BuddyNext\Feed\IntegrationActivity` | Only the methods tagged `@api`: publish, remove, withdraw, restore, refresh, rewrite and their `_by_meta` forms, `set_space_by_meta`, `card_types`, `render_bridge_card` |

`SuitePanelHost` is not public: add panels through `ProfilePanelModule` or the `buddynext_member_suite_panels` filter.

- Use only `@api` classes and `@since` hooks. A symbol that is not marked may change in any release.
- A breaking change bumps `BUDDYNEXT_ADDON_API`. The old shape keeps working for two minor releases, with `_deprecated_function()` naming the replacement.
- Deactivating the partner or your addon deletes nothing. The owner can switch an addon's surfaces off from Integration Settings, and data is exactly as it was when it comes back.
- Do not copy BuddyNext code into your addon. If you need something that is not here, it belongs in BuddyNext: ask for it.

## Testing

BuddyNext ships a fixture addon (`dev/addon-fixture`) that implements every module and the tests that drive it. Copy its tests as the pattern for yours: register your addon, call `wire()`, and assert the filters it should have added. A throwing module must leave the site working.
