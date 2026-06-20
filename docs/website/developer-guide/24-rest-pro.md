# REST: Pro namespace

The Pro plugin registers its own REST namespace, `buddynext-pro/v1`, with 48 routes. This page is the route reference for developers building on the Pro surfaces: membership and billing, analytics, drip and broadcast campaigns, member labels and tiers, moderation rules, AI assistance, scheduled posts, push, saved searches, white-label/brand, and the realtime + Stripe integration endpoints.

![The Pro admin settings backed by the buddynext-pro/v1 REST routes documented here](../images/admin-settings.webp)

![The BuddyNext admin dashboard the Pro REST namespace populates and reads](../images/admin-overview.webp)

## Contract

`buddynext-pro/v1` follows the same envelope, error shape, pagination, and `wp_rest` nonce rules as Free - see the REST contract page (14-rest-contract) for the cross-surface conventions, and REST: Auth and Account (21-rest-auth-account) for the auth flows. Pro-specific points:

- **The namespace is `buddynext-pro/v1`**, not `buddynext/v1`. All paths below are prefixed with `/wp-json/buddynext-pro/v1`.
- **Most routes are admin- or owner-gated.** Campaign, moderation-rule, label-admin, analytics-overview, and AI-classify routes require an admin capability. Member-scoped routes (anything under `/me/...`, own subscriptions, saved searches, push) require login. A few are public reads.
- **Two routes are open at the permission layer but signed at the payload layer:** `/stripe/webhook` and `/realtime/auth`. See the highlight below - "open" does not mean "unauthenticated trust".

Source of truth: `audit/manifest.json` (`rest.endpoints`, namespace `buddynext-pro/v1`) in the Pro repo, and the controllers under `includes/`.

## Open-but-signed routes (read this first)

| Method | Path | Permission gate | How it is actually authorised |
|---|---|---|---|
| POST | `/stripe/webhook` | `none` (public) | Verifies the `Stripe-Signature` header against the configured webhook secret via `\Stripe\Webhook::constructEvent()`; rejects with `stripe_invalid_signature` on mismatch and `stripe_webhook_secret_missing` when no secret is set. Handled in `Stripe/WebhookController`. |
| POST | `/realtime/auth` | `require_logged_in` | Mints a Soketi/Pusher channel auth signature `key:hmac_sha256(socket_id:channel, secret)` - but only after confirming the current user may access the requested private channel. Handled in `Realtime/AuthController`. |

`/stripe/webhook` has no WordPress capability check because Stripe calls it server-to-server with no session; its trust comes entirely from the HMAC signature on the payload. `/realtime/auth` is login-gated and additionally enforces per-channel access before returning the signature, so a logged-in user cannot subscribe to a channel they are not entitled to.

## Routes by domain

### Membership: tiers, checkout, subscriptions

Tiers are the membership plans. There is no separate `/plans` route - a tier is the plan, and checkout/portal/subscription routes operate against tiers.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/tiers` | Public (GET) / Admin (POST) | List tiers; create a tier. |
| GET, DELETE | `/tiers/{id}` | Public (GET) / Admin (DELETE) | Get a tier; delete a tier. |
| POST | `/me/checkout` | Logged in | Create a Stripe checkout session for the current user. |
| POST | `/me/portal` | Logged in | Create a Stripe billing-portal session for the current user. |
| POST | `/admin/tiers/{slug}/test-checkout` | Admin | Run a test checkout against a tier. |
| GET | `/me/subscriptions` | Logged in | Current user's subscriptions. |
| GET | `/users/{id}/subscriptions` | Admin | A user's subscription history. |

### Member tiers vs labels

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/labels` | Mixed (GET public / POST admin) | List member labels; create a label. |
| GET, PUT | `/labels/{id}` | Admin (`manage_options`) | Get a label; update a label. |
| DELETE | `/labels/{id}/delete` | Admin (`manage_options`) | Delete a label. |
| GET | `/users/{user_id}/labels` | Public | Get a user's labels. |
| POST, DELETE | `/users/{user_id}/labels/{slug}` | Admin (`manage_options`) | Assign / unassign a label to a user. |

### Analytics

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/analytics/overview` | Admin | Site DAU/WAU/MAU + growth. |
| GET | `/analytics/content/top` | Admin | Top content by engagement. |
| GET | `/analytics/members/top` | Admin | Top members by activity. |
| GET | `/analytics/spaces/{space_id}/health` | Admin | Space health metrics. |
| GET | `/analytics/me/profile-views` | Logged in | Current user's own profile-view data. |
| GET | `/analytics/users/{user_id}/profile-views` | Admin | Any user's profile-view data. |

### Drip sequences

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/drip-sequences` | Admin | List drip sequences; create one. |
| GET, PUT, DELETE | `/drip-sequences/{id}` | Admin | Get, update, delete a drip sequence. |
| POST | `/drip-sequences/{id}/steps` | Admin | Add a step to a sequence. |
| POST | `/drip-sequences/{id}/enroll` | Admin | Enroll a user in a sequence. |

### Broadcasts

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/broadcasts` | Admin | List broadcast campaigns; create one. |
| GET, PUT | `/broadcasts/{id}` | Admin | Get and update a broadcast. |
| POST | `/broadcasts/{id}/dispatch` | Admin | Send a broadcast now. |

### Email preferences

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/me/email-preferences` | Logged in | Read and update the current user's email unsubscribe settings. |

### Moderation rules and AI

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/mod-rules` | Admin | List moderation rules; create one. |
| GET, PUT, DELETE | `/mod-rules/{id}` | Admin | Get, update, delete a rule. |
| POST | `/mod-rules/{id}/toggle` | Admin | Toggle a rule's enabled state. |
| POST | `/moderation/bulk` | Admin | Run a bulk moderation action. |
| POST | `/ai/classify` | Admin | Classify content (moderation signal). |
| POST | `/ai/reply-suggestions` | Commenter | AI smart-reply suggestions for a thread. |

### Scheduled posts

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/posts/{id}/schedule` | Post owner | Schedule a post. |
| GET | `/me/scheduled-posts` | Logged in | Current user's scheduled posts. |
| GET | `/posts/scheduled` | Admin | All scheduled posts across the site. |

### Push notifications

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/me/push-tokens` | Logged in | List and register the current user's device tokens. |
| DELETE | `/me/push-tokens/{id}` | Logged in | Delete a push token. |
| POST | `/me/push-tokens/test` | Admin | Send a test push notification. |
| GET, PUT | `/me/push-prefs` | Logged in | Read and update push notification preferences. |

### Saved searches

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/me/saved-searches` | Logged in | List and create saved searches. |
| GET, PUT | `/me/saved-searches/{id}` | Logged in | Get and update a saved search. |
| DELETE | `/me/saved-searches/{id}/delete` | Logged in | Delete a saved search. |
| POST | `/me/saved-searches/{id}/run` | Logged in | Execute a saved search. |

### White-label and brand

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/admin/whitelabel/preview` | Admin | Render a white-label preview. |
| GET, POST | `/spaces/{id}/brand` | Space brand manager | Get and save a space's brand settings. |

### Realtime and Stripe

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/realtime/auth` | Logged in (+ per-channel check) | Mint a realtime channel auth signature. See open-but-signed above. |
| POST | `/realtime/test-connection` | Admin | Test the realtime connection. |
| POST | `/stripe/webhook` | Public (signature-verified) | Handle Stripe events. See open-but-signed above. |

## Example: create a checkout session

```bash
curl -X POST https://example.com/wp-json/buddynext-pro/v1/me/checkout \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: <wp_rest nonce>' \
  --cookie 'wordpress_logged_in_...=...' \
  -d '{ "tier": "pro-monthly" }'
```

The handler (`Membership/CheckoutController::handle_checkout`) returns a Stripe checkout-session URL for the current user to redirect to. After payment, Stripe calls back into `POST /stripe/webhook`, which verifies the signature and updates the user's subscription. The user can later open the billing portal with `POST /me/portal`.

## Notes

- **Mixed-permission routes.** `/tiers`, `/tiers/{id}`, and `/labels` register more than one method with different gates - the GET read is public or member-facing, the write (POST/PUT/DELETE) is admin. Treat the "Auth" column as per-method.
- **Pro requires Free.** These routes only register when Pro is active, and they read Free data (spaces, posts, follows, analytics tables) through Free services. The namespaces stay separate: Free is `buddynext/v1`, Pro is `buddynext-pro/v1`.
- **Webhook secret is a setup precondition.** `/stripe/webhook` returns `stripe_webhook_secret_missing` until the Stripe webhook secret is configured in the membership settings - that is required setup, not a fault.
