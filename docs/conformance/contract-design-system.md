# Contract Conformance — Design System & Dark Mode

**Contract:** Design system & dark mode (OKLCH `--bn-*` tokens, Lucide via `buddynext_icon()`, working dark mode, no raw hex, no emoji in markup)
**Locked spec:** `docs/specs/features/20-theme-integration.md`
**Checked:** 2026-05-31
**Verdict:** usable-leave-as-is (documentation drift + minor polish noted)

---

## Summary

The contract spine is **fully built and wired**. Every guarantee the spec exists to deliver — a theme-adopting token layer, a plugin `theme.json` fallback, a `buddynext_css_vars` filter for Customizer bridging, a working dark mode, and a safe SVG icon service — is present in code and bootstrapped at runtime. The implementation has in fact **evolved past the locked doc**: it ships a richer v2 OKLCH vocabulary (`--bn-accent`, `--bn-ink`, `--bn-canvas`) plus dyslexia and reduced-motion support the spec never mentions.

The only findings are (a) the locked spec's literal token *names* are stale and (b) a small set of cosmetic raw-hex / one admin emoji. None break the journey.

---

## Journey — contract guarantees

| Guarantee | Layer | Status | Evidence |
|---|---|---|---|
| Plugin-level `theme.json` ships as fallback palette/type/spacing | db | wired | `theme.json` (root), version 3, palette+fontSizes+spacing present |
| `--bn-*` tokens output at wp_head, presets bridged via `var(--wp--preset--*, fallback)` | service | wired | `includes/Theme/TokenService.php:69-169` (`get_defaults`), attached `:378-380` |
| TokenService bootstrapped at runtime | service | wired | `includes/Core/Plugin.php:260` `( new TokenService() )->init()` |
| `buddynext_css_vars` filter for Customizer/theme override | service | wired | `TokenService.php:233`, dark variant `:245` |
| OKLCH is the canonical color source | ui | wired | `assets/css/bn-base.css` — 115 `oklch()` declarations |
| Working dark mode (dual selector + system auto + legacy-alias re-pin) | ui | wired | `bn-base.css:320-384`; alias re-pin `TokenService.php:176-217` |
| OKLCH `@supports` hex fallback for old browsers | ui | wired | `bn-base.css:402-469` (intentional, documented) |
| Icons via `buddynext_icon()` / IconService, wp_kses-sanitized SVG | ui | wired | `includes/Core/IconService.php`; 79 SVGs in `assets/icons/`; 106 call sites |
| No raw hex in front-end component CSS | ui | broken (minor) | bare `#fff` at `bn-spaces.css:801`, `bn-profile.css:902/984/1667/1675/1676/1685`, `bn-feed.css:1921/2494/2612`, `bn-shell.css:213`; brand-chip hex `bn-profile.css:2465-2469` |
| No emoji in markup | ui | broken (minor) | `⚙` in admin help string `includes/Admin/NavManager.php:585` |

---

## Spec / code drift (documentation, not a break)

The locked spec (lines 107-138) names the PHP-rendered token set as
`--bn-color-primary`, `--bn-color-surface`, `--bn-font-size-md`, `--bn-space-md`, `--bn-color-text`, etc.
**These exact names appear ZERO times in the codebase.** The shipped vocabulary is:

- v2 OKLCH sources: `--bn-accent*`, `--bn-ink*`, `--bn-canvas`, `--bn-surface`, `--bn-line`, `--bn-success/warn/danger/info` (declared in `bn-base.css`)
- Legacy aliases mapped in `TokenService::get_defaults()`: `--brand`, `--bg`, `--surface`, `--border`, `--text-1..3`, `--green/amber/red`, `--font-body`, `--text-*`, `--s1..s16`, `--r-*`

The spec's palette slug list (`contrast`, `subtle`, `surface`) is likewise stale: `theme.json` ships `foreground`, `base`, `base-subtle`, `primary-hover` instead, and TokenService reads those matching slugs. The bridge is **internally consistent** (theme.json slugs match `var(--wp--preset--color--<slug>)` references), so theme adoption works — the doc just no longer describes the real token names. Spec is marked "fully locked"; it needs a refresh to match the v2 OKLCH system.

---

## Findings (contract violations)

1. **Spec token names stale** — medium. Locked doc names tokens that do not exist; v2 OKLCH names shipped instead. Doc-only; bridge works. (`docs/specs/features/20-theme-integration.md:107-138` vs `TokenService.php` / `bn-base.css`)
2. **Bare `#fff` in component CSS** — low. ~7 `color: #fff` (button/avatar foreground) should be `var(--bn-accent-fg)` so dark/whitelabel rebrand cannot strand white text.
3. **Social-chip brand hex** — low / by-design. `bn-profile.css:2465-2469` hardcode platform brand hues (Twitter/LinkedIn/Instagram/YouTube). Brand-mandated, not theme tokens; acceptable with a documented exception.
4. **Admin emoji** — low. `⚙` in a translatable admin help string (`NavManager.php:585`); per the no-emoji rule should be a Lucide `buddynext_icon()`. Admin-only.

Note: `var(--bn-token, #hex)` fallbacks inside `var()` calls (the bulk of the 179 hex hits) and the entire `@supports not (oklch)` block are **intentional defensive fallbacks, not violations** — the token always resolves first.

---

## Why not "broken"

Absence != broken, and "exists vs used" was checked. The token layer, theme.json, filter, dark mode and icon service all exist AND are wired (Plugin.php bootstrap, 106 icon call sites, 115 OKLCH decls, dual dark selectors). The drift lives in the *spec document*; the hex/emoji are cosmetic. Nothing breaks the rendered experience. Default verdict stands.
