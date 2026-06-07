# Contract Conformance: REST/App Contract

**Checked:** 2026-05-31
**Spec:** docs/specs/REST-FRONTEND-CONTRACT.md + docs/specs/REST-INVENTORY.md
**Scope:** includes/REST/Router.php, all *Controller.php in buddynext/includes/ and buddynext-pro/includes/
**Verdict:** usable-leave-as-is

## What the contract guarantees, and whether it holds

| Guarantee | Status | Evidence |
|---|---|---|
| Versioned namespace `buddynext/v1` (Free) / `buddynext-pro/v1` (Pro), no leakage | wired | 143 `register_rest_route` calls in Free all use literal `'buddynext/v1'`; Pro uses `'buddynext-pro/v1'` (21 base-const usages -> 46 routes); zero Pro registrations into the Free namespace. |
| Frontend is 100% REST - no admin-ajax surface | wired | Broad grep for `admin-ajax.php\|wp_ajax_\|ajaxurl` across includes/templates/assets in BOTH repos returns zero live hits (only a doc comment in SlugCheckController). CI gate `bin/check-rest-boundary.sh` enforces it. |
| Uniform nonce: `X-WP-Nonce` header from `wp_create_nonce('wp_rest')` | wired | 171 matches for the `wp_rest` nonce / `X-WP-Nonce` header across both repos. No REST nonce in a query string. The single non-`wp_rest` `wp_create_nonce` is an admin-post page-reload form (bn_delete_profile_group), outside the frontend-REST surface. |
| Consistent error envelope `{code, message, data.status}` | wired | Every controller returns `WP_Error( '<code>', __( ... ), array( 'status' => N ) )` (sampled Feed, Spaces, Profile, Realtime). WordPress serializes this to the standard REST envelope automatically. |
| `permission_callback` on every route | wired | Inventory enumerates a permission callback for all routes; sampled callbacks (`require_auth`, `require_admin`, etc.) return `true\|WP_Error` and resolve to standard 401/403. |
| Cursor pagination for timeline/list reads | wired (by domain) | Feed (`FeedController`), member directory (`MemberDirectoryController`), and `/search/members` all return opaque `next_cursor`. Page-number pagination is used for `/search` and Pro analytics - intentional, not mandated as cursor by the spec. |

## Consistency notes (NOT breaks - wire contract intact)

1. **Pagination model is split by domain.** Infinite-scroll timelines use opaque `next_cursor`; bounded admin/search listings use `page`. The spec's "cursor pagination" language describes the feed/directory reads an app drives for scrolling; it does not require cursors on every list. An app can still drive every journey. No action.

2. **No shared base REST controller.** Each of the 48 controllers hand-rolls its permission helpers, and the names diverge across the surface: `require_auth` / `require_admin` / `require_logged_in` / `require_manage_options` / `logged_in_permission` / `admin_permission` / `post_owner_permission`. A few Pro callbacks (Realtime/AuthController, Analytics) return bare `bool` where Free returns `true|WP_Error`. WordPress normalizes both `false` and a `WP_Error` to the same `rest_forbidden` envelope on the wire, so the client-facing contract (auth result + error shape) is identical. The divergence is internal naming/return-type only - a refactor-quality concern, not a usability break. Not flagged for change under the prime directive (working infrastructure).

3. **Inventory drift (doc only).** REST-INVENTORY.md (auto-generated 2026-05-31) omits the Free route `POST /me/presence/heartbeat` (`includes/Realtime/RealtimeController.php:65`), which is booted in `REST/Router.php:87` and fully conforms (`buddynext/v1`, `require_auth` -> `true|WP_Error` 401, `WP_REST_Response`). The route is on the wire and contract-compliant; only the inventory table is stale. Regenerate the inventory. No wire impact.

## First break

None - the contract's external guarantees (namespace, no-admin-ajax, nonce header, error envelope, permission callbacks, cursor reads where the app scrolls) all hold across both repos. An app can drive every member journey on a uniform surface.

## Refactor plan

Empty. The surface is consistent on the wire. The base-controller / permission-naming unification is optional internal cleanup, not required for the contract to be usable, and falls under "do not redo working infrastructure."
