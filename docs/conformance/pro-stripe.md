# Conformance — Stripe Payments / Membership Gating (Pro)

**Feature:** Stripe Payments (membership tier checkout + access gating)
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P1-stripe-membership.md`
**Cross-cutting:** REST-FRONTEND-CONTRACT.md, SCALE-CONTRACT.md, 17-roles-permissions.md
**Live-walk URL:** http://buddynext-dev.local/spaces (gated join → checkout)
**Verdict:** partial-needs-wiring

---

## What the spec actually mandates

The locked spec (`P1-stripe-membership.md`) is explicit: BuddyNext **does not process payments**. Gating is ability-based. The expected member journey is:

1. Member hits a gated space they cannot access.
2. A **paywall UI** renders: blurred preview + admin-configured CTA button whose href points OUT to wherever the site sells access (WooCommerce, Stripe payment link, etc.).
3. Member pays externally; that platform calls `POST buddynext/v1/webhook/access` with `grant_ability`.
4. `buddynext_ability_granted` fires → `bn_ability_{slug}` user_meta is written → access granted.

Pro is allowed to ship a thin first-party Stripe bridge ("Pro Addons" section). The code here goes further: a full hosted-checkout (`CheckoutService`/`CheckoutController`) plus a Stripe-event webhook (`Stripe/WebhookController`). That richer model is in spec spirit, but its member-facing front-end is not wired.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member opens `/spaces`, sees Join button on a gated space | ui | wired | `buddynext/templates/parts/space-hero.php:231` (`data-wp-on--click="actions.joinSpace"`) |
| Join click → POST spaces/{id}/join | store | wired | `buddynext/assets/js/spaces/store.js:134-146` |
| Gate evaluates `required_ability` | service | wired | `buddynext-pro/includes/Membership/GatedSpacesIntegration.php:60-81` via `buddynext_can_join_space` filter (`buddynext/includes/Spaces/SpaceMemberService.php:77`) |
| Denied join returns WP_Error | rest | wired | `buddynext/includes/Spaces/SpaceMemberService.php:78-83` (`cannot_join_space`) |
| **Front-end shows paywall / CTA on denial** | ui | **broken** | `buddynext/assets/js/spaces/store.js:163-169` — on non-OK it only resets button text + re-enables; no paywall, no CTA, no checkout. Member sees a silent flicker. |
| PaywallRenderer produces CTA HTML | service | missing (not invoked) | `buddynext-pro/includes/Membership/PaywallRenderer.php:55` `render()` has **zero callers**; only its config readers are used in `Admin/MembershipAdmin.php`. Not hooked into any space view. |
| Member reaches Stripe checkout from front-end | ui | api-only | `POST buddynext-pro/v1/me/checkout` registered (`includes/Membership/Controllers/CheckoutController.php:70-96`) but **no front-end control calls it** — Pro `assets/` has only admin test JS (`assets/admin/tier-stripe-test.js`), no member checkout JS. |
| CheckoutService mints session URL | service | broken-at-runtime | `includes/Stripe/CheckoutService.php:217-219` calls `\Stripe\StripeClient`; SDK declared (`composer.json:7`) but **absent from vendor/ and composer.lock** → fatal/`stripe_not_configured` unless short-circuit filter used. |
| External pay → grant via spec webhook | rest | wired | `buddynext/includes/Outbound/AccessWebhookController.php:46,197` (`POST /webhook/access`, fires `buddynext_ability_granted`) |
| Stripe-event webhook grants tier | rest/service | api-only + runtime-gated | `includes/Stripe/WebhookController.php:102-169,305`; returns `stripe_sdk_missing` 503 (`:213-219`) until SDK installed; signature secret read from option (`:127-135`) |
| Ability grant → user_meta + subscription row | db | wired | Free `OutboundWebhookListener.php:42`; Pro `WebhookSubscriptionSync.php:41-42` listens to `buddynext_ability_granted` |
| Access now allowed on next join | service | wired | `GatedSpacesIntegration.php:81` re-checks `buddynext_can()` |

---

## First break

`buddynext/assets/js/spaces/store.js:163-169` — when a gated join is denied, the front end silently resets the Join button. No paywall, no CTA, no path to checkout. The member cannot discover how to gain access. `PaywallRenderer::render()` (the spec's mandated paywall UI) is never called by any front-end code, so even the spec-minimum "button pointing out" never reaches the page.

This is a web-journey break. For an app/REST client the pieces exist (`/me/checkout`, gate, webhook), so the API surface is usable headlessly — but the bundled web UX does not complete the journey.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|-----------|----------|
| Gated-join denial shows no paywall/CTA on the front end; Join button silently resets | critical | confirmed-in-code | `buddynext/assets/js/spaces/store.js:163-169`; `PaywallRenderer::render()` has no caller |
| `PaywallRenderer` (spec's required paywall UI) is never rendered into a space view | critical | confirmed-in-code | `buddynext-pro/includes/Membership/PaywallRenderer.php:55`; only `get_cta_*`/`get_description` used by `Admin/MembershipAdmin.php` |
| First-party Stripe checkout is API-only: `/me/checkout` has no member-facing UI control | high | confirmed-in-code | `includes/Membership/Controllers/CheckoutController.php:70-96`; Pro `assets/` has only `assets/admin/tier-stripe-test.js` |
| Stripe PHP SDK not installed; checkout + webhook fail at runtime | high | confirmed-in-code | `composer.json:7` declares `stripe/stripe-php`; absent from `composer.lock` and `vendor/`; guarded at `includes/Stripe/WebhookController.php:213-219` and `CheckoutService.php:92-98,217-219` |

Note: the ability-grant backbone (Free `AccessWebhookController`, `buddynext_ability_granted`, `WebhookSubscriptionSync`, the gate filter) is built and wired correctly — do not touch it.

---

## Minimal refactor plan (reuse existing working code)

1. Hook the **already-built** `PaywallRenderer::render( $space_id )` into the single-space denied view. Add a render hook in Free's space view where a non-member of a gated space is shown the body, and have Pro render the paywall there (or return its HTML through an existing space-body filter). Reuse the existing per-space + global CTA options — no new option keys.
2. In `buddynext/assets/js/spaces/store.js` `joinSpace`/`requestJoin`, on a non-OK response carrying the gated error, surface the paywall CTA instead of silently resetting (e.g. read the CTA URL the space view already carries, or re-render the denied state). This makes the spec-minimum "button pointing out" reachable.
3. For the first-party Stripe path: either (a) add the SDK to the build (`composer require stripe/stripe-php`, commit lock + vendor per release process) and wire one front-end control that POSTs to `/me/checkout` from the paywall CTA, OR (b) if the first-party checkout is not yet in scope, keep the paywall CTA pointing to an external URL per spec and defer `/me/checkout` UI. Pick one so the journey is coherent.
4. Re-walk `/spaces` as a logged-in non-member of a gated space (light + dark) to confirm the paywall renders and the CTA leads somewhere.

---

## Live-walk URL

http://buddynext-dev.local/spaces — log in as a member who lacks the gated tier, open a gated space, click Join, and confirm a paywall/CTA appears (currently it does not).
