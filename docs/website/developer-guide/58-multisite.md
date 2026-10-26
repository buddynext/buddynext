# Multisite: One Community on the Main Site

How BuddyNext behaves on a WordPress network, and what themes and partner plugins should rely on.

## Overview / Contract

| Fact | Detail |
|---|---|
| Where the community lives | The network's main site. One community per network. |
| Activation | Network Activate only (`Network: true`). |
| Other sites | BuddyNext defines nothing on them except `buddynext_is_community_site()`. No constants, classes, functions, routes, tables or options. |
| Who is a member | A user with a role on the main site. Network users join on their first community write action. |

## Detect BuddyNext

```php
// Is BuddyNext loaded on this request?
if ( defined( 'BUDDYNEXT_VERSION' ) || class_exists( \BuddyNext\Core\Plugin::class ) ) {
	// Safe to call BuddyNext functions.
}

// Is this the site that runs the community?
if ( buddynext_is_community_site() ) {
	// A single site, or the main site of a network.
}
```

`buddynext_is_community_site()` is public API. It is always defined, on every site, and returns `! is_multisite() || is_main_site()`. On a subsite, `defined( 'BUDDYNEXT_VERSION' )` is false, so a theme or partner that detects BuddyNext that way behaves as if it were not installed.

## Member scope for your own queries

The users table is shared by the whole network. When you list or count members with a raw query, restrict it to the community:

```php
$where = buddynext_member_scope_sql( 'u.ID', ' AND ' ); // '' on a single site.
```

`WP_User_Query` already limits to the current site and needs nothing.

## Removing a member

Use `buddynext_remove_member( $user_id, $actor_id )`. It always removes the community data. On a network it deletes the account only when the person belongs to no other site and the actor is the member, a system job (actor `0`) or a user allowed to delete users.

## Events that fire while a site is switched

Content listeners (posts, comments) do nothing when the current site is not the community site, so `switch_to_blog()` plus a publish never reaches BuddyNext's tables. User listeners keep running because user data is network-wide. If your plugin switches sites and fires user events, expect BuddyNext to react to them as it would on the main site.

## Related hooks

| Hook | When |
|---|---|
| `buddynext_network_user_joined` (action, `int $user_id`) | A network user has just become a member through their first action. Sign-up listeners (search index, onboarding, auto-join, welcome, webhook) hook this. |
