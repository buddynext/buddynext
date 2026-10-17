# Use Your Own Login, Sign-up or Reset Page

How to send BuddyNext's Log in, Sign up and Forgot password links to a page you choose: a membership plugin's login page, a custom page, or WordPress's own `wp-login.php`. Three filters, no setting. BuddyNext's own screens keep working on every site, so nothing breaks for a visitor who reaches them another way.

## Overview / Contract

| Filter | Moves | Default | Arguments |
|---|---|---|---|
| `buddynext_login_url` | Every BuddyNext Log in link, every guest gate (private community, login-required pages, Explore when it is members-only, Pro checkout and plan pages), the sign-in link in emails (`{{login_url}}`, account approved, after a password reset), and the logout fallback. Since 1.2.6. | The BuddyNext login page | `string $url, string $destination` |
| `buddynext_signup_url` | Every BuddyNext Sign up link. | `{auth}/signup/` | `string $url` |
| `buddynext_reset_url` | Every BuddyNext Forgot password link. | `{auth}/reset/` | `string $url` |

What you can rely on:

- **Return the bare page.** BuddyNext adds `redirect_to` (the page the visitor was on) after `buddynext_login_url` and `buddynext_signup_url` run, URL-encoded, the same parameter WordPress and most login plugins read. `$destination` is passed so you can build a different parameter if your page needs one; it is `''` when there is no return page.
- **No filter, no change.** Without these filters every link is exactly what earlier versions printed.
- **Core's links follow too.** On the front end BuddyNext answers WordPress's `wp_login_url()`, `wp_registration_url()` and `wp_lostpassword_url()` with these same addresses, so a theme or plugin that calls core gets your page as well. wp-admin and `wp-login.php` keep core's addresses. To stop BuddyNext answering core at all, return `false` from `buddynext_owns_core_auth_links`.
- **BuddyNext-only screens stay on BuddyNext.** The set-new-password step in the reset email (`?key=...&login=...`), email verification, two-factor, social login and connecting the mobile app always use the BuddyNext screens, because only they read those tokens. The REST `/auth` routes are unchanged.
- **Returning WordPress's address is safe.** A callback may return `wp_login_url()`, `wp_registration_url()` or `wp_lostpassword_url()`; while the filter runs, BuddyNext stands aside so the call gives WordPress's address instead of looping. Returning `''` from `buddynext_login_url` does the same.

## Examples

### Send every Log in link to your own page

```php
add_filter(
	'buddynext_login_url',
	static function () {
		return home_url( '/member-login/' );
	}
);
```

A guest who opens a members-only page is sent to `/member-login/?redirect_to=...` and returns there after logging in, if your page honours `redirect_to`.

### Paid Memberships Pro: login and sign-up on its pages

```php
add_filter(
	'buddynext_login_url',
	static function ( $url ) {
		return function_exists( 'pmpro_login_url' ) ? pmpro_login_url() : $url;
	}
);

add_filter(
	'buddynext_signup_url',
	static function ( $url ) {
		return function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : $url;
	}
);
```

### Forgot password on another page

```php
add_filter(
	'buddynext_reset_url',
	static function () {
		return wp_lostpassword_url(); // WordPress's own form; WooCommerce's when it is active.
	}
);
```

## Notes and gotchas

- **Another domain needs `allowed_redirect_hosts`.** Guest gates redirect with `wp_safe_redirect()`, which refuses an unknown host. Add the host to WordPress's `allowed_redirect_hosts` filter, or the gate falls back to the dashboard login.
- **Your page must not be behind the private-community gate.** It is not, as long as it is an ordinary WordPress page: that gate covers BuddyNext's own pages only.
- **Themes may re-point core's links themselves.** BuddyX and Reign send `wp_login_url()` and friends to the BuddyNext screens on their own, so on those themes "return WordPress's address" gives the BuddyNext screen. Return the page you want directly instead.
- **Where members land after logging in** is a separate choice: Settings > Registration & Login, or the `buddynext_redirect_url` filter (see [Hooks: Moderation, Trust, and Authentication](31-hooks-moderation-auth-trust.md)).
