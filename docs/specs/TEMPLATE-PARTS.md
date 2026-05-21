# Template parts — reusable partial layer

BuddyNext ships a small library of reusable template parts under
`templates/parts/`. Each part wraps a v2 primitive (`.bn-empty-state`,
`.bn-pagination`, `.bn-sidebar-card`, `.bn-section-head`, `.bn-stat`,
`.bn-tabs` + `.bn-filter-strip`) so that hub templates, blocks, bridge
plugins, and child themes describe **state** instead of duplicating
markup.

Render a part with:

```php
buddynext_get_template(
    'parts/empty-state.php',
    array(
        'icon'  => 'inbox',
        'title' => __( 'No conversations yet', 'buddynext' ),
        'body'  => __( 'Start one by messaging a member.', 'buddynext' ),
    )
);
```

`buddynext_get_template()` delegates to the `TemplateLoader` service,
which checks the child theme (`{theme}/buddynext/parts/{name}.php`),
the parent theme, and the plugin defaults in order — so every part is
fully overridable downstream.

## Hook + filter contract

Every part fires the same shape of hooks. Replace `{name}` with the
part's filename (underscored — e.g. `empty_state`, `sidebar_card`,
`section_head`, `stat_strip`, `filter_strip`, `pagination`).

| Hook | Type | Purpose |
| --- | --- | --- |
| `buddynext_part_{name}_args` | filter | Filter the resolved arg array before render. Receives + returns `array`. |
| `buddynext_part_{name}_classes` | filter | Filter the root element's class list. Receives + returns `array<int,string>`. |
| `buddynext_part_{name}_before` | action | Fired before the part emits any markup. Receives `array $args`. |
| `buddynext_part_{name}_after` | action | Fired after the closing tag. Receives `array $args`. |

Parts may declare additional hooks where appropriate. Those are
documented per-part below.

## Catalogue

### `parts/empty-state.php`

Empty-state card. Wraps `.bn-empty-state`.

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `icon` | `string` | `'inbox'` | Lucide-icon slug. Pass `''` to omit the icon disc. |
| `title` | `string` | _required_ | Heading text (already-translated). The part returns silently when this is empty. |
| `body` | `string` | `''` | Supporting copy. |
| `cta_url` | `string` | `''` | URL for an optional secondary-button CTA. |
| `cta_label` | `string` | `''` | Button label. Both URL + label must be present for the button to render. |
| `cta_icon` | `string` | `''` | Lucide-icon slug rendered inside the CTA. |
| `tone` | `string` | `'default'` | Emitted as `data-tone` for future variants. |
| `classes` | `array` | `[]` | Extra CSS classes appended to `.bn-empty-state`. |

Used by: `templates/blocks/activity-feed.php`,
`templates/blocks/my-spaces.php`, `templates/blocks/space-directory.php`,
`templates/blocks/member-directory.php`,
`templates/blocks/profile-fields.php`,
`templates/blocks/trending-hashtags.php`,
`templates/spaces/home.php` (media tab).

### `parts/pagination.php`

Page-link strip. Wraps `.bn-pagination` + emits `.bn-page-btn` items
identical to the in-template idiom used previously.

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `current` | `int` | `1` | Current page (1-based). |
| `total` | `int` | `0` | Total page count. Part returns silently when `< 2`. |
| `base_url` | `string` | `''` | Base URL pattern with `%#%` placeholder; defaults to the current URL with the `paged` query var. |
| `query_var` | `string` | `'paged'` | Query var used to drive the default `base_url`. |
| `end_size` | `int` | `1` | Page numbers shown at each edge. |
| `mid_size` | `int` | `2` | Page numbers shown around the current page. |
| `prev_text` | `string` | `'&laquo; Prev'` | Previous-link text. |
| `next_text` | `string` | `'Next &raquo;'` | Next-link text. |
| `aria_label` | `string` | `'Pagination'` | ARIA label for the `<nav>`. |
| `classes` | `array` | `[]` | Extra CSS classes. |

Extra filter: `buddynext_part_pagination_paginate_args( array $paginate_args, array $args )` — filter the underlying `paginate_links()` args.

Used by: `templates/profile/connections.php`.

### `parts/sidebar-card.php`

Sidebar widget shell. Wraps `.bn-sidebar-card` and provides three
ways to supply body content:

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `id` | `string` | `''` | Suffix used in the contextual body hook (`buddynext_part_sidebar_card_body__{id}`). Sanitized with `sanitize_key()`. |
| `title` | `string` | _required_ | Heading text. The part returns silently when empty. |
| `title_icon` | `string` | `''` | Lucide-icon slug rendered next to the title. |
| `body_html` | `string` | `''` | Pre-built body HTML. Caller is responsible for escaping. |
| `body_action` | `string` | `''` | A `do_action()` hook fired inside the body slot. Receives `array $args`. |
| `see_all_url` | `string` | `''` | URL for an optional `bn-sidebar-see-all` link rendered after the body. |
| `see_all_label` | `string` | `''` | Label for the see-all link. |
| `classes` | `array` | `[]` | Extra CSS classes. |

Extra action: `buddynext_part_sidebar_card_body__{id}` — fires inside the body slot when `id` is set.

### `parts/section-head.php`

Hub / admin section header. Wraps a new `.bn-section-head` primitive
(see `assets/css/bn-base.css`) that holds an icon, title, optional
subtitle, optional meta row, and an actions slot.

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `title` | `string` | _required_ | Heading text. |
| `subtitle` | `string` | `''` | Supporting text shown beneath the title. |
| `title_icon` | `string` | `''` | Lucide-icon slug rendered before the title. |
| `heading_level` | `string` | `'h1'` | One of `h1`, `h2`, `h3`. |
| `meta` | `array` | `[]` | List of `[ 'icon' => string, 'label' => string ]` items. |
| `actions_html` | `string` | `''` | Pre-built HTML for the actions slot. |
| `actions_action` | `string` | `''` | `do_action()` hook fired inside the actions slot. |
| `classes` | `array` | `[]` | Extra CSS classes. |

### `parts/stat-strip.php`

Grid of `.bn-stat` tiles inside `.bn-stat-grid`.

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `stats` | `array` | _required_ | List of tile descriptors (see below). |
| `classes` | `array` | `[]` | Extra CSS classes. |

Each tile descriptor accepts:

| Key | Type | Purpose |
| --- | --- | --- |
| `label` | `string` | Tile label (required). |
| `value` | `string\|int` | Tile value (required). |
| `delta` | `string` | Delta text (e.g. `+12%`). |
| `trend` | `string` | `up`, `down`, or `flat` (default `flat`). |
| `icon` | `string` | Lucide-icon slug. |
| `href` | `string` | URL — when present, the tile becomes an anchor. |
| `tone` | `string` | Emitted as `data-tone` (reserved). |

### `parts/filter-strip.php`

Top-of-list chrome wrapping `.bn-tabs`, `.bn-input`, and `.bn-select`.

| Arg | Type | Default | Purpose |
| --- | --- | --- | --- |
| `tabs` | `array` | `[]` | List of `[ 'key', 'label', 'href'?, 'count'?, 'icon'? ]` items. |
| `active` | `string` | `''` | Key of the currently-active tab. |
| `search` | `array` | `[]` | `[ 'name', 'value'?, 'placeholder'?, 'aria_label'? ]`. |
| `selects` | `array` | `[]` | List of `[ 'name', 'value'?, 'options', 'aria_label'? ]`. |
| `form_action` | `string` | `''` | Wrapping `<form action>`. Empty = current URL. |
| `form_method` | `string` | `'get'` | `get` or `post`. |
| `hidden` | `array` | `[]` | `[ name => value ]` pairs for hidden inputs. |
| `classes` | `array` | `[]` | Extra CSS classes. |

Extra hooks:
- Action `buddynext_part_filter_strip_extras` — fires inside the form, after the rendered fields, before the submit button.
- Filter `buddynext_part_filter_strip_tabs` — filter the tab list before render.

## How Pro and bridge plugins consume these

Do not copy hub-template chrome. Hook the partials instead.

Examples:

```php
// Add a "Resume" CTA to the empty-state on Pro's onboarding-incomplete card.
add_action( 'buddynext_part_empty_state_after', function ( array $args ) {
    if ( 'onboarding-incomplete' !== ( $args['tone'] ?? '' ) ) {
        return;
    }
    printf(
        '<a class="bn-btn" data-variant="primary" data-size="sm" href="%s">%s</a>',
        esc_url( home_url( '/getting-started/' ) ),
        esc_html__( 'Resume setup', 'buddynext-pro' )
    );
} );

// Inject a moderator-only filter into the search/notifications filter strip.
add_filter( 'buddynext_part_filter_strip_tabs', function ( array $tabs, array $args ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return $tabs;
    }
    $tabs[] = array(
        'key'   => 'flagged',
        'label' => __( 'Flagged', 'buddynext-pro' ),
        'icon'  => 'flag',
        'href'  => add_query_arg( 'filter', 'flagged' ),
    );
    return $tabs;
}, 10, 2 );
```

The same pattern applies to every part: filter `_args` or `_classes`,
or hook `_before` / `_after`. Pro is never required to ship a forked
hub template.

## Authoring a new part

1. Drop a new file under `templates/parts/{name}.php`.
2. Reserve four hooks: `buddynext_part_{name}_args`,
   `buddynext_part_{name}_classes`, `buddynext_part_{name}_before`,
   `buddynext_part_{name}_after`. Add contextual hooks as needed.
3. Use v2 primitives only — no inline `<style>` or `<script>`.
4. Escape at point of emit.
5. Add an entry to this document.
