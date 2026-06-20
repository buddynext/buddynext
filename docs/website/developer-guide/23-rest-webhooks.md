# REST: Webhooks

BuddyNext exposes two webhook surfaces: an outbound CRUD surface that registers external endpoints the site POSTs lifecycle events to, and a single signed inbound endpoint that lets a trusted external service manage a user's role, abilities, and credits. This page covers both. The namespace, auth header, envelope, and error shape are defined on the REST Contract page - read it first.

![The Platform Webhooks admin tab where the outbound and inbound webhook endpoints on this page are configured](../images/admin-webhooks.webp)

## Overview / Contract

The outbound webhook feature is an opt-in feature (`webhooks`, tier `opt_in`, group `integrations`). It is off by default. The owner enables Webhooks in Settings before the CRUD routes register: `REST/Router.php` only registers the outbound CRUD controller when `features->is_enabled( 'webhooks' )` is true. Until then, `/webhooks*` is not on the wire and the admin screen shows a "Webhooks are turned off" notice.

Free caps a site at 1 registered outbound endpoint. The cap is applied at registration time via the `buddynext_outbound_webhook_limit` filter (default `1`), checked against all rows in `bn_outbound_webhooks` (active or inactive). Exceeding it returns a 422 `webhook_limit_reached`. Pro lifts the cap by returning a higher integer (or `PHP_INT_MAX`) from the filter.

The outbound CRUD routes all require the `manage_options` capability. The inbound access endpoint is unauthenticated at the nonce layer (`permission_callback => '__return_true'`) and instead verifies an HMAC-SHA256 signature inside the handler.

## Route table

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/buddynext/v1/webhooks` | `manage_options` | List all registered outbound endpoints |
| POST | `/buddynext/v1/webhooks` | `manage_options` | Register a new endpoint (returns id + signing secret) |
| DELETE | `/buddynext/v1/webhooks/{id}` | `manage_options` | Delete an endpoint and its delivery log |
| GET | `/buddynext/v1/webhooks/{id}/log` | `manage_options` | Paginated delivery log (`per_page` max 100, `page`) |
| POST | `/buddynext/v1/webhooks/{id}/test` | `manage_options` | Send a test ping to the endpoint |
| POST | `/buddynext/v1/webhook/access` | HMAC-SHA256 signature | Inbound: manage a user's role, abilities, or credits |

> **Note:** The outbound routes are plural (`/webhooks`). The inbound access endpoint is singular (`/webhook/access`) and is a different surface with a different auth model. They are not gated by the `webhooks` opt-in feature in the same way - the access endpoint registers whenever its controller boots, but every request must carry a valid signature, and it returns 503 if no secret is configured.

## Outbound: register and manage endpoints

`POST /webhooks` accepts:

| Param | Type | Required | Notes |
|---|---|---|---|
| `url` | string | yes | Must begin with `https://` and be a valid URL, or the call returns 422 `invalid_url` |
| `secret` | string | no | HMAC signing secret. Omit it and the server generates a 40-character secret and returns it once |
| `events` | array of string | no | Event slugs to subscribe to. Empty (the default) subscribes to all events |

The signing secret is returned in the create response and is the only time the generated value is shown. Outbound deliveries are signed with HMAC-SHA256 using this secret and sent off-request; a delivery that fails is retried with exponential backoff, and an endpoint that accumulates three consecutive failures is automatically deactivated.

### Request/response example

Register an endpoint:

```bash
curl -X POST 'https://example.com/wp-json/buddynext/v1/webhooks' \
  -H 'X-WP-Nonce: 5f3a9c2b1d' \
  -H 'Content-Type: application/json' \
  --cookie 'wordpress_logged_in_...=...' \
  -d '{
    "url": "https://hooks.example.net/buddynext",
    "events": ["buddynext_post_created", "buddynext_user_followed"]
  }'
```

Success response (201) - note the one-time secret:

```json
{
  "id": 7,
  "secret": "n3vMq1Xk7pR2sT9bL0cZ8dF4gH6jK1mN5wA3eY2"
}
```

Registering a second endpoint on Free (cap reached) returns 422:

```json
{
  "code": "webhook_limit_reached",
  "message": "You have reached the maximum number of registered webhook endpoints.",
  "data": { "status": 422 }
}
```

### Delivery log and test ping

`GET /webhooks/{id}/log` returns the paginated per-attempt delivery log (`per_page` defaults to 20, max 100; `page` defaults to 1). `POST /webhooks/{id}/test` sends a test ping and returns:

```json
{
  "success": true,
  "message": "Test ping delivered successfully."
}
```

A failed test ping returns the same shape with `"success": false` and a 502 status, pointing the caller at the delivery log.

## Inbound: the access endpoint

`POST /webhook/access` lets a trusted external service (for example a billing system) change a BuddyNext user's access. Every request must carry an `X-BuddyNext-Signature` header of the form `sha256=<hmac>`, where the HMAC is computed over the raw request body using the shared secret stored in the `buddynext_webhook_secret` option (set on the admin settings page). The handler compares signatures with `hash_equals`.

Outcomes:

- No secret configured: 503 `webhook_not_configured`.
- Signature mismatch: 401 `invalid_signature`.
- Body is not valid JSON: 400 `invalid_payload`.
- User cannot be resolved: 404 `user_not_found`.
- Unknown `action`: 400 `unknown_action`.

The target user is resolved from `user_id` or `user_email` in the body. Every call - success or error - is written to `bn_webhook_log` for auditing.

| `action` | Effect |
|---|---|
| `set_role` | Set the community role (`admin`, `moderator`, or `member`); fires `buddynext_role_changed` |
| `grant_ability` | Grant a BuddyNext ability with optional `expires_at`; fires `buddynext_ability_granted` |
| `revoke_ability` | Remove a granted ability; fires `buddynext_ability_revoked` |
| `add_credits` | Add `amount` to the user's credit balance |
| `set_credits` | Replace the user's credit balance with `amount` |
| `deduct_credits` | Subtract `amount` (floors at 0) |

### Request/response example

```bash
BODY='{"action":"grant_ability","user_email":"member@example.com","ability":"post-in-feed","expires_at":"2026-12-31","source":"stripe"}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $2}')"

curl -X POST 'https://example.com/wp-json/buddynext/v1/webhook/access' \
  -H "X-BuddyNext-Signature: $SIG" \
  -H 'Content-Type: application/json' \
  -d "$BODY"
```

Success response (200):

```json
{ "success": true }
```

## Notes / gotchas

- The generated outbound `secret` is shown only in the `POST /webhooks` response. Store it on creation; it is not returned again by `GET /webhooks`.
- The outbound CRUD routes only exist when the `webhooks` opt-in feature is enabled. If the routes 404, confirm the feature is turned on in Settings before debugging auth.
- Free is capped at 1 outbound endpoint. To raise the cap in Pro or a custom build, return a higher integer from `buddynext_outbound_webhook_limit`.
- The inbound `/webhook/access` endpoint signs over the raw request body. Compute the HMAC on the exact bytes you send - any re-serialization (whitespace, key reordering) changes the signature and produces a 401.
- The two surfaces use different secrets: outbound deliveries are signed with the per-endpoint secret returned at registration; inbound requests are verified against the single `buddynext_webhook_secret` option.
