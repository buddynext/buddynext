# Conformance — BuddyNext Pro: White Label (P6)

**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P6-white-label.md`
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/WhiteLabel/` + `includes/Admin/WhiteLabelAdmin.php`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/
**Verdict:** usable-minor-polish

---

## Summary

The core white-label journey a site owner expects — set a custom brand name + brand hue + UI font + custom CSS in a Pro admin tab, save, and have BuddyNext branding/colors swapped across wp-admin chrome and the front-end UI — is **fully wired end to end**. UI form → `admin-post.php` save → `BrandService` → options → `AdminLabelRewriter` (admin chrome string swap) + `HueOverride` (front-end `:root` OKLCH override). A per-space variant (`SpaceBrandController`) and live-preview endpoint (`PreviewController`) are also wired, with matching admin JS.

Two spec scope items are present in the admin UI/storage but have **no runtime consumer**, and one is admin-context-only:

- **Logo URL** is stored, validated, and previewed in the admin form, but no code path renders it on any front-end or admin chrome surface.
- **Email footer** "Powered by BuddyNext" (`EmailEditor.php:928`) is only rewritten by the admin-only `gettext` filter; email send in cron/front-end context does not pass through it.
- **REST namespace alias** and **Gutenberg block label rename** (spec scope) are not implemented (spec marks namespace alias "optional").

None of these break the core hue/brand-name journey. The logo field is the sharpest gap: a first-class config control that does nothing.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens White-label tab (Settings → Advanced) | ui | wired | `includes/Admin/WhiteLabelAdmin.php:74-82` (AdminHub tab) + `:90-100` (submenu) |
| Form renders brand name / hue swatches / font / custom CSS | ui | wired | `includes/Admin/WhiteLabelAdmin.php:205-339` |
| Live preview updates hue/font/css before save | store | wired | `assets/admin/whitelabel.js:32-108` |
| Submit posts to admin-post.php with nonce | ui→rest | wired | `WhiteLabelAdmin.php:229-231`, handler `:107-142` |
| Persist + validate brand fields | service/db | wired | `WhiteLabel/BrandService.php:107-165` (save_brand → wp options) |
| Admin chrome shows custom name (menu/plugin row/title/strings) | service | wired | `WhiteLabel/AdminLabelRewriter.php:55-166` |
| Front-end UI rotates to brand hue/font + custom CSS | service | wired | `WhiteLabel/HueOverride.php:47-129` (wp_head priority 1) |
| Per-space brand override (read/write) | rest | wired | `WhiteLabel/Controllers/SpaceBrandController.php:57-194` + JS `whitelabel.js:111-197` |
| Custom logo appears on a user-facing surface | service | broken | stored `BrandService.php:31,76`; **no consumer** — `HueOverride::render` emits only hue/font/css (`HueOverride.php:109-129`); only render is admin form thumbnail (`WhiteLabelAdmin.php:255-260`) |
| Email footer drops "Powered by BuddyNext" | service | broken | string `EmailEditor.php:928`; gettext rewriter admin-only (`AdminLabelRewriter.php:56-58,69`), email send runs non-admin/cron |
| REST namespace remap to custom alias | rest | missing | spec §"REST API namespace"; no impl in `WhiteLabel/`. Spec marks "optional" |
| Gutenberg block labels renamed in editor | ui | missing | spec "Gutenberg blocks label"; no block-rename code in `WhiteLabel/` |

---

## First break

**Custom logo never renders.** An admin sets a Logo URL (a first-class spec config field with full admin control + thumbnail), saves successfully, but no front-end or admin chrome surface ever outputs it. `HueOverride::render()` emits only `--bn-hue`, `--bn-font-ui`, and custom CSS — never the logo. The brand-name + hue + font + CSS journey itself completes fully; this is a dead control within an otherwise working feature.

---

## UX gaps

1. **Logo URL is a dead control** — high — confirmed-in-code. Admin can set/validate/preview a logo (`WhiteLabelAdmin.php:246-260`, `BrandService.php:31,76`) but it is never rendered anywhere user-facing; no consumer of `logo_url` beyond the admin form's own preview thumbnail. Spec lists "Custom logo" as a primary configuration field and "Admin panel branding: Custom logo + name".

2. **Email footer keeps "Powered by BuddyNext"** — medium — confirmed-in-code. `EmailEditor.php:928` emits "Sent by %s - Powered by BuddyNext"; the only rewriter (`AdminLabelRewriter::rewrite_translatable_strings`) registers behind `is_admin()` (`AdminLabelRewriter.php:56-58`), so transactional email sent via cron/front-end keeps the BuddyNext mention. Spec scope: "Email template footers: Custom branding, no BuddyNext mention".

3. **Gutenberg block labels not renamed** — low — confirmed-in-code. No block-title rewrite in `WhiteLabel/`. Spec scope: "Gutenberg blocks label: Blocks renamed in editor".

4. **REST namespace alias not implemented** — low — confirmed-in-code. No namespace remap. Spec marks this "optional", so not a journey break.

---

## Minimal refactor plan

1. Render the configured logo. Add a consumer for `BrandService::get_brand()['logo_url']`: emit it in the front-end header template (where the site logo renders) and in admin chrome so the stored value is used. Reuse the existing getter; no new storage.
2. Make the email footer respect the brand name. Cleanest native fix: have `EmailEditor.php:928` call `BrandService::get_brand_name()` directly instead of hardcoding "BuddyNext" (or drop the `is_admin()` guard so the gettext rewrite also runs at send time).
3. (Optional, low priority) Rename Gutenberg blocks via a `block_type_metadata` title filter keyed off `BrandService::get_brand_name()`.

REST namespace alias is spec-optional — leave as-is unless prioritized.
