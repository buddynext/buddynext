# Hooks: Spaces

The action and filter seams for spaces (groups) and their membership: creation, update, deletion, ownership, joins, requests, invitations, bans, role changes, and per-member notification preferences. This page is for developers building moderation tools, notification bridges, gated-access or paywall integrations, and theme extensions for space pages. Every hook below is fired or applied by BuddyNext Free. The two seams that matter most for extension are `buddynext_can_join_space` (the Free-to-Pro access gate) and `buddynext_space_types` (registering new space kinds).

![A Space home whose creation, membership, role, and access hooks are documented on this page](../images/space-home.webp)

## Overview / Contract

- **Actions fire after the write commits.** Membership and lifecycle actions pass IDs, not hydrated rows. Re-fetch via `buddynext_service( 'space_service' )->get( $space_id )` when you need more than the IDs.
- **`buddynext_can_join_space` is the access gate.** It runs before any database work in both the direct-join and request-membership paths. Return `false` to block; BuddyNext then short-circuits with a `WP_Error` built by the denial path, and `buddynext_space_join_denied_data` lets you attach a payload (for example a Pro paywall) to that error.
- **Removal vs ban are distinct events.** A ban also removes the membership, so a ban fires both `buddynext_space_member_removed` (so removal listeners such as cache busting always react) and `buddynext_space_user_banned` (so ban-specific listeners react). Listen to whichever matches your intent.
- **Idempotent membership writes.** Joins, requests, and invites use `INSERT IGNORE`; their actions fire only when the membership state actually changes. Unban fires only when an active ban row was deleted.
- **Space types are config maps, not classes.** `buddynext_space_types` filters a slug-keyed array. Behaviour (visibility and join flow) is derived from each entry's `visibility` field; the three built-in types cannot be removed.

## Space lifecycle

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_created` | action | A new space is created | `int $space_id, int $owner_id` |
| `buddynext_space_updated` | action | A space's fields are edited | `int $space_id, int $user_id, array $fields` (columns written this update) |
| `buddynext_space_archived` | action | A space is archived | `int $space_id, int $actor_id` |
| `buddynext_space_unarchived` | action | A space is unarchived | `int $space_id, int $actor_id` |
| `buddynext_space_ownership_transferred` | action | A space's ownership moves to a new owner | `int $space_id, int $new_owner_id, int $actor_id` |
| `buddynext_space_deleted` | action | A space is deleted | `int $space_id, int $user_id` |

`buddynext_space_archived` and `buddynext_space_unarchived` are dispatched from a single call site that selects the hook name by state, so a listener only fires on the transition it registered for.

## Membership: join, request, invite

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_can_join_space` | filter | Before a direct join or a membership request, gating access | `bool $can, array $space, int $user_id, string $action` (`$action` is `'join'` or `'request'`) |
| `buddynext_space_member_joined` | action | A user becomes an active member (direct join or approved request) | `int $space_id, int $user_id, string $role` (`'member'`) |
| `buddynext_space_join_requested` | action | A user requests to join a private space | `int $space_id, int $user_id` |
| `buddynext_space_member_invited` | action | A user is invited to a space | `int $invited_user_id, int $space_id, int $inviter_id` |
| `buddynext_space_join_approved` | action | A pending join request is approved | `int $space_id, int $user_id, int $actor_id` |
| `buddynext_space_join_declined` | action | A pending join request is declined | `int $space_id, int $user_id, int $actor_id` |
| `buddynext_space_join_request_cancelled` | action | A member cancels their own pending request | `int $space_id, int $user_id` |
| `buddynext_space_join_denied_data` | filter | A gated join/request is denied, to build the error payload | `array $data, int $space_id, int $user_id, array $space, string $action` |

> **Note:** When a request is approved, both `buddynext_space_join_approved` and `buddynext_space_member_joined` fire (in that order). The first is the moderation event; the second is the "this user is now an active member" event, identical to the one fired on a direct join.

## Membership: leave, remove, ban, roles, preferences

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_member_left` | action | A user leaves a space voluntarily | `int $space_id, int $user_id` |
| `buddynext_space_member_removed` | action | A member is removed by a moderator (also fires when a member is banned) | `int $space_id, int $user_id, int $actor_id` |
| `buddynext_space_role_changed` | action | A member's role is promoted or demoted | `int $space_id, int $target_id, string $new_role, int $actor_id` |
| `buddynext_space_user_banned` | action | A user is banned from a space | `int $space_id, int $user_id, int $actor_id` |
| `buddynext_space_user_unbanned` | action | A space ban is lifted | `int $space_id, int $user_id` |
| `buddynext_space_notification_pref_updated` | action | A member changes their per-space notification preference | `int $space_id, int $user_id, string $pref` (`'all'`, `'mentions_only'`, `'none'`) |

> **Warning:** A ban removes the membership, so it fires `buddynext_space_member_removed` and `buddynext_space_user_banned` together. If you maintain a banned-users list, listen to `buddynext_space_user_banned` specifically; if you only need to react to "this user is no longer in the space" (for example, busting a sidebar cache), listen to `buddynext_space_member_removed` and you will cover both removals and bans.

## Space types

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_types` | filter | The registered space-type map is resolved | `array $types` (slug-keyed config map) |
| `buddynext_space_tabs` | filter | A space home page builds its tab bar | `array $tabs` |

Each space-type entry has this shape. Visibility drives the behaviour: `public` allows direct joins, `private` requires a request, `secret` is invite-only.

```php
'open' => [
    'label'      => __( 'Open', 'buddynext' ),  // UI label
    'tone'       => 'success',                   // badge tone slug
    'visibility' => 'public',                    // 'public' | 'private' | 'secret'
    'join'       => 'direct',                    // 'direct' | 'request' | 'invite'
],
```

The built-in types are `open` (public/direct), `private` (private/request), and `secret` (secret/invite). They cannot be removed by the filter, only added to.

## Examples

### Gate a space behind a membership tier

`buddynext_can_join_space` is the seam Pro uses for paywalls and gated tiers. Return `false` to block; pair it with `buddynext_space_join_denied_data` to surface a reason or paywall payload in the REST error response. The gate runs before any database work, so a denied user never creates a row.

```php
// Block the join/request unless the user holds the required entitlement.
add_filter( 'buddynext_can_join_space', function ( bool $can, array $space, int $user_id, string $action ): bool {
    if ( ! $can ) {
        return false; // Someone already denied it.
    }
    $required_tier = (int) get_post_meta( (int) ( $space['id'] ?? 0 ), '_required_tier', true );
    if ( $required_tier > 0 && ! my_membership_user_has_tier( $user_id, $required_tier ) ) {
        return false;
    }
    return $can;
}, 10, 4 );

// Attach a paywall payload to the denial so the client can render a CTA.
add_filter( 'buddynext_space_join_denied_data', function ( array $data, int $space_id, int $user_id, array $space, string $action ): array {
    $data['paywall'] = [
        'message' => __( 'This space is for premium members.', 'my-addon' ),
        'cta_url' => home_url( '/upgrade/' ),
    ];
    return $data;
}, 10, 5 );
```

> **Note:** `buddynext_can_join_space` fires for both the direct-join path (`$action === 'join'`) and the request-membership path (`$action === 'request'`). Branch on `$action` if your rules differ between the two.

### Register a custom space type

```php
add_filter( 'buddynext_space_types', function ( array $types ): array {
    $types['announce_only'] = [
        'label'      => __( 'Announcements', 'my-addon' ),
        'tone'       => 'info',
        'visibility' => 'public',  // anyone can join
        'join'       => 'direct',
    ];
    return $types;
} );
```

### React to a new member in a space

```php
add_action( 'buddynext_space_member_joined', function ( int $space_id, int $user_id, string $role ): void {
    my_addon_send_welcome_dm( $user_id, $space_id );
}, 10, 3 );
```

## Notes / gotchas

- **Free vs Pro.** Every hook here is fired by Free. `buddynext_can_join_space` plus `buddynext_space_join_denied_data` are the documented gated-spaces / paywall seam that Pro builds on; `buddynext_space_types` is the extension point for new space kinds.
- **The gate runs first.** Because `buddynext_can_join_space` short-circuits before any insert, you cannot rely on a `*_member_joined` action to undo a join you wanted to block. Block it at the gate.
- **Ban fires two actions.** Choose `buddynext_space_user_banned` for ban-specific behaviour and `buddynext_space_member_removed` for "no longer a member" behaviour. They fire together on a ban.
- **Re-fetch space data.** Lifecycle actions pass IDs only. Hydrate via `buddynext_service( 'space_service' )->get( $space_id )` rather than reading `$space` from a stale closure.
