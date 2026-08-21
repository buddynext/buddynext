# REST: Pro namespace

The Pro plugin registers its own REST namespace, `buddynext-pro/v1`, with 63 registered routes (across the controllers under `includes/`). This page is the route reference for developers building on the Pro surfaces: membership and billing, analytics, drip and broadcast campaigns, member labels and plans, moderation rules, AI assistance, scheduled posts, push, saved searches, the member portfolio, Learnomy course links, and the realtime + payment-gateway (Stripe / PayPal) webhook endpoints.

![The Pro admin settings backed by the buddynext-pro/v1 REST routes documented here](../images/admin-settings.webp)

![The BuddyNext admin dashboard the Pro REST namespace populates and reads](../images/admin-overview.webp)

## Contract

`buddynext-pro/v1` follows the same envelope, error shape, pagination, and `wp_rest` nonce rules as Free - see the REST contract page (14-rest-contract) for the cross-surface conventions, and REST: Auth and Account (21-rest-auth-account) for the auth flows. Pro-specific points:

- **The namespace is `buddynext-pro/v1`**, not `buddynext/v1`. All paths below are prefixed with `/wp-json/buddynext-pro/v1`.
- **Most routes are admin- or owner-gated.** Campaign, moderation-rule, label-admin, analytics-overview, and AI-classify routes require an admin capability. Member-scoped routes (anything under `/me/...`, own subscriptions, saved searches, push) require login. A few are public reads.
- **The payment-webhook routes are open at the permission layer but signed at the payload layer:** `/stripe/webhook`, `/stripe/membership-webhook`, and `/paypal/membership-webhook` register `permission_callback => __return_true` and are authorised entirely by verifying the provider's signature on the payload. `/realtime/auth` is login-gated and additionally enforces per-channel access. See the highlight below - "open" does not mean "unauthenticated trust".

Source of truth: the controllers under `includes/` in the Pro plugin - grep `register_rest_route(` for the `buddynext-pro/v1` namespace.

## Open-but-signed routes (read this first)

| Method | Path | Permission gate | How it is actually authorised |
|---|---|---|---|
| POST | `/stripe/webhook` | `none` (public) | Verifies the `Stripe-Signature` header against the configured webhook secret via `\Stripe\Webhook::constructEvent()`; rejects with `stripe_invalid_signature` on mismatch and `stripe_webhook_secret_missing` when no secret is set. Handled in `Stripe/WebhookController`. |
| POST | `/stripe/membership-webhook` | `none` (public) | The membership gateway's own Stripe webhook receiver. Signature-verified inside the handler. Handled in `Payments/Gateways/Stripe/StripeGateway`. |
| POST | `/paypal/membership-webhook` | `none` (public) | The membership gateway's PayPal webhook receiver; verifies the event via PayPal's `verify-webhook-signature` API before processing. Handled in `Payments/Gateways/PayPal/PayPalGateway`. |
| POST | `/realtime/auth` | `require_logged_in` | Mints a Soketi/Pusher channel auth signature `key:hmac_sha256(socket_id:channel, secret)` - but only after confirming the current user may access the requested private channel. Handled in `Realtime/AuthController`. |

`/stripe/webhook` has no WordPress capability check because Stripe calls it server-to-server with no session; its trust comes entirely from the HMAC signature on the payload. `/realtime/auth` is login-gated and additionally enforces per-channel access before returning the signature, so a logged-in user cannot subscribe to a channel they are not entitled to.

## Routes by domain

### Membership: plans, checkout, subscriptions

Plans are the membership plans. Plan CRUD lives under `/tiers`; the buyer-facing checkout flow (plan list, gateway list, checkout, quote) lives under `/membership/*` in `Membership/CheckoutController`; subscriptions and the billing portal live in `Membership/Controllers/SubscriptionsController`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/tiers` | Public (GET) / Admin (POST) | List plans; create a plan. |
| GET, DELETE | `/tiers/{id}` | Public (GET) / Admin (DELETE) | Get a plan; delete a plan. |
| GET | `/membership/plans` | Public | List purchasable plans. |
| GET | `/membership/gateways` | Public | List enabled payment gateways. |
| POST | `/membership/checkout` | Logged in | Start a checkout for a plan (`plan_id`, optional `gateway`, `mode`, `coupon`, `country`). |
| POST | `/membership/quote` | Logged in | Return a price quote (subtotal, tax, discount, total) for a plan without charging. |
| POST | `/me/billing-portal` | Logged in | Create a billing-portal session for the current user. |
| GET | `/me/subscriptions` | Logged in | Current user's subscriptions. Each row carries a `capabilities` block - see below. |
| POST | `/me/subscriptions/{id}/cancel` | Logged in | Cancel one of the current user's subscriptions. |
| GET | `/users/{id}/subscriptions` | Admin | A user's subscription history. |

#### The `capabilities` block on `/me/subscriptions`

Every row carries `capabilities`, and **any client rendering a membership control should read it
rather than deriving the rules again**. That is not style advice: the same rules exist in the
cancel endpoint, the plan-change service and the gateway registry, and every time a surface has
re-derived them it has drifted - a Cancel button that 409'd on click, a Switch button that worked
for a member billed by WooCommerce.

The response is a bare array (typed `MySubscription[]` by the mobile app), so the block is a key on
each element rather than a sibling of the list; wrapping the list would break every installed copy.

| Key | Type | Means |
|---|---|---|
| `can_buy` | bool | The **site** has something for sale. The one site-level answer in the block, so it is identical on every row. False on a free-only site or one whose gateway was never credentialed - render no Buy CTA at all rather than a link to an empty pricing page. |
| `can_change` | bool | This member may move to another plan. False for anything billed elsewhere. |
| `can_cancel` | bool | The cancel endpoint will accept. When false, `reason` says why. |
| `can_update_payment` | bool | `POST /me/billing-portal` will return somewhere to go - a minted provider portal, or the partner's own account page. **False for a comped member and for Offline**, both of which have an active subscription and no billing to manage. |
| `billed_by` | string | `gateway` (billed here), `external` (billed by a connected system), `manual` (comped), `none` (no subscription). |
| `source` | string | Raw source slug, e.g. `stripe`, `woocommerce`. For logic, prefer `billed_by`. |
| `source_label` | string | The system's own spelling, for display: `WooCommerce`, not `Woocommerce`. |
| `manage_url` | string | Where an externally-billed member manages their billing. **May be empty** - a source with nowhere to send them. Show the explanation without a link; a wrong link is worse than none. |
| `reason` | string | Member-facing, already translated. Non-empty whenever a control is missing. |

Two rules that make the difference between a correct client and a plausible one:

- **If a control is hidden, show `reason`.** Silence reads as a broken screen. The string is the
  same sentence the endpoint would return if the member forced the request, so the explanation and
  the refusal cannot describe different rules.
- **Never infer one key from another.** `can_update_payment` is the clearest case: Offline is
  `billed_by: gateway` and still has no portal, so `billed_by === 'gateway'` is not a substitute.

### Member plans vs labels

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/labels` | Mixed (GET public / POST admin) | List member labels; create a label. |
| GET, PUT, DELETE | `/labels/{id}` | Mixed (GET public / PUT, DELETE admin `manage_options`) | Get, update, or delete a label. |
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
| GET | `/analytics/cohorts` | Admin | Retention cohort data. |
| GET | `/analytics/funnel` | Admin | Conversion-funnel data. |

### Drip sequences

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/drip-sequences` | Admin | List drip sequences; create one. |
| GET, PUT, DELETE | `/drip-sequences/{id}` | Admin | Get, update, delete a drip sequence. |
| POST | `/drip-sequences/{id}/steps` | Admin | Add a step to a sequence. |
| PUT | `/drip-sequences/{id}/steps/{index}` | Admin | Update a step at a given index. |
| POST | `/drip-sequences/{id}/enroll` | Admin | Enroll a user in a sequence. |

### Broadcasts

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET, POST | `/broadcasts` | Admin | List broadcast campaigns; create one. |
| GET, PUT, DELETE | `/broadcasts/{id}` | Admin | Get, update, or delete a broadcast. |
| POST | `/broadcasts/{id}/dispatch` | Admin | Send a broadcast now. |
| GET | `/broadcasts/{id}/stats` | Admin | Delivery / engagement stats for a broadcast. |
| GET | `/broadcasts/{id}/preview` | Admin | Render a preview of the broadcast. |
| POST | `/broadcasts/{id}/test-send` | Admin | Send a test copy of the broadcast. |

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
| GET | `/mod-rules/defaults` | Admin | List the built-in default rule definitions. |
| PUT | `/mod-rules/defaults/{id}` | Admin | Update a built-in default rule (id is a slug). |
| POST | `/moderation/bulk` | Admin | Run a bulk moderation action. |
| GET | `/moderation/bulk/{batch_id}` | Admin | Poll the status of a bulk moderation batch. |
| POST | `/ai/classify` | Admin | Classify content (moderation signal). |
| POST | `/ai/reply-suggestions` | Commenter | AI smart-reply suggestions for a thread. |

### Scheduled posts

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST, DELETE | `/posts/{id}/schedule` | Post owner | Schedule a post; DELETE unschedules it. |
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
| GET, PUT, DELETE | `/me/saved-searches/{id}` | Logged in | Get, update, or delete a saved search. |
| POST | `/me/saved-searches/{id}/run` | Logged in | Execute a saved search. |

### Member portfolio

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/members/{id}/portfolio` | Public | Read a member's aggregated portfolio (`Suite/Controllers/PortfolioController`). |

### Learnomy course links

Course-space linking for the Learnomy integration (`Integrations/Learnomy/LearnomyLinkController`). Registered only when Learnomy is active.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/learnomy-link/spaces` | Logged in | List spaces available to link a course to. |
| GET, POST | `/learnomy-link` | Logged in | List existing course-space links; create a link. |
| POST | `/learnomy-link/create` | Course creator | Create a linked course (requires create capability). |
| DELETE | `/learnomy-link/{space}` | Logged in | Remove the course link for a space. |

### Realtime and payment webhooks

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/realtime/auth` | Logged in (+ per-channel check) | Mint a realtime channel auth signature. See open-but-signed above. |
| POST | `/realtime/test-connection` | Admin | Test the realtime connection. |
| POST | `/stripe/webhook` | Public (signature-verified) | Handle Stripe events (`Stripe/WebhookController`). See open-but-signed above. |
| POST | `/stripe/membership-webhook` | Public (signature-verified) | Membership gateway Stripe webhook receiver (`Payments/Gateways/Stripe/StripeGateway`). |
| POST | `/paypal/membership-webhook` | Public (signature-verified) | Membership gateway PayPal webhook receiver (`Payments/Gateways/PayPal/PayPalGateway`). |

## Example: create a checkout session

```bash
curl -X POST https://example.com/wp-json/buddynext-pro/v1/membership/checkout \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: <wp_rest nonce>' \
  --cookie 'wordpress_logged_in_...=...' \
  -d '{ "plan_id": 12, "gateway": "stripe" }'
```

The handler (`Membership/CheckoutController::handle_checkout`) takes a required `plan_id` (plus optional `gateway`, `mode`, `coupon`, `country`) and returns a gateway checkout URL for the current user to redirect to. After payment, the gateway calls back into its membership webhook (`POST /stripe/membership-webhook` or `POST /paypal/membership-webhook`), which verifies the signature and updates the user's subscription. The user can later open the billing portal with `POST /me/billing-portal`.

## Notes

- **Mixed-permission routes.** `/tiers`, `/tiers/{id}`, and `/labels` register more than one method with different gates - the GET read is public or member-facing, the write (POST/PUT/DELETE) is admin. Treat the "Auth" column as per-method.
- **Pro requires Free.** These routes only register when Pro is active, and they read Free data (spaces, posts, follows, analytics tables) through Free services. The namespaces stay separate: Free is `buddynext/v1`, Pro is `buddynext-pro/v1`.
- **Webhook secret is a setup precondition.** `/stripe/webhook` returns `stripe_webhook_secret_missing` until the Stripe webhook secret is configured in the membership settings - that is required setup, not a fault.
