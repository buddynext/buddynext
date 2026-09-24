# Hooks: Spaces

The action and filter seams for spaces (groups) and their membership: creation, update, deletion, ownership, joins, requests, invitations, bans, role changes, and per-member notification preferences. This page is for developers building moderation tools, notification bridges, gated-access or paywall integrations, and theme extensions for space pages. Every hook below is fired or applied by BuddyNext Free. The two seams that matter most for extension are `buddynext_can_join_space` (the Free-to-Pro access gate) and `buddynext_space_types` (registering new space kinds).

![A Space home whose creation, membership, role, and access hooks are documented on this page](../images/space-home.webp)

## Overview / Contract

- **Actions fire after the write commits.** Membership and lifecycle actions pass IDs, not hydrated rows. Re-fetch via `buddynext_service( 'spaces' )->get( $space_id )` when you need more than the IDs. The container key is `spaces`, not `space_service`.
- **`buddynext_can_join_space` is the access gate.** It runs before any database work in both the direct-join and request-membership paths. Return `false` to block; BuddyNext then short-circuits with a `WP_Error` built by the denial path, and `buddynext_space_join_denied_data` lets you attach a payload (for example a Pro paywall) to that error.
- **Removal vs ban are distinct events.** A ban also removes the membership, so a ban fires both `buddynext_space_member_removed` (so removal listeners such as cache busting always react) and `buddynext_space_user_banned` (so ban-specific listeners react). Listen to whichever matches your intent.
- **Idempotent membership writes.** Joins, requests, and invites use `INSERT IGNORE`; their actions fire only when the membership state actually changes. Unban fires only when an active ban row was deleted.
- **Space types are config maps, not classes.** `buddynext_space_types` filters a slug-keyed array. Behaviour (visibility and join flow) is derived from each entry's `visibility` field; the three built-in types cannot be removed.
- **Visibility has ONE decision point.** `BuddyNext\Spaces\SpaceVisibility` answers "can this viewer see this space / its roster / its content?" for every surface — the server-rendered template AND the REST route. `buddynext_space_can_view_roster` is applied inside it, so a single `add_filter()` changes the members page and `GET /spaces/{id}/members` together; the page and the app cannot disagree.

## Space visibility

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_can_view_roster` | filter | A surface resolves whether a viewer may see a space's member roster | `bool $can_view, int $space_id, int $viewer_id, string $type` |
| `buddynext_can_view_space_content` | filter | A viewer's access to a space's **content** is resolved, before it is rendered or cached. Return `false` to withhold the space's posts while leaving the space itself visible. Fired from `SpaceVisibility` and again in `FeedService` when building a space feed, so an add-on that gates content only has to answer once. Default `true`. | `bool $can_view, int $space_id, int $viewer_id` |
| `buddynext_space_files_tab_for_guests` | filter | The space nav decides whether to show the Files tab to a logged-out visitor. Default `false`: WPMediaVerse refuses anonymous document reads, so on a public space the tab could only ever render its empty state. Return `true` if your MediaVerse serves anonymous reads. | `bool $show, int $space_id` |
| `buddynext_space_default_tab` | filter | Which tab a space opens on when the URL names none (`/spaces/{slug}/`). Runs for the resolved default only - a non-member of a private space gets `about`, then the space's own "Space opens on" setting, then the first inline tab in the site's Navigation order - never for an explicit `/spaces/{slug}/{tab}/`. Return a tab id; a value the viewer cannot see falls back to the first renderable tab, so a bad return can never blank the space. Example: open course spaces on About - `return 'about';`. | `string $tab, array $space, int $viewer_id` |

Default: `true` for open spaces; `false` for private and secret spaces unless the viewer is an active member, a moderator, the space owner, or a site admin. A private space is **listed but gated** — its name, description, house rules, avatar, cover, category, member COUNT, and its owner + moderator list stay public (a stranger needs them to decide whether to request to join), while the full member roster does not.

Return `true` to re-open private rosters Facebook-style. The filter is applied at the single decision point, so this one call re-opens both the members page and the REST roster route:

```php
// Anyone may browse a private space's member list (Facebook-style).
add_filter( 'buddynext_space_can_view_roster', '__return_true' );

// Or selectively: open private rosters to logged-in members of the community,
// but never a secret space's, and never to a logged-out visitor.
add_filter( 'buddynext_space_can_view_roster', function ( bool $can_view, int $space_id, int $viewer_id, string $type ): bool {
    if ( $can_view || 'private' !== $type ) {
        return $can_view;
    }
    return $viewer_id > 0;
}, 10, 4 );
```

## Space lifecycle

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_created` | action | A new space is created | `int $space_id, int $owner_id` |
| `buddynext_reserved_space_slugs` | filter | A space slug is generated or validated. These slugs are refused because they collide with BuddyNext's own space sub-routes (`members`, `files`, `about`, …); a space claiming one would shadow its own tab. Add your own to reserve them. | `string[] $slugs` |
| `buddynext_space_updated` | action | A space's fields are edited | `int $space_id, int $user_id, array $fields` (columns written this update). **See the arity warning below - one call site passes only `$space_id`.** |
| `buddynext_space_archived` | action | A space is archived | `int $space_id, int $actor_id` |
| `buddynext_space_unarchived` | action | A space is unarchived | `int $space_id, int $actor_id` |
| `buddynext_space_ownership_transferred` | action | A space's ownership moves to a new owner | `int $space_id, int $new_owner_id, int $actor_id, int $previous_owner_id` |
| `buddynext_space_deleted` | action | A space is deleted | `int $space_id, int $user_id` |

`buddynext_space_archived` and `buddynext_space_unarchived` are dispatched from a single call site that selects the hook name by state, so a listener only fires on the transition it registered for.

> **`buddynext_space_updated` fires with the full three arguments from every call site.**
>
> This was not always true. `SpaceFieldRegistry::save()` used to fire `$space_id` alone, so a typed three-parameter listener - registered exactly as documented - took an `ArgumentCountError` on that one path. It now passes `$space_id, get_current_user_id(), $saved` like the two `SpaceService` call sites, and the source carries a comment saying the arity is part of the contract and must not vary by call site. Earlier versions of this page told you to default the second and third parameters as a workaround; that is no longer necessary.

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
| `buddynext_space_joined_via_link` | action | A member joins a space through its shareable invite link (see REST: Spaces, Invite links) | `int $space_id, int $user_id` |
| `buddynext_space_can_invite` | filter | After the per-space `who_can_invite` gate, whether a user may invite others to a space | `bool $can, int $space_id, int $inviter_id, string $inviter_role` |
| `buddynext_space_can_post` | filter | After the per-space `who_can_post` gate, whether a user may post in a space. Lets an add-on apply conditional rules, e.g. require an active membership tier to post | `bool $can, int $space_id, int $user_id, string $role` (`role` is `owner`\|`moderator`\|`member`) |

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

## Ownership succession

Resolved by `SpaceSuccession` when a space's owner is removed (leaves, is removed, or is deleted as a user) and the space needs a new owner. The default heir is the longest-tenured active moderator; a site administrator is the last-resort fallback.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_successor_id` | filter | A heir is being resolved for a space losing its owner. Return `0` to leave the space ownerless (flagged with the `needs_owner` space meta) instead of auto-assigning one | `int $heir, int $space_id, int $outgoing_owner_id` |
| `buddynext_space_successor_fallback_user_id` | filter | No moderator heir was found, resolving the last-resort site-admin fallback | `int $fallback, int $space_id` |

The outgoing owner is never accepted as a valid heir, and a heir id that does not resolve to a real user is treated as `0` (none), regardless of what either filter returns.

## Space types

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_space_types` | filter | The registered space-type map is resolved | `array $types` (slug-keyed config map) |
| `buddynext_register_space_fields` | action | The per-space field registry is built. Call `$registry->register()` to add your own space fields | `SpaceFieldRegistry $registry` |
| `buddynext_space_max_per_member` | filter | The ceiling on how many spaces one member may own is resolved. Defaults to the site-wide setting; `0` means unlimited | `int $max_per_member, int $owner_id` |
| `buddynext_space_posts_changed` | action | A space's post set changes - a post created in it, or removed from it. Carries the space id, which `buddynext_post_created` does not | `int $space_id` |

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

### Gate a space behind a membership plan

`buddynext_can_join_space` is the seam Pro uses for paywalls and gated plans. Return `false` to block; pair it with `buddynext_space_join_denied_data` to surface a reason or paywall payload in the REST error response. The gate runs before any database work, so a denied user never creates a row.

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

## Featured spaces

Owner-curated spaces shown first in the directory sidebar, the phone strip, and onboarding. Two filters tune them; both are applied by `SpaceService::featured_spaces()`.

```php
// Raise or lower how many spaces an owner may feature (default 6, clamped 1–12).
add_filter( 'buddynext_featured_spaces_limit', fn () => 10 );

// Adjust the final featured list PER SURFACE. Runs AFTER visibility filtering and
// its result is visibility-checked again, so you can reorder/trim/add but can
// never surface a space the viewer must not see. $surface is one of
// 'sidebar' | 'directory_mobile' | 'onboarding' | 'suggestions'.
add_filter( 'buddynext_featured_spaces', function ( array $spaces, int $viewer_id, string $surface ): array {
    if ( 'onboarding' === $surface ) {
        // e.g. cap onboarding to the top 3.
        return array_slice( $spaces, 0, 3 );
    }
    return $spaces;
}, 10, 3 );
```

- The directory sidebar "Featured" card is registered via `buddynext_sidebar_widgets` with id `spaces-featured` (priority 10) — remove or reorder it there.
- Featured spaces the member has not joined are boosted in feed/explore suggestions via the existing `buddynext_space_suggestions` filter (behind the member's strongest personal matches).
- REST: `GET`/`POST /spaces/... ` — see `16-rest-spaces.md` (`/settings/featured-spaces`).

## Space admin page

The space admin page (`/spaces/{slug}/admin/`) renders an "at a glance" stats row (Members, Pending requests, Open reports) and, right after it, an action for add-ons to append their own stat tiles for the people who manage the space.

```php
// Append a stat tile after the space-admin at-a-glance row.
// Fires only on a page the viewer can already manage (owner, moderators, admins).
add_action( 'buddynext_space_admin_after_stats', function ( int $space_id, int $viewer_id ): void {
    // Reuse the shared tile markup so the surface stays consistent:
    echo '<div class="bn-space-admin__stats" role="list">';
    echo '  <div class="bn-card bn-space-admin__stat" role="listitem">';
    echo '    <span class="bn-space-admin__stat-value">' . esc_html( my_metric( $space_id ) ) . '</span>';
    echo '    <span class="bn-space-admin__stat-label">' . esc_html__( 'My metric', 'my-plugin' ) . '</span>';
    echo '  </div>';
    echo '</div>';
}, 10, 2 );
```

BuddyNext Pro uses this seam to render its "Last 30 days" analytics row (new members, left, net growth, posts) for space owners; with Pro inactive the page is unchanged. `$viewer_id` has already passed the manage-space capability gate, so the hook never fires for a member.

## Space settings tabs

**Start with space fields.** If your add-on only needs a few per-space values, register them with `buddynext_register_space_field()` on the `buddynext_register_space_fields` action. They appear in the space's **Custom fields** settings tab, save over REST with validation and a write permission, and need no form or save handler (snippet: [`settings/add-space-settings-fields.php`](https://github.com/buddynext/buddynext-snippets/blob/master/settings/add-space-settings-fields.php)). Add a whole tab only when you need your own layout.

The settings page (`/spaces/{slug}/settings/`) builds its tab strip from a registry you can extend with the `buddynext_part_space_settings_tabs_args` filter. The active tab comes from the `?bn_stab=<slug>` query var.

| Key in `$args` | Type | Meaning |
|---|---|---|
| `space_id` | int | The space being edited. |
| `active_tab` | string | The current `bn_stab` slug. |
| `tabs` | array | The tab rows. Append yours. |
| `base_url`, `classes` | string, array | Present only on the second call (see below). |

Each tab row:

| Key | Required | Meaning |
|---|---|---|
| `slug` | yes | The `bn_stab` value. Prefix it with your plugin's name so it cannot clash. |
| `label` | yes | Translated tab label. |
| `icon` | no | An icon slug from `assets/icons/`. |
| `cap` | no | A site capability for `current_user_can()`. When it fails, the tab link is hidden. |
| `panel` | no | A callable `( string $slug, array $args )`, or a template path relative to `templates/`. `$args` holds `space` (the space row) and `space_id`. With no `panel`, the General panel renders. |

```php
// 1. Add the tab. The filter runs twice per request, so guard against a duplicate.
add_filter( 'buddynext_part_space_settings_tabs_args', function ( array $args ): array {
    if ( in_array( 'my-webhook', array_column( $args['tabs'] ?? array(), 'slug' ), true ) ) {
        return $args;
    }
    $args['tabs'][] = array(
        'slug'  => 'my-webhook',
        'label' => __( 'Webhook', 'my-plugin' ),
        'icon'  => 'link',
        'panel' => 'my_plugin_render_webhook_tab',
    );
    return $args;
} );

// 2. Render the panel. It is NOT wrapped in BuddyNext's settings form: bring your own.
function my_plugin_render_webhook_tab( string $slug, array $args ): void {
    $space_id = (int) $args['space_id'];
    // ?bn_stab=my-webhook reaches this panel even when the tab link is hidden,
    // so check permission here. Owner-only in this example.
    if ( ! buddynext_can( get_current_user_id(), 'buddynext-own-space', array( 'space_id' => $space_id ) ) ) {
        echo '<p>' . esc_html__( 'Only the space owner can change this.', 'my-plugin' ) . '</p>';
        return;
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bn-card" style="padding: var(--bn-s4);">
        <input type="hidden" name="action" value="my_plugin_save_webhook" />
        <input type="hidden" name="space_id" value="<?php echo esc_attr( (string) $space_id ); ?>" />
        <?php wp_nonce_field( 'my_plugin_webhook_' . $space_id ); ?>
        <label for="my-webhook-url"><?php esc_html_e( 'Webhook URL', 'my-plugin' ); ?></label>
        <input type="url" id="my-webhook-url" name="webhook_url" class="bn-input"
            value="<?php echo esc_attr( (string) get_space_meta( $space_id, 'my_webhook_url', true ) ); ?>" />
        <button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save', 'my-plugin' ); ?></button>
    </form>
    <?php
}

// 3. Save: nonce, the same permission check, then back to the tab.
add_action( 'admin_post_my_plugin_save_webhook', function (): void {
    $space_id = isset( $_POST['space_id'] ) ? absint( $_POST['space_id'] ) : 0;
    check_admin_referer( 'my_plugin_webhook_' . $space_id );
    if ( ! buddynext_can( get_current_user_id(), 'buddynext-own-space', array( 'space_id' => $space_id ) ) ) {
        wp_die( esc_html__( 'You cannot change this space.', 'my-plugin' ), '', array( 'response' => 403 ) );
    }
    update_space_meta( $space_id, 'my_webhook_url', esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ) );
    $space = buddynext_service( 'spaces' )->get( $space_id ); // An array.
    wp_safe_redirect( add_query_arg( 'bn_stab', 'my-webhook', buddynext_space_settings_url( (string) ( $space['slug'] ?? '' ) ) ) );
    exit;
} );
```

Things to know:

- **The filter runs twice per request.** It runs once in `templates/spaces/settings.php`, so your slug counts as a valid `bn_stab` value, and once in `templates/parts/space-settings-tabs.php`, which draws the strip. Always check that your slug is not already in `$args['tabs']` before appending.
- **`cap` only hides the link.** The page itself needs the `buddynext-spaces/manage-settings` ability (space owners, space moderators and site admins). Beyond that, any registered slug is reachable by URL. Check permission inside your panel and in your save handler. Use `buddynext-own-space` for owner-only settings.
- **You own the form and the save.** Only BuddyNext's own General, Privacy and Integrations tabs sit inside the settings form, and the sticky save bar does not track your inputs. Post to your own handler (`admin-post.php` as above, or your own REST route with a permission callback).
- **Store per-space values in `bn_space_meta`** with `get_space_meta()` / `update_space_meta()`. Don't create a table or an option per space.
- **`buddynext_service( 'spaces' )->get()` returns an array**, so read the slug as `$space['slug']`.

Tested on BuddyNext 1.2.1: the tab appears once at the end of the strip, the panel renders, a save redirects back to the tab with the new value, and the owner check refuses a non-owner.

## Notes / gotchas

- **Free vs Pro.** Every hook here is fired by Free. `buddynext_can_join_space` plus `buddynext_space_join_denied_data` are the documented gated-spaces / paywall seam that Pro builds on; `buddynext_space_types` is the extension point for new space kinds.
- **The gate runs first.** Because `buddynext_can_join_space` short-circuits before any insert, you cannot rely on a `*_member_joined` action to undo a join you wanted to block. Block it at the gate.
- **Ban fires two actions.** Choose `buddynext_space_user_banned` for ban-specific behaviour and `buddynext_space_member_removed` for "no longer a member" behaviour. They fire together on a ban.
- **Re-fetch space data.** Lifecycle actions pass IDs only. Hydrate via `buddynext_service( 'spaces' )->get( $space_id )` rather than reading `$space` from a stale closure.
