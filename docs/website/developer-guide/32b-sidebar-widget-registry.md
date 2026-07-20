# Sidebar Widget Registry

The surface-scoped registry that renders BuddyNext's right-hand rail. New in 1.0.9, it replaces the hand-rolled `templates/partials/sidebar.php` with a single renderer: a plugin registers a widget descriptor once, scopes it to one or more of the twelve surfaces (the activity feed, a single space, the member directory, and so on), and the registry decides per request which widgets a surface shows, in what order, and whether each even renders. Free's own widgets and every Pro bridge register through the same seam, so a bridge descriptor needs no special-casing. This page is for developers adding a sidebar card from a Pro module, an integration, or a theme.

## Overview / Contract

- **One filter collects every widget.** `SidebarRegistry` (hooked to the `buddynext_right_sidebar` action at priority 20) applies `buddynext_sidebar_widgets` with the current surface slug and renders whatever descriptors come back, so you never touch a template - you append a descriptor array.
- **Surface, not hub, is the unit of scoping.** Each surface template calls `\BuddyNext\Sidebar\Surface::set( $slug, $context )` before the shell paints the right column; the registry reads that fine-grained slug, and `bn_hub` is only a coarse fallback.
- **Widgets are filtered, sorted, capped, then self-hide.** A descriptor is dropped unless its `surfaces` (or `hubs` fallback) contains the current surface and its optional `condition` returns true; survivors sort by ascending `priority`, are capped by `buddynext_sidebar_max_widgets` (default 6), then any whose `render` callback emits an empty body is skipped so no empty card ever paints.
- **Opt-in widgets set `default => false`.** Such a widget is off until a filter flips it on, which lets a provider register a card the site owner enables per surface.
- **Two chrome modes.** By default the registry wraps your body in the standard `parts/sidebar-card.php` card (you supply `title`, `icon`, and optional `see_all_*`); set `chrome => false` when your `render` closure already emits its own `.bn-sidebar-card` wrapper, and the registry echoes the body raw instead of double-wrapping it.
- **The render callback echoes; it does not return.** Your callback receives the surface slug, prints its markup (already escaped), and the registry captures the output buffer - a callback that prints nothing removes the widget.

## Descriptor keys

A descriptor is an associative array appended to the `buddynext_sidebar_widgets` return value. Only `id` and `render` are required.

| Key | Type | Required | Meaning |
|---|---|---|---|
| `id` | string | yes | Stable widget identifier; becomes the card's DOM `id`. |
| `render` | callable | yes | Callback `function( string $surface ): void` that echoes the widget body (escaped). Empty output self-hides the widget. |
| `surfaces` | string[] | one of `surfaces`/`hubs` | Surface slugs this widget appears on; the widget is skipped when the current surface is not in the list. |
| `hubs` | string[] | fallback | Coarse `bn_hub` slugs, consulted only when `surfaces` is empty. |
| `condition` | callable | no | Gate `function( string $surface ): bool`; the widget is skipped when it returns false. |
| `default` | bool | no | `false` makes the widget opt-in (off unless a filter re-enables it); omit for always-on. |
| `priority` | int | no | Ascending sort order within the surface; defaults to `50`. |
| `chrome` | bool | no | `false` means the render callback supplies its own card wrapper and the body is echoed raw; omit to get the standard card. |
| `classes` | string\|string[] | no | Extra CSS classes merged onto the standard card (chromed widgets only). |
| `title` | string | no | Card heading (chromed widgets only). |
| `icon` | string | no | Lucide icon slug shown beside the title (chromed widgets only). |
| `see_all_url` | string | no | Footer link URL for the card (chromed widgets only). |
| `see_all_label` | string | no | Footer link label paired with `see_all_url` (chromed widgets only). |

## Surface slugs

Each slug below is set by its template via `\BuddyNext\Sidebar\Surface::set()`. Templates that pass a context payload expose it to render callbacks through `\BuddyNext\Sidebar\Surface::context()`.

| Surface slug | Where it is set | Context payload |
|---|---|---|
| `feed` | `templates/feed/home.php` | none |
| `bookmarks` | `templates/feed/bookmarks.php` | none |
| `single-post` | `templates/feed/single-post.php` | none |
| `explore` | `templates/feed/explore.php` | none |
| `search` | `templates/search/results.php` | none |
| `hashtag` | `templates/hashtags/feed.php` | tag context |
| `leaderboard` | `templates/gamification/leaderboard.php` | none |
| `members` | `templates/directory/members.php` | none |
| `spaces` | `templates/spaces/directory.php` | none |
| `space` | `templates/spaces/home.php`, `members.php`, `moderation.php` | `space_id`, `viewer_id`, `active_tab` |
| `profile` | `templates/profile/view.php` | profile args |
| `notifications` | `templates/notifications/index.php` | sidebar data |

## Register a widget

Register a provider that hooks `buddynext_sidebar_widgets` (accepting two arguments: the descriptors collected so far and the current surface) and appends your descriptor. This mirrors how the Free core providers register.

```php
add_filter(
	'buddynext_sidebar_widgets',
	function ( array $descriptors, string $surface ): array {
		if ( 'space' !== $surface ) {
			return $descriptors; // Only add this card on the single-space surface.
		}

		$context  = \BuddyNext\Sidebar\Surface::context(); // space_id, viewer_id, active_tab.
		$space_id = (int) ( $context['space_id'] ?? 0 );

		$descriptors[] = array(
			'id'            => 'my-space-events',
			'priority'      => 45,
			'surfaces'      => array( 'space' ),
			'title'         => __( 'Upcoming events', 'my-plugin' ),
			'icon'          => 'calendar',
			'see_all_url'   => home_url( '/events/' ),
			'see_all_label' => __( 'See all events', 'my-plugin' ),
			'condition'     => static function () use ( $space_id ): bool {
				return $space_id > 0;
			},
			'render'        => static function ( string $surface ) use ( $space_id ): void {
				// Echo escaped markup; printing nothing self-hides the card.
				echo '<ul class="my-space-events">' . /* ... */ '</ul>';
			},
		);

		return $descriptors;
	},
	10,
	2
);
```

For a self-chromed card that renders its own `.bn-sidebar-card` wrapper, set `'chrome' => false` and drop the `title`/`icon`/`see_all_*` keys - the registry echoes your body verbatim.

## Hooks

| Hook | Type | Fired / applied when | Parameters |
|---|---|---|---|
| `buddynext_right_sidebar` | action | The shell (`templates/shell/right-sidebar.php`) paints the right column; `SidebarRegistry::render()` is hooked here at priority 20 | `string $hub` |
| `buddynext_sidebar_widgets` | filter | The registry collects widget descriptors for the current surface | `array<int,array<string,mixed>> $descriptors, string $surface` |
| `buddynext_sidebar_max_widgets` | filter | The registry caps how many widgets a surface shows | `int $max (default 6), string $surface` |

Notes:

- `buddynext_sidebar_widgets` is the one registration seam. Return the descriptors array with yours appended; return it unchanged when the surface is not one you target. Register your callback with two accepted arguments so you receive `$surface`.
- `buddynext_sidebar_max_widgets` returning `0` (or a negative value) disables the cap for that surface; any positive value keeps the highest-priority widgets up to that count.
- The `buddynext_right_sidebar` action passes the coarse `$hub`; prefer scoping by `surfaces` over `hubs`, and read fine-grained context with `\BuddyNext\Sidebar\Surface::context()` rather than re-deriving it.
