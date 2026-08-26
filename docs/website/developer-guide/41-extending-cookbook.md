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

**Seam:** `buddynext_safeguard_check`. In `SafeguardService::check()` it runs after the built-in IP, banned-word, blocked-domain, rate-limit, and banned-hashtag gates, and **before** the duplicate-content and new-member gates - those two return a hold-for-review verdict, and a hold must never outrank a hard block. Return `true` to allow, or a `WP_Error` to reject - the `WP_Error` message is shown to the user. The same filter runs on edits via `check_content()`, so your rule covers edited content too.

This is the seam the Pro Moderation Rules engine attaches its keyword blocklists and ML scoring to. Banned-word lists are configured through that rules engine, not by adding `check_*()` methods to `SafeguardService`.

The filter takes **five** arguments. The fifth, `$context`, is `'create'` or `'edit'`, and it is the one you have to think about: if your rule counts an author's recent activity (a rate limit, flood control, a cooldown), it must skip `'edit'`. An edit is not a new post, and re-asking the question there locks an author who has hit your cap out of editing the posts they already published. Content rules - banned words, links, ML scoring - should keep running on edits, or editing becomes a way to smuggle content past you.

```php
add_filter(
    'buddynext_safeguard_check',
    static function ( $result, int $user_id, string $content, string $link_url, string $context ) {
        // Respect an upstream block - never override another guard's WP_Error.
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // A CONTENT rule: it must keep running on edits too, so do not gate it on $context.
        if ( false !== stripos( $content, 'buy-now-cheap.example' ) ) {
            return new WP_Error(
                'my_addon_spam',
                __( 'That link is not allowed here.', 'my-addon' )
            );
        }

        // A "how often" rule: create-time only. On an edit, stand down.
        if ( 'create' === $context && my_addon_over_hourly_cap( $user_id ) ) {
            return new WP_Error(
                'my_addon_flood',
                __( 'You are posting too quickly. Try again shortly.', 'my-addon' )
            );
        }

        return true;
    },
    10,
    5
);
```

> **Warning:** Check `is_wp_error( $result )` first and pass an existing error through unchanged. If you always return `true` you silently re-allow content another safeguard already blocked.
>
> **Warning:** Declare `5` accepted args, not `4`. A callback registered with `4` never sees `$context`, so a rate-limit-shaped rule fires on every edit - which is exactly the bug this argument was added to fix.

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

**Goal:** block certain users from joining or requesting membership in a space (for example, gate a space behind a paid plan).

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

Append a tile to the shared stat-strip primitive (`parts/stat-strip.php`), the part that renders the profile and space stat rows. Each item needs at least a `label` and a `value` (optional `icon`, `href`, `delta`, `trend`, `tone`):

```php
add_filter( 'buddynext_part_stat_strip_args', static function ( array $args ): array {
    $args['stats'][] = array(
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

> **Note:** `type` must be one of the Free field types (`text`, `textarea`, `url`, `email`, `phone`, `number`, `date`, `boolean`, `select`, `radio`, `multiselect`, `category_multiselect`, `color`). The "File upload" (`file`) type is **Pro-only** - it is registered by Pro on the `buddynext_field_types` filter and is not available in Free.

---

## Recipe 10 - Register an add-on hub (1.0.4)

**Goal:** give your add-on its own community page - a real URL like `/events/` that renders inside the BuddyNext shell, with a backing WP page, rewrite rules, and template resolution handled for you. (Surfacing an editable URL slug in the admin needs one extra filter today - see the end of the recipe.)

**Seam:** `HubRegistry` + the `buddynext_register_hubs` action.

```php
add_action( 'buddynext_register_hubs', function ( \BuddyNext\Core\HubRegistry $reg ) {
	$reg->register(
		new \BuddyNext\Core\HubDescriptor(
			'events',                       // hub key (bn_hub query var)
			'myaddon_slug_events',          // option holding the URL slug
			'events',                       // default slug
			'myaddon_page_events',          // option holding the backing page id
			__( 'Events', 'my-addon' ),     // backing page title
			'[myaddon_events]',             // backing page content shortcode
			null,                           // query_var (defaults to the key)
			function ( string $slug ) {     // register_rules
				add_rewrite_rule( '^' . $slug . '/?$', 'index.php?bn_hub=events', 'top' );
			},
			function ( string $hub ): ?string { // resolve_template
				return 'events' === $hub ? MY_ADDON_DIR . 'templates/events.php' : null;
			}
		)
	);
} );

### Give the hub a proper document `<title>`

Without this your hub's browser tab reads `Events` at best, and `Circle-studio` at
worst: `PageRouter` keeps a title map for its own hubs and falls back to
`ucfirst( $hub )` for everything else, which turns a slug into a near-miss of a name.

`buddynext_document_title` is the seam. It receives the title and a **context**,
which for a hub render is the hub key — so match on your own key and leave every
other surface alone:

```php
add_filter( 'buddynext_document_title', function ( string $title, string $context ): string {
	return 'events' === $context ? __( 'Events', 'my-addon' ) : $title;
}, 10, 2 );
```

Two things worth knowing before you rely on it:

- **The context is not always a hub key.** BuddyNext also fires this filter from
  `HeadMeta` with the context `head-meta`, for surfaces that run no hub render at all
  (a single-post permalink, for one). Always match on the value you expect rather
  than assuming a hub.
- **It does not fight an SEO plugin.** When Yoast, Rank Math or similar is active,
  BuddyNext leaves the document title alone entirely and your filter will not be
  applied. That is deliberate — the owner installed that plugin to own their titles —
  so set your title there instead on those sites.
```

One registration gives your hub a live route: `PageRouter` dispatches your `register_rules` and `resolve_template` on every request, a slug change flushes rewrites automatically (BuddyNext hooks `update_option_{your_slug_option}` for every registered hub), and - if your add-on is active when BuddyNext is activated - the Installer creates a backing WP page for it. `includes/Core/CoreHubs.php` registers the built-in hubs through the same `HubRegistry`.

Two limits are worth knowing today. Both are being closed as the hub-registry migration finishes; until then, plan around them:

- **The admin Pages & URLs screen does not list add-on hubs yet.** `NavManager::page_hub_catalogue()` is a fixed list of the built-in hubs, so your hub's URL slug is not editable there out of the box. Add it with the `bn_admin_hub_pages` filter, which receives that catalogue as `hub key => { label, desc, slug_opt, page_opt, default }`:

  ```php
  add_filter( 'bn_admin_hub_pages', function ( array $hubs ): array {
      $hubs['events'] = array(
          'label'    => __( 'Events', 'my-addon' ),
          'desc'     => __( 'Your community events hub.', 'my-addon' ),
          'slug_opt' => 'myaddon_slug_events',
          'page_opt' => 'myaddon_page_events',
          'default'  => 'events',
      );
      return $hubs;
  } );
  ```

- **Most built-in hubs do not yet go through `register_rules` / `resolve_template`.** Their rewrites and template resolution still live directly in `PageRouter`; only `community_admin` rides this add-on seam so far. The callbacks you pass here are the supported path and are dispatched on every request - as the built-ins move onto the same seam, the two converge and a core hub and your hub run identical code.

## Recipe 11 - Ship your own templates (1.0.4)

**Goal:** let BuddyNext's template loader find templates inside your add-on, with the child-theme override chain intact.

**Seam:** the `buddynext_template_locations` filter on `TemplateLoader`.

```php
add_filter( 'buddynext_template_locations', function ( array $dirs ): array {
	$dirs[] = MY_ADDON_DIR . 'templates/buddynext';
	return $dirs;
} );
```

Lookup order: child theme, then parent theme, then BuddyNext's own `templates/`, then each registered add-on directory. Add-on directories are searched last on purpose - they resolve templates Free does not ship (like Pro membership surfaces), while a site owner can still override any of them from their theme at `buddynext/{path}` - see Child Theme Template Overrides.

## Recipe 12 - Add an owner-only Portfolio panel (Pro, 1.0.7)

**Goal:** give an integration its own panel inside a member's Portfolio tab that only the profile owner ever sees - a personal "in progress" shelf (Learnomy's "Continue Learning") rather than a public credential like a certificate or a listing.

**Seam:** `buddynext_member_suite_panels` (Pro). Every suite integration (Career Board, Listora, Learnomy, ...) contributes its panels to the one shared Portfolio tab through this filter; `SuiteProfile::panels()` normalizes each entry and reads an `owner_only` flag off it. Set that flag and the panel is withheld from every viewer except the profile owner - enforced in three places so it can never leak: the Portfolio sub-nav (`SuiteProfile::add_subnav()`, via its `visible_panels()` gate), the section renderer (`SuiteProfile::render_section()`), and the REST endpoint (`PortfolioController::get_portfolio()`, which drops the panel outright for a non-owner viewer).

```php
add_filter( 'buddynext_member_suite_panels', function ( array $panels, int $member_id ): array {
	$courses = my_addon_get_in_progress_courses( $member_id );
	if ( empty( $courses ) ) {
		return $panels;
	}

	$panels[] = array(
		'key'        => 'continue-learning',
		'label'      => __( 'Continue Learning', 'my-addon' ),
		'icon'       => 'play',        // a BuddyNext icon slug.
		'priority'   => 25,
		'items'      => $courses,      // each item: title, url, image, highlight, ...
		// Owner-only: rendered on the member's OWN profile alone, never a visitor's.
		'owner_only' => true,
		'owner_cta'  => array(
			'label' => __( 'Go to my courses', 'my-addon' ),
			'url'   => home_url( '/account/courses/' ),
		),
	);

	return $panels;
}, 10, 2 );
```

> **Note:** `BuddyNextPro\Integrations\AbstractSuitePanelProvider` is the shared base every bundled Pro integration extends. It wires this filter, the in-process REST fetch, and the panel-array assembly for you - its `panel()` helper takes the same `owner_only` opt. Extend it instead of hooking `buddynext_member_suite_panels` directly if you are shipping more than one panel.

---

## Recipe 13 - Keep a cookie-consent (or other must-run) plugin on community pages (1.1.3)

**Goal:** a plugin that must run on every page - a cookie-consent banner is the canonical case - keeps working on BuddyNext's community routes.

**Seams:** `buddynext_isolation_whitelist` (plugin loading) and `buddynext_allowed_assets` (its CSS/JS).

BuddyNext isolates its community routes for speed and a uniform UX, in two layers: plugin isolation loads only the essential plugin family on those routes, and asset isolation dequeues any stylesheet or script that is not core, theme, or BuddyNext. Both are good defaults - a consent banner is the exception, because a MISSING banner is invisible: nobody notices it is gone, and consent-based script gating silently stops on exactly the pages members use most.

Site owners can allow a plugin without code at **Admin > BuddyNext > Plugin isolation**. The code route needs BOTH filters, and the file **must be a mu-plugin** (`wp-content/mu-plugins/`): plugin isolation filters the active-plugins list before regular plugins load, so a normal plugin or theme hooks too late.

```php
<?php
/**
 * Plugin Name: Keep consent banner on BuddyNext pages
 * Description: Allows the cookie-consent plugin through BuddyNext's
 *              plugin and asset isolation. Must live in wp-content/mu-plugins/.
 */

// Layer 1: keep the plugin LOADED on BuddyNext routes.
add_filter(
    'buddynext_isolation_whitelist',
    static function ( array $whitelist ): array {
        $whitelist[] = 'wpconsent-cookies-banner-privacy-suite/wpconsent.php';
        return $whitelist;
    }
);

// Layer 2: keep its CSS/JS ENQUEUED on BuddyNext routes.
add_filter(
    'buddynext_allowed_assets',
    static function ( array $prefixes ): array {
        $prefixes[] = plugins_url( '', 'wpconsent-cookies-banner-privacy-suite/wpconsent.php' );
        return $prefixes;
    }
);
```

Swap the basename for your consent plugin: `cookie-law-info/cookie-law-info.php` (CookieYes), `complianz-gdpr/complianz-gpdr.php` (Complianz), `cookiebot/cookiebot.php` (Cookiebot). The same two-filter pattern keeps any must-run plugin alive on community pages - security headers, affiliate tracking the owner has consent for, and so on. Use it sparingly: every plugin allowed through gives up part of the memory saving isolation exists to provide.

---

## Recipe 14 - Ask the gate before offering an action (1.1.5)

If your surface renders a Join button, ask whether the join would actually be allowed. Do not infer it from the space being open.

```php
$spaces = buddynext_service( 'space_members' );   // SpaceMemberService

if ( $spaces->can_join( $space, get_current_user_id() ) ) {
    // Safe to render the Join control.
}
```

`can_join( array|object $space, int $user_id ): bool` takes either a `bn_spaces` row array or the object templates carry, and `0` for a logged-out visitor.

This exists because Free cannot know what Pro will decide. Surfaces used to offer Join to anyone looking at an open space, including members a listener was certain to refuse - on a plan-gated space that produced a screen telling the member they needed a paid plan, with two buttons beside it inviting them to join anyway. Asking the gate is the only way to know the offer is real. The same reasoning applies to any control you render on someone else's behalf.

## Recipe 15 - Hydrate a batch of posts in one query (1.1.5)

Looping `get()` over a list of post ids issues one query per row. At a page of 20 that is 20 round trips for data a single `IN()` answers.

```php
$posts = buddynext_service( 'post_service' )->get_many( $post_ids );   // PostService
```

`get_many( array $post_ids ): array` returns hydrated posts **in the order you asked for**, not table order, because the caller's order is usually meaningful - relevance, for one - and the database loses it. Ids with no row are skipped rather than returned as blanks, so do not assume the result is the same length as the input.

**It is a fetch, not a gate.** Visibility is deliberately not applied. Pass the ids through `filter_visible()` first, exactly as the feed does, or you will hand a member content they cannot see.

---

## Notes and gotchas

- **Filters return, actions react.** A filter that returns nothing erases the value. An action's return value is ignored.
- **Hook timing.** Register service-dependent code at `plugins_loaded` priority 20 or later, or on the `buddynext_loaded` action.
- **Never read constants directly.** Resolve reaction types via `ReactionService::reaction_types()` and profile field types via `ProfileFieldsManager::field_types()` so your filter actually runs.
- **Escaping is yours.** Any HTML you emit through a `*_before` / `*_after` action or a user-overlay HTML filter is echoed raw. Escape on output.
- **Free degrades cleanly.** Several Free defaults (one webhook endpoint, a pin limit of 1) are the seams Pro raises. Your addon raises them the same way and should never assume Pro is present.

For the complete catalog of every hook referenced here, see the hooks reference pages: Feed and Content Hooks, Spaces Hooks, Notifications and Email Hooks, Moderation, Auth and Trust Hooks, Template Part Hooks, and Pro and Integration Hooks.

## Count things across many spaces without an N+1

**Goal:** an owner dashboard showing several spaces at once — total members, join
requests waiting, content reported — without one query per space, and without
getting the member count wrong.

**Seam:** batched counters on the services that own each table.

```php
$members = new \BuddyNext\Spaces\SpaceMemberService();
$mod     = new \BuddyNext\Moderation\ModerationService();
$posts   = buddynext_service( 'posts' );

$space_ids = array( 12, 19, 44 );   // the spaces this owner runs

$people   = $members->count_distinct_members( $space_ids );
$waiting  = $members->count_pending_requests_for_spaces( $space_ids );
$reported = $mod->count_open_reports_for_spaces( $space_ids );
$drafts   = $posts->count_draft_announcements( $space_ids );
```

**Do not sum `bn_spaces.member_count` across spaces.** It is a per-space
denormalised column, so anyone who belongs to two of an owner's spaces is counted
twice — and the error grows with exactly the communities that are working, because
active members join more spaces. On the development site, summing across ten spaces
gave **54** where the real number of people was **14**.

The two member counters deliberately answer differently, and the difference is not
an inconsistency:

- `count_distinct_members()` counts **people**, once each. Only `status = 'active'`;
  invited, pending and banned rows are not members.
- `count_pending_requests_for_spaces()` counts **rows**. One person asking to join
  three spaces is three decisions an owner has to make, and collapsing them would
  under-report the work waiting.

`count_open_reports_for_spaces()` counts distinct reported **objects**, not report
rows — five members reporting one post is one thing to look at, not five.

Each has a singular sibling (`count_pending_requests( int $space_id )`,
`count_open_reports_for_space( int $space_id )`). The convention across both
services: **singular takes an int, `_for_spaces` takes an array.** An empty array
returns `0` rather than falling through to a site-wide count.