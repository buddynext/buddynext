# QA — REST API & Outbound Webhooks (free)

**Manifest refs:** tables: `bn_outbound_webhooks` · `bn_outbound_webhook_log` · `bn_webhook_log` (legacy alias) · REST routes: 154 live (23 path-segment groups) · services: OutboundWebhookService · controllers: 28 (27 always-on + OutboundWebhookController conditional on `features->is_enabled('webhooks')`) · capabilities: `manage_options` (webhook CRUD), `__return_true` (inbound `/webhook/access` with HMAC gate)
**Cross-ref (no dup):** JOURNEYS J-58 (feature toggle that enables this component) · FLOW-TEST-MATRIX O8 (outbound webhooks: add / test / view log) · P15 (unlimited webhooks, Pro)
**Admin location:** BuddyNext → Settings → Webhooks tab (`admin.php?page=buddynext&tab=webhooks`); REST surface: `http://buddynext.local/wp-json/buddynext/v1`

---

## 1. Backend settings & options (justify each)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_webhook_secret` | Settings → Webhooks | `''` | HMAC-SHA256 secret used to sign ALL outbound webhook deliveries (`X-BuddyNext-Signature: sha256=<hex>`) AND to verify inbound `/webhook/access` requests | Yes | (a) Empty secret causes `AccessWebhookController` to return 503 `webhook_not_configured` on every inbound call — no UI warning that the secret is unset. (b) No rotation mechanism: changing the secret invalidates all existing subscriber HMAC verification instantly, with no transition period. (c) Secret stored in plaintext in `wp_options`; any user with DB access can read it. |
| `buddynext_features` (key `webhooks`) | Settings → Features | `false` (opt_in tier) | Master toggle: when disabled, `OutboundWebhookController` is never registered (routes return 404) and `OutboundWebhookListener` is never loaded (no events dispatched) | Yes | Currently disabled on this install — all five `/webhooks` CRUD routes return 404 until `buddynext_features['webhooks'] = true` is persisted. No in-tab notice on the Webhooks settings tab that the feature must be enabled first. |
| `buddynext_outbound_webhook_limit` (filter, not option) | n/a — code filter | `1` (Free) | Max number of active outbound webhook endpoints; Pro overrides to `PHP_INT_MAX` via `buddynext_outbound_webhook_limit` filter | Yes | Free is hard-capped at 1 endpoint with no UI indicator; the error response from `OutboundWebhookService::register()` is a `WP_Error`, not yet surfaced as a user-facing admin notice. |

### Endpoint-level data (stored in `bn_outbound_webhooks`, not `wp_options`)

| Column | Source | What it controls | Gap |
|---|---|---|---|
| `url` | `POST /webhooks` — `sanitize_url()` | Target HTTPS URL; validated with `filter_var(FILTER_VALIDATE_URL)` and `https://` prefix check | HTTP URLs rejected at service layer, but error not surfaced with clear message in UI |
| `secret` | `POST /webhooks` — auto-generated via `wp_generate_password(40, false)` if omitted | Per-endpoint HMAC secret (overrides global for delivery signing) | Owner has no UI to view the per-endpoint secret after creation (create response returns it once only) |
| `events` | `POST /webhooks` — array of strings | Event slug filter; empty array = subscribe to all 14 events | No enum validation: invalid slugs stored silently and never matched |
| `is_active` | `OutboundWebhookService::maybe_deactivate_on_failure()` | Auto-set to `0` after 3 consecutive delivery errors | No admin notification when an endpoint is auto-deactivated; owner discovers it only by visiting the log |

---

## 2. Frontend functions / API surface

### REST API — endpoint groups (live on `buddynext/v1`, confirmed `curl http://buddynext.local/wp-json/buddynext/v1`)

154 routes registered across 23 path-segment groups. Manifest reports 135; live count is 154 (post-refactor additions including `account/2fa/*`, `pwa/*`, `me/social/*` groups not yet reflected in manifest).

| Group | Route prefix | Approx. count | Auth model |
|---|---|---|---|
| me | `/me/*` | 30 | Cookie / Application Password (logged-in) |
| users | `/users/*` | 26 | Mixed (public reads; write requires auth) |
| spaces | `/spaces/*` | 24 | Mixed |
| auth | `/auth/*` | 10 | Public (register/login); nonce on state-change |
| posts | `/posts/*` | 9 | Mixed |
| feed | `/feed/*` | 8 | Logged-in |
| reports | `/reports/*` | 6 | Logged-in + capability |
| account | `/account/2fa/*` | 5 | Logged-in |
| appeals | `/appeals/*` | 4 | Logged-in |
| hashtags | `/hashtags/*` | 4 | Mixed |
| reactions | `/reactions/*` | 3 | Logged-in |
| comments | `/comments/*` | 3 | Mixed |
| profile-fields | `/profile-fields/*` | 3 | Mixed |
| profile-groups | `/profile-groups/*` | 3 | Mixed |
| pwa | `/pwa/*` | 3 | Logged-in |
| member-types | `/member-types/*` | 2 | Mixed |
| search | `/search/*` | 2 | Mixed |
| space-categories | `/space-categories/*` | 2 | Mixed |
| admin | `/admin/*` | 1 | `manage_options` |
| follow-suggestions | `/follow-suggestions` | 1 | Logged-in |
| invites | `/invites/import-csv` | 1 | `manage_options` |
| members | `/members` | 1 | Mixed |
| webhook (inbound) | `/webhook/access` | 1 | Public + HMAC |
| webhooks (outbound CRUD) | `/webhooks/*` | 5 | `manage_options` — conditional on `webhooks` feature flag |

### Outbound webhook CRUD endpoints (conditional on `features->is_enabled('webhooks')`)

| Method | Route | What it does | Auth |
|---|---|---|---|
| `GET` | `/buddynext/v1/webhooks` | List all outbound endpoints (id, label, url, events, is_active, created_at) | `manage_options` |
| `POST` | `/buddynext/v1/webhooks` | Register a new endpoint; auto-generates 40-char secret if omitted; returns `{id, secret}` | `manage_options` |
| `DELETE` | `/buddynext/v1/webhooks/{id}` | Delete endpoint + all its log rows | `manage_options` |
| `GET` | `/buddynext/v1/webhooks/{id}/log` | Delivery log: `{items, total}`; `per_page` (max 100), `page` | `manage_options` |
| `POST` | `/buddynext/v1/webhooks/{id}/test` | Send a test ping (`event: ping`); returns 200 on 2xx, 502 on failure | `manage_options` |

### Inbound webhook (always registered, no feature flag)

| Method | Route | What it does | Auth |
|---|---|---|---|
| `POST` | `/buddynext/v1/webhook/access` | Receives `set_role`, `grant_ability`, `revoke_ability`, `add_credits`, `set_credits`, `deduct_credits` actions; gates on HMAC `X-BuddyNext-Signature`; 503 if `buddynext_webhook_secret` is empty | Public + HMAC |

### Outbound webhook event catalogue (14 events dispatched by `OutboundWebhookListener`)

| Event slug | Hook | Key payload fields |
|---|---|---|
| `member.registered` | `user_register` | `user_id, display_name, user_email, registered` |
| `post.created` | `buddynext_post_created` | `post_id, user_id, type` |
| `post.deleted` | `buddynext_post_deleted` | `post_id, user_id` |
| `space.joined` | `buddynext_space_member_joined` | `user_id, space_id, role` |
| `space.left` | `buddynext_space_member_left` | `user_id, space_id` |
| `connection.accepted` | `buddynext_connection_accepted` | `connection_id, requester_id, addressee_id` |
| `user.followed` | `buddynext_user_followed` | `follower_id, following_id` |
| `reaction.added` | `buddynext_reaction_added` | `object_type, object_id, user_id, emoji` |
| `comment.created` | `buddynext_comment_created` | `comment_id, object_type, object_id, user_id` |
| `user.suspended` | `buddynext_user_suspended` | `user_id, actor_id, reason, expires_at` |
| `user.unsuspended` | `buddynext_user_unsuspended` | `user_id` |
| `member.ability_granted` | `buddynext_ability_granted` | `user_id, ability` |
| `member.ability_revoked` | `buddynext_ability_revoked` | `user_id, ability` |
| `member.verified` | `buddynext_user_verified` | `user_id, display_name, user_email` |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-REST-001 | anonymous | api | Default install | `curl -s http://buddynext.local/wp-json/buddynext/v1` | 200 JSON; `routes` object present; `namespace` = `buddynext/v1`; total route count ≥ 150 | n/a |
| QA-REST-002 | anonymous | api | Default install | `curl -s http://buddynext.local/wp-json/buddynext/v1/feed/explore` | 401 or 403; non-admin endpoint still enforces auth for logged-out | n/a |
| QA-REST-003 | admin | api | Application password created | `curl -u admin:<app-pw> http://buddynext.local/wp-json/buddynext/v1/me/profile` | 200 with profile JSON; logged-in auth via Application Password works | n/a |
| QA-REST-004 | admin | api | `webhooks` feature disabled (default) | `curl -u admin:<app-pw> http://buddynext.local/wp-json/buddynext/v1/webhooks` | 404 `rest_no_route` — feature flag gates registration entirely | n/a |
| QA-REST-005 | admin | backend | `webhooks` feature disabled | Visit `admin.php?page=buddynext&tab=webhooks` | Webhooks tab renders; HMAC secret field present; **no notice** that the `webhooks` feature must first be enabled on the Features tab — gap | 1440px, 390px |
| QA-REST-006 | admin | backend | Enable `webhooks` feature via `wp option patch insert buddynext_features webhooks true --path=...` | Revisit `admin.php?page=buddynext&tab=webhooks`; try to add an endpoint via JS form | Form present; endpoint registered via REST; row appears in list; secret shown once in success state | 1440px |
| QA-REST-007 | admin | api | `webhooks` feature enabled | `POST /buddynext/v1/webhooks` with `{"url":"https://example.com/hook","events":["post.created"]}` (admin Application Password) | 201 `{id: <int>, secret: "<40-char>"}` | n/a |
| QA-REST-008 | admin | api | Endpoint created (QA-REST-007) | `GET /buddynext/v1/webhooks` | 200 array; endpoint row includes `url`, `events`, `is_active: 1`, `label` | n/a |
| QA-REST-009 | subscriber | api | `webhooks` feature enabled | `POST /buddynext/v1/webhooks` with subscriber Application Password | 403 `rest_forbidden`; `manage_options` required | n/a |
| QA-REST-010 | admin | api | Endpoint created (QA-REST-007) | `POST /buddynext/v1/webhooks/{id}/test` | 200 on success; target URL receives POST with `event: ping`; `bn_outbound_webhook_log` gains 1 row with `status: success` | n/a |
| QA-REST-011 | admin | api | Endpoint created | `POST /buddynext/v1/webhooks/{id}/test` where target URL returns 500 | Response is 502; `bn_outbound_webhook_log` gains row with `status: error` | n/a |
| QA-REST-012 | admin | api | Endpoint exists with 2 consecutive `error` log rows | Trigger a 3rd delivery failure (repeat failed test ping twice) | After 3rd failure `maybe_deactivate_on_failure()` sets `is_active = 0`; `GET /webhooks` returns `is_active: 0`; no further events dispatched to this endpoint | n/a |
| QA-REST-013 | admin | api | Endpoint created (QA-REST-007) | `GET /buddynext/v1/webhooks/{id}/log?per_page=5&page=1` | 200 `{items: [...], total: <int>}`; `items` length ≤ 5; `total` matches `SELECT COUNT(*)` from `bn_outbound_webhook_log WHERE webhook_id={id}` | n/a |
| QA-REST-014 | admin | api | Endpoint created | `DELETE /buddynext/v1/webhooks/{id}` | 200 `{deleted: true}`; `bn_outbound_webhooks` row gone; all `bn_outbound_webhook_log` rows for that `webhook_id` gone (no orphan rows) | n/a |
| QA-REST-015 | admin | api | Endpoint created; `POST /webhooks` already registered one (Free cap = 1) | `POST /buddynext/v1/webhooks` with a second URL | `WP_Error` response; register rejected; count stays at 1 | n/a |
| QA-REST-016 | admin | api | HMAC — endpoint created with known secret | Trigger a community event (create a post via REST); capture delivery at `https://example.com/hook` | Request carries header `X-BuddyNext-Signature: sha256=<hex>`; recomputing `hash_hmac('sha256', $body, $secret)` matches; `X-BuddyNext-Event` header = event slug | n/a |
| QA-REST-017 | admin | api | HMAC validation — inbound webhook | `POST /buddynext/v1/webhook/access` with `buddynext_webhook_secret` unset (empty string) | 503 `webhook_not_configured` — access webhook rejects all requests when secret is unconfigured | n/a |
| QA-REST-018 | admin | api | HMAC — inbound webhook, secret set | `POST /buddynext/v1/webhook/access` with wrong `X-BuddyNext-Signature` | 403 (signature mismatch via `hash_equals()`) | n/a |
| QA-REST-019 | admin | api | HMAC — inbound webhook, correct signature | `POST /buddynext/v1/webhook/access` with `{"action":"grant_ability","user_id":2,"ability":"bn_post_long"}` and correct HMAC | 200; `usermeta` row written for `user_id=2`; `buddynext_ability_granted` fires; `bn_webhook_log` gains 1 row with `status: success` | n/a |
| QA-REST-020 | admin | api | Inbound webhook | `POST /buddynext/v1/webhook/access` with `{"action":"set_role","user_id":2,"role":"moderator"}` + correct HMAC | 200; `bn_community_role` usermeta for user 2 = `moderator`; `buddynext_role_changed` fires | n/a |
| QA-REST-021 | admin | api | Cron retry — endpoint has `error` log rows ≤ 24h old, `is_active=1` | Manually trigger `do_action('buddynext_webhook_retry')` via `wp eval` | `retry_failed()` re-delivers each failed row; delivery result updates `bn_outbound_webhook_log`; no new rows created for already-success entries | n/a |
| QA-REST-022 | admin | api | State-changing REST route | `POST /buddynext/v1/posts` **without** `X-WP-Nonce` and without Application Password (cookie session only) | 403 nonce missing — nonce required on all state-changing calls made from a browser context | n/a |
| QA-REST-023 | admin | api | Any endpoint | Issue request with a valid nonce that has since expired (>12h old) | 403 `rest_cookie_invalid_nonce` | n/a |
| QA-REST-024 | anonymous | frontend | Webhooks tab in Settings | Navigate to `admin.php?page=buddynext&tab=webhooks?autologin=1` as admin; verify endpoint list renders | Endpoint list panel renders; JS loads; add/delete/test buttons present; **if feature disabled** the CRUD buttons should warn that the feature is not active (currently does not — gap) | 1440px, 390px |

---

## 4. Site-owner expectations & suggestions

- **No admin notice when the `webhooks` feature is disabled.** The Webhooks settings tab renders the HMAC secret field and endpoint list UI even when the `webhooks` feature flag is off (opt_in tier, off by default). Admins who configure webhooks there and then wonder why no events are delivered will have no indication they need to enable the feature first on the Features tab. Add an inline notice: "Outbound webhooks are currently disabled. Enable them under Settings → Features." Priority: high.

- **Per-endpoint secret shown only at creation.** `POST /webhooks` returns `{id, secret}` once. There is no `GET /webhooks/{id}/secret` endpoint or UI display. If an admin loses the secret they must delete and re-register the endpoint, breaking any downstream HMAC verification. Priority: high.

- **No admin notification on auto-deactivation.** `maybe_deactivate_on_failure()` silently sets `is_active = 0` after 3 consecutive delivery failures. The site owner has no way to know an endpoint has been deactivated without polling the delivery log. An admin email alert or an in-hub admin notice is required for any production use. Priority: high.

- **Free plan capped at 1 endpoint with no UI indicator.** The `buddynext_outbound_webhook_limit` filter defaults to 1 in Free. The UI does not mention this cap; the error surfaces only as a REST `WP_Error` that the current JS may or may not display. Show the cap (and a Pro upsell link) in the Settings → Webhooks panel. Priority: medium.

- **No event-slug validation on endpoint creation.** `POST /webhooks` accepts any array of strings in `events` without checking against the 14 canonical slugs. A typo like `post.create` (missing `d`) silently registers but never matches. Add server-side enum validation. Priority: medium.

- **Inbound webhook has no secret-rotation path.** The single `buddynext_webhook_secret` option signs both outbound deliveries and inbound access requests. Rotating it requires updating all subscriber endpoints simultaneously. A dual-secret grace window (old + new accepted for 24h) would prevent downtime. Priority: medium.

- **No retry-count cap in cron.** `retry_failed()` retries every `error` row from the last 24 hours on every 5-minute run. A persistently-down endpoint can generate unbounded retry attempts. Add a `retry_count` column and a cap (e.g., 5 total attempts before a row is skipped). Priority: medium.

- **Manifest count (135) is behind live count (154).** The manifest.json REST endpoint count is 135 but `curl /wp-json/buddynext/v1` returns 154 routes. Manifest should be refreshed to match live. Priority: low (docs hygiene).
