# Design System: Tokens, Primitives, and the Shell Contract

The contract a theme author needs to restyle, override, or extend BuddyNext's frontend: the three-layer `--bn-*` token cascade, the component primitives, the shell model (who owns what markup), the mobile bottom nav, RTL and dark-mode rules, and the `buddynext_part_*` hooks for overriding markup. This page is for theme developers and bridge authors.

## Overview / Contract

BuddyNext renders inside your theme's chrome and styles itself entirely from CSS custom properties. You do not need to fork a template or write a stylesheet to re-skin it. The contract is:

- **Tokens are the single source of truth.** Every color, type size, weight, spacing, radius, and shadow is a `--bn-*` custom property. No frontend CSS hardcodes a hex, px, or font-family value. Re-theming is overriding tokens, not rewriting CSS.
- **Components are attribute-driven primitives.** A small set of `.bn-*` classes (button, card, input, badge, page, empty state) carry all variant logic through `data-*` attributes, so you style by token, not by reimplementing components.
- **Your theme owns the document; BuddyNext owns `.bn-app`.** `get_header()` and `get_footer()` are yours (your header IS the top navigation). BuddyNext renders only the rail, main column, optional right sidebar, and mobile bottom nav between them.
- **Markup is overridable two ways.** Copy a template part into `{theme}/buddynext/`, or hook the `buddynext_part_*` actions and filters - no fork required.

This page covers the contract. Canonical token values live in `assets/css/bn-base.css` (`:root`) and `docs/v2 Plans/tokens.css`; the component library is `docs/v2 Plans/style-guide.html`. Read those for exact values - this page does not duplicate the value table, which drifts.

## The three-layer token model

A single `--bn-hue` cascades through an OKLCH accent ramp, so the whole product re-tints from one hue change. `TokenService` (`includes/Theme/TokenService.php`) injects the resolved values inline on the `bn-base` style handle. Values resolve through three layers, in order:

| Layer | Source | Wins when |
| --- | --- | --- |
| 1. Your theme's preset | `theme.json` `--wp--preset--color--*`, `--wp--preset--font-size--*`, etc. | The preset slug is defined - the host theme always wins. |
| 2. BuddyNext OKLCH default | The `--bn-*` ramp in `assets/css/bn-base.css`, driven by `--bn-hue` / `--bn-chroma`. | No matching theme preset is set. |
| 3. Plugin `theme.json` fallback | BuddyNext's own `theme.json` palette and presets. | Merged by WordPress as the baseline; the active theme overrides it. |

Concretely, each legacy alias resolves to `var(--wp--preset--*, var(--bn-*))`. For example `--brand` is `var(--wp--preset--color--primary, var(--bn-accent))`: if your `theme.json` defines a `primary` color it wins; otherwise the OKLCH accent is used. Nothing in this chain pins a hex value.

### Token families

All tokens are `--bn-` prefixed. The families:

| Family | Tokens |
| --- | --- |
| Surfaces | `--bn-bg`, `--bn-canvas`, `--bn-surface`, `--bn-sunken`, `--bn-raised` |
| Ink (text) | `--bn-ink`, `--bn-ink-2`, `--bn-ink-3`, `--bn-ink-4` |
| Lines / focus | `--bn-line`, `--bn-line-faint`, `--bn-line-strong`, `--bn-ring` |
| Accent ramp | `--bn-accent`, `--bn-accent-50` ... `--bn-accent-900`, `--bn-accent-fg` |
| Semantic | `--bn-success(-bg)`, `--bn-danger(-bg)`, `--bn-info(-bg)`, `--bn-warn(-bg)` |
| Integration accents | `--bn-jetonomy(-bg)`, `--bn-media(-bg)`, `--bn-paid(-bg)`, `--bn-events(-bg)` |
| Type | `--bn-font-{body,display,ui,mono}`, `--bn-text-{2xs..4xl,base,md}`, `--bn-fw-{normal..extrabold}`, `--bn-leading-{tight,snug,normal,body}` |
| Spacing (4px grid) | `--bn-s1` ... `--bn-s16` |
| Radius | `--bn-r-{sm,md,lg,xl,full}` |
| Shadow | `--bn-shadow-{xs,sm,md,lg}` |

Bare-named aliases (`--bg`, `--text-1`, `--s4`, ...) exist only for back-compat. Always author against the `--bn-*` names.

### Overriding a token

Two ways, both supported. The cleanest is a `theme.json` preset - it slots into layer 1 with no PHP:

```json
{
    "settings": {
        "color": {
            "palette": [
                { "slug": "primary", "color": "#0a66c2", "name": "Brand" }
            ]
        }
    }
}
```

That single `primary` slug re-tints every BuddyNext surface that resolves `--brand` / `--bn-accent`, because BuddyNext prefers `--wp--preset--color--primary` over its own default.

For finer control - or to override a token that has no theme.json preset - use the `buddynext_css_vars` filter. It receives the resolved token map (`TokenService::get_defaults()`) before it is injected:

```php
add_filter( 'buddynext_css_vars', function ( array $vars ) {
    $vars['--bn-hue']    = '255';   // shift the whole OKLCH accent ramp
    $vars['--bn-r-md']   = '12px';  // rounder cards and buttons
    $vars['--bn-surface'] = 'var(--wp--preset--color--base, #fff)';
    return $vars;
} );
```

A matching `buddynext_css_vars_dark` filter overrides the dark-mode token map (`TokenService::get_dark_overrides()`).

> **Warning:** Do not add a stylesheet that hardcodes hex/px to restyle BuddyNext. Override the tokens. Hardcoded values break dark mode and RTL, which the token path handles automatically. The plugin's own `bin/ux-audit.sh` (gate F3) rejects raw hex/px in BuddyNext CSS for the same reason.

## Component primitives

BuddyNext composes its UI from a small primitive vocabulary. Variants are expressed as `data-*` attributes, not extra classes, so you re-skin by styling the base class plus its attribute selectors.

| Primitive | Class | Variant API | Notes |
| --- | --- | --- | --- |
| Page shell | `.bn-page` | - | Outer page container for a hub surface. |
| Card | `.bn-card` | `data-interactive` | Surface container; `data-interactive` adds hover affordance. |
| Button | `.bn-btn` | `data-variant` (`primary`, `secondary`, `danger`, `ghost`, `ai`), `data-size` (`sm`, `md`) | Single button primitive; never style a bare `<button>`. |
| Input | `.bn-input` | - | Text inputs, selects (`.bn-select`), textareas. |
| Badge | `.bn-badge` | `data-tone` | Count and status pills. |
| Empty state | `.bn-empty-state` | `data-tone` | Empty/zero-result card. Rendered via the `parts/empty-state.php` part. |

> **Note:** Interactive states (`:hover`, `:focus`, `:active`, `[aria-selected]`) are scoped under `.bn-app` so your theme's bare-element chrome cannot bleed into BuddyNext controls, and BuddyNext's styles cannot leak out into your header. Focus rings use `--bn-ring`.

The navigation components are a separate, conflict-free set with their own namespaces (`.bn-nav-metrics`, `.bn-tabs`, `.bn-subnav`) so a change to one never bleeds into another - see Navigation components in `docs/standards/nav-components.md`.

## The shell model

Every BuddyNext-mapped slug (activity, members, spaces, messages, notifications, auth, onboarding, moderation) renders inside your active theme's chrome. There is no shell-takeover mode and no opt-out filter - theme chrome is the only mode.

The render sequence (`PageRouter` -> `templates/shell/hub-shell.php`):

```text
get_header()                      <- your theme: DOCTYPE, <head>, wp_head(),
                                     <body>, site header / nav / branding.
                                     THIS HEADER IS THE TOP NAVIGATION.
  <div class="bn-app">            <- BuddyNext owns this subtree only.
    rail  +  main  +  right-sidebar?  +  mobile bottom nav
  </div>
get_footer()                      <- your theme: site footer, wp_footer().
```

What this means for a theme author:

- BuddyNext does **not** render a topbar inside `.bn-app`. Your site header is the top nav. Style it as you normally would.
- `.bn-app` bursts to `100vw` via `bn-shell.css` so it stays edge-to-edge regardless of your theme's content container `max-width`. You do not need a full-width page template, though one looks best.
- The left rail and mobile bottom nav are gated by the owner's "Show community navigation" setting. When off, navigation is driven entirely from your theme menus and BuddyNext renders neither.
- The right sidebar auto-renders when anything is hooked onto `buddynext_right_sidebar` (and the Sidebar feature is enabled). Register widgets with `add_action( 'buddynext_right_sidebar', ... )`.

### Mobile bottom navigation

Below 640px the left rail hides and the `.bn-mobile-nav` bottom tab bar (`templates/partials/nav.php`) becomes the primary navigation. It is rendered by `hub-shell.php` on every hub, so it appears consistently without each hub template including it. Item classes follow the BEM pattern: `.bn-mobile-nav__item`, with `--create`, `--more`, and `--active` modifiers. The active item also carries `aria-current="page"`; after a client-side navigation the active marker is re-synced in JS.

## RTL and dark mode

Both are handled by the token system and logical CSS, so token-driven overrides inherit them for free.

**RTL.** BuddyNext CSS uses logical properties throughout - `margin-inline-start` / `-end`, `padding-inline`, `inset-inline` - never physical `left` / `right`. WordPress flips direction from the site locale; you do not ship an RTL stylesheet. If you add overriding CSS, use logical properties too.

**Dark mode.** Tokens flip under any of these selectors on an ancestor:

```css
[data-bn-theme="dark"],   /* BuddyNext's own toggle (v2 canonical) */
[data-theme="dark"],      /* legacy alias still honored */
[data-bx-mode="dark"]     /* bridges BuddyX / Reign's .bx-color-mode toggle */
```

The third selector means that if your theme is BuddyX or Reign, BuddyNext follows the host theme's dark toggle automatically. A `prefers-color-scheme: dark` block also applies when the user has set no explicit theme. Because dark mode flips the `--bn-*` values, anything authored against tokens is dark-safe with no extra work. Verify dark via the real theme toggle, not a hand-set attribute.

## Overriding markup: the `buddynext_part_*` hooks

BuddyNext ships a library of reusable template parts under `templates/parts/` (empty state, pagination, sidebar card, section head, stat strip, filter strip, and feature panels). Each wraps a primitive so you describe state instead of duplicating markup. There are two ways to override a part.

### 1. Copy the part into your theme

`buddynext_get_template()` resolves through the child theme, then the parent theme, then the plugin default. So copying a file overrides it:

```text
{your-theme}/buddynext/parts/empty-state.php   <- wins over the plugin's copy
```

This is the right tool when you want to change the part's structure wholesale.

### 2. Hook the part's actions and filters

Every part fires the same four hooks (replace `{name}` with the part's filename, underscored - `empty_state`, `sidebar_card`, `section_head`, `stat_strip`, `filter_strip`, `pagination`):

| Hook | Type | Purpose |
| --- | --- | --- |
| `buddynext_part_{name}_args` | filter | Filter the resolved arg array before render. Receives and returns `array`. |
| `buddynext_part_{name}_classes` | filter | Filter the root element's class list. Receives and returns `array<int,string>`. |
| `buddynext_part_{name}_before` | action | Fires before the part emits markup. Receives `array $args`. |
| `buddynext_part_{name}_after` | action | Fires after the closing tag. Receives `array $args`. |

This is the right tool for additive changes - inject a button, add a tab, append a class - without forking the file.

### Overriding a template part - worked example

Add a CTA to the empty-state card on a specific surface, and inject a moderator-only tab into a filter strip:

```php
// Append a "Resume setup" button to the onboarding-incomplete empty state.
add_action( 'buddynext_part_empty_state_after', function ( array $args ) {
    if ( 'onboarding-incomplete' !== ( $args['tone'] ?? '' ) ) {
        return;
    }
    printf(
        '<a class="bn-btn" data-variant="primary" data-size="sm" href="%s">%s</a>',
        esc_url( home_url( '/getting-started/' ) ),
        esc_html__( 'Resume setup', 'my-theme' )
    );
} );

// Add a moderator-only "Flagged" tab to any filter strip.
add_filter( 'buddynext_part_filter_strip_tabs', function ( array $tabs, array $args ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return $tabs;
    }
    $tabs[] = array(
        'key'   => 'flagged',
        'label' => __( 'Flagged', 'my-theme' ),
        'icon'  => 'flag',
        'href'  => add_query_arg( 'filter', 'flagged' ),
    );
    return $tabs;
}, 10, 2 );
```

The same `_args` / `_classes` / `_before` / `_after` pattern applies to every part, so a theme or bridge never needs to ship a forked hub template. The full part catalogue (every part's args and extra hooks) is in `docs/specs/TEMPLATE-PARTS.md`.

## Notes / gotchas

- **Icons are SVG, never emoji or icon fonts.** Use `buddynext_icon( 'slug' )` in templates; the slugs are Lucide-style SVGs in `assets/icons/`. Custom icons follow the same `stroke="currentColor"`, `viewBox="0 0 24 24"`, no width/height shape.
- **Never hardcode values to restyle.** Hardcoded hex/px breaks dark mode and RTL. Override tokens via `theme.json` or `buddynext_css_vars`.
- **`.bn-app` scoping is deliberate.** It isolates BuddyNext's interactive states from your theme and vice versa. Keep any custom rules scoped under `.bn-app` so they do not leak.
- **Free and Pro share the token system.** Pro adds no second token set; it reuses the same `--bn-*` families and the same `buddynext_css_vars` override point, so one theme override covers both tiers.
