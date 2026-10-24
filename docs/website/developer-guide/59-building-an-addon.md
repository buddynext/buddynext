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

Do not add an admin tab. Declare fields and they appear in your card. Prefer a filter to a setting where an opinionated default will do.

## Hooks

| Hook | When |
|---|---|
| `buddynext_register_addons` (action, `AddonRegistry $registry`) | Register your addon here. Fires once, at `buddynext_load_bridges`. |
| `buddynext_addon_module_failed` (action, `string $slug`, `string $module`, `Throwable $error`) | A module threw and has been switched off for the rest of the request. The error is also written to the PHP error log. |

## Keeping it stable

- Use only `@api` classes and `@since` hooks. A symbol that is not marked may change in any release.
- A breaking change bumps `BUDDYNEXT_ADDON_API`. The old shape keeps working for two minor releases, with `_deprecated_function()` naming the replacement.
- Deactivating the partner or your addon deletes nothing. The owner can switch an addon's surfaces off from Integration Settings, and data is exactly as it was when it comes back.
- Do not copy BuddyNext code into your addon. If you need something that is not here, it belongs in BuddyNext: ask for it.

## Testing

BuddyNext ships a fixture addon (`dev/addon-fixture`) that implements every module and the tests that drive it. Copy its tests as the pattern for yours: register your addon, call `wire()`, and assert the filters it should have added. A throwing module must leave the site working.
