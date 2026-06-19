# Wbcom Community-OS Design Standard

> **Normative.** Every Wbcom community app aligns to this so the whole suite reads
> as one product when installed together — and still looks premium standalone.
> Canonical home: this file in BuddyNext (the token host). Each app keeps a synced
> copy at its own `docs/standards/community-os-design.md`.
>
> Reference implementation: **Jetonomy 1.5.0** (`--jt-*` layer in `assets/css/jetonomy.css`).

## 1. The model

**BuddyNext is the Community OS and the design-token host.** Apps that run on it
(Jetonomy, WPMediaVerse, WB Listora, Career Board, …) are guests that adopt its
visual language. Two hard requirements, always both:

1. **Match when together** — an app reads BuddyNext's tokens first, so when
   BuddyNext is active the app is pixel-consistent with it (including white-label
   hue changes, which only flip `--bn-hue`).
2. **Premium when alone** — every token has a fallback whose *value matches
   BuddyNext's*, so the app looks the same with BuddyNext absent. Never fall back
   to a generic framework default (e.g. `#3B82F6`).

Never make an app *depend* on BuddyNext (no requiring its shell, classes, or JS).
Alignment is through **CSS custom properties only**.

## 2. Token contract

BuddyNext owns the canonical tokens (`--bn-*` in `bn-base.css`, injected by
`TokenService`) and exposes bare aliases used cross-app. Each app defines its own
`--{prefix}-*` token layer that reads the BuddyNext upstream first, then a
matching fallback:

```css
:root, .{app}-app {
  /* BuddyNext-first, then WP theme.json, then a BuddyNext-matching fallback */
  --x-accent:  var(--brand,   var(--wp--preset--color--primary, /* BN indigo */ #5b63d6));
  --x-text:    var(--text-1,  var(--wp--preset--color--contrast, #1a1a1a));
  --x-bg:      var(--bg,      var(--wp--preset--color--base, #ffffff));
  --x-surface: var(--surface, var(--x-bg));          /* card surface ≠ page */
  --x-canvas:  var(--canvas,  color-mix(in srgb, var(--x-text) 2%, var(--x-bg)));
  --x-radius:  var(--r-md,    8px);
  --x-font:    var(--font-body, inherit);

  /* Elevation — soft, layered (matches BuddyNext --bn-shadow-*). Never a literal
     box-shadow in a component; pick a token so elevation is uniform suite-wide. */
  --x-shadow-xs: var(--bn-shadow-xs, 0 1px 2px rgba(16,24,40,.04));
  --x-shadow-sm: var(--bn-shadow-sm, 0 1px 2px rgba(16,24,40,.04), 0 2px 4px rgba(16,24,40,.04));
  --x-shadow-md: var(--bn-shadow-md, 0 2px 4px rgba(16,24,40,.04), 0 8px 16px rgba(16,24,40,.06));
  --x-shadow-lg: var(--bn-shadow-lg, 0 8px 16px rgba(16,24,40,.06), 0 24px 48px rgba(16,24,40,.08));
}
```

Upstream variables an app may read (all BuddyNext-provided): `--brand`,
`--text-1`, `--bg`, `--surface`, `--canvas`, `--line`, `--green(-bg)`,
`--amber(-bg)`, `--red(-bg)`, `--r-sm/-md/-lg/-full`, `--font-body`,
`--font-display`, `--bn-shadow-xs/-sm/-md/-lg`. Scale (spacing 4→64, type
11→48px, radius) mirrors BuddyNext's.

## 3. Component conventions

- **Card** = a distinct *surface* on the canvas: `background: var(--x-surface)`,
  `1px solid var(--x-line/border)`, `border-radius: var(--x-radius-lg)`,
  `box-shadow: var(--x-shadow-sm)`; hover lifts to `--x-shadow-md`. Cards read
  `--x-surface`, **never** the page colour.
- **Cover header** (spaces/groups/profiles): always render a banner — a tonal
  accent gradient fallback when there's no cover image — with an **overlapping
  avatar tile** (surface fill, surface ring, `--x-shadow-sm`) at its lower edge.
- **Sidebar widgets**: when BuddyNext is active, render as `bn-sidebar-card`
  (`__header` / `__body`) so every app's sidebar matches; standalone, style the
  app's own card to the same spec (uppercase micro-label header, surface, shadow).
- **Tab bar**: active tab carries an accent underline; inactive is `--x-text-2`.
- **Buttons**: primary = accent fill + `--x-accent-fg`; secondary = ghost
  (`1.5px` border, surface fill). Pill radius for nav, `--x-radius` for inline.
- **Empty / loading / error** states present on every async surface.

## 4. Dark mode

Class-driven from the host theme — **not** a media query. Honor the Wbcom theme
signal `<html data-bx-mode="light|dark|auto">` (Reign / BuddyX 5.1+) and the
BuddyNext `[data-bn-theme]` / `[data-theme]` attributes; mirror it onto the app
root (e.g. Jetonomy's `dark-mode-mirror.js` sets `.jt-dark`). Dark overrides
re-point the surface/ink/line/border tokens; components inherit automatically.

## 5. Checklist (per app, per release)

- [ ] App defines a self-contained `--{prefix}-*` token layer, BuddyNext-first
      with BuddyNext-matching fallbacks (verified standalone, BuddyNext inactive).
- [ ] No literal `box-shadow` / hex / px in components — tokens only.
- [ ] Cards use `--x-surface` + `--x-shadow-sm`; cover headers + sidebar widgets
      follow §3.
- [ ] Dark mode via the host signal; verified at the real theme toggle.
- [ ] Verified beside BuddyNext (consistent) **and** standalone (premium), at
      desktop + 390px.

## 6. Status

| App | Token layer | Status |
|---|---|---|
| BuddyNext | `--bn-*` (host) | Canonical |
| Jetonomy | `--jt-*` | Reference impl (1.5.0): elevation/surface tokens + space cover header shipped |
| WPMediaVerse | `--mvs-*` | In progress |
| WB Listora | `--listora-*` | Planned |
| Career Board | `--wcb-*` | Planned |
