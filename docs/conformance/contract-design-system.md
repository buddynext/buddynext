# Contract Conformance — Design System & Dark Mode

**Contract:** Design system & dark mode (OKLCH `--bn-*` tokens, Lucide via `buddynext_icon()`, working dark mode, no raw hex, no emoji)
**Spec:** `docs/specs/features/20-theme-integration.md` (Locked, 2026-03-20)
**Checked:** 2026-05-31 (static read-only)
**Verdict:** usable-leave-as-is

---

## Summary

The design-system spine is real, consistent, and theme-adaptive. The OKLCH token
source of truth lives in `assets/css/bn-base.css` `:root`; the `buddynext_css_vars`
filter + plugin `theme.json` give themes the override points the spec promises; dark
mode is genuinely wired end to end. No usability break found.

Note: the spec's *example* token names (`--bn-color-primary`, `--bn-color-surface`,
`--bn-font-size-md`, `--bn-space-md`) are illustrative — the shipped implementation
uses a richer, evolved vocabulary (`--bn-accent*`, `--bn-canvas/surface/sunken`,
`--bn-ink*`, plus legacy aliases `--brand/--surface/--text-1`). The *mechanism* the
spec locks (preset-bridged `var(--wp--preset--*, fallback)` mapped to `--bn-*`,
exposed through `buddynext_css_vars`) is implemented faithfully. Naming divergence
from an example block, not a contract break.

---

## Guarantee chain

1. **OKLCH `--bn-*` token source** — `bn-base.css:20-127` defines the full accent
   ramp, canvas/surface/sunken, ink scale, semantic colors entirely in `oklch()`,
   driven by `--bn-hue`/`--bn-chroma`. Solid.
2. **Plugin `theme.json` fallback palette** — `theme.json` ships at plugin root with
   color palette, font sizes, weights, spacing, radius. WordPress merges it; active
   theme wins. Matches spec cascade step 1.
3. **`--wp--preset--*` → `--bn-*` mapping + `buddynext_css_vars` filter** —
   `TokenService::get_defaults()` (Theme/TokenService.php:69) maps presets to legacy
   aliases with `--bn-*` fallbacks; `build_css()` applies
   `apply_filters('buddynext_css_vars', …)` at line 233. Matches spec steps 2-4.
4. **Component CSS uses tokens** — `bn-members/messages/notifications/moderation/`
   `search/hashtags/gamification/auth/blocks.css` have zero raw hex. Hot files use
   `var(--bn-*)`/`var(--brand)` throughout.
5. **Dark mode** — `font-scale.js:56-87` reads `bn_theme` (light/dark/auto +
   `prefers-color-scheme`) from localStorage and stamps `data-bn-theme` on `<html>`
   pre-paint; `bn-base.css:320` re-pins 40 OKLCH tokens under `[data-bn-theme="dark"],
   [data-theme="dark"]`; `TokenService::get_dark_overrides()` (line 176) re-pins the
   preset-bridged legacy aliases straight at dark `--bn-*` so a host theme's static
   light preset can't leak through and produce white-on-white. The subtle bug the
   spec cares about is handled.
6. **Icons via `buddynext_icon()`** — `buddynext.php:205` delegates to
   `IconService::render()` (Core/IconService.php:155), which `wp_kses()`-sanitizes
   vendored SVGs and force-injects the `bn-icon` class. Emoji reactions go through a
   separate `render_emoji()` `<img>` path (Fluent set), not Unicode glyphs.

---

## Contract observations (none blocking)

- **`color: #fff` on brand-filled surfaces** (`bn-feed.css:1921,2535,2588,2653,2747`;
  `bn-profile.css:902,984,1667,1675-1685`; `bn-spaces.css:801`; `bn-shell.css:213`).
  White text/border over an accent fill. A `--text-on-brand` / `--bn-accent-fg` token
  exists and is canonical. Low severity — white-on-accent is invariant across
  light/dark, so no dark-surface break results.
- **Literal brand-color social chips** (`bn-profile.css:2465-2469`: Twitter #1da1f2,
  LinkedIn #0a66c2, Instagram #e1306c, YouTube #ff0000). Deliberately the platforms'
  own brand hexes, not theme values — out of token scope by intent.
- **`var(--token, #hex)` fallbacks** across feed/profile/spaces — these are the
  spec-sanctioned pattern (`var(--wp--preset--*, #fallback)`), not violations.
- **One gear glyph (U+2699)** in an admin help string `NavManager.php:585`
  ("Click ⚙ to assign…"). Admin copy, not front-end community markup, not a UI icon.
  Cosmetic.

No purple/pink/teal AI-gradient palette present. No `!important` abuse in the token
path. Dark surfaces resolve correctly because of the deliberate alias re-pin.

---

## Refactor plan

None. Working infrastructure; the observations above are optional polish, not breaks.
