# Member Identity Seams — avatar, name, profile URL, cover

How a plugin (third-party **or** one of ours) reads a member's community identity
from BuddyNext, and the contract BuddyNext follows so you can rely on the result.

If you need a member's avatar, display name, profile link or cover on your own
screen, call the helper below. Do **not** read the usermeta keys directly and do
**not** re-implement avatar resolution — the keys and the resolution order are
BuddyNext's to change, and code that reaches around these seams is exactly what
drifts out of sync the next time either side moves.

## The pull seams

All four are plain global functions, available once BuddyNext has loaded
(`plugins_loaded`). Each is safe to call for any user id and returns a sensible
empty value rather than throwing when something is missing.

| Function | Returns | Notes |
|---|---|---|
| `buddynext_user_avatar_url( int $user_id, int $size = 96 )` | Absolute URL or `data:` URI | **Never empty** for a real user — BuddyNext supplies a generated initials image when nobody has a photo. |
| `buddynext_member_url( int $user_id )` | Absolute profile URL, or `''` | The member's community profile page. |
| `buddynext_member_label( int $user_id, string $fallback = '' )` | Display name, or `$fallback` | The name BuddyNext shows for the member. |
| `buddynext_user_cover_url( int $user_id )` | Cover URL, or `''` | Custom cover, else the site default; `''` means "use your own fallback". |

```php
// In your plugin — render a member the way the community does.
if ( function_exists( 'buddynext_user_avatar_url' ) ) {
	$avatar = buddynext_user_avatar_url( $user_id, 48 );      // always a usable URL
	$name   = buddynext_member_label( $user_id, 'Member' );
	$link   = buddynext_member_url( $user_id );               // '' if unavailable

	printf(
		'<a href="%s"><img src="%s" width="48" height="48" alt="%s"> %s</a>',
		esc_url( $link ),
		esc_url( $avatar ),
		esc_attr( $name ),
		esc_html( $name )
	);
}
```

### Why `buddynext_user_avatar_url()` and not the usermeta

`buddynext_user_avatar_url()` runs the standard WordPress `get_avatar_url()`
pipeline, which BuddyNext powers. That means it returns the member's **resolved**
avatar under the shared-surface precedence (see below): a real photo — a
BuddyNext upload, or a sibling plugin's such as WPMediaVerse — wins, and only when
the member has none does BuddyNext hand back a deterministic initials image. You
get the same face the community itself shows, and never an empty string.

If all you want is an `<img>`, core `get_avatar( $user_id, $size )` also runs the
same pipeline and is perfectly fine to use directly.

## Overriding what BuddyNext returns

To change the avatar BuddyNext resolves for a user — a custom avatar plugin, an
SSO photo, a per-site rule — hook the `buddynext_avatar_url` filter and return an
absolute URL. Returning `''` (the default) means "I have nothing, let BuddyNext
decide".

```php
add_filter( 'buddynext_avatar_url', function ( string $url, int $user_id ): string {
	$mine = my_plugin_avatar_for( $user_id );
	return $mine ?: $url;   // never clobber a URL another source already set
}, 10, 2 );
```

That filter is the single override point: it feeds both
`buddynext_user_avatar_url()` and every core `get_avatar()` surface (comments, the
admin user list, REST embeds), so you set an avatar once and it appears
everywhere.

## The contract these seams honour: own, share, provide

BuddyNext is the community layer on top of the whole plugin family, so on a
BuddyNext-first site it hooks shared WordPress surfaces (avatars, mail identity,
templates) for the entire site. Being that hub means **integrate, never clobber**.
Every BuddyNext filter on a surface something else also touches plays one of three
roles:

- **Own** — a surface BuddyNext is authoritative for (its own routes, its own
  emails, its hub pages). BuddyNext wins, but *scoped*: the override applies only
  within its own context or send, never leaves a global filter altering another
  plugin's context, and ships an opt-out filter. (Example: BuddyNext sets
  `wp_mail_from` only for the duration of its own send, at `PHP_INT_MAX`, with
  `buddynext_email_identity_priority` to opt out.)
- **Share** — a core surface many plugins fill with real member data (avatars).
  BuddyNext runs **late**, **defers** to any real value an earlier filter set, and
  contributes only a **fallback**. A generated placeholder never outranks a real
  photo. (See the precedence table in
  [WPMediaVerse Surface Ownership](54-mediaverse-surface-ownership.md#avatars-shared-by-precedence).)
- **Provide** — BuddyNext exposes its identity through the `buddynext_*` helpers
  and filters on this page, so a plugin that wants the community avatar/name/URL
  **pulls** it. Pull, not push — BuddyNext never forces its avatar onto your
  plugin's surfaces.

The practical upshot for you: read identity through the pull seams, override
through `buddynext_avatar_url`, and if you register your own filter on a shared
core surface, run it late and defer to a value already set — never let a
placeholder win over someone's real data.

## See also

- [WPMediaVerse Surface Ownership](54-mediaverse-surface-ownership.md) — the
  avatar precedence in full, and the build-time guard that enforces it.
- [Hooks: members, profiles, social](28-hooks-members-profiles-social.md) — the
  profile-image *upload* filters (size and dimension caps).
- [Extending cookbook](41-extending-cookbook.md) — worked integration recipes.
