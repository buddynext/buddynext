# Conformance: REST / App Contract

**Contract:** REST-Frontend Contract (BuddyNext is 100% REST under versioned namespaces)
**Spec:** `docs/specs/REST-FRONTEND-CONTRACT.md` + `docs/specs/REST-INVENTORY.md`
**Date:** 2026-05-31
**Verdict:** usable-leave-as-is (minor polish opportunities, no contract break)

## What the locked spec actually mandates

The contract doc itself promises four hard guarantees:

1. **Versioned namespace** — everything under `buddynext/v1` (Free) / `buddynext-pro/v1` (Pro), separable.
2. **Uniform permission + nonce** — every route declares `permission_callback`; clients send `X-WP-Nonce` (`wp_create_nonce('wp_rest')`) as a header, never in the query string, never a custom nonce name.
3. **Consistent error envelope** — JSON with `code`, `message`, `data.status`.
4. **Single audit surface** — `bin/check-rest-boundary.sh` proves admin-ajax is dead.

The spec does **not** mandate cursor pagination on every list. Cursor pagination is a directive-level expectation, not a locked-spec clause.

## Guarantee-by-guarantee verification

### G1 — admin-ajax forbidden / 100% REST — WIRED
- `bin/check-rest-boundary.sh` exits 0: "REST-boundary clean — no admin-ajax surface."
- Only `admin-ajax` mention in Free `includes/` is a comment in `Admin/SlugCheckController.php:5` documenting the migration of the last surface.
- Pro `includes/` has zero `wp_ajax_` / `wp_send_json` / `check_ajax_referer` hits.
- (Note: `Admin/Members/ProfileFieldsManager.php:1213` uses a custom `_wpnonce` — but that is a classic server-rendered `admin-post` delete form, not a REST/JS data interaction, so it is outside the contract's scope.)

### G2 — versioned, isolated namespaces — WIRED
- Free: all 26 controllers register under `buddynext/v1` via `includes/REST/Router.php`.
- Pro: all controllers carry `'buddynext-pro/v1'` (const NAMESPACE / NS / $namespace).
- No cross-contamination: grep for `buddynext-pro/v1` in Free = 0; grep for `'buddynext/v1'` in Pro = 0. Pro is purely additive.

### G3 — uniform auth — WIRED
- `X-WP-Nonce` header used in 21 JS files; zero nonce-in-query-string hits.
- Zero custom `wp_create_nonce` for REST (the one non-`wp_rest` nonce is the admin-post form above).
- Every inventoried route has a `permission_callback` (the inventory column is fully populated).

### G4 — consistent error envelope — WIRED
- Free `require_auth`/`require_admin` return `WP_Error( <code>, <message>, array('status'=>401|403) )` — correct `code`/`message`/`data.status` shape across all 18 controllers that define them.
- Pro typed errors carry the same shape with `bnpro_`/`buddynextpro_` prefixes.
- 13 Pro permission callbacks return a **bare bool** (`require_logged_in(): bool`, `require_admin(): bool` in Realtime, Push, SavedSearch, Analytics). On denial these return `false`, which WP core wraps in its standard `rest_forbidden` envelope — so `data.status` is still present. Envelope holds; only the error `code` slug is WP-generic rather than plugin-typed.

## Inconsistencies (polish, not breaks)

1. **Error `code` slug drift (low).** Free uses both `rest_not_logged_in` (Profile, Follow, Post, Connection, Block, Share, Poll, Feed, Notification, Onboarding, Spaces, Realtime) and `rest_forbidden` (Comments, Moderation) for the identical "not logged in / 401" case. Same envelope shape, different `code`. An app switching on `code` sees two slugs for one condition.

2. **Bare-bool Pro perms (low).** The 13 bare-bool callbacks lose the plugin-typed `code` on denial (fall back to core `rest_forbidden`). Cosmetic for an app; functionally the envelope and status are intact.

3. **Pagination is mixed (medium, but spec-compliant).** Cursor pagination (`cursor` param + `next_cursor` in body) is used on the high-volume journey lists: `/members`, `/search`, `/search/members`, `/feed/*`, `/users/{id}/feed`, `/spaces/{id}/feed`, `/me/notifications`. Offset/page (`page`+`per_page`) is used on `/spaces`, `/me/bookmarks`, `/me/shares`, moderation queues/appeals. Relationship lists `/me/connections`, `/me/connection-requests`, `/users/{id}/followers`, `/users/{id}/following` return **unbounded `{ids:[...]}` arrays with no pagination at all**. An app can still drive every journey, but a member with tens of thousands of followers gets an unbounded payload on those four endpoints. This is the only finding with real-world bite; it is not a locked-spec violation.

## No hand-rolled base / shared trait

Each controller hand-rolls its own `require_auth`/`require_admin` (18 copies in Free). There is no shared base controller or trait. This is duplication, not a break — the implementations are byte-identical where they share a name (verified Profile/Follow/Post). Consolidating to a trait would cut the slug drift in G3-1 but is not required by the spec.

## Refactor plan (optional polish, not required to ship)

None required for contract conformance. If reducing app-facing friction is desired later:
- Standardise the "not logged in" `WP_Error` code to a single slug (suggest `rest_not_logged_in`) across Free controllers.
- Convert the 13 bare-bool Pro perms to typed `WP_Error` returns for richer client error handling.
- Add cursor (or page/per_page) pagination to `/me/connections`, `/me/connection-requests`, `/users/{id}/followers`, `/users/{id}/following` to bound payloads.

All three are low-risk, additive, and independent.
