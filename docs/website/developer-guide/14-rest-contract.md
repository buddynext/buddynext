# REST Contract

The contract every BuddyNext REST route obeys: one versioned namespace, nonce-header authentication, a uniform success/error envelope, a mandatory permission callback, and cursor-based pagination with hard ceilings. Read this page before reading any other REST page - they all assume these rules.

![The admin dashboard whose surfaces are driven by the REST contract every route on this page obeys](../images/admin-overview.webp)

## Overview / Contract

The BuddyNext frontend is 100% REST. Every data interaction from templates, frontend JavaScript, admin JavaScript, and block view-scripts goes through the WP REST API under `wp-json/buddynext/v1/*`. There is no `admin-ajax.php` surface: `wp_ajax_*`, `admin_url( 'admin-ajax.php' )`, the global `ajaxurl`, `check_ajax_referer()`, and `wp_send_json_*()` are not used by the frontend. A CI gate (`bin/check-rest-boundary.sh`) enforces this.

| Rule | Value |
|---|---|
| Namespace (Free) | `buddynext/v1` |
| Namespace (Pro) | `buddynext-pro/v1` |
| Auth | `X-WP-Nonce` request header, nonce from `wp_create_nonce( 'wp_rest' )` |
| Transport | REST only - no admin-ajax |
| Success body | The controller's data array/object, with the HTTP status on the response (200/201) |
| Error body | `{ "code": "...", "message": "...", "data": { "status": N } }` |
| Permission | Every route declares a `permission_callback` - never omitted |
| Pagination | Cursor-based (`next_cursor`) for timelines/directories; page-numbered for bounded admin/search lists |
| `per_page` max | 50 on collection reads; webhook delivery log allows up to 100 |

The Free surface registers its routes under `buddynext/v1`; Pro registers its own under `buddynext-pro/v1`. Pro never registers into the Free namespace, so a future `buddynext/v2` can ship without breaking integrations. The route pages that follow are the reference; to enumerate the live surface on a given install, read `/wp-json/buddynext/v1` rather than trusting a count in prose.

## Authentication: the X-WP-Nonce header

BuddyNext uses cookie authentication plus a REST nonce, the standard WordPress logged-in REST pattern. The client sends the `wp_rest` nonce in the `X-WP-Nonce` header on every request. There are no per-action nonce names and the nonce never goes in the query string.

PHP localizes the REST base URL and the nonce:

```php
wp_localize_script(
    'my-handle',
    'myCfg',
    array(
        'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
        'restNonce' => wp_create_nonce( 'wp_rest' ),
    )
);
```

JavaScript sends the nonce as a header:

```javascript
fetch( cfg.restUrl + 'feed', {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
        'X-WP-Nonce': cfg.restNonce,
        Accept: 'application/json',
    },
} );
```

External integrations that are not running inside a logged-in browser session authenticate with WordPress Application Passwords over HTTP Basic auth, which WordPress accepts on REST routes in place of the cookie + nonce pair.

## Response envelope

A successful response returns the controller's payload directly with the appropriate HTTP status. Reads return 200; a create returns 201. There is no outer `success`/`data` wrapper added by BuddyNext - the payload is the resource (or a collection of resources), and metadata such as the next cursor travels as fields in that payload.

Example success response (200) for a feed read:

```json
{
  "items": [
    {
      "id": 4821,
      "type": "status",
      "author": { "id": 12, "name": "Ada Lovelace" },
      "content": "First post of the day.",
      "reaction_count": 3,
      "comment_count": 1,
      "created_at": "2026-06-20T09:14:00+00:00"
    }
  ],
  "next_cursor": "eyJjcmVhdGVkX2F0IjoiMjAyNi0wNi0yMFQwOToxNDowMFoiLCJpZCI6NDgyMX0"
}
```

## Error envelope

Errors are returned as a `WP_Error` carrying a `code`, a human-readable `message`, and `data.status` (the HTTP status). WordPress serializes this to the standard REST error envelope and sets the response status code to match `data.status`. Every BuddyNext controller follows this shape, so clients can branch on `code` and `data.status` uniformly across the whole surface.

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to manage webhooks.",
  "data": { "status": 403 }
}
```

Common status codes in use: 400 (bad payload), 401 (not authenticated / bad nonce or signature), 403 (authenticated but lacks capability), 404 (object not found), 422 (validation failure, for example an invalid URL), 502/503 (an upstream/integration failure). A permission failure resolves to `rest_forbidden` with status 401 or 403 regardless of whether the callback returned `false` or a `WP_Error`, because WordPress normalizes both to the same envelope.

## Permission callbacks

Every `register_rest_route` call declares a `permission_callback`. This is a hard requirement - a route without one is a contract violation. The callback runs before the route's callback and returns `true`, `false`, or a `WP_Error`.

| Access level | Callback pattern |
|---|---|
| Genuinely public read | `'__return_true'` (only for public data, for example explore feed counts and the signed inbound webhook below) |
| Logged-in only | A method calling `is_user_logged_in()` |
| Capability-gated | A method calling `current_user_can( 'manage_options' )` (admin), `'edit_posts'` (authors), or a plugin capability |
| Object-scoped | A method that resolves the object from the request and checks ownership/membership |

A public `permission_callback` does not mean an unauthenticated write is unguarded. The inbound access webhook (see REST: Webhooks) uses `'__return_true'` but verifies an HMAC-SHA256 signature inside the handler, so authentication happens at the body-signature layer instead of the cookie/nonce layer.

## Pagination

Two pagination models are used, chosen by the shape of the read:

### Cursor pagination (timelines and directories)

Infinite-scroll reads - the home feed, the member directory, member search - return an opaque `next_cursor`. The client passes it back as a query parameter to fetch the next page. Cursors encode the sort position (`WHERE created_at < ? AND id < ?`) so each page costs O(per_page) regardless of how deep the client has scrolled. `OFFSET` is never used for these reads because at deep pages it scans and discards every preceding row.

When `next_cursor` is absent or null, there are no more results.

### Page-numbered pagination (bounded lists)

Bounded admin and search listings - global search, the webhook delivery log, Pro analytics - use `page` plus `per_page`. These lists are capacity-capped rather than infinitely scrolled.

### Ceilings

- `per_page` maximum is 50 on collection reads, enforced per route. The webhook delivery log allows up to 100.
- Sidebar widgets cap at 5-10 rows.
- Search results cap at 100 per page with a hard ceiling of 1000 rows across all pages.
- Counts shown in the UI (post count, follower count, member count, unread count) come from cached/denormalized columns, never a live `SELECT COUNT(*)` in a page render.

## Examples

Authenticated read with a REST nonce, from a logged-in browser session (the cookie travels automatically, the nonce proves intent):

```bash
curl 'https://example.com/wp-json/buddynext/v1/feed?per_page=20' \
  -H 'X-WP-Nonce: 5f3a9c2b1d' \
  -H 'Accept: application/json' \
  --cookie 'wordpress_logged_in_...=...'
```

Authenticated request from an external integration using an Application Password:

```bash
curl 'https://example.com/wp-json/buddynext/v1/webhooks' \
  -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Accept: application/json'
```

A forbidden request (authenticated user without the required capability) returns:

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to manage webhooks.",
  "data": { "status": 403 }
}
```

## Notes / gotchas

- Send the nonce in the `X-WP-Nonce` header only. A nonce in the query string is not accepted, and there are no custom per-action nonce names.
- A `WP_Error` with `data.status` and a bare `false` from a permission callback both produce the same `rest_forbidden` envelope on the wire - clients should not depend on a specific message string, only on `code` and `data.status`.
- Counts are denormalized. Do not expect a route to compute `COUNT(*)` on demand; counts come from cached columns and are eventually consistent within the cache TTL.
- The feature surface is split Free/Pro by namespace. A route documented as Pro lives under `buddynext-pro/v1` and is only present when BuddyNext Pro is active.

## Timestamp contract: *_gmt siblings

Every BuddyNext REST timestamp is stored as a bare `Y-m-d H:i:s` MySQL string with no timezone marker, which is ambiguous for a native client. Rather than each controller emitting a `_gmt` field by hand - partial, drift-prone, and forgotten on every new endpoint - one dispatch seam (`BuddyNext\Core\Dates`) adds a `<key>_gmt` UTC ISO-8601 sibling (for example `created_at_gmt` => `2026-07-20T12:08:45Z`) next to every whitelisted timestamp key it finds in the response. Web and app therefore share one contract, and future endpoints - including integration-bridge rows served under the namespace - inherit it for free.

The seam runs on the `rest_request_after_callbacks` filter, not `rest_post_dispatch`. The former fires inside `WP_REST_Server::dispatch()`, so it covers both real HTTP requests and internal `rest_do_request()` calls; the latter only fires in `serve_request()` and would silently miss every internally-dispatched route. The transform is idempotent (a key that already has a `_gmt` sibling is left alone) and only touches strings shaped like a MySQL datetime - ints, nulls, and already-ISO values pass through untouched.

Two filters control what gets normalized:

| Filter | Purpose | Default |
|---|---|---|
| `buddynext_rest_timestamp_namespaces` | Route prefixes whose responses receive `<key>_gmt` siblings | `array( '/buddynext/v1/' )` |
| `buddynext_rest_timestamp_keys` | Response keys known to hold a UTC datetime, each of which gets an ISO-8601 `<key>_gmt` sibling | `created_at`, `updated_at`, `edited_at`, `scheduled_at`, `expires_at`, `registered_at`, `joined_at`, `archived_at`, `last_activity_at`, `last_active_at`, `last_message_at`, `sent_at`, `reacted_at` |

Pro and add-ons that register their own namespace opt every one of their endpoints in with a one-liner instead of shipping a second copy of the seam:

```php
add_filter(
    'buddynext_rest_timestamp_namespaces',
    function ( array $namespaces ): array {
        $namespaces[] = '/buddynext-pro/v1/';
        return $namespaces;
    }
);
```

Never add a key to `buddynext_rest_timestamp_keys` that can carry site-local time - every listed key is written with `current_time( 'mysql', true )` / `gmdate()` or read from a UTC column, so the `_gmt` sibling is always a true UTC instant.

## App bootstrap: GET /app/config

`GET /buddynext/v1/app/config` is the single handshake the native app calls first, before it does anything else - and before it authenticates. It answers three questions in one round trip: whether the app may run on this site, how to theme its pre-auth screens, and which features exist. It is public on purpose (`permission_callback` is `'__return_true'`): the app has to theme the connect and sign-in screens in the site's colours, and it has to be able to say "this site does not have the app" without asking for credentials first. There is nothing here a logged-out visitor cannot already read off the page.

Answering `200` with `app_enabled: false` is deliberate. A Pro-only route would 404 on a free-only site, indistinguishable from a wrong URL, a firewall, or a site that is not BuddyNext at all. Serving the handshake from Free lets the app tell "this community does not have the app" apart from "something is broken", so it never has to infer a capability from a probe.

| Property | Value |
|---|---|
| Method | `GET` |
| Path | `/wp-json/buddynext/v1/app/config` |
| Auth | Public - `permission_callback` is `'__return_true'`; read before login |
| Extension | `apply_filters( 'buddynext_app_config', $data, $request )` - Pro flips `app_enabled` on a valid licence, fills `legal`, and may override `branding` |

Top-level response keys:

| Key | Type | Meaning |
|---|---|---|
| `contract_version` | int | Payload shape version. Bumped only when a field's meaning changes, never for additive fields; the app refuses a contract it does not understand. |
| `app_enabled` | bool | Whether the app may run here. Fails closed - unlocks on `=== true` and nothing else. Pro flips it on a valid licence. |
| `pro_active` | bool | Whether BuddyNext Pro is active on the site. |
| `min_app_version` | string | Lowest app version this site will serve. Fails open - empty (the default) or unparseable means "no floor". |
| `branding` | object | Pre-auth theming: `app_name`, `accent_color`, `logo_url`, `login_bg_url`, `color_scheme_default` (`auto`/`light`/`dark`). Empty values mean "use your own default". |
| `features` | object | Feature flags keyed by slug, resolved through `FeatureRegistry` so the app sees the same answer the site does (mandatory tiers, unmet dependencies, and absent partner plugins already folded in). Always `map<string, bool>` - integrations live in their own key precisely so this shape stays flat. |
| `integrations` | object | Installed integrations keyed by integration key, each `{ enabled: bool, version: string\|null }`. See below. |
| `limits` | object | Server-side limits the app enforces locally: `connect_note_max_length`, `max_connections`, `max_following`, `viewer_state_max_ids`. |
| `time` | object | The site's time contract: `site_timezone` (WP `timezone_string`), `gmt_offset` (hours, float), `server_utc` (UTC ISO-8601 with `Z`). The app renders in the site owner's WordPress timezone. |
| `legal` | object | Links required for App Store review: `privacy_url`, `terms_url`, `eula_url`, `guidelines_url`, `abuse_contact`. Emitted empty rather than invented when the site has not set one. |

```bash
curl 'https://example.com/wp-json/buddynext/v1/app/config' \
  -H 'Accept: application/json'
```

The `time.server_utc` field pairs with the timestamp contract above: the seam gives every row an absolute UTC instant, and `time` tells the app which timezone to format it in.

### Gating a module: `integrations`

`features` answers "is this BuddyNext feature on", and it cannot answer for a partner: Messages, Discussions, Jobs, Courses, Events and Listings are not BuddyNext features and have no key there. `integrations` answers for those, one entry per registered integration:

```json
"integrations": {
  "media":        { "enabled": true, "version": "2.0.0" },
  "jetonomy":     { "enabled": true, "version": "1.8.0" },
  "gamification": { "enabled": true, "version": "1.6.4" },
  "careerboard":  { "enabled": true, "version": "1.6.0" },
  "learnomy":     { "enabled": true, "version": "1.7.0" },
  "eventonomy":   { "enabled": true, "version": "1.1.0" },
  "listora":      { "enabled": true, "version": "1.2.2" }
}
```

| Field | Meaning |
|---|---|
| `enabled` | The owner's **nav** toggle for that integration (BuddyNext - Integrations). The nav aspect only: an owner who stops a partner posting activity cards (the `feed` toggle) keeps its tab. |
| `version` | The partner plugin's own version, declared by the integration. `null` means the integration did not declare one - an honest "unknown", never a guess. Treat `null` as "cannot version-gate" rather than "too old". |

Two rules the client must follow:

**An absent key means not installed.** Only registered integrations appear, and an integration registers itself only while its plugin is active. The registry is an open filter - any third-party plugin can add an entry - so BuddyNext cannot emit a fixed key list without hardcoding one, which would both rot and shut out the third parties the filter exists to welcome. Deactivating a partner therefore *removes* its key rather than flipping `enabled` to `false`. Read absent and `enabled: false` identically: stay silent. They mean the same thing to a tab, so this needs no extra branch.

**Do not hardcode the key list.** `media` is the key for WPMediaVerse (not `mediaverse`). Iterate what you receive.

Adding a key here does not bump `contract_version` - it is additive, and a client that predates it simply sees no `integrations` object. Treat a missing block as "no integration information", not as "no integrations".
