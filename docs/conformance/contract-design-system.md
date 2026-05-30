# Contract Conformance — Design System & Dark Mode

**Contract:** Design system & dark mode
**Spec:** `docs/specs/features/20-theme-integration.md` (Locked, 2026-03-20)
**Checked:** 2026-05-31
**Verdict:** usable-leave-as-is (minor polish optional)

---

## Summary

The design-system spine is built, wired, and theme-agnostic. OKLCH `--bn-*`
tokens are the single canonical source (`assets/css/bn-base.css`), legacy
aliases bridge through `var(--wp--preset--*, var(--bn-*))` so a host block
theme's palette wins in light mode (`TokenService::get_defaults()`), and dark
mode is a complete, runtime-togglable surface. Lucide icons route through
`buddynext_icon()` / `IconService` (103 template call sites, 79 vendored SVGs).
No contract-breaking violations found. Two cosmetic nits noted below.

---

## Guarantee chain

| Guarantee | Layer | Status | Evidence |
|---|---|---|---|
| OKLCH `--bn-*` tokens are the canonical source | service | wired | `assets/css/bn-base.css:20-122` (`:root` / `[data-bn-theme="light"]`, oklch-derived from one rotatable `--bn-hue`) |
| Plugin `theme.json` ships neutral preset fallbacks | db | wired | `theme.json:1-80` (primary/base/foreground/border/success/error palette, font + spacing presets) |
| `--bn-*` tokens output at wp_head via inline style | service | wired | `TokenService::attach_tokens()` → `wp_add_inline_style('bn-base', …)`; `Plugin.php:256` calls `(new TokenService())->init()` |
| `buddynext_css_vars` filter lets themes override | service | wired | `TokenService::build_css():233` applies filter; dark twin `buddynext_css_vars_dark:245` |
| Theme preset wins in light, BuddyNext owns dark | service | wired | defaults bridge `var(--wp--preset--*, var(--bn-*))`; `get_dark_overrides()` re-pins straight to dark `--bn-*` (documented preset-chain fix, `TokenService.php:176-217`) |
| Dark mode is real and togglable | ui | wired | `bn-base.css:320-363` dark OKLCH overrides + `366` `prefers-color-scheme` auto; toggle UI `templates/profile/edit.php:650-654`; applied/persisted `assets/js/shell/font-scale.js:88-92`; default attr `AssetService.php:186-189` |
| Lucide icons via `buddynext_icon()` | ui | wired | `IconService::render()` wp_kses-sanitized SVG, injects `.bn-icon`; 103 call sites across templates, 79 SVGs in `assets/icons/` |
| No raw hex for design values | ui | api-only | bare hex limited to `color:#fff` on accent buttons + brand-literal social chips; bulk hex is `@supports not(oklch)` fallback + `var(--token,#fallback)` defenses |
| No emoji in markup | ui | broken | one `⚙` in `includes/Admin/NavManager.php:585` echoed help string |

---

## Findings (non-blocking)

1. **`⚙` emoji in admin markup** — `includes/Admin/NavManager.php:585`, inside an
   `esc_html_e()` help string. Violates the no-emoji-in-markup rule. Replace with
   `buddynext_icon('settings')` or the word "gear". Severity: low (admin-only,
   one string).

2. **Bare `color:#fff` on brand buttons** (`bn-feed.css:1921,2494,2547,2612,2706`;
   `bn-profile.css:902,1667,1675-1685`; `bn-spaces.css:801`; `bn-shell.css:213`).
   The canonical token `--bn-accent-fg` already exists for exactly this. White on
   the mid-tone accent reads correctly in both light and dark, so this is a
   consistency nit, not a broken surface. Severity: low.

3. **Brand-literal social-chip colors** (`bn-profile.css:2465-2469`: Twitter
   `#1da1f2`, LinkedIn `#0a66c2`, Instagram `#e1306c`, YouTube `#ff0000`). These
   are third-party brand identities, not theme colors — intentionally outside the
   token system. Not a violation.

4. **`!important` usages** — almost all `[hidden]{display:none!important}` and
   admin layout resets (`#wpcontent`, `#wpfooter`). These are
   WordPress-conventional visibility/utility resets, not design-value overrides.
   One borderline `width:35%!important` at `bn-base.css:2572`. Spec's "no
   !important" targets design values; not a contract break.

### Not violations (verified legitimate)
- `@supports not (color: oklch(…))` hex republish block (`bn-base.css:402-490`) —
  documented progressive-enhancement fallback for browsers without OKLCH.
- `var(--token, #hexfallback)` patterns — hex is a CSS-var fallback; value still
  resolves through the token first.

---

## Refactor plan

None required for usability. Optional polish (one wave, no rewrites):
1. Swap `⚙` in `NavManager.php:585` for `buddynext_icon('settings')`.
2. Replace bare `color:#fff` on `.bn-btn-*` / accent surfaces with
   `var(--bn-accent-fg)`.
