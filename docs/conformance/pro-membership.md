# Conformance: Membership Tiers & Gated Spaces (Pro)

**Feature:** Membership Tiers & Gated Spaces
**Repo:** buddynext-pro
**Code traced:** `buddynext-pro/includes/Membership/` + `buddynext-pro/includes/Admin/MembershipAdmin.php` + Free seams
**Spec ref:** `buddynext/docs/specs/features/P1-stripe-membership.md` (Locked, 2026-03-19); journey `buddynext-pro/docs/journeys/membership-gated-spaces.md`
**Cross-cutting:** REST-FRONTEND-CONTRACT.md, 17-roles-permissions.md (visibility/abilities)
**Live-walk URL:** http://buddynext-dev.local/spaces
**Date:** 2026-05-31

## Verdict

**usable-leave-as-is.** The full happy path — admin creates a tier, configures the paywall, gates a space, a member is blocked at the paywall, gains access via webhook/Stripe, joins, and is later revoked — is wired end-to-end across web UI, REST, service, and DB. No usability break was found by reading the code. Refactor plan is empty.

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin creates a membership tier | ui | wired | `includes/Admin/MembershipAdmin.php:494-526` (Add Tier form, nonce `bnpro_add_tier`); handler `:182-216` |
| 2 | Tier persists | service/db | wired | `MembershipTierService::create_tier()` `MembershipTierService.php:50-103` → `wp_bn_membership_tiers` |
| 3 | Admin configures paywall CTA (global + per-space) | ui | wired | `MembershipAdmin.php:681-779` render; handler `:353-412` writes `buddynextpro_paywall_*` + `bn_space_{id}_paywall_*` options |
| 4 | Space gated via `required_ability` | db | wired (no Free write-UI — by design) | Read by `MembershipAdmin.php:698-701`; journey sets it via SQL/WP-CLI (`membership-gated-spaces.md:34-44,191`) |
| 5 | Member clicks Join on a gated space | ui | wired | Spaces store `joinSpace` `buddynext/assets/js/spaces/store.js:387-425`; button wired `:1698` (`actions.joinSpace`) |
| 6 | Join POST hits Free REST | rest | wired | `POST buddynext/v1/spaces/{id}/join`; Free fires gate filter `buddynext/includes/Spaces/SpaceMemberService.php:77,178` |
| 7 | Gate denies via Pro filter | service | wired | `GatedSpacesIntegration::check_gated_space()` `GatedSpacesIntegration.php:60-82` calls `buddynext_can()` (`buddynext/buddynext.php:55`); registered `Plugin.php:152` |
| 8 | Denial carries paywall payload | rest | wired | `PaywallIntegration::attach_paywall_data()` + `restore_join_paywall_data()` `PaywallIntegration.php:89-147` on `buddynext_space_join_denied_data` (`SpaceMemberService.php:1295`) + `rest_request_after_callbacks` |
| 9 | Web surfaces paywall on denial | ui | wired | `isGatedDenial`/`surfacePaywall` `store.js:133-240`; injects `data.paywall.html` from `PaywallRenderer.render()` `PaywallRenderer.php:134-147` |
| 9b | SSR paywall on gated space view | ui | wired | `PaywallIntegration::render_space_paywall()` `PaywallIntegration.php:163-216` on `buddynext_part_space_hero_after` (`buddynext/templates/parts/space-hero.php:267`); template `buddynext/templates/spaces/paywall.php` |
| 10 | CTA → external URL OR first-party checkout | ui/rest | wired | Template `paywall.php:71-88`; `startCheckout` `store.js:469-516` → `POST buddynext-pro/v1/me/checkout` (`CheckoutController.php:71-76`); external anchor when no Stripe price |
| 11 | External system grants ability (webhook/admin/Stripe) | rest/service | wired | Free `AccessWebhookController.php:197` fires `buddynext_ability_granted` → `WebhookSubscriptionSync::on_ability_granted()` `WebhookSubscriptionSync.php:62-95`; registered `Plugin.php:153` |
| 12 | Subscription row created | service/db | wired | `SubscriptionService::create_subscription()` `SubscriptionService.php:63-146` → `wp_bn_subscriptions` |
| 13 | Member re-joins, now passes gate | rest/service | wired | `buddynext_can()` now true (ability meta) → join succeeds, `store.js:402-415` joined state |
| 14 | Admin sees subscription, can revoke | ui/service | wired | `MembershipAdmin.php:535-674` Subscriptions table + Revoke form; `expire_subscription()` `SubscriptionService.php:156-200` |
| 15 | Expiry cron flips expired rows | service | wired | `SubscriptionService::run_expiry_cron()` `:304-351`; daily event `register_cron()` `:41-47`, `Plugin.php:155` |

REST controllers registered at `rest_api_init`: `Plugin.php:283-285` (Tiers, Subscriptions, Checkout). Admin instantiated `Plugin.php:156`. Admin pages exposed both as legacy slugs and Monetization Hub tabs (`MembershipAdmin.php:102-121`).

## First break

**none — journey complete.** Every link from `/spaces` join through gate denial, paywall, ability grant, subscription, re-join, and revoke has a real UI control bound to a store action bound to a REST/service/DB path. Both the SSR paywall (Interactivity `data-wp-on--click="actions.startCheckout"`, `paywall.php:78`) and the dynamically-injected fallback (manual `addEventListener` → `startCheckout`, `store.js:219-221`) wire the checkout button, so the CTA is never a dead end.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Setting a space's `required_ability` has no front-end or admin write-UI in Free — admins must use SQL/WP-CLI to gate a space. The Pro Paywall Settings page only *lists* already-gated spaces, it cannot gate one. Affects web and app/REST equally (no gating endpoint exists). | medium | confirmed-in-code | `MembershipAdmin.php:698-701` reads `required_ability` with no write control; journey states the same `membership-gated-spaces.md:191` |
| Subscriptions admin table shows user_login but no link to the member's Free profile (admin copies the ID manually). | low | confirmed-in-code | `MembershipAdmin.php:643-648`; called out in journey `membership-gated-spaces.md:186` |

Both are pre-existing, documented, and non-blocking; the journey still completes.

## Spec divergence (note, not a break)

The locked `P1-stripe-membership.md` states "No Payment Processing. No Stripe SDK" and describes gating purely through abilities + an external CTA. The shipped code additionally implements a first-party Stripe loop (tiers, `wp_bn_subscriptions`, `POST /me/checkout`, webhook sync). This is an additive layer that **degrades to the spec's external-CTA default** when no Stripe price is linked (`PaywallRenderer::checkout_available()` `PaywallRenderer.php:226-243`; template fallback `paywall.php:80-88`). The spec's mandated path is present and functional; Stripe is opt-in. Recommend reconciling the locked spec text with the built Stripe scope — but no code change is warranted for usability.

## Minimal refactor plan

Empty — feature is usable as built. (The `required_ability` write-UI is a documented future enhancement, not a regression; do not rewrite working gating code to chase it in this pass.)
