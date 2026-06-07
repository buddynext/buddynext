# Conformance — Pro: Unlimited Webhooks

**Feature:** Unlimited Webhooks (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/HOOKS.md` § Outbound (lines 353–360) — `apply_filters( 'buddynext_outbound_webhook_limit', int $limit )`, default 1, Pro sets PHP_INT_MAX.
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → BuddyNext → Settings → Webhooks tab

---

## What the feature is

Free ships a complete outbound-webhook subsystem (register endpoint, sign + POST on lifecycle events, log, retry, auto-deactivate) but caps registered endpoints at **1** via the `buddynext_outbound_webhook_limit` filter. The Pro "Unlimited Webhooks" feature is a three-line filter override that returns `PHP_INT_MAX`, removing the practical cap. This is Pattern C (filter-override, no rebind/inheritance) per FREE-PRO-CONTRACT.

The cap is enforced in exactly one place: `OutboundWebhookService::register()` counts rows in `bn_outbound_webhooks` and rejects with `webhook_limit_reached` (HTTP 422) when `count >= limit`. Lifting the filter lifts the cap everywhere it matters.

---

## Journey chain (admin happy path)

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Open BuddyNext Settings → Webhooks tab | ui | wired | `includes/Admin/Settings.php:131` (tab registered), `:1173` `render_tab_webhooks()`, `:1191` renders endpoint manager |
| 2 | Page loads endpoint manager card + JS | ui | wired | `includes/Admin/Settings.php:219-236` enqueue gated to `toplevel_page_buddynext`; `assets/js/admin/settings.js:66` binds `[data-bn-webhooks]` |
| 3 | Admin enters HTTPS URL + checks events, clicks "Register endpoint" | ui→store | wired | `Settings.php:1311-1345` controls; `settings.js:91-128` POSTs `{url,events}` with `X-WP-Nonce` |
| 4 | POST /buddynext/v1/webhooks | rest | wired | `includes/REST/Router.php:85` registers controller; `OutboundWebhookController.php:59-87,178-200` CREATABLE, auto-generates 40-char secret, `require_admin` (manage_options) |
| 5 | Service applies cap filter then inserts | service | wired | `OutboundWebhookService.php:105` `apply_filters('buddynext_outbound_webhook_limit',1)`; `:113` rejects at cap; `:133` inserts row |
| 6 | **Pro lifts the cap to PHP_INT_MAX** | service | wired | `buddynext-pro/includes/Outbound/UnlimitedWebhooksIntegration.php:31,39` filter→`PHP_INT_MAX`; registered at `buddynext-pro/includes/Core/Plugin.php:202` inside `wire_extensions()` |
| 7 | Row persisted; UI count gate releases | db→ui | wired | insert into `bn_outbound_webhooks`; `Settings.php:1206-1207` recomputes `$at_limit` from same filter, so add form/fields un-disable for Pro |
| 8 | Events dispatch to registered endpoints | service | wired | `OutboundWebhookListener.php` registered at `Plugin.php:226`; `OutboundWebhookService::dispatch()` `:269` selects active hooks, `deliver()` `:404` signs (HMAC-SHA256) + POSTs |
| 9 | Send test / Remove endpoint | ui→rest | wired | `settings.js:132-183` delegated handlers → POST `/webhooks/{id}/test` (`Controller:242`) and DELETE `/webhooks/{id}` (`Controller:208`) |

## First break

**none — journey complete.** With Pro active, `UnlimitedWebhooksIntegration::register()` runs in `wire_extensions()`, the cap filter returns `PHP_INT_MAX`, the service's count check at `OutboundWebhookService.php:113` never trips, and the admin UI's `$at_limit` flag (`Settings.php:1207`) stays false so the registration form remains enabled for additional endpoints.

## UX gaps

None that break the journey. Minor notes (not gaps):

- After a successful registration the JS does a full `window.location.reload()` (`settings.js:122`) rather than appending a row — intentional ("keeps server-side numbering authoritative") and acceptable for an admin-only screen.
- The Webhooks tab is admin-only (`manage_options`), correct for this feature; there is no member-facing surface and none is specified. Fine — the locked spec scopes the cap filter to site owners.

## Minimal refactor plan

(empty — usable, leave as is)

---

### Verification notes

- Pro override is unconditionally registered in `wire_extensions()` (Plugin.php:202) alongside the other Pro integrations, so no per-feature flag sits in the trace path; any higher-level license gate wrapping `wire_extensions()` is out of scope for this feature's wiring and does not change the verdict.
- Static read only; not exercised against a live DB. Cap logic, filter wiring, REST routes, admin UI, and JS bindings are all present and consistent, so confidence is high without a live walk. A live confirmation would be: activate Pro, register a 2nd endpoint, confirm no 422.
</content>
