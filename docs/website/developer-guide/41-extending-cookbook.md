# Extending BuddyNext - a recipe cookbook

Short, runnable recipes for the most common ways to extend BuddyNext from an addon plugin or a theme. Each recipe states the goal, names the seam, and gives a working snippet you can drop into a `plugins_loaded`-or-later hook. These are the same seams BuddyNext Pro uses - Pro never re-implements Free code, it attaches to these filters and actions, and so should you.

![The feed surface most cookbook recipes extend through the documented filter and action seams](../images/community-activity-feed.webp)

## Overview

BuddyNext exposes three families of extension point:

- **Action hooks** - fired after every significant platform event (`buddynext_post_created`, `buddynext_user_followed`, ...). React, do not block.
- **Filter hooks** - let you change a value before BuddyNext uses it (`buddynext_reaction_types`, `buddynext_post_pin_limit`, `buddynext_safeguard_check`, ...). Always return the value.
- **The service container** - `buddynext_service( '<key>' )` resolves any core service (`post_service`, `follows`, `webhooks`, ...) so an addon can read and write through the same code paths the plugin uses.

Two rules apply across every recipe:

1. **Register on `plugins_loaded` priority 20 or later** (BuddyNext boots at `plugins_loaded:15` and fires `buddynext_loaded` when ready). Hooking earlier risks the container not being built yet.
2. **A filter must always return a value.** Returning `null` or nothing from a filter breaks the feature.

For the full inventory of hooks behind these recipes, see Feed and Content Hooks, Spaces Hooks, Notifications and Email Hooks, Moderation, Auth and Trust Hooks, and Search, Hashtags, Sidebar and Admin Hooks.

---

## Recipe 1 - Add a custom reaction

**Goal:** add a reaction beyond the built-in six (`like`, `love`, `haha`, `wow`, `sad`, `angry`).

**Seam:** `buddynext_reaction_types` (which slugs are allowed) plus `buddynext_reaction_meta` (the label/char/color for a slug). The owner-facing `buddynext_enabled_reactions` option is a separate, owner-chosen *subset* of the built-in six - do not write it from an addon; it is the site owner's on/off control in Settings > Activity Feed.

This is exactly how Pro Custom Reactions works: its `CustomReactionsService` stores admin-configured slugs and merges them in through `buddynext_reaction_types`, capping the merged total at 20.

```php
add_filter(
    'buddynext_reaction_types',
    static function ( array $types ): array {
        // Append, never replace - the built-in six must stay present.
        $types[] = 'celebrate';
        return $types;
    }
);

add_filter(
    'buddynext_reaction_meta',
    static function ( array $meta, string $slug ): array {
        if ( 'celebrate' === $slug ) {
            $meta['label'] = __( 'Celebrate', 'my-addon' );
        }
        return $meta;
    },
    10,
    2
);
```

> **Important:** Every slug you add needs a matching icon at `assets/icons/reaction-{slug}.svg` (or, for the Pro Fluent-emoji path, a vendored emoji slug) and a `--bn-reaction-{slug}` color token. Adding a slug with no icon/token renders a broken reaction picker. BuddyNext resolves the list through `ReactionService::reaction_types()` - never read the `REACTION_TYPES` constant directly, or you bypass the filter.

---

## Recipe 2 - Raise the pin cap

**Goal:** let some users pin more than one post per scope (the Free default is 1 pinned post per profile and per space).

**Seam:** `buddynext_post_pin_limit`. It is read in `PostService` as `apply_filters( 'buddynext_post_pin_limit', 1, $space_id, $user_id )`, so you receive the target scope and the acting user and can decide per case. This is the seam Pro's `ProPinService` uses to lift the cap for premium members.

```php
add_filter(
    'buddynext_post_pin_limit',
    static function ( int $limit, ?int $space_id, int $user_id ): int {
        // Editors get up to 5 pins anywhere; everyone else keeps the default.
        if ( user_can( $user_id, 'edit_others_posts' ) ) {
            return 5;
        }
        return $limit;
    },
    10,
    3
);
```

`$space_id` is `null` for a profile pin and the space ID for a space pin, so you can set different caps per surface.

---

## Recipe 3 - Add an outbound webhook event

**Goal:** fire your addon's own event to every external endpoint a site owner has registered, signed with the site's per-endpoint HMAC secret.

**Seam:** the `webhooks` service. BuddyNext's own `OutboundWebhookListener` does nothing more than call `buddynext_service( 'webhooks' )->dispatch( $event_slug, $payload )` from each core action handler - your addon does the same with its own slug. Delivery is queued to Action Scheduler, fanned out only to endpoints subscribed to that slug, signed, logged, and retried with backoff. You write one line.

```php
add_action( 'my_addon_course_completed', static function ( int $user_id, int $course_id ): void {
    buddynext_service( 'webhooks' )->dispatch(
        'course.completed',                       // your event slug (use a dotted namespace)
        array(
            'user_id'   => $user_id,
            'course_id' => $course_id,
            'completed' => current_time( 'mysql', true ),
        )
    );
}, 10, 2 );
```

Endpoints that subscribe to *all* events (an empty event list) receive your slug automatically; endpoints with an explicit subscription list receive it only if `course.completed` is on their list. The number of endpoints a site may register is itself filterable - see Recipe 9 - via `buddynext_outbound_webhook_limit` (Free default 1, Pro lifts it).

> **Note:** Do not query `bn_outbound_webhooks` or build signatures yourself. Dispatching through the service is the only supported path and is what keeps signing, retry, and logging correct.

---

## Recipe 4 - Add a moderation safeguard

**Goal:** block a post (or comment, or any user-submitted text) at submit time based on your own rule.

**Seam:** `buddynext_safeguard_check`. It runs last in `SafeguardService::check()`, after the built-in IP, banned-word, blocked-domain, rate-limit, duplicate, and new-member gates. Return `true` to allow, or a `WP_Error` to reject - the `WP_Error` message is shown to the user. The same filter runs on edits via `check_content()`, so your rule covers edited content too.

This is the seam the Pro Moderation Rules engine attaches its keyword blocklists and ML scoring to. Banned-word lists are configured through that rules engine, not by adding `check_*()` methods to `SafeguardService`.

```php
add_filter(
    'buddynext_safeguard_check',
    static function ( $result, int $user_id, string $content, string $link_url ) {
        // Respect an upstream block - never override another guard's WP_Error.
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( false !== stripos( $content, 'buy-now-cheap.example' ) ) {
            return new WP_Error(
                'my_addon_spam',
                __( 'That link is not allowed here.', 'my-addon' )
            );
        }

        return true;
    },
    10,
    4
);
```

> **Warning:** Check `is_wp_error( $result )` first and pass an existing error through unchanged. If you always return `true` you silently re-allow content another safeguard already blocked.

---

## Recipe 5 - Add a notification type

**Goal:** create your own in-app notification and react when any notification is created.

**Seam:** write with `buddynext_service( 'notifications' )->create( $data )`; observe with the `buddynext_notification_created` action; gate with the `buddynext_notification_should_send` filter.

`create()` already respects the recipient's per-type and per-channel preferences, the `should_send` gate, and the `send_at` scheduling filter, then fires `buddynext_notification_created`. Use a `group_key` to collapse repeated notifications of the same kind into one row within a 24-hour window.

```php
// 1. Create a notification of your own type.
add_action( 'my_addon_mention_detected', static function ( int $recipient_id, int $sender_id, int $object_id ): void {
    buddynext_service( 'notifications' )->create(
        array(
            'recipient_id' => $recipient_id,
            'sender_id'    => $sender_id,
            'type'         => 'my_addon_mention',
            'object_type'  => 'post',
            'object_id'    => $object_id,
            'group_key'    => 'my_addon_mention_' . $object_id,
        )
    );
}, 10, 3 );

// 2. React when ANY notification is created (e.g. mirror to your own channel).
add_action( 'buddynext_notification_created', static function ( int $notification_id, int $recipient_id, array $data ): void {
    if ( ( $data['type'] ?? '' ) !== 'my_addon_mention' ) {
        return;
    }
    // your dispatch logic here
}, 10, 3 );

// 3. Optionally suppress a notification before it is ever stored.
add_filter( 'buddynext_notification_should_send', static function ( bool $should, array $payload ): bool {
    if ( ( $payload['type'] ?? '' ) === 'my_addon_mention' && is_user_muted_by_addon( (int) $payload['recipient_id'] ) ) {
        return false; // create() returns 0, nothing is stored or emailed.
    }
    return $should;
}, 10, 2 );
```

> **Note:** The live `buddynext_notification_created` signature is `( int $notification_id, int $recipient_id, array $data )` - the notification `type` is read from `$data['type']`, not a separate parameter. A `type` whose per-type in-app preference is off causes `create()` to return `0` and store nothing.

---

## Recipe 6 - Gate a space by capability

**Goal:** block certain users from joining or requesting membership in a space (for example, gate a space behind a paid tier).

**Seam:** `buddynext_can_join_space`. It runs in `SpaceMemberService` for both the direct-join and the request-to-join paths, receiving the resolved space row, the user, and the action. Return `false` to block. This is the seam Pro uses for gated spaces.

```php
add_filter(
    'buddynext_can_join_space',
    static function ( bool $can, array $space, int $user_id, string $action ): bool {
        // $action is 'join' or 'request'.
        if ( ! $can ) {
            return $can; // already blocked upstream
        }

        // Example: a space flagged "members-only" needs an active membership.
        if ( ! empty( $space['my_addon_members_only'] ) && ! my_addon_has_active_membership( $user_id ) ) {
            return false;
        }

        return $can;
    },
    10,
    4
);
```

Returning `false` blocks both the join button and the request flow, so a gated space cannot be entered through either path.

---

## Recipe 7 - Inject sidebar or feed content

**Goal:** add your own block to a sidebar, a row to the left navigation rail, or a tab to a space.

**Seam:** the template-part hooks. Every reusable part under `templates/parts/` fires four hooks named after the part: `buddynext_part_{name}_args` (filter the args before render), `buddynext_part_{name}_classes` (filter the root class list), `buddynext_part_{name}_before` and `buddynext_part_{name}_after` (actions around the markup). Several surfaces also expose dedicated list filters.

Inject a row into the left navigation rail:

```php
add_filter( 'buddynext_rail_items', static function ( array $items ): array {
    $items[] = array(
        'key'   => 'leaderboard',
        'label' => __( 'Leaderboard', 'my-addon' ),
        'url'   => home_url( '/leaderboard/' ),
        'icon'  => 'list',          // a BuddyNext icon slug, NOT raw SVG
        'show'  => true,            // must be truthy or the item is skipped
    );
    return $items;
} );
```

Append a content block after the profile-stats strip part (the same seam Pro uses to surface a streak tile):

```php
add_filter( 'buddynext_part_profile_stats_strip_args', static function ( array $args ): array {
    $args['stats'][] = array(
        'slug'  => 'points',
        'label' => __( 'Points', 'my-addon' ),
        'value' => '1,240',
    );
    return $args;
} );

// Or render arbitrary markup directly after a sidebar card:
add_action( 'buddynext_part_sidebar_card_after', static function ( array $args ): void {
    // echo your already-escaped markup here
} );
```

Add a tab to a space's nav bar through the unified Nav API. The old `buddynext_space_tabs` filter is **retired** - space tabs now flow through the Nav registry (the same system that owns profile tabs), so register on `buddynext_register_nav` with `surface => 'space'`. Space tabs are URL-only real links (`/spaces/{slug}/{tab}/`) and you server-render the panel for that route:

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

The same action registers profile tabs (`surface => 'profile'`) - see the Navigation API page for the full registration contract, the profile-vs-space tab difference, and how to reorder or remove existing items via the `buddynext_nav_items` filter.

> **Warning:** Output rendered through `*_before` / `*_after` actions and through user-overlay HTML filters is echoed raw at the call site. Escape everything you emit. For the `rail_items` `icon` key, pass a BuddyNext icon *slug*; a raw `<svg>` string will not render.

For the full Nav registry contract see the Navigation API page. For the per-part hook tables and the user-overlay HTML filters (member-card meta, profile hero badges, avatar overlay, comment author meta, and more), see Template Part Hooks and Search, Hashtags, Sidebar and Admin Hooks.

---

## Recipe 8 - Consume a Free service from an addon

**Goal:** read or write BuddyNext data through the same code paths the plugin uses, instead of querying `bn_*` tables directly.

**Seam:** the service container, via `buddynext_service( '<key>' )`. This is how Pro consumes Free: its AI ranked feed pulls follow relationships through the `follows` service and posts through `post_service`; its analytics collector reads posts through `post_service`. You get caching, counter integrity, and hook firing for free.

Common service keys:

| Key | Service | Use it for |
| --- | --- | --- |
| `post_service` | `PostService` | Fetch a post by ID, read author, increment/decrement counters |
| `follows` | `FollowService` | Follow relationships and follower/following lists |
| `connections` | `ConnectionService` | Connection (two-way) relationships |
| `notifications` | `NotificationService` | Create and read notifications (Recipe 5) |
| `webhooks` | `OutboundWebhookService` | Dispatch outbound events (Recipe 3) |
| `safeguard` | `SafeguardService` | Run the content-safety pipeline |
| `spaces` / `space_members` | Space services | Read space rows and membership |
| `moderation` | `ModerationService` | Read the report queue and moderation log |
| `search` | `SearchService` | Index content into `bn_search_index` |

```php
add_action( 'plugins_loaded', static function (): void {
    if ( ! function_exists( 'buddynext_service' ) ) {
        return; // BuddyNext not active - degrade gracefully.
    }

    add_action( 'my_addon_thing_happened', static function ( int $post_id ): void {
        $post = buddynext_service( 'post_service' )->get( $post_id );
        if ( ! $post ) {
            return;
        }
        // ... use the hydrated post row instead of querying bn_posts yourself.
    } );
}, 20 ); // priority 20: after BuddyNext boots at plugins_loaded:15.
```

> **Note:** Always guard with `function_exists( 'buddynext_service' )` so your addon does not fatal when BuddyNext is inactive. When `buddynext_post_created` hands you only an ID and a type, re-fetch the full row via `buddynext_service( 'post_service' )->get( $post_id )` rather than assuming fields off the action arguments.

---

## Recipe 9 - Register a member profile field from code

**Goal:** add an extended-profile field to every member from an addon, without an admin creating it in the field builder by hand.

**Seam:** `buddynext_register_member_field( string $key, array $args )` - the member-side companion to `buddynext_register_space_field()`, with the same `( $key, $args )` shape. Call it on `buddynext_loaded` (or `init`).

```php
add_action( 'buddynext_loaded', static function (): void {
    buddynext_register_member_field( 'github_url', [
        'label'      => 'GitHub',
        'type'       => 'url',          // a Free field type (see note below).
        'group_key'  => 'social_links', // attached to an existing group, or created if absent.
        'visibility' => 'public',
    ] );
} );
```

The field then:

- renders in the profile **edit UI** and on the **profile**, and
- is returned by **`GET /users/{id}/profile`** through `ProfileService`.

Because a programmatic field has no `bn_profile_fields` row, its submitted value is stored to **`bn_field_{key}` usermeta** (here `bn_field_github_url`) on save, not to the `bn_profile_values` table. Read it back with `get_user_meta( $user_id, 'bn_field_github_url', true )`.

> **Note:** `type` must be one of the Free field types (`text`, `textarea`, `url`, `email`, `phone`, `number`, `date`, `boolean`, `select`, `radio`, `multiselect`, `color`). The "File upload" (`file`) type is **Pro-only** - it is registered by Pro on the `buddynext_field_types` filter and is not available in Free.

---

## Notes and gotchas

- **Filters return, actions react.** A filter that returns nothing erases the value. An action's return value is ignored.
- **Hook timing.** Register service-dependent code at `plugins_loaded` priority 20 or later, or on the `buddynext_loaded` action.
- **Never read constants directly.** Resolve reaction types via `ReactionService::reaction_types()` and profile field types via `ProfileFieldsManager::field_types()` so your filter actually runs.
- **Escaping is yours.** Any HTML you emit through a `*_before` / `*_after` action or a user-overlay HTML filter is echoed raw. Escape on output.
- **Free degrades cleanly.** Several Free defaults (one webhook endpoint, a pin limit of 1) are the seams Pro raises. Your addon raises them the same way and should never assume Pro is present.

For the complete catalog of every hook referenced here, see the hooks reference pages: Feed and Content Hooks, Spaces Hooks, Notifications and Email Hooks, Moderation, Auth and Trust Hooks, Template Part Hooks, and Pro and Integration Hooks.
