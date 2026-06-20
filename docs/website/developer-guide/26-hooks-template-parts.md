# Hooks: Template Parts

BuddyNext renders its frontend from small, reusable template parts under `templates/parts/`. Every part exposes the same three-hook convention so you can inject markup around it and reshape the data it renders without overriding the template. This page covers that convention and is for anyone theming or extending BuddyNext's frontend.

![A feed view built from the template parts whose three-hook convention this page documents](../images/community-activity-feed.webp)

This is the dominant hook family in BuddyNext. Of the 1055 hooks the Free plugin fires, 705 are `buddynext_part_*` hooks - it is the primary theming seam, and the one you will reach for most when adjusting presentation.

## Overview / Contract

Each template part fires a consistent set of hooks named after the part. For a part named `{part}` the convention is:

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_part_{part}_args` | filter | Right after the part assembles its arguments, before it renders | `array $args` |
| `buddynext_part_{part}_before` | action | Just before the part's root markup is output | `array $args` |
| `buddynext_part_{part}_after` | action | Just after the part's root markup is output | `array $args` |

Across all parts this produces 177 `_args` filters, 174 `_before` actions, and 174 `_after` actions. The counts differ slightly because a few parts have additional, part-specific seams (described below) and a small number of parts predate or omit one of the three.

Many parts also expose a `buddynext_part_{part}_classes` filter (and occasionally `_tabs` or `_extras`) so you can add CSS classes to a part's root element or extend a specific region. These are part-specific - check the part's PHP header to see which it offers.

### How a part is wired

Every part follows the same shape internally. Reading `templates/parts/post-actions.php` (the post action toolbar - React, Comment, Share, Save buttons), the order is:

1. The part collects and sanitizes its inputs into a single `$args` array.
2. It runs `$args` through `buddynext_part_{part}_args` so you can add, remove, or rewrite values.
3. It runs its root class list through `buddynext_part_{part}_classes` (when the part has one).
4. It fires `buddynext_part_{part}_before` with the final `$args`.
5. It outputs its markup.
6. It fires `buddynext_part_{part}_after` with the final `$args`.

The `$args` array is documented in each part's file header (the `@var` block) and in the `Fires:` / `Filters:` comment, so the per-part contract is self-describing in the source. For `post-actions` the array carries `bn_post`, `bn_post_id`, `bn_post_type`, `user_reaction`, `is_bookmarked`, the `can_*` capability flags, the counts, and `classes`.

> **Note:** Hooks receive `$args` after sanitization, in the order listed above. A value you add in the `_args` filter is visible to the `_classes` filter and to the `_before` / `_after` actions, because they all run on the same already-filtered array.

## Injecting markup before or after a part

Use the `_before` and `_after` actions to add markup around a part without touching the template. Both pass the part's final `$args`, so your markup can be context-aware.

This example adds a small "boosted" badge after the action toolbar on posts flagged in post meta:

```php
add_action(
    'buddynext_part_post_actions_after',
    function ( array $args ): void {
        $post_id = (int) ( $args['bn_post_id'] ?? 0 );
        if ( 0 === $post_id || ! get_post_meta( $post_id, '_my_boosted', true ) ) {
            return;
        }
        printf(
            '<span class="my-boosted-badge">%s</span>',
            esc_html__( 'Boosted', 'my-addon' )
        );
    }
);
```

Use `_before` the same way when your markup belongs ahead of the part. Escape everything you output - these actions write directly into the rendered page.

## Filtering a part's arguments

Use the `_args` filter to reshape what a part renders before it builds its markup. Return the modified array.

This example hides the Share button on a specific post type by clearing the `can_share` flag in the `post-actions` args:

```php
add_filter(
    'buddynext_part_post_actions_args',
    function ( array $args ): array {
        if ( 'announcement' === ( $args['bn_post_type'] ?? '' ) ) {
            $args['can_share'] = false;
        }
        return $args;
    }
);
```

To add a CSS class to a part's root element, use its `_classes` filter. This filter receives the class array and the part's `$args` as a second argument:

```php
add_filter(
    'buddynext_part_post_actions_classes',
    function ( array $classes, array $args ): array {
        if ( ! empty( $args['is_bookmarked'] ) ) {
            $classes[] = 'is-bookmarked';
        }
        return $classes;
    },
    10,
    2  // the _classes filter passes 2 arguments
);
```

> **Tip:** When you only need to restyle a part, prefer the `_classes` filter over overriding the template file. It survives plugin updates, where a copied template can silently drift out of sync with the part's real markup.

## Notes / gotchas

- **Find the exact part name and args in the source.** Each file under `templates/parts/` documents its `$args` keys and the hooks it fires in the PHP header. The part name in the hook is the file name with hyphens replaced by underscores - `post-actions.php` fires `buddynext_part_post_actions_*`, `dm-composer.php` fires `buddynext_part_dm_composer_*`, `member-card.php` fires `buddynext_part_member_card_*`.
- **Not every part exposes all five seams.** `_args`, `_before`, and `_after` are the convention; `_classes`, `_tabs`, and `_extras` are present only on parts that need them. The `_classes` family is the most common extra and is almost always a filter that takes `(array $classes, array $args)`.
- **Always return from a filter; always escape in an action.** The `_args` and `_classes` filters must return the (possibly modified) array. The `_before` / `_after` actions output directly, so run every value through the right `esc_*` function.
- **Theming over template overrides.** Template parts can be overridden by copying them into `{theme}/buddynext/`, but the hook seams on this page are the lower-maintenance path: they keep your customization independent of the part's internal markup, which can change between releases.
- **This family is presentation, not events.** For reacting to platform events (a post was created, a member was followed), use the domain hooks on the per-domain pages (27-33), not the template-part hooks. See Hooks Overview for the full map.
