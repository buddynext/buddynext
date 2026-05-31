# Conformance: Membership Tiers & Gated Spaces (Pro)

**Feature:** Membership Tiers & Gated Spaces (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P1-stripe-membership.md` (locked) + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/membership-gated-spaces.md`
**Cross-cutting:** REST-FRONTEND-CONTRACT, SCALE-CONTRACT, 17-roles-permissions (visibility/abilities)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/spaces

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin creates a tier (`bnpro-membership-tiers` form → "Add Tier") | ui→service→db | wired | `includes/Admin/MembershipAdmin.php:94,182,194` (`admin_post_bnpro_add_tier` → `create_tier()`) |
| 2 | Admin configures global paywall CTA (`bnpro-paywall-settings`) | ui→service | wired | `includes/Admin/MembershipAdmin.php:98,353,713` (`admin_post_bnpro_save_paywall`) |
| 3 | Space gated via `required_ability` (`tier:premium`) | db | wired (SQL/CLI only) | journey step 4; read at `includes/Admin/MembershipAdmin.php:699` and `includes/Membership/PaywallIntegration.php:248` |
| 4 | Ungated member join blocked at gate | service | wired | Free `includes/Spaces/SpaceMemberService.php:77` fires `buddynext_can_join_space`; Pro `includes/Membership/GatedSpacesIntegration.php:32,81` returns `buddynext_can()` |
| 5 | REST join denial carries paywall payload | rest→service | wired | Free emits `buddynext_space_join_denied_data` (`SpaceMemberService.php:1295`); Pro `PaywallIntegration.php:69,89` attaches `data.paywall`; re-attached at `rest_request_after_callbacks` (`PaywallIntegration.php:70,113,144`) |
| 6 | Web single-space paywall renders (blurred preview + CTA) | ui→service | wired | Free hook `templates/parts/space-hero.php:267`; Pro `PaywallIntegration.php:71,163`; template `buddynext/templates/spaces/paywall.php` (Lucide `lock`/`sparkles`, OKLCH tokens, no emoji) |
| 7 | External webhook grants ability → subscription row | service→db | wired | Free `includes/Outbound/AccessWebhookController.php:197` fires `buddynext_ability_granted`; Pro `WebhookSubscriptionSync.php:40,62` resolves `tier:` → inserts via `create_subscription()` |
| 8 | Admin sees subscription, can revoke | ui→service→db | wired | `includes/Admin/MembershipAdmin.php:97,326,600` (`admin_post_bnpro_revoke_sub`) |
| 9 | Subscribed member joins gated space | service | wired | gate at `SpaceMemberService.php:77` now passes; `buddynext_can()` returns true |
| 10 | First-party Stripe checkout CTA (optional path) | ui→store→rest | wired | template button `data-wp-on--click="actions.startCheckout"` (`paywall.php:78`); store `assets/js/spaces/store.js:336-383` POSTs `buddynext-pro/v1/me/checkout` and redirects; config island `PaywallIntegration.php:197` |

All classes registered in `includes/Core/Plugin.php:152-156` (`GatedSpacesIntegration`, `WebhookSubscriptionSync`, `PaywallIntegration`, `MembershipAdmin`). REST controllers registered at `Plugin.php:283-285`.

---

## First break

none — journey complete. Both the web journey (SSR paywall + Interactivity-API checkout button) and the REST/app journey (`data.paywall` on the 403 join denial) are wired through real controls bound to real handlers.

---

## UX gaps (real, non-blocking)

1. **No Free UI to set a space's `required_ability`** — severity: low — confidence: confirmed-in-code. The paywall settings page only *reads* gated spaces (`MembershipAdmin.php:699`); gating a space requires direct SQL/WP-CLI. The journey doc itself documents this (lines 34, 191) as a known seam in Free's space admin, not a defect in this Pro feature. Does not block the happy path — once a space is gated by any means, the entire gate→paywall→subscribe→join loop works.
2. **Subscriptions admin does not link to the member profile** — severity: low — confidence: confirmed-in-code. Documented in journey "Known limitations". Admin must copy the user ID. Cosmetic.

Neither gap stops a member or admin from completing the journey.

---

## Minimal refactor plan

EMPTY — usable-leave-as-is. The gaps above are documented, low-severity, and do not break the journey. No rewrite of working code is warranted.

---

## Notes on grounding rules applied

- Did not assume breakage from absence: every "wired" status is backed by a read filter/action emission point on the Free side and a registered listener on the Pro side, plus the `Plugin::wire_extensions()` registration at `Plugin.php:152-156`.
- Web vs app journeys distinguished: REST clients get `data.paywall` (HTML + context); web gets SSR paywall + Interactivity store checkout. Both confirmed.
- Stripe path degrades safely: `PaywallRenderer::checkout_available()` (`PaywallRenderer.php:226`) returns false without keys/price, falling back to external CTA or "not configured" notice — never fatal.
