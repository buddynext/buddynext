# Customizing cards and template parts

How to restyle and extend BuddyNext's cards - the member card, the notification row, the post card, and the shared UI primitives - without forking a template. Most cards expose a uniform four-hook contract you attach to; the two composite cards (post card, explore card) are customized through their delegated regions instead. This page is for anyone theming or extending BuddyNext's frontend cards.

![A member directory whose member cards are built from the reusable part documented here](../images/member-directory.webp)

## Overview / Contract

BuddyNext renders its frontend from small reusable parts under `templates/parts/`. Every part follows a uniform four-hook contract named after the part's file (hyphens become underscores - `member-card.php` fires `buddynext_part_member_card_*`):

| Hook | Type | Fired | Parameters |
| --- | --- | --- | --- |
| `buddynext_part_{name}_args` | filter | After the part assembles its `$args`, before render | `array $args` |
| `buddynext_part_{name}_classes` | filter | On the root element's class list | `array $classes, array $args` |
| `buddynext_part_{name}_before` | action | Just before the root markup | `array $args` |
| `buddynext_part_{name}_after` | action | Just after the root markup | `array $args` |

Each part documents its `$args` keys and the hooks it fires in its PHP file header. Reach for `_classes` or `_args` to restyle or reshape, and `_before` / `_after` to inject markup - all four survive plugin updates, where a copied template can silently drift. The full convention and the catalogue of shared primitives (`empty-state`, `pagination`, `sidebar-card`, `section-head`, `stat-strip`, `filter-strip`) are in the Template Parts reference (`docs/specs/TEMPLATE-PARTS.md`) and Hooks: Template Parts.

## The member card

`templates/parts/member-card.php` is the high-value reusable card (avatar, name, handle, type badge, bio, mutual-connection count, the Follow / 5-state Connect / Message / kebab action cluster), shared by the member directory, search results, and space-members panels. It exposes the full four-hook contract. Its `$args` carry, among others: `member` (the `WP_User`), `viewer_id`, `is_following`, `connection_state`, `mutual_count`, `member_type_label`, `bio`, `profile_url`, `avatar_url`, and `classes`.

### Worked example: add a badge to the member card

To append markup after the card, hook `buddynext_part_member_card_after` and read the member off `$args`:

```php
add_action( 'buddynext_part_member_card_after', static function ( array $args ): void {
    $member = $args['member'] ?? null;
    if ( ! $member || ! isset( $member->ID ) ) {
        return;
    }
    if ( ! get_user_meta( (int) $member->ID, 'my_addon_is_pro', true ) ) {
        return;
    }
    printf(
        '<span class="bn-badge my-pro-badge" data-tone="accent">%s</span>',
        esc_html__( 'Pro member', 'my-addon' )
    );
} );
```

To add a CSS class to the card root instead of markup, use the `_classes` filter (it receives the class array and `$args`):

```php
add_filter( 'buddynext_part_member_card_classes', static function ( array $classes, array $args ): array {
    $member = $args['member'] ?? null;
    if ( $member && isset( $member->ID ) && get_user_meta( (int) $member->ID, 'my_addon_is_pro', true ) ) {
        $classes[] = 'is-pro';
    }
    return $classes;
}, 10, 2 );
```

The member card also exposes two pre-escaped HTML overlay filters for richer additions without `_after`: `buddynext_member_card_meta_html` (a meta-overlay slot, receives `'', $member_id, $args`) and `buddynext_avatar_overlay_html` (an avatar corner overlay, receives `'', $member_id, 'xl'`). Whatever you return from these is echoed raw, so escape it yourself.

## The notification row

`templates/parts/notification-row.php` is a pure presenter: every notification's message, deep-link URL, icon, tone, and pill label are pre-composed by `NotificationMessageService::compose()` (`includes/Notifications/NotificationMessageService.php`) and handed in via `$args['payload']`. The row exposes the standard four hooks (`buddynext_part_notification_row_{args,classes,before,after}`), but the important consequence is upstream:

**Adding a new notification type is a service change, not a template change.** Every type has an exhaustive `case` in `NotificationMessageService::compose_single()` (and `compose_grouped()` for collapsible types) that returns its copy, plus a `meta_for()` entry for its icon/tone/label and a `url_for()` entry for its deep link. Add those cases and the row renders your type with no template edit. A type with no case is a presentation bug (the legacy fallback string was removed), so wire all three: message, meta, URL.

To restyle a specific type's row, branch on the type inside a `_classes` filter:

```php
add_filter( 'buddynext_part_notification_row_classes', static function ( array $classes, array $args ): array {
    $row = $args['notif_row'] ?? null;
    if ( $row && 'my_addon_mention' === ( $row->type ?? '' ) ) {
        $classes[] = 'is-mention';
    }
    return $classes;
}, 10, 2 );
```

## The post card

`templates/partials/post-card.php` is different from the parts above: it is a thin composer, not a leaf card. It resolves shared post state once, then delegates each UI region to a `templates/parts/post-*.php` part:

| Region part | Renders |
| --- | --- |
| `post-byline.php` | Author row, degree pill, inline Follow, options menu |
| `post-options-menu.php` | The kebab menu (rendered inside the byline) |
| `post-cw-overlay.php` | The content-warning overlay |
| `post-body.php` | Text, media, link preview, poll, shared-post embed |
| `post-reaction-summary.php` | The engagement chip strip |
| `post-actions.php` | React / Comment / Share / Save toolbar |
| `post-comments-list.php` | The thread |
| `post-comment-form.php` | The comment composer |

Each region part exposes its own four-hook contract (for example `buddynext_part_post_actions_after`, `buddynext_part_post_byline_classes`). **Customize a region through its part hooks - never fork `post-card.php`.** Adjusting the action toolbar means hooking `buddynext_part_post_actions_*`, not copying the whole card.

### Gotcha: the composite partials do not expose the top-level four-hook contract

`templates/partials/post-card.php` and `templates/partials/explore-card.php` are composers - they do **not** fire `buddynext_part_post_card_*` or `buddynext_part_explore_card_*` hooks of their own. To customize them you have three paths, in order of preference:

1. **Hook a delegated region part** (`buddynext_part_post_byline_after`, `buddynext_part_post_actions_classes`, and so on). This is the supported, update-safe path for almost everything.
2. **Use the content filters the composer already exposes.** For example `buddynext_byline_show_follow` (receives `true, $post_author_id, $post_id`) suppresses the inline byline Follow button; return `false` to remove it.
3. **Override by copy** as a last resort (see the child-theme template overrides page) - `post-card.php` carries an `Overridable:` header, but a copied composer drifts from the region parts on the next release, so prefer 1 and 2.

```php
// Remove the inline Follow button from every post byline.
add_filter( 'buddynext_byline_show_follow', '__return_false' );

// Add a class to the post action toolbar when the post is bookmarked.
add_filter( 'buddynext_part_post_actions_classes', static function ( array $classes, array $args ): array {
    if ( ! empty( $args['is_bookmarked'] ) ) {
        $classes[] = 'is-bookmarked';
    }
    return $classes;
}, 10, 2 );
```

## The shared primitives

The reusable primitives under `templates/parts/` (`empty-state`, `pagination`, `sidebar-card`, `section-head`, `stat-strip`, `filter-strip`) all follow the same four-hook contract, plus a few part-specific extras documented in `docs/specs/TEMPLATE-PARTS.md`. Render one with `buddynext_get_template( 'parts/{name}.php', $args )`, and customize it with its `_args` / `_classes` / `_before` / `_after` hooks. Notable extras:

- `filter-strip` adds `buddynext_part_filter_strip_tabs` (filter the tab list) and a `buddynext_part_filter_strip_extras` action (inject fields into the form).
- `sidebar-card` adds a contextual `buddynext_part_sidebar_card_body__{id}` action that fires inside the body slot when the card is given an `id`.
- `pagination` adds `buddynext_part_pagination_paginate_args` to filter the underlying `paginate_links()` args.

```php
// Inject a moderator-only tab into a filter strip.
add_filter( 'buddynext_part_filter_strip_tabs', static function ( array $tabs, array $args ): array {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return $tabs;
    }
    $tabs[] = array(
        'key'   => 'flagged',
        'label' => __( 'Flagged', 'my-addon' ),
        'icon'  => 'flag',
        'href'  => add_query_arg( 'filter', 'flagged' ),
    );
    return $tabs;
}, 10, 2 );
```

## Notes / gotchas

- **Filters return, actions echo.** The `_args` and `_classes` filters must return the (possibly modified) array; the `_before` / `_after` actions output directly, so escape every value you emit.
- **Read the part's `@var` header for the real `$args` keys.** Each file documents what it receives - do not guess key names.
- **Hook the region, never fork the composer.** For the post card and explore card, customize the delegated `post-*` parts, the content filters, or (last resort) override by copy - the composite partials have no top-level four-hook contract.
- **New notification type = service case.** Add the `compose_single` / `meta_for` / `url_for` cases in `NotificationMessageService`; the row template needs no change.
- **Overlay HTML filters echo raw.** `buddynext_member_card_meta_html`, `buddynext_avatar_overlay_html`, and the post overlay filters output what you return without escaping - escape at source.

See also Hooks: Template Parts for the full hook family, Overriding templates in a child theme for the copy-and-override path, and the Navigation API for adding tabs rather than cards.
