# Conformance: White Label (Pro)

**Feature:** White Label
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P6-white-label.md`
**Code traced:** `/Users/vapvarun/dev/repos/buddynext-pro/includes/WhiteLabel/` + `includes/Admin/WhiteLabelAdmin.php`, `includes/Admin/SpaceBrandAdmin.php`, `assets/admin/whitelabel.js`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/

## Verdict

**usable-minor-polish** — The core admin journey (configure brand name + logo + hue + font + custom CSS, save, see it applied to admin chrome and the front-end OKLCH cascade) is fully wired end-to-end. Secondary spec scope rows (Gutenberg block rename, optional REST namespace alias) are not implemented; one narrow gap exists in the gettext rewriter being admin-gated. None of these stop the primary journey.

## Journey chain (site-owner white-label, P6.1)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Settings tab "White-label" appears under Advanced | ui | wired | `includes/Admin/WhiteLabelAdmin.php:74-82` (AdminHub::register_tab) + legacy submenu `:90-100`; `buddynext/includes/Admin/AdminHub.php:217` |
| Form renders brand name / logo / hue swatches / font / custom CSS | ui | wired | `includes/Admin/WhiteLabelAdmin.php:205-340`; helpers in `buddynext/includes/Admin/AdminPageBase.php:198,233,344` |
| Live preview (hue/font/CSS) before save | store | wired | `assets/admin/whitelabel.js:45-108` (applies `--bn-hue`, `--bn-font-ui`, injects preview `<style>`) |
| Save posts to admin-post.php | rest | wired | form action `:229-231`; handler hook `:71`, `handle_save()` `:107-142` |
| Persist + validate brand fields | service | wired | `includes/WhiteLabel/BrandService.php:107-165` (save_brand, per-field WP_Error) |
| Store in WP options | db | wired | `BrandService.php:119,130,135,150,161` (update_option) |
| Admin chrome shows brand name (menu, plugin row, title, BN strings) | service | wired | `includes/WhiteLabel/AdminLabelRewriter.php:55-166` |
| Front-end UI rotates to brand hue/font/CSS | service | wired | `includes/WhiteLabel/HueOverride.php:47-129` (wp_head priority 1, OKLCH `:root` block) |
| Per-space brand override (P6.2) | rest/service | wired | tab `includes/Admin/SpaceBrandAdmin.php`; REST `includes/WhiteLabel/Controllers/SpaceBrandController.php:57-193`; JS save `assets/admin/whitelabel.js:111-197`; storage `BrandService.php:246-515` (`bn_space_meta`, created in `includes/Core/Installer.php:178-195`) |
| Wiring on boot | service | wired | `includes/Core/Plugin.php:252-257` (HueOverride/AdminLabelRewriter/WhiteLabelAdmin/SpaceBrandAdmin registered); REST `:334,337` |

## First break

none — journey complete (the primary configure→save→apply journey has no broken link).

## UX gaps

1. **Email footer rewrite is admin-context-only** (severity: low, confidence: confirmed-in-code). `AdminLabelRewriter::register()` returns early when `! is_admin()` (`includes/WhiteLabel/AdminLabelRewriter.php:56-58`), so the `gettext` filter that swaps "BuddyNext" → brand name (`:69`, `:158-166`) does not run during email send (cron / front-end context). The admin email-editor *preview* footer string `buddynext/includes/Admin/EmailEditor.php:928` IS rewritten because it renders in admin, but a sent email rendered outside admin would retain "BuddyNext" in any BN-domain string. Spec lists "Email template footers — Custom branding, no BuddyNext mention" as in-scope. Needs live verification of the actual send template path.

2. **Gutenberg blocks not renamed** (severity: low, confidence: confirmed-in-code). Spec scope row "Gutenberg blocks label — Blocks renamed in editor" has no implementation; no block-registration filter found in `includes/WhiteLabel/`. Editor block labels still read "BuddyNext".

3. **Optional REST namespace alias not implemented** (severity: low, confidence: confirmed-in-code). Spec marks this "Optionally remapped" — controllers hardcode `buddynext-pro/v1` (`Controllers/PreviewController.php:37`, `Controllers/SpaceBrandController.php:34`). Being optional, this does not block the journey.

## Minimal refactor plan

These are optional polish items for full spec-scope coverage; the primary journey is usable as-is. If pursued:

1. Register the `gettext` rewrite filter unconditionally (move it out of the `is_admin()` guard in `AdminLabelRewriter::register()`), keeping the other three filters admin-only, so email/cron-rendered BN-domain strings also get the brand swap.
2. Add a `register_block_type` label/title filter in the WhiteLabel module to rename BN blocks in the editor when a brand name is set.

(REST namespace alias intentionally omitted — spec marks it optional and it is not part of the happy path.)

## Live-walk URL

http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Advanced → White-label. Set a brand name + pick a hue, Save, then confirm the admin menu label changes and the front end rotates.
