# Conformance — Stripe Payments (Pro)

**Feature:** Stripe Payments / first-party membership checkout
**Repo:** buddynext-pro
**Spec ref:** docs/specs/features/P1-stripe-membership.md (+ 17-roles-permissions.md webhook contract)
**Verdict:** usable-leave-as-is
**Date:** 2026-05-31

---

## Spec note (important)

The locked spec (`P1-stripe-membership.md`) describes the *minimum* model: BuddyNext Free does
no payment processing; gating is `required_ability` + a paywall CTA pointing OUT, and any external
system (incl. Stripe) grants access by calling the `buddynext/v1/webhook/access` endpoint.

BuddyNext **Pro** ships a *first-party* Stripe checkout that goes beyond the Free spec: a
hosted-checkout button on the paywall, a Pro Stripe webhook, and tier→price linkage. This is
additive and degrades to the spec's external-CTA default when Stripe/price is not configured
(PaywallRenderer::checkout_available → false → external CTA or "not configured" notice). The Pro
flow is checked here; it does not violate the Free spec — it sits on top of the same
`bn_ability_{slug}` grant model (17-roles-permissions.md).

---

## Journey chain

Site owner gates a space on `tier:premium`; a logged-in non-member hits the paywall, buys via
Stripe Checkout, the webhook grants the ability, and access opens.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin links tier→Stripe price | service/db | wired | includes/Admin/MembershipAdmin.php:275-278 (handle_save_tier_stripe writes `buddynextpro_tier_stripe_price_id_{slug}`), form field :847 |
| 2 | Admin sets Stripe keys + webhook secret | ui | wired | includes/Stripe/StripeAdmin.php:43-47, 106-119, 149 (webhook URL) |
| 3 | Non-member denied join → paywall context attached to REST denial | service | wired | includes/Membership/PaywallIntegration.php:69-98; Free fires filter at includes/Spaces/SpaceMemberService.php:1295 |
| 3b| Paywall payload re-attached after SpaceController overwrites error data | rest | wired | includes/Membership/PaywallIntegration.php:113-147 (rest_request_after_callbacks) |
| 4 | Paywall rendered into single-space web view | ui | wired | PaywallIntegration.php:163-216 on `buddynext_part_space_hero_after` (fired templates/parts/space-hero.php:267) |
| 5 | Paywall CTA button bound to store action | ui | wired | templates/spaces/paywall.php:71-79 (`data-wp-on--click="actions.startCheckout"`, checkout mode only when price linked) |
| 6 | startCheckout posts to checkout endpoint, redirects to Stripe | store | wired | assets/js/spaces/store.js:469-516 (reads window.bnProCheckout printed at PaywallIntegration.php:197-208) |
| 7 | POST /me/checkout mints Stripe Checkout session | rest | wired | includes/Membership/Controllers/CheckoutController.php:70-114, 124-163; registered Core/Plugin.php:285 |
| 8 | CheckoutService builds session via Stripe SDK | service | wired | includes/Stripe/CheckoutService.php:83-243; SDK bundled at vendor/stripe/stripe-php |
| 9 | Stripe webhook → grant ability + sub row | rest/service/db | wired | includes/Stripe/WebhookController.php:147-321 (writes `bn_ability_tier_{slug}`, fires `buddynext_ability_granted`); registered Core/Plugin.php:327-331 |
| 10| Granted ability opens the gate | service/db | wired | Pro writes the exact key Free reads: WebhookController.php:633-638 → \BuddyNext\Core\PermissionService::ability_meta_key (buddynext/includes/Core/PermissionService.php:344-345, read at :322) |
| 11| Sub row synced from external/manual grants too | service | wired | includes/Membership/WebhookSubscriptionSync.php:42-92 on `buddynext_ability_granted` |
| 12| Returning subscriber → billing portal | rest/service | wired | CheckoutController.php:98-114,171-188 + includes/Stripe/PortalService.php |

## First break

none — journey complete. Both the web journey (paywall button → store → REST → Stripe → webhook →
grant → gate opens) and the headless/app journey (denial payload carries `data.paywall` with
context + rendered HTML, plus the `/me/checkout` endpoint) are wired.

## UX gaps

- **Stale docblock**, severity low, confirmed-in-code: CheckoutService.php:14-15 says "P1.3
  introduces the admin UI that writes this [price] option; until then it can be seeded by hand."
  That admin UI already exists in MembershipAdmin.php:275-278. Comment only — no behavioral impact.
- **First-party checkout depends on three admin preconditions** (Stripe keys set, webhook secret +
  Stripe-dashboard webhook subscription added, tier→price linked), severity low,
  needs-live-verification: if any is missing the paywall correctly degrades to the external CTA or a
  "not configured yet" notice (paywall.php:80-88, PaywallRenderer.php:226-243) — not a break, but the
  happy path is only "live" after admin setup. Confirm on the live site that keys/price are seeded
  before walking checkout.

## Minimal refactor plan

(empty — usable-leave-as-is)

## Live-walk URL

http://buddynext-dev.local/spaces (gated join → checkout). Seed first: a tier (e.g. `premium`)
with a linked Stripe test price, Stripe test keys + webhook secret in BuddyNext → Stripe, a space
gated on `tier:premium`, and a logged-in non-member account. Watch Mailpit (http://localhost:10010/)
and the bn_subscriptions table after the test purchase.
