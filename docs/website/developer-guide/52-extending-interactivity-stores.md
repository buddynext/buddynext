# Extending a BuddyNext Interactivity Store

How another plugin adds to, or replaces, the behavior of a BuddyNext frontend store without editing BuddyNext files or copying a template. This is the JavaScript counterpart to the PHP template-part seams in [Hooks and Template Parts](26-hooks-template-parts.md).

## Overview / Contract

BuddyNext builds every interactive surface on the WordPress Interactivity API. Each surface registers a store under a namespace such as `buddynext/feed`.

`store()` **merges**. Calling it a second time with the same namespace does not replace the store - it merges your object into the existing one, key by key. That is core Interactivity API behavior, not something BuddyNext implements, and it is the whole extension mechanism:

```js
import { store } from '@wordpress/interactivity';

store( 'buddynext/feed', {
	actions: {
		// Adds a new action alongside BuddyNext's own.
		myPluginTrackShare() {
			// …
		},
	},
} );
```

Declare an action BuddyNext already defines and yours wins - that is how you override one behavior without touching the other twenty.

## Load order is the part people get wrong

A merge only works if it runs **after** the store it extends. If your module executes first, BuddyNext's registration merges over yours and your override silently disappears.

Declare the BuddyNext module as a dependency so WordPress orders them for you:

```php
add_action( 'init', function () {
	wp_register_script_module(
		'@my-plugin/feed-extension',
		plugins_url( 'build/feed-extension.js', __FILE__ ),
		array( '@wordpress/interactivity', '@buddynext/feed' )
	);
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script_module( '@my-plugin/feed-extension' );
} );
```

The module ID matches the store namespace: `buddynext/feed` is shipped by `@buddynext/feed`, `buddynext/messages` by `@buddynext/messages`, and so on.

A symptom worth recognising: your override works on a hard refresh and stops working after a client-side navigation, or vice versa. That is almost always a missing dependency rather than a bug in your code.

## Which namespaces are public surface

These are the stores that back a documented, member-facing surface. They are the ones worth extending, and the ones we try not to break:

| Namespace | Surface |
|---|---|
| `buddynext/feed` | Activity feed, composer, comments |
| `buddynext/post-card` | An individual post card |
| `buddynext/post-composer` | The composer block |
| `buddynext/members` · `buddynext/member-directory` | Member directory |
| `buddynext/spaces` · `buddynext/space-directory` · `buddynext/space-members` | Spaces |
| `buddynext/messages` | Direct messages |
| `buddynext/notifications` · `buddynext/notification-bell` · `buddynext/notification-prefs` | Notifications |
| `buddynext/profile` · `buddynext/profile-completion-bar` | Profiles |
| `buddynext/media` · `buddynext/media-albums` | Media and albums |
| `buddynext/search` · `buddynext/search-bar` | Search |
| `buddynext/hashtags` | Hashtag feeds |
| `buddynext/follow-button` · `buddynext/connection-button` | Social graph controls |
| `buddynext/moderation` | Reporting and moderation controls |
| `buddynext/onboarding` | Setup wizard |
| `buddynext/auth` and its `auth-*` siblings | Login, signup, reset, verify |

Everything else is internal. If a namespace is not in that table, treat it as private: it exists to make one screen work and may be renamed, split, or removed without notice.

## What we do and do not guarantee

**We try to keep stable, and will treat a break as a bug:**

- The namespace strings in the table above.
- The corresponding `@buddynext/*` module IDs.
- That `store()` keeps merging - this is core behavior, not ours to change.

**We do not guarantee, and will change without a major version:**

- The internal shape of any store's `state`, `actions`, or `callbacks`. Adding an action is safe; depending on the arguments or return value of one of ours is not.
- Any namespace absent from the table.
- DOM structure and CSS class names inside a surface. Use the PHP template-part seams if you need to change markup - see [Hooks and Template Parts](26-hooks-template-parts.md).

The practical rule: **add behavior freely, replace behavior deliberately, and read nothing you did not write.** An override that only adds an action survives upgrades comfortably. An override that reimplements one of ours has to be re-checked on each release, because we are free to change what it replaced.

## Choosing between JS and PHP

Reach for a store extension when you need to change what happens *after* first paint - a click, a fetch, an optimistic update.

Reach for the PHP seams when you need to change what is *rendered* - an extra badge, a different class, a new row. Those are documented in [Hooks and Template Parts](26-hooks-template-parts.md) and are more stable than store internals, because markup seams are an explicit contract and store internals are not.

## Related

- [Frontend Interactivity and Client-Side Navigation](36-frontend-interactivity.md) - how our stores are built, and the client-navigation rules an extension has to survive.
- [Hooks and Template Parts](26-hooks-template-parts.md) - the PHP half of the same question.
