# Admin UI Consistency Audit — premium chrome pass (free + Pro)

Status: audit (owner chose "audit first, then fix"). Goal: one cohesive,
premium admin — no naked sections, no double boxes, one nav vocabulary, one
form vocabulary. Fix free first, then Pro in lockstep, browser-verified per
screen (desktop + 390px).

## Canonical rules (the system every screen must follow)

1. **Tabs (navigation)** — one underline strip at every level: section tabs
   (`.bn-admin-hub__tabs`), page sub-nav (`.bn-atab`), in-page content sub-nav.
   No pill/segmented "boxed" nav for navigation.
2. **Range/segment filters** — a single shared *segmented* control (7/30/90,
   All/Active), visually distinct from tabs so it doesn't read as a 4th nav row.
3. **Cards** — every content block sits in a card (`.bn-settings-section`).
   No content floating on bare grey ("naked"), no card-inside-card ("double box"),
   no boxes touching (consistent vertical rhythm).
4. **Forms** — one field vocabulary; taxonomy editors (member types, space
   categories) share ONE compact editor (see below).
5. **Empty states** — one pattern: icon + line + optional CTA, inside a card.
6. **Buttons / stat cards / spacing** — accent-normalized, token spacing.

## Already unified this session

- Section tabs + page sub-nav (`.bn-atab`) → underline; wide editors use the
  same strip (picker removed); stat-grid no longer touches the panel below.

## Findings (observed) → fix

| Screen / pattern | Violation | Fix |
|---|---|---|
| Engagement → **Analytics** (Pro) | Content sub-nav (Overview/Cohorts/Funnel/Profile Views) + range pills + stat grid render **naked** on grey | Wrap content in a card; content sub-nav → underline; range → shared segment control |
| Range selectors (7/30/90 days) | Bespoke pill style | Shared `.bn-segment` control |
| **Member Types add form** | Cluttered, bad UX (separate Badge BG / Badge Text / **Icon SVG paste** / separate "Preview" button / 2 toggles), and **inconsistent with the Space Categories editor** | Adopt ONE shared taxonomy-editor (below) for both |
| Space Categories editor | Fewer fields than member types — inconsistent | Same shared editor |
| Pro list screens (Broadcasts/Drip/Scheduled/AI/Bulk/Rules/Labels/Membership/Stripe) | Auto-card covers forms/tables, but custom nav/stat/empty markup may render naked | Per-screen verify; wrap stray blocks |
| Empty states | Mixed ("Nothing to review" vs bare text) | One carded empty-state pattern |

## Shared taxonomy editor (member types + space categories)

One compact form used by both:

- **Name** (required) → auto-fills **Slug** (editable, advanced).
- **Description** (short).
- **Color** (single swatch) with an **inline live badge preview** beside it —
  no separate "Preview" button, no separate text-colour picker (derive readable
  text colour from the background, with an advanced override).
- **Advanced (collapsed)**: custom Slug, custom text colour, Icon (picker first;
  raw SVG paste behind "advanced"), Sort order.
- **Visibility toggles**: "Show in directory filter", "Allow self-assign"
  (member types) / "Show in directory" (categories) — same control style.

Result: the common case is Name + Color + Save; power options are tucked away.
Space categories gain colour so they can be colour-coded consistently.

## Scope to sweep

Free: Settings, Platform, Members (+ Profile Fields / Avatar / Member Types /
Invites), Spaces (+ Categories), Moderation, Engagement→Insights, Notifications.
Pro: Analytics, Reactions, Realtime, Push, Push Prefs, Broadcasts, Drip,
Scheduled, AI Feed, AI Moderation, Rules, Bulk, Member Labels, Tiers,
Subscriptions, Stripe, Paywall.

## Execution

1. Land the shared CSS primitives: `.bn-segment` (range filters), a carded
   wrapper helper for naked Pro blocks, the shared taxonomy-editor markup/CSS.
2. Free screens → fix + verify each (desktop + 390px).
3. Pro screens → fix in lockstep + verify each.
4. Re-run UX audit + WPCS on all touched files.

## Header API (foundation — landed)

AdminHub now owns the entire screen header so every screen looks identical and
no screen prints its own title. This is the single reference the screen-conversion
work follows.

### How a screen declares its header

A screen declares its subtitle + primary action through `register_tab()` args —
it never prints them itself:

```php
\BuddyNext\Admin\AdminHub::register_tab(
    'engagement',          // origin section
    'analytics',           // tab slug
    __( 'Analytics', 'buddynext' ),
    array( $this, 'render_content' ),
    array(
        'cap'      => 'manage_options',
        'subtitle' => __( 'Community engagement over time.', 'buddynext' ),
        // Pre-built, ALREADY-ESCAPED HTML. The screen escapes everything inside.
        'action'   => $export_form_html,
    )
);
```

- `subtitle` (string) — one-line description. AdminHub escapes it with `esc_html`.
- `action` (string) — trusted, pre-escaped HTML (e.g. an Export CSV form/button).
  Printed verbatim; the supplying screen is contractually responsible for escaping
  every value inside it.

Both are optional. The bar renders only when at least one is set.

### Markup AdminHub emits

After the underline tab strip (`render_tabstrip`) and before `<main>`:

```html
<div class="bn-admin-hub__subhead">
  <p class="bn-admin-hub__subtitle">{subtitle}</p>          <!-- omitted if no subtitle -->
  <div class="bn-admin-hub__subhead-actions">{action}</div> <!-- omitted if no action -->
</div>
```

The H1 comes from `render_header()` (`.bn-admin-hub__header` / `.bn-admin-hub__title`).

### Rules for screens being converted

1. **Stop printing your own page title.** AdminHub already prints the section H1.
   Remove the legacy `parts/section-head.php` title (and any `<h1>`/`<h2>` page
   title) from `render_content()`. `section-head` must no longer be used to
   reprint the screen title.
2. **Move subtitle + primary action into `register_tab()`** via `subtitle` / `action`.
   Do not print a subtitle paragraph or a free-floating "Export" button inside the body.
3. **Range/segment filters use `.bn-segment`**, never tabs:

   ```html
   <div class="bn-segment" role="group" aria-label="Range">
     <a class="bn-segment__item is-active" aria-selected="true" href="...">30 days</a>
     <a class="bn-segment__item" aria-selected="false" href="...">90 days</a>
   </div>
   ```

   Items may be `<a>` or `<button>`; active state is `.is-active` or
   `[aria-selected="true"]`. Navigation sub-navs stay underline tabs
   (`.bn-admin-hub__tabs` / `.bn-atab`) — never `.bn-segment`.

### Canonical class names

| Class | Role |
|---|---|
| `.bn-admin-hub__header` / `.bn-admin-hub__title` | Section H1 (AdminHub-owned) |
| `.bn-admin-hub__tabs` | Underline section tab strip (navigation) |
| `.bn-admin-hub__subhead` | Standardized sub-header bar |
| `.bn-admin-hub__subtitle` | Subtitle text (left) |
| `.bn-admin-hub__subhead-actions` | Action container (right) |
| `.bn-segment` / `.bn-segment__item` | Shared range/segment filter control |
