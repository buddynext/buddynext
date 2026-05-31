# Conformance: Stripe Payments (BuddyNext Pro)

**Feature:** Stripe Payments / first-party membership checkout
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P1-stripe-membership.md`
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `17-roles-permissions.md` (gating via `bn_ability_{slug}` user_meta + `buddynext_can`)
**Live-walk URL:** http://buddynext-dev.local/spaces (gated join → checkout)
**Verdict:** partial-needs-wiring

---

## Spec vs. code note

The locked spec (`P1-stripe-membership.md`) states "No Payment Processing … No Stripe SDK". The Pro code ships a **full first-party Stripe checkout** (`includes/Stripe/CheckoutService.php`, `WebhookController.php`, `PortalService.php`, `StripeClient.php`) layered on top of the spec's webhook/ability model. The spec is the looser contract; Pro is a superset. This audit verifies the *built* first-party journey, since that is the journey the live entry URL exercises. The spec's external-CTA path is also present and correct.

---

## Journey chain

Member views a gated space → clicks "Become a Member" → first-party Stripe checkout → pays → returns → space unlocks.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Gated space denies non-member; SSR paywall renders with checkout CTA | ui | wired | `buddynext-pro/includes/Membership/PaywallIntegration.php:163` (`render_space_paywall` on `buddynext_part_space_hero_after`) + `buddynext/templates/spaces/paywall.php:71` |
| 2 | REST join denial carries `data.paywall` for app/headless clients | service | wired | `PaywallIntegration.php:89` + `:113` (`restore_join_paywall_data` on `rest_request_after_callbacks`) |
| 3 | Paywall CTA bound to store action | ui | wired | `buddynext/templates/spaces/paywall.php:78` (`data-wp-on--click="actions.startCheckout"`); dynamic build `buddynext/assets/js/spaces/store.js:219` |
| 4 | `startCheckout` POSTs tier_slug, redirects to returned URL | store | wired | `buddynext/assets/js/spaces/store.js:336-383` (POST `me/checkout`, `window.location.href = data.url`) |
| 5 | `POST buddynext-pro/v1/me/checkout` mints session | rest | wired | `buddynext-pro/includes/Membership/Controllers/CheckoutController.php:71` + `:124` |
| 6 | CheckoutService builds Stripe subscription session | service | wired | `buddynext-pro/includes/Stripe/CheckoutService.php:83` (price from `buddynextpro_tier_stripe_price_id_{slug}`, customer mapping) |
| 7 | Admin links Stripe price ID to tier | ui | wired | `buddynext-pro/includes/Stripe/StripeAdmin.php:106` (`register_settings`); price option key `CheckoutService.php:69` |
| 8 | Stripe webhook receives `customer.subscription.created` | rest | wired | `buddynext-pro/includes/Stripe/WebhookController.php:103` (route), `:147` (event dispatch) |
| 9 | Webhook persists subscription row + tier slug meta | db | wired | `buddynext-pro/includes/Stripe/WebhookController.php:280-291` (`bn_subscriptions` row + `USERMETA_TIER_SLUG`) |
| 10 | **Webhook grants the ability the gate reads (`bn_ability_tier_{slug}`)** | service | **broken** | `buddynext-pro/includes/Stripe/WebhookController.php:305` fires `do_action('buddynext_ability_granted', …)` but never writes `bn_ability_` meta; only listener `WebhookSubscriptionSync.php:62-95` writes a `bn_subscriptions` row, not the ability meta |
| 11 | Space unlocks; `buddynext_can(user,'tier:slug')` passes | service | broken | `buddynext/includes/Core/PermissionService.php:107` reads `has_active_grant` (`:321` → `bn_ability_` meta), and `:121` `buddynext_user_can` filter has **no Pro listener** that honors `bn_subscriptions`. Meta absent → gate still denies. |

---

## First break

**Step 10 — `WebhookController::on_subscription_upsert` (`buddynext-pro/includes/Stripe/WebhookController.php:305`).**

The first-party Stripe webhook fires the *notification* action `buddynext_ability_granted` but never **persists** the grant the gate actually checks. The canonical external path does both, in order: `AccessWebhookController::action_grant_ability` writes `update_user_meta( ability_meta_key($ability), $expires )` (`buddynext/includes/Outbound/AccessWebhookController.php:188`) **then** fires the action (`:197`). The Stripe path skips the `update_user_meta` write.

Net effect: a member completes payment, is redirected to `/me/?subscribed=1`, a `bn_subscriptions` row is created — but `buddynext_can($user,'tier:premium')` still returns false because `bn_ability_tier_premium` was never written and no `buddynext_user_can` filter consults `bn_subscriptions`. The gated space stays locked after a successful purchase.

This break affects BOTH the web journey and the app/REST journey, because both terminate in the same gate (`PermissionService::check`).

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Successful Stripe payment does not unlock the gated space — ability grant never persisted by the Stripe webhook | critical | confirmed-in-code | `WebhookController.php:305` (action only) vs. canonical `AccessWebhookController.php:188` (meta write) vs. gate read `PermissionService.php:321`; sole listener `WebhookSubscriptionSync.php:88` writes subscription row only |
| Webhook signature verification / live event handling on the Stripe webhook route not exercised here; live Stripe round-trip not walked | medium | needs-live-verification | route exists `WebhookController.php:103`; behavior under a real Stripe event needs the human browser+CLI walk |

Steps 1–9 are correctly wired (UI control → store action → REST → service → Stripe session → admin price link → webhook persistence of subscription). The single load-bearing defect is the missing ability-meta write at step 10.

---

## Minimal refactor plan

1. In `buddynext-pro/includes/Stripe/WebhookController.php::on_subscription_upsert` (around line 305), before firing `do_action('buddynext_ability_granted', …)`, persist the grant the gate reads: `update_user_meta( $user_id, \BuddyNext\Core\PermissionService::ability_meta_key('tier:'.$tier_slug), $expires_ts )` — where `$expires_ts` is `0` for no-expiry or the unix timestamp from `extract_period_end` (`WebhookController.php:278`). This mirrors `AccessWebhookController::action_grant_ability` (`:186-197`) so both grant paths leave identical state. Reuse the existing `ability_meta_key` helper; do not invent a new key format.
2. In `on_subscription_deleted` / `revoke_user_tier` (`WebhookController.php:340` / `:595`), symmetrically `delete_user_meta` for the same `bn_ability_tier_{slug}` key so cancellation re-locks the space, alongside the already-firing `buddynext_ability_revoked` action (`:595`). Match `AccessWebhookController`'s revoke path.
3. Verify by live walk: link a Stripe test price to a tier, gate a space on `tier:<slug>`, complete test-mode checkout, confirm the space unlocks, then cancel in the Stripe portal and confirm re-lock.
