# Conformance: White Label (Pro)

**Feature:** White Label
**Repo:** buddynext-pro
**Spec ref:** /Users/vapvarun/dev/repos/buddynext/docs/specs/features/P6-white-label.md
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/

---

## Summary

The core white-label journey — an admin opens the White-label settings tab, sets a
brand name / logo / hue / font / custom CSS, saves, and sees the brand applied to
both wp-admin chrome and the front-end OKLCH token cascade — is fully wired end to
end. UI form, server save, persistence, admin label rewriting, and front-end CSS
injection all exist and are registered unconditionally in the Pro boot path. A REST
preview endpoint and a per-space brand override (P6.2) are also present and wired.

No journey break found. Secondary spec scope items are either marked optional in the
spec (REST namespace alias) or already satisfied indirectly (sent emails use
site-name tokens, not a "Powered by BuddyNext" literal). These are noted as
low-severity gaps, not breaks.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens White-label tab (Settings -> Advanced) | ui | wired | includes/Admin/WhiteLabelAdmin.php:74-82 (AdminHub::register_tab) + :90-100 (submenu) |
| Form renders brand name / logo / hue swatches / font / custom CSS | ui | wired | includes/Admin/WhiteLabelAdmin.php:205-340 |
| Form submits to admin-post.php and is saved | store | wired | includes/Admin/WhiteLabelAdmin.php:71,107-142 (admin_post_buddynextpro_whitelabel_save) |
| Values validated + persisted to options | service | wired | includes/WhiteLabel/BrandService.php:107-165 (save_brand -> update_option) |
| Admin chrome relabeled "BuddyNext" -> brand name | service | wired | includes/WhiteLabel/AdminLabelRewriter.php:55-166 (menu, plugin row, admin_title, gettext) |
| Front-end emits :root brand override at wp_head pri 1 | service | wired | includes/WhiteLabel/HueOverride.php:47-77,109-129 |
| Module registered in Pro boot (no license gate) | service | wired | includes/Core/Plugin.php:256-261 (inside wire_extensions, after Free guard) |
| REST live-preview render endpoint | rest | wired | includes/WhiteLabel/Controllers/PreviewController.php:44-71; registered Plugin.php:338 |
| Per-space brand read/write (P6.2) | rest | wired | includes/WhiteLabel/Controllers/SpaceBrandController.php:57-103; registered Plugin.php:341 |
| Email footer branding (sent mail) | service | wired | includes/Notifications/EmailSender.php:194 uses {{site_name}}; no hardcoded BuddyNext literal in sent path |
| Email editor preview footer literal | ui | wired | includes/Admin/EmailEditor.php:928 "Sent by %s - Powered by BuddyNext" (buddynext domain) rewritten by AdminLabelRewriter gettext filter when brand name set (AdminLabelRewriter.php:158-166) |

---

## First break

none — journey complete.

---

## UX gaps

1. **REST namespace alias not implemented.** Spec scope lists "REST API namespace —
   Optionally remapped to custom namespace" and Configuration lists "Optional REST
   namespace alias". No option or routing exists; controllers hard-code
   `buddynext-pro/v1` (PreviewController.php:37, SpaceBrandController.php:34).
   Severity: low (spec marks it optional). Confidence: confirmed-in-code.

2. **Per-space brand override is API-wired but the front-end owner control was not
   confirmed.** SpaceBrandController exposes GET/POST `/spaces/{id}/brand`, and
   SpaceBrandAdmin is registered (Plugin.php:261), but whether a non-admin space owner
   reaches these endpoints from a rendered space surface (bound store action /
   template control) was not confirmed in this static trace. Fine for app/REST
   clients. Severity: low. Confidence: needs-live-verification.

3. **Email editor preview footer relies on the admin-only gettext rewriter.**
   EmailEditor.php:928 hardcodes "Powered by BuddyNext". It is neutralised only when a
   brand NAME is set and the string renders in is_admin() context (AdminLabelRewriter
   registers gettext only under is_admin -- AdminLabelRewriter.php:56). If a brand
   hue/logo is configured but the name field is left blank, this preview still reads
   "BuddyNext". Sent emails are unaffected (they use {{site_name}}). Severity: low.
   Confidence: confirmed-in-code.

---

## Minimal refactor plan

None. Verdict is usable-leave-as-is. The core journey works end to end; the gaps
above are an optional spec item (namespace alias) or low-severity polish that do not
stop a real user from completing white-labeling. Do not rewrite working code.

If the team later chooses to close gap #1, add a `buddynextpro_brand_rest_namespace`
option plus a route-alias layer rather than editing each controller's hardcoded
NAMESPACE constant.
