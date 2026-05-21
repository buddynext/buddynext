# BuddyNext v2 design system — engineering review

Written after porting `tokens.css` into `assets/css/bn-base.css` (commits `2e81afb`, `de00823`, `031007e`) and walking the v2 prototypes (`style-guide.html`, `home-feed.html`, `user-profile.html`, `admin.html`).

This is not a design critique — it's an engineering pass for browser support, accessibility coverage, whitelabel correctness, and bundle size. Aesthetic decisions (palette, type scale, breakpoint cadence) are outside scope.

## What v2 gets right

These are the parts that make v2 worth migrating onto and we should not lose them in iteration:

1. **Single rotatable `--bn-hue`.** One number drives the accent ramp + surfaces + ink + line + ring + sibling-product accents. Whitelabel becomes one variable flip instead of a 90-token palette fork.
2. **OKLCH instead of HSL.** Perceptually uniform — `accent-500` reads "equally bright" at any hue. HSL would shift visually when the hue rotates.
3. **Theme-agnostic namespace.** `--bn-*` only — nothing leaks into a host theme's `--bg`, `--text-1`, or `--brand`. This is what made the migration commits safe without breaking BuddyX/Reign.
4. **Density + text-scale + dyslexia as first-class modes.** Accessibility baked in via `[data-bn-density]`, `[data-bn-text]`, `[data-bn-dyslexia]` rather than bolted on as toggles.
5. **Sibling-product accents derived from hue rotations.** Jetonomy / WPMediaVerse / Events / Paid stay in the same chromatic family but read as distinct.
6. **Attribute-driven component API** (`data-variant`, `data-tone`, `data-size`). Composable, easier to extend, plays well with WP Interactivity API.

## Concerns to address before v0.3.0 cut

Numbered so they're addressable as discrete issues.

### 1. OKLCH browser support window

Chrome 111+ / Safari 16.4+ / Firefox 113+. Solid for 2026, but customer admins on older corporate Edge/Safari will see CSS vars resolve as invalid → properties fall back to `inherit` (often black on white). **Mitigation**: add `@supports not (color: oklch(0 0 0)) { :root { ...hex fallbacks for the top ~20 tokens... } }` block.

### 2. `color-mix()` same support window

Used in the AI button gradient and admin dark-mode logo tiles. Same fix needed as concern #1.

### 3. Geist self-hosting cost — DECIDED

200KB on top of the existing Inter bundle. **Decision (2026-05-21)**: stay on Inter. v2 tokens fall through to Inter without a visual delta meaningful at body sizes; the Geist character mainly shows on display headings. Geist + Atkinson Hyperlegible self-hosting reopens if a customer specifically requests the dyslexia mode font swap.

### 4. Compact density radius math drift

`--bn-radius-base: calc(10px * var(--bn-radius-scale, 1))` is the canonical model, but `[data-bn-density="compact"]` sets `--bn-radius-base: 8px` (absolute) — breaks the multiplicative invariant. **Fix**: `[data-bn-density="compact"] { --bn-radius-scale: 0.8; }` so the calc holds.

### 5. Cover gradient hues hardcoded

`.pf-cover` uses `oklch(60% 0.20 290)` + `oklch(70% 0.18 350)` + accent gradient. The first two ignore `--bn-hue` — on a teal whitelabel the cover stays purple/pink. **Fix**: derive as `calc(var(--bn-hue) + 38deg)` and `calc(var(--bn-hue) + 98deg)`, or declare these as intentional always-magenta decorative accents (and document).

### 6. Accent ramp missing 300 / 800 / 900

50/100/200/400/500/600/700 — usable but a tier shy of editorial control. 300 unlocks "text on accent-100 chip" without dipping to body ink; 800/900 unlocks high-contrast dark surfaces. Cost to add: 6 lines.

### 7. Avatar presence ring uses `--bn-surface`

Looks right when the avatar sits on a card, looks wrong when the same avatar sits on `--bn-sunken` (sidebar widget). **Fix**: add `--bn-avatar-presence-border` (defaults to `--bn-surface`) so sunken surfaces can override locally.

### 8. No `prefers-contrast: more` block

AAA contrast is the default, but Windows High Contrast + macOS Increase Contrast prefs aren't actively respected. One short block:

```css
@media (prefers-contrast: more) {
  :root {
    --bn-line: oklch(50% 0 0);
    --bn-ink-3: oklch(30% 0 0);
  }
}
```

### 9. No `forced-colors` handling

Same family — `forced-color-adjust: auto` on interactive primitives keeps focus rings + danger semantics visible in Windows High Contrast Mode.

### 10. Breakpoint count

Audit flags 9 distinct breakpoint values across the prototypes (640, 720, 768, 782, 1024, 1080, ...). Should standardize on 3 (mobile / tablet / wide). `.pf-head { @media (max-width: 720px) }` is a one-off.

### 11. `max-width: 1200px` in prototypes ≠ `--bn-content-wide: 1080px`

Either align the prototypes to the token or update the token. Right now they disagree silently.

### 12. `admin.html` hardcodes WP chrome (`#1d2327`, `#c3c4c7`)

Intentional (mimics the actual WP admin sidebar) but if WP ever updates its admin chrome these go stale. Consider pulling into a separate `--bn-wp-rail-bg` token with a comment that says "manual sync with WP core required".

### 13. No theme-toggle UX shown

`data-bn-theme="auto"` is defined in tokens but the prototypes don't show the toggle widget. v0.3.0 needs a canonical 3-way switch (System / Light / Dark) baked into a primitive before front-end + admin ship.

### 14. No print styles

Admin moderation queues, broadcast receipts, member exports — all benefit from `@media print`. v2 doesn't have one. Defer until first customer hits the seam.

### 15. RTL pass missing on prototypes themselves

The audit flagged raw `margin-left` in `chrome.css:63` and elsewhere. Prototypes should be RTL-clean as the reference before we sweep templates onto them.

## Suggested priority order

| Priority | Item | Estimated effort |
|---|---|---|
| High | #1 OKLCH `@supports` hex fallback | 1 hour |
| High | #2 `color-mix()` fallbacks | 30 min |
| High | #6 Add accent-300/800/900 | 15 min |
| Medium | #4 Fix compact density radius math | 5 min |
| Medium | #5 Hue-derive cover gradient or document magenta-by-design | 15 min |
| Medium | #7 `--bn-avatar-presence-border` indirection | 10 min |
| Medium | #8 `prefers-contrast: more` block | 15 min |
| Medium | #9 `forced-colors` handling | 30 min |
| Medium | #10 Standardize on 3 breakpoints, sweep prototypes | 2 hours |
| Low | #11 Reconcile `1200px` ↔ `--bn-content-wide` | 5 min |
| Low | #12 `--bn-wp-rail-bg` token | 10 min |
| Low | #13 Theme-toggle primitive | 2 hours |
| Low | #14 Print styles | 1 hour |
| Low | #15 RTL sweep on prototypes | 1-2 hours |

## What the migration commits today cover

Already in place:

- v2 token vocabulary canonical in `assets/css/bn-base.css`
- Legacy names re-aliased onto v2 source (16 CSS files render against the new palette without class changes)
- `TokenService` publishes legacy names via `var(--wp--preset--*, var(--bn-*))` — theme.json presets win, v2 is universal fallback
- `data-bn-theme="light" data-bn-density="comfortable"` stamped on every BN hub + admin page via `language_attributes` filter
- Component primitive layer (`.bn-btn[data-variant]`, `.bn-badge[data-tone]`, `.bn-avatar[data-size+data-presence]`, `.bn-input`, `.bn-textarea`, `.bn-select`, `.bn-kbd`, `.bn-hr`, `.bn-ring`)
- `bn-profile.css` / `bn-onboarding.css` / `bn-admin.css` private namespaces re-aliased onto v2 source

Still queued:

- Template sweep onto `data-variant` / `data-tone` / `data-size` attribute API
- Resume the F1 inline-style + F2 inline-script + Rule 14 outline-none sweep using v2 patterns
- The 15 items above

## Bottom line

v2 is a strong architectural foundation. The OKLCH single-hue model + namespace isolation + accessibility modes are best-in-class for a WordPress plugin design system. The concerns above are deliverability details — none of them invalidate the structural shape of the system, and all are addressable without forking the token vocabulary.

Recommendation: ship the migration as-is, address #1 + #2 + #6 + #4 + #7 in a focused follow-up before v0.3.0, defer the rest to the design phase that comes after dogfood feedback.
