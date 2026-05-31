# Conformance — Pro Unlimited Webhooks

**Feature:** Unlimited Webhooks (repo: pro)
**Spec ref:** `docs/specs/HOOKS.md` § Outbound — `apply_filters( 'buddynext_outbound_webhook_limit', int $limit )` (Free: 1, Pro: PHP_INT_MAX)
**Cross-cutting:** REST-FRONTEND-CONTRACT.md (UI bound to REST), 17-roles-permissions.md (manage_options gate)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (BuddyNext top-level menu → Settings → Webhooks tab)

## Intent (per spec)

Free caps outbound webhook *endpoint registrations* at 1 via the
`buddynext_outbound_webhook_limit` filter. Pro lifts the cap to `PHP_INT_MAX`
by returning a higher value from the same filter — no new service, no REST
route, no UI of its own. The journey owner is a **site admin** (`manage_options`)
who registers, tests, and removes outbound webhook endpoints from the BuddyNext
Settings → Webhooks tab. Pro's only contribution is removing the count gate.

## Journey chain

| Step | Layer | Status | Evidence |
|---|---|---|---|
| Admin opens BuddyNext → Settings → Webhooks tab | ui | wired | `buddynext/includes/Admin/Settings.php:385` (tab), `:1173` render_tab_webhooks |
| Endpoints table + Add form + Test/Remove buttons render | ui | wired | `buddynext/includes/Admin/Settings.php:1201-1349` |
| Settings JS enqueued on the page | ui | wired | `buddynext/includes/Admin/Settings.php:219-236` (gated to `toplevel_page_buddynext`) |
| Buttons/form bound to REST via fetch + X-WP-Nonce | store | wired | `buddynext/assets/js/admin/settings.js:66-170` (POST `/webhooks`, DELETE `/webhooks/{id}`, POST `/webhooks/{id}/test`) |
| REST routes registered (list/create/delete/log/test) | rest | wired | `buddynext/includes/Outbound/OutboundWebhookController.php:49-157`; routed at `buddynext/includes/REST/Router.php:85` |
| Service enforces limit via filter, then inserts row | service | wired | `buddynext/includes/Outbound/OutboundWebhookService.php:105` (filter), `:107-120` (count gate) |
| **Pro lifts the cap to PHP_INT_MAX** | service | wired | `buddynext-pro/includes/Outbound/UnlimitedWebhooksIntegration.php:30-41`; registered at `buddynext-pro/includes/Core/Plugin.php:202` |
| UI "at_limit" gate reads the same filter (form stays enabled on Pro) | ui | wired | `buddynext/includes/Admin/Settings.php:1206-1207` |
| Endpoint persisted | db | wired | `bn_outbound_webhooks` insert at `OutboundWebhookService.php:133-143` |
| Lifecycle events dispatch to endpoints | service | wired | `buddynext/includes/Outbound/OutboundWebhookListener.php:30-44` (14 hooks → dispatch), booted at `buddynext/includes/Core/Plugin.php:226` |
| Signed delivery + log + auto-deactivate + 5-min retry | service | wired | `OutboundWebhookService.php:404-511`; cron `buddynext_5min` at `CronScheduler.php:103` |

## First break

none — journey complete. The Free UI, JS, REST, service, and DB are all wired,
and Pro's single filter (`UnlimitedWebhooksIntegration.php`) is registered in
`Plugin::wire_extensions()`. Both the service-side count gate
(`OutboundWebhookService.php:107`) and the UI's disabled-form gate
(`Settings.php:1207`) consume the *same* filter, so when Pro returns `PHP_INT_MAX`
the admin can register a 2nd, 100th, etc. endpoint without the form locking.

## UX gaps

- **`window.confirm()` for the Remove confirmation** (severity: low,
  confidence: confirmed-in-code) — `buddynext/assets/js/admin/settings.js:157`.
  UX-foundation drift (native confirm vs. design-system dialog), not a journey
  break. Belongs to the Free admin shell, not the Pro feature.
- **Limit-reached copy is Free-oriented** (severity: low,
  confidence: confirmed-in-code) — `Settings.php:1220-1228`. With Pro active the
  `$at_limit` branch is unreachable (limit = PHP_INT_MAX), so the "Pro lifts this
  cap" message never shows. Cosmetic only.

## Minimal refactor plan

(empty — usable-leave-as-is)

## App / REST client note

Fully usable for both web-admin and REST/app clients: every operation
(list, create, delete, log, test) is a `buddynext/v1/webhooks*` route guarded by
`manage_options` (`OutboundWebhookController.php:262`). The cap lives in the
service layer (`OutboundWebhookService.php:105`), not the UI, so REST clients get
Pro's lifted cap identically.
