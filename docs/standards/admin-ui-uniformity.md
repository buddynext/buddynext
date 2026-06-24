# Admin UI Uniformity Standard (v1.0)

The one guiding rule for every admin option and surface: **a user moving between
any two BuddyNext admin screens must feel one product.** Same input, same focus
ring, same nav icon, same card anatomy — regardless of which screen rendered it.

This is the source of truth for adding options/UI. Follow it and new screens are
uniform by construction; the items below are also what review and the gates check.

## 1. Options / form fields — ONE input

There is exactly one admin field look. Do not invent a per-screen input style.

- **Add fields through the central renderer**: `AdminPageBase::render_text_row()`,
  `render_select_row()`, `render_textarea_row()`, `render_number_row()`,
  `render_password_row()`, `render_color_row()`, `render_toggle_row()`. They emit
  the canonical `.bn-text-input` / `.bn-select-input` + `.bn-field` / `.bn-field-hint`.
- **Hand-rolled markup still converges**: every screen renders inside
  `.bn-admin-hub`, and `bn-admin.css` carries a baseline that styles *any*
  `select` / `input[type=text|url|email|search|password|number|tel|date]` /
  `textarea` inside the hub. So even a native no-class `<select>` looks uniform.
- **Never** write a new input rule with its own `border` / `border-radius` /
  padding / focus ring. If you think you need one, you don't — use the tokens.
- **The tokens** (defined in `bn-admin.css :root`, dark-mode aware):
  `--bn-a-input-border`, `--bn-a-input-radius`, `--bn-a-input-pad-y/-x`,
  `--bn-a-focus-ring`. The focus ring is **always** `--bn-a-focus-ring` — never an
  inline `box-shadow: 0 0 0 Npx …` literal.
- Pro mirrors this: `.bnpro-*` inputs use `--bn-line-strong` border, no resting
  shadow, the same ring.

## 2. Navigation — every item carries a Lucide icon

- Every sidebar **section** (`AdminHub::default_sections()`) has an `'icon'` key,
  and every **tab/leaf** has one (explicit `icon` arg or `default_icon_for()`).
- Icons are vendored Lucide SVGs in `assets/icons/`, rendered via
  `IconService::render( $slug, $css_class )`. Use a slug that exists on disk
  (`IconService::has()` guards it). No Dashicons, no emoji.
- A new top-level section without an icon is incomplete.

## 3. Cards / content lists — one anatomy

- Result/section cards share: a **type label** (kicker), a **clamped** title
  (`-webkit-line-clamp`), an optional clamped snippet, and a consistent footer.
- Cards have a `min-height` floor so a short card never collapses beside a tall
  one; titles/snippets are line-clamped so long content never balloons into a
  wall. (Reference: the Explore `.ec-card` family in `bn-explore.css`.)
- Every card states its type — don't gate the type label on optional data
  (e.g. a hashtag). A "Post"/"Photo"/"Member" label is always present.

## 4. Always-on rules

- **Token-driven**: no raw hex / px in admin CSS — reference `--bn-*` / `--bn-a-*`
  (a hex fallback before a single `color-mix()` is the only exception).
- **Lucide only**, stroke 1.75, sized via the icon classes.
- **`:focus-visible` ring** on every interactive control (the input ring above;
  nav/buttons via their own tokened rings). Never `outline: none` with no
  replacement.
- **Dark mode + RTL** come for free when you consume the tokens and use logical
  properties — never write a per-component dark rule or a `margin-left`.
- Admin screens are verified at **desktop + iPad**; member-facing frontend
  surfaces (Explore, profiles, feeds) are also verified at **390px** and in dark.

## Checklist when adding an option or admin screen

- [ ] Field added via an `AdminPageBase::render_*_row()` helper (or, if custom,
      it's inside `.bn-admin-hub` and carries no bespoke input CSS).
- [ ] No new input `border` / `radius` / focus-ring literal — tokens only.
- [ ] New nav section/tab has a vendored Lucide `icon`.
- [ ] Any new card type uses the kicker + clamped title/snippet + footer anatomy
      with a `min-height` floor.
- [ ] No raw hex/px; dark mode + RTL adapt via tokens/logical properties.
- [ ] Verified in the browser (admin: desktop + iPad; frontend: + 390px + dark).
