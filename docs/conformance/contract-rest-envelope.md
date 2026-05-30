# Conformance: REST / App Contract

**Contract:** REST/App contract — the `buddynext/v1` surface an app drives every member journey through.
**Locked spec:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/REST-INVENTORY.md`
**Date walked:** 2026-05-31
**Verdict:** usable-minor-polish

## Scope walked

- `includes/REST/Router.php` (boot order)
- All 27 `*Controller.php` under `buddynext/includes/`
- All 21 route-registering controllers under `buddynext-pro/includes/`
- JS callers under `buddynext/assets/js/`

## The contract's guarantees — status

| Guarantee | Status | Evidence |
|---|---|---|
| Versioned namespace (`buddynext/v1`) | wired | Router.php:59-86; all Free routes register under `'buddynext/v1'`. Pro ships its own versioned namespace `buddynext-pro/v1` (48 occurrences, zero leakage into the Free namespace). Two parallel versioned surfaces, both consistent internally. |
| Uniform permission callback on every route | wired | Free 140 `register_rest_route` calls, 0 with null/empty `permission_callback`; Pro 46 calls, 0 missing. Public reads use `'__return_true'`; webhooks use `'__return_true'` + in-callback signature auth (Stripe `stripe_signature` constructEvent; Free AccessWebhook HMAC-SHA256 `X-BuddyNext-Signature`). |
| `X-WP-Nonce` header is the auth contract (no query-string nonce, no custom nonce name) | wired | 21 JS files send `X-WP-Nonce`; zero `?_wpnonce=` query-string hits; the single non-`wp_rest` `wp_create_nonce` is an admin server-rendered delete link (ProfileFieldsManager.php:1060), outside REST scope. |
| Consistent error envelope `code` / `message` / `data.status` | wired | All 145 `new WP_Error(...)` constructs across Free controllers carry an `array( 'status' => NNN )` third arg (verified by balanced-paren scan — 0 misses). Returns are `WP_REST_Response` / `WP_Error`, never `wp_send_json_*`. |
| admin-ajax forbidden | wired | 0 `wp_ajax_` / `check_ajax_referer` / `wp_send_json` / `admin-ajax` in Free or Pro `includes/` (only doc-comment references). Last surface migrated 2026-05-21 to `GET /admin/slug-check`. |
| Inventory is the source of truth for the surface | broken | `REST-INVENTORY.md` lists 113 Free routes and claims to be "the source of truth for the BuddyNext REST surface." It omits 5+ live Free routes and the entire ~46-route `buddynext-pro/v1` surface. |
| `/members` resolves to one documented handler | broken | Two controllers register `GET buddynext/v1/members` with different arg schemas. |

## Confirmed contract violations

### 1. `GET /members` route collision (high)
`Search/SearchController.php:66` and `Profile/MemberDirectoryController.php:49` both register
`GET buddynext/v1/members`, same method, different callbacks (`list_members` in each) and
**different arg schemas** — SearchController expects `cursor`/`per_page` (cursor pagination),
MemberDirectoryController expects `search`/`sort` (offset/page). `register_rest_route` defaults
to `override=false`, so both endpoints are merged onto the route; Router boots MemberDirectory
(line 74) before Search (line 75), so a GET dispatches to MemberDirectory and Search's `/members`
is shadowed. The inventory documents `/members` as SearchController:66 — which is **not** the
handler that serves. An app reading the inventory and sending `?cursor=` gets the wrong schema.

### 2. Inventory drift (medium)
`REST-INVENTORY.md` (dated 2026-05-21, "source of truth") is stale:
- Missing Free routes: `/me/onboarding/step`, `/me/onboarding/skip`, `/me/onboarding/complete`
  (OnboardingController), `/me/drafts` (ComposerDraftController), and MemberDirectoryController's
  `/members`.
- Missing the entire Pro surface: ~46 routes under `buddynext-pro/v1`
  (Push, Realtime, Membership/Tiers/Subscriptions/Checkout, Email/Broadcast/Drip/Unsubscribe,
  AI moderation/reply, Analytics, Saved search, White-label, Scheduled posts, Bulk moderation,
  Stripe webhook, etc.).
An app integrator cannot drive the full member journey from the documented inventory because the
documented inventory is a subset of the live surface.

## Not a violation (design choices the spec permits)

- **Mixed pagination.** Feeds + notifications + directory search use cursor (`{items,
  next_cursor, has_more}`); comments/spaces/bookmarks/shares/moderation lists use `page`/`per_page`.
  The locked `REST-FRONTEND-CONTRACT.md` does **not** mandate a single pagination style, so this is
  a deliberate split (infinite-scroll feeds vs. paged admin tables), not a spec break. Worth noting
  for an SDK author but does not violate the locked contract.
- **`buddynext-pro/v1` as a second namespace.** The contract's stated rationale for versioning is
  forward-compatibility; a sibling Pro namespace is consistent with that intent and keeps Pro
  additively separable. Internally consistent (envelope, permission, nonce all match Free).

## Refactor plan (minimal)

1. Resolve the `GET /members` collision: pick one owner. MemberDirectoryController is the directory
   surface; fold SearchController's member listing into it (or give Search a distinct path such as
   `/search/members`) so one path = one schema = one documented handler.
2. Regenerate `REST-INVENTORY.md` from `grep -rn register_rest_route includes/` across **both**
   repos, and either (a) add a `buddynext-pro/v1` section or (b) state explicitly that the file
   covers only the Free namespace and link a Pro inventory.

The error-envelope / auth / permission-callback / no-admin-ajax core is solid across both repos and
must not be touched.
