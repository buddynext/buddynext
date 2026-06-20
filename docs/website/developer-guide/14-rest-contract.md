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

The Free surface registers routes under `buddynext/v1` (168 endpoints in the manifest); Pro registers its own routes under `buddynext-pro/v1`. Pro never registers into the Free namespace, so a future `buddynext/v2` can ship without breaking integrations.

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
