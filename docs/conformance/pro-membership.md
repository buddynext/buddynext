# Conformance: Membership Tiers & Gated Spaces (Pro)

**Feature:** Membership Tiers & Gated Spaces (repo: buddynext-pro)
**Spec ref:** `buddynext/docs/specs/features/P1-stripe-membership.md` (locked) + journey `buddynext-pro/docs/journeys/membership-gated-spaces.md`
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md` (visibility/abilities)
**Live-walk URL:** http://buddynext-dev.local/spaces
**Verdict:** partial-needs-wiring

---

## Summary

The data + REST + admin layers of this feature are fully built and correctly wired. The back-end gating chain (webhook grant → ability meta → join gate → subscription sync) is complete and works end-to-end for the **REST/app journey** and for the **admin journey**. The single break is at the **web UI**: the spec's central deliverable — the paywall (blurred preview + configurable "Become a Member" CTA pointing to the site's checkout) — is implemented as a class (`PaywallRenderer`) but is **never invoked anywhere**, and the web join button silently reverts on a gated denial with no message and no upgrade path. A web visitor hits a gated space, clicks "Join space", sees a spinner flash, and the button just goes back to "Join space" — no paywall, no CTA, no explanation.

This is a real usability break for the web journey (the feature's headline UX), but the REST surface and grant path are sound. Refactor is small: surface the existing `PaywallRenderer` output to the web join flow.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin creates a tier (`bnpro-membership-tiers`) | ui | wired | `buddynext-pro/includes/Admin/MembershipAdmin.php:141-173` (menu), `:182-216` (handle_add_tier) |
| 2 | Tier persisted to `bn_membership_tiers` | service/db | wired | `buddynext-pro/includes/Membership/MembershipTierService.php:50-103` |
| 3 | Admin sets paywall CTA defaults (`bnpro-paywall-settings`) | ui/db | wired | `buddynext-pro/includes/Admin/MembershipAdmin.php:98` (handle_save_paywall), options read by `PaywallRenderer.php:91-130` |
| 4 | Space gets `required_ability='tier:premium'` (no Free UI; SQL/CLI only) | db | api-only | journey doc lines 34-44; Free exposes no UI for `required_ability` (confirmed: only `Installer.php` + `SpaceMemberService.php` touch the column in Free) |
| 5 | Member attempts to join gated space via REST | rest | wired | `buddynext/includes/Spaces/SpaceController.php:701-778` → `SpaceMemberService::join()` |
| 6 | Join gate fires `buddynext_can_join_space` | service | wired | `buddynext/includes/Spaces/SpaceMemberService.php:77,181` |
| 7 | Pro gate checks ability for `required_ability` | service | wired | `buddynext-pro/includes/Membership/GatedSpacesIntegration.php:60-82`; registered at `Plugin.php:152` |
| 8 | Denied join returns error to client | rest | wired (generic) | `SpaceMemberService.php:78-83` returns `WP_Error('cannot_join_space','You cannot join this space.')` — no paywall payload |
| 9 | **Web UI shows paywall + CTA on denial** | ui | **broken** | `assets/js/spaces/store.js:163-166` — on non-ok response the button text is reset and re-enabled; no error surfaced, no paywall. `PaywallRenderer::render()` is never called (grep across `buddynext-pro/includes/` returns zero call sites) |
| 10 | External webhook grants ability | rest | wired | `buddynext/includes/Outbound/AccessWebhookController.php:44-46,120,172-197` (`POST buddynext/v1/webhook/access` → `do_action('buddynext_ability_granted')`) |
| 11 | Ability grant written to `bn_ability_{slug}` meta | service/db | wired | `AccessWebhookController.php:172-197`; meta key normalization `PermissionService.php:339-345` |
| 12 | Pro syncs grant → `bn_subscriptions` row | service/db | wired | `buddynext-pro/includes/Membership/WebhookSubscriptionSync.php:62-95`; registered `Plugin.php:153` |
| 13 | Re-join after grant succeeds (gate passes) | service | wired | `GatedSpacesIntegration.php:81` → `buddynext_can()` reads granted meta (`PermissionService::can()` `buddynext/includes/Core/PermissionService.php:85-121`) |
| 14 | Admin views/revokes subscription (`bnpro-subscriptions`) | ui | wired | `MembershipAdmin.php:97` (handle_revoke_sub) → `SubscriptionService::expire_subscription()` `:156-200` |
| 15 | Member's tier list via REST | rest | wired | `buddynext-pro/includes/Membership/Controllers/SubscriptionsController.php:53-80`; registered `Plugin.php:280` |
| 16 | Daily expiry cron | service | wired | `SubscriptionService.php:41-47,304-351`; registered `Plugin.php:154` |

---

## First break

**Step 9 — the web UI never renders the paywall.** The earliest user-facing break in the web journey. `PaywallRenderer` (the spec's required "blurred/locked space preview with CTA button", `P1-stripe-membership.md` §Paywall UI) is dead code: no call site exists in `buddynext-pro/includes/`. Free's join REST endpoint returns a generic `cannot_join_space` error with no CTA/paywall payload (`SpaceController.php:773-776`), and the web store action (`store.js:163-166`) discards the error and silently resets the button. A web member is given no upgrade path. (Everything before this — admin config, gate enforcement — works; everything after, via webhook grant and re-join, also works.)

Note: the REST/app journey itself is not broken at the data level — a client that knows to read the 400 body can present its own paywall. But the platform's own web UI provides nothing, so for the web community journey this is a break.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Paywall is never shown to web users on a gated denial; `PaywallRenderer` has zero call sites | critical | confirmed-in-code | `PaywallRenderer.php:55` defined; grep for `PaywallRenderer`/`->render(` across `buddynext-pro/includes/` finds no invocation |
| Web join button silently reverts on gated denial — no message, no CTA, no explanation | critical | confirmed-in-code | `assets/js/spaces/store.js:163-166` |
| Join REST error carries no paywall metadata (CTA url/label/space-id), so even a smart client cannot render the configured CTA | high | confirmed-in-code | `buddynext/includes/Spaces/SpaceController.php:773-776`; `SpaceMemberService.php:78-83` |
| Gated space still renders the standard "Join space" / "Request to join" button (template is unaware of `required_ability`); the lock state is invisible until after a failed click | medium | confirmed-in-code | `buddynext/templates/parts/space-hero.php:226-243` (no gated/lock branch) |
| No Free UI to set a space's `required_ability`; admin must use SQL/WP-CLI | medium | confirmed-in-code | journey doc lines 34, 191; no Free admin surface writes the column (only `Installer.php`/`SpaceMemberService.php` reference it) |

---

## Minimal refactor plan

Reuse the existing, working `PaywallRenderer` and gate — do not rewrite the data/REST layers.

1. **Surface paywall data in the join error.** In Pro, when the gate denies (`GatedSpacesIntegration::check_gated_space` returns false), attach paywall context so the REST client can render it. Cleanest seam: have Free's `SpaceController::join_space` add structured `data` to the `cannot_join_space` `WP_Error` (e.g. `paywall` = rendered HTML from `PaywallRenderer::render($space_id)` plus `cta_url`/`cta_label`). Since `PaywallRenderer` lives in Pro, expose it through the existing `buddynext_user_can`/a Free action hook, or have Pro filter the error payload via an existing Free filter on the join response. Prefer a native filter already present on the join path; add one in Free only if none exists.
2. **Render the paywall in the web store action.** In `assets/js/spaces/store.js` `joinSpace` (and `requestJoin`), when the response is non-ok and `data.data?.paywall` is present, inject that HTML into the space hero (or a modal) instead of silently resetting the button. The markup + classes already exist (`bn-paywall*`).
3. **(medium) Mark gated spaces in the template.** In `space-hero.php`, when the space carries `required_ability` and the viewer lacks it, render the CTA button label/locked state up front rather than only after a failed join. This can read the same `PaywallRenderer` config.
4. **(medium) Add a Free admin control for `required_ability`** on the space edit screen so admins are not forced into SQL/CLI (journey step 4). Out of scope for the gating break itself but required for the documented happy path to be fully UI-driven.

Steps 1-2 close the critical break; 3-4 complete the documented journey.

---

## What works as-is (do not touch)

- Tier CRUD service + REST + admin (`MembershipTierService`, `TiersController`, `MembershipAdmin`).
- Subscription service, webhook sync, admin revoke, daily expiry cron.
- The join gate (`GatedSpacesIntegration`) and Free's `buddynext_can_join_space` filter + ability/meta chain.
- The webhook grant path (`AccessWebhookController` → `buddynext_ability_granted` → `WebhookSubscriptionSync`).
- `PaywallRenderer` itself is correct and theme-agnostic — it just needs to be called.
