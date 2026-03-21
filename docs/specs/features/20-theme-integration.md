# BuddyNext — Theme Integration

**Status:** Locked
**Last updated:** 2026-03-20

---

## Approach

**Blocks inherit via `supports`. PHP-rendered pages use `--bn-*` CSS tokens mapped from `--wp--preset--*` vars. BuddyNext ships a plugin-level `theme.json` as the guaranteed fallback — active theme always wins. Kirki Customizer values feed in via a filter.**

Works out of the box on every theme: block themes, hybrid themes (BuddyX, BuddyX Pro, Reign), classic themes. Third-party themes get sensible defaults automatically from the plugin `theme.json`; integration-aware themes pass Customizer values via the `buddynext_css_vars` filter.

---

## Layer Cascade

```
1. BuddyNext plugin theme.json        ← always present, neutral defaults
        ↓  (active theme overrides)
2. Active theme theme.json            ← theme palette, fonts, spacing
        ↓  (Kirki Customizer overrides, via filter in BuddyX Pro / Reign)
3. buddynext_css_vars filter          ← get_theme_mod() values mapped in by theme
        ↓
4. --bn-* CSS tokens output at wp_head
        ↓
5. All BuddyNext component CSS uses var(--bn-*)
```

---

## Plugin-Level `theme.json`

BuddyNext ships `theme.json` at the plugin root. WordPress (6.1+) merges plugin `theme.json` into the global styles cascade — active theme always takes priority.

Defines these preset slugs as neutral/accessible defaults:

```json
{
  "$schema": "https://schemas.wp.org/trunk/theme.json",
  "version": 3,
  "settings": {
    "color": {
      "palette": [
        { "slug": "primary",   "color": "#0073aa", "name": "Primary" },
        { "slug": "secondary", "color": "#005a87", "name": "Secondary" },
        { "slug": "base",      "color": "#ffffff", "name": "Base" },
        { "slug": "contrast",  "color": "#1e1e1e", "name": "Contrast" },
        { "slug": "subtle",    "color": "#6b7280", "name": "Subtle" },
        { "slug": "border",    "color": "#e0e0e0", "name": "Border" },
        { "slug": "surface",   "color": "#f9f9f9", "name": "Surface" },
        { "slug": "success",   "color": "#00a32a", "name": "Success" },
        { "slug": "error",     "color": "#d63638", "name": "Error" }
      ]
    },
    "typography": {
      "fontSizes": [
        { "slug": "xs",   "size": "0.75rem",  "name": "Extra Small" },
        { "slug": "sm",   "size": "0.875rem", "name": "Small" },
        { "slug": "md",   "size": "1rem",     "name": "Medium" },
        { "slug": "lg",   "size": "1.125rem", "name": "Large" },
        { "slug": "xl",   "size": "1.5rem",   "name": "Extra Large" }
      ]
    },
    "spacing": {
      "spacingSizes": [
        { "slug": "xs", "size": "0.25rem", "name": "XS" },
        { "slug": "sm", "size": "0.5rem",  "name": "SM" },
        { "slug": "md", "size": "1rem",    "name": "MD" },
        { "slug": "lg", "size": "1.5rem",  "name": "LG" },
        { "slug": "xl", "size": "2rem",    "name": "XL" }
      ]
    }
  }
}
```

Active theme `theme.json` with matching slugs automatically overrides these values. A theme that defines `primary` in its own palette replaces BuddyNext's default — zero configuration needed.

---

## Two Surfaces, Two Strategies

### Gutenberg Blocks

All BuddyNext blocks declare `supports` in `block.json`:

```json
"supports": {
  "color": { "background": true, "text": true, "link": true },
  "typography": { "fontSize": true, "lineHeight": true },
  "spacing": { "padding": true, "margin": true, "blockGap": true }
}
```

Active theme's palette, font sizes, and spacing are available in block controls — including BuddyNext's own fallback presets when the theme doesn't define them.

### PHP-Rendered Pages

Feed, profile, spaces, DM, notifications, member directory, onboarding — all PHP-rendered. BuddyNext outputs `--bn-*` CSS tokens at `wp_head` via `wp_add_inline_style()`:

```php
// BuddyNext maps --wp--preset--* vars to --bn-* tokens
// Active theme's theme.json has already set --wp--preset--color--primary
// (or BuddyNext's plugin theme.json fallback applies)

$defaults = [
    '--bn-color-primary'       => 'var(--wp--preset--color--primary, #0073aa)',
    '--bn-color-secondary'     => 'var(--wp--preset--color--secondary, #005a87)',
    '--bn-color-surface'       => 'var(--wp--preset--color--base, #ffffff)',
    '--bn-color-surface-raised'=> 'var(--wp--preset--color--surface, #f9f9f9)',
    '--bn-color-border'        => 'var(--wp--preset--color--border, #e0e0e0)',
    '--bn-color-text'          => 'var(--wp--preset--color--contrast, #1e1e1e)',
    '--bn-color-text-muted'    => 'var(--wp--preset--color--subtle, #6b7280)',
    '--bn-color-success'       => 'var(--wp--preset--color--success, #00a32a)',
    '--bn-color-error'         => 'var(--wp--preset--color--error, #d63638)',
    '--bn-font-size-xs'        => 'var(--wp--preset--font-size--xs, 0.75rem)',
    '--bn-font-size-sm'        => 'var(--wp--preset--font-size--sm, 0.875rem)',
    '--bn-font-size-md'        => 'var(--wp--preset--font-size--md, 1rem)',
    '--bn-font-size-lg'        => 'var(--wp--preset--font-size--lg, 1.125rem)',
    '--bn-font-size-xl'        => 'var(--wp--preset--font-size--xl, 1.5rem)',
    '--bn-font-family'         => 'inherit',
    '--bn-space-xs'            => 'var(--wp--preset--spacing--xs, 0.25rem)',
    '--bn-space-sm'            => 'var(--wp--preset--spacing--sm, 0.5rem)',
    '--bn-space-md'            => 'var(--wp--preset--spacing--md, 1rem)',
    '--bn-space-lg'            => 'var(--wp--preset--spacing--lg, 1.5rem)',
    '--bn-space-xl'            => 'var(--wp--preset--spacing--xl, 2rem)',
    '--bn-radius-sm'           => '4px',
    '--bn-radius-md'           => '8px',
    '--bn-radius-lg'           => '12px',
    '--bn-radius-full'         => '9999px',
];

$vars = apply_filters( 'buddynext_css_vars', $defaults );
// outputs: :root { --bn-color-primary: var(--wp--preset--color--primary, #0073aa); ... }
```

All BuddyNext component CSS uses only `--bn-*` tokens — never `--wp--preset--*` directly.

---

## Customizer Integration (`buddynext_css_vars` filter)

BuddyX Pro / Reign hook this filter to pass Kirki `get_theme_mod()` values. This lives in the **theme**, not in BuddyNext:

```php
// In BuddyX Pro: inc/buddynext-compat.php
add_filter( 'buddynext_css_vars', function ( $vars ) {
    $primary = get_theme_mod( 'buddyx_primary_color' );
    if ( $primary ) {
        $vars['--bn-color-primary']        = $primary;
        $vars['--bn-color-primary-hover']  = buddyx_adjust_color( $primary, -15 );
    }
    $body_font = get_theme_mod( 'buddyx_body_font_family' );
    if ( $body_font ) {
        $vars['--bn-font-family'] = $body_font;
    }
    return $vars;
} );
```

**Result:** User sets brand color once in the Customizer. BuddyNext picks it up automatically — no duplication, no extra settings panel.

---

## Theme Compatibility Summary

| Theme | Blocks | PHP pages |
|-------|--------|-----------|
| BuddyX / BuddyX Pro | Inherit palette via `supports` + plugin theme.json | Add filter in theme mapping Kirki mods → `buddynext_css_vars` |
| Reign | Inherit palette via `supports` + plugin theme.json | Add filter in theme mapping Kirki mods → `buddynext_css_vars` |
| Any block theme with theme.json | Full inheritance | Preset slugs map to `--bn-*` automatically |
| Classic theme (no theme.json) | `supports` present, plugin theme.json provides palette | Neutral defaults from plugin theme.json apply |
| Third-party theme | Same as block theme or classic above | Works out of the box |

---

## CSS Conventions

| Rule | Detail |
|------|--------|
| All classes | `.bn-` prefix |
| No `!important` | Never |
| No hardcoded hex | All via `--bn-*` tokens |
| No hardcoded `px` font sizes | All via `--bn-font-size-*` tokens |
| No inline `style=""` | Never for design values |
| `font-family: inherit` default | Body font inherits theme unless token overrides |

---

## Gaps / Open Questions

- None — fully locked
