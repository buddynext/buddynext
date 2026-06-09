# Journey: Outbound Webhooks

**Opt-in feature**: `includes/Outbound/` (OutboundWebhookService, OutboundWebhookListener, OutboundWebhookController) — catalog slug `webhooks`, tier `opt_in`, group `integrations`
**Actions / filters consumed (delivery triggers)**: `user_register`, `buddynext_post_created`, `buddynext_post_deleted`, `buddynext_space_member_joined`, `buddynext_space_member_left`, `buddynext_connection_accepted`, `buddynext_user_followed`, `buddynext_reaction_added`, `buddynext_comment_created`, `buddynext_user_suspended`, `buddynext_user_unsuspended`, `buddynext_ability_granted`, `buddynext_ability_revoked`, `buddynext_user_verified`
**Filter fired**: `buddynext_outbound_webhook_limit` (max endpoints; Free default `1`)
**Cron action fired**: `buddynext_webhook_retry` (every `buddynext_5min`)
**DB tables touched**: `bn_outbound_webhooks`, `bn_outbound_webhook_log`
**Estimated time**: 12 min manual

## Site-owner expectation

A community owner enables outbound webhooks to **notify external systems when things happen in the community** — fire a Zapier/Make/n8n flow, sync a new member into a CRM, drop a message in Slack/Discord, or kick off a custom automation. They expect that once an endpoint is registered, every matching community event produces a signed HTTPS POST to their URL within seconds, and that failures are logged and retried rather than silently dropped.

What the owner configures, per endpoint:

- **Endpoint URL** — must be `https://` (plaintext `http://` is rejected).
- **Secret** — an HMAC signing secret used to verify the payload server-side. If omitted at registration, BuddyNext auto-generates a 40-char secret and returns it once in the create response (store it — it is the receiver's verification key).
- **Events** — an array of event slugs to subscribe to (e.g. `["member.registered","user.followed"]`). An **empty array means "all events"**.

The receiving endpoint verifies each delivery by recomputing `sha256=HMAC_SHA256(raw_body, secret)` and comparing it to the `X-BuddyNext-Signature` header.

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Enable the **Outbound webhooks** feature (opt-in, off by default). Set the toggle via WP-CLI:

  ```bash
  wp eval '$f = (array) get_option("buddynext_features", array()); $f["webhooks"] = true; update_option("buddynext_features", $f, false); echo buddynext_feature_registry()->is_enabled("webhooks") ? "enabled\n" : "disabled\n";'
  ```

  - Expected: prints `enabled`. (If `buddynext_feature_registry()` is unavailable in your build, set the option directly with `wp option patch` — the catalog entry lives in `FeatureRegistry::catalog()`.)
  - NOTE: see Known limitations — webhook wiring (service init, listener, REST routes) is currently active regardless of this toggle. Set it anyway to model the intended owner flow.
- A reachable HTTPS sink to receive deliveries. Use a request-bin style service and capture its URL as `SINK_URL`, e.g.:

  ```bash
  SINK_URL="https://webhook.site/<your-unique-id>"     # 2xx responder → status=success
  FAIL_URL="https://httpstat.us/500"                    # always 500    → status=error
  ```

- Admin user `admin` / `password` (webhook management requires `manage_options`)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Register a webhook endpoint

1. As `admin`, register an endpoint subscribed to all events (empty `events` array). Omit `secret` to have one auto-generated:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/webhooks \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d "{\"url\": \"${SINK_URL}\", \"events\": []}"
   ```

   - Expected: 201 response of shape `{ "id": <WEBHOOK_ID>, "secret": "<40-char-secret>" }`. Note both. The `secret` is returned only here — store it.

2. Verify the endpoint row:

   ```sql
   SELECT id, label, url, secret, events, is_active, created_at
   FROM wp_bn_outbound_webhooks
   WHERE id = WEBHOOK_ID;
   ```

   - Expected: 1 row, `is_active = 1`, `events = []` (empty JSON array = all events), `label` is `<host> — <admin display name>`, `secret` matches the returned value.

3. List endpoints over REST to confirm it is registered:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/webhooks -u admin:password
   ```

   - Expected: 200, JSON array containing your endpoint. `events` is decoded to a JSON array (not a string).

### Part 2: Send a test ping

4. Fire the built-in test ping:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/webhooks/WEBHOOK_ID/test \
     -u admin:password
   ```

   - Expected: 200 `{ "success": true, "message": "Test ping delivered successfully." }` when the sink returns 2xx. (502 if it does not — see edge cases.)

5. Confirm the ping was logged:

   ```sql
   SELECT id, webhook_id, event, response_code, status, created_at
   FROM wp_bn_outbound_webhook_log
   WHERE webhook_id = WEBHOOK_ID AND event = 'ping'
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row, `event = ping`, `status = success`, `response_code` a 2xx code. The envelope sent was `{ "event": "ping", "timestamp": <unix>, "data": { "message": "BuddyNext webhook test ping" } }`.

### Part 3: Trigger a delivery from a real community event

6. As `member1`, follow `member2` (use `member2`'s numeric user ID, `MEMBER2_ID`). This fires `buddynext_user_followed`, which the listener maps to the `user.followed` event:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/follow \
     -u member1:password
   ```

   - Expected: 200. The follow succeeds; the listener dispatches `user.followed` to every active endpoint whose `events` is empty or contains `user.followed`.

7. Confirm the delivery row landed in the log:

   ```sql
   SELECT id, webhook_id, event, response_code, status, created_at
   FROM wp_bn_outbound_webhook_log
   WHERE webhook_id = WEBHOOK_ID AND event = 'user.followed'
   ORDER BY id DESC LIMIT 1;
   ```

   - Expected: 1 row, `event = user.followed`, `status = success` (sink returned 2xx), `response_code` a 2xx code.

8. Inspect the delivery log over REST and confirm the payload envelope:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/webhooks/WEBHOOK_ID/log?per_page=10&page=1" \
     -u admin:password
   ```

   - Expected: 200, `{ "items": [...], "total": <n> }`. The newest item has `event = user.followed`. At the sink you received an HTTPS POST with:
     - Header `X-BuddyNext-Event: user.followed`
     - Header `X-BuddyNext-Signature: sha256=<hex>` (= `HMAC_SHA256(raw_body, secret)`)
     - Body `{ "event": "user.followed", "timestamp": <unix>, "data": { "follower_id": MEMBER1_ID, "following_id": MEMBER2_ID } }`

9. (Optional) Trigger a second event type to confirm fan-out across event slugs — as `member1`, create a post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Webhook journey post", "type": "status"}'
   ```

   - Expected: 201. A new `post.created` row appears in `wp_bn_outbound_webhook_log` with `data` = `{ "post_id": ..., "user_id": MEMBER1_ID, "type": "status" }`.

## Edge cases to also verify

- **Failed delivery is logged + retried**: Register a second endpoint pointing at a non-2xx sink, then trigger an event.

  ```bash
  curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/webhooks \
    -u admin:password -H "Content-Type: application/json" \
    -d "{\"url\": \"${FAIL_URL}\", \"events\": [\"user.followed\"]}"
  ```

  Note the new `FAIL_WEBHOOK_ID`. As `member2`, follow `member1` to fire `user.followed`. Expected: a log row for `FAIL_WEBHOOK_ID` with `status = error` and `response_code = 500`. The `buddynext_webhook_retry` cron (every 5 min) re-delivers any `status = error` rows from the past 24h against still-active endpoints, appending **new** log rows (it does not mutate the original). Force a retry pass without waiting:

  ```bash
  wp cron event run buddynext_webhook_retry
  ```

  - Expected: an additional `status = error` log row for the same `event = user.followed` after the retry runs.

- **Auto-deactivation after 3 consecutive failures**: Continue triggering `user.followed` (or run the retry) until `FAIL_WEBHOOK_ID` accumulates 3 consecutive `error` rows. Expected: `is_active` flips to `0` on that endpoint (`maybe_deactivate_on_failure()` checks the last 3 log rows). Confirm:

  ```sql
  SELECT id, is_active FROM wp_bn_outbound_webhooks WHERE id = FAIL_WEBHOOK_ID;
  ```

  - Expected: `is_active = 0`. No further deliveries dispatch to it, and the retry job skips it (`WHERE w.is_active = 1`).

- **Delete endpoint stops deliveries**: Delete the happy-path endpoint, then trigger another `user.followed`.

  ```bash
  curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/webhooks/WEBHOOK_ID -u admin:password
  ```

  - Expected: 200 `{ "deleted": true }`. The endpoint row **and all its log rows** are removed (log deleted first to avoid orphans). A subsequent follow produces no new log row for `WEBHOOK_ID`. Deleting a non-existent ID returns 404 `not_found`.

- **Plain http:// URL rejected**: `POST /webhooks` with `{"url": "http://example.com/hook"}`. Expected: 422 `invalid_url` — the URL must begin with `https://`.

- **Free webhook cap**: With one endpoint already registered, attempt a second (without a Pro filter lifting the cap). Expected: 422 `webhook_limit_reached` — `buddynext_outbound_webhook_limit` defaults to `1` in Free. (The failed-delivery edge case above assumes you registered the fail endpoint *before* hitting the cap, or raised the cap via the filter.)

- **Non-admin forbidden**: Call any `/webhooks` route as `member1`. Expected: 403 `rest_forbidden` — all routes require `manage_options`.

## What this validates

- `OutboundWebhookService::register()` validates the `https://` scheme, enforces the `buddynext_outbound_webhook_limit` cap, derives the label, and inserts into `bn_outbound_webhooks` with `is_active = 1`.
- `OutboundWebhookController::create_webhook()` auto-generates a 40-char secret via `wp_generate_password()` when none is supplied and returns `{ id, secret }` with 201.
- `OutboundWebhookListener::register()` binds 14 domain hooks and routes each to `buddynext_service('webhooks')->dispatch($slug, $data)`.
- `OutboundWebhookService::dispatch()` fans out to every active endpoint where `events` is empty (all) or contains the slug.
- `OutboundWebhookService::deliver()` builds the `{ event, timestamp, data }` envelope, signs it with `X-BuddyNext-Signature: sha256=HMAC_SHA256(body, secret)`, sends `X-BuddyNext-Event`, POSTs via `wp_remote_post` (5s timeout), and logs every attempt to `bn_outbound_webhook_log` with `status` `success`/`error`.
- `OutboundWebhookService::send_test_ping()` delivers a `ping` event and returns true only on a 2xx response (controller returns 200/502 accordingly).
- `OutboundWebhookService::maybe_deactivate_on_failure()` deactivates an endpoint after 3 consecutive failed log rows.
- `OutboundWebhookService::retry_failed()` (cron `buddynext_webhook_retry`) re-delivers `status = error` rows from the past 24h against active endpoints, reconstructing the event slug + data from the stored envelope.
- `OutboundWebhookService::delete()` removes log rows then the endpoint row, and halts future deliveries.

## Verification queries

```sql
-- All registered endpoints:
SELECT id, label, url, events, is_active, created_at
FROM wp_bn_outbound_webhooks
ORDER BY id ASC;

-- Delivery log for one endpoint, newest first:
SELECT id, webhook_id, event, response_code, status, attempt, created_at
FROM wp_bn_outbound_webhook_log
WHERE webhook_id = WEBHOOK_ID
ORDER BY id DESC;

-- Success vs error breakdown per endpoint:
SELECT webhook_id, status, COUNT(*) AS n
FROM wp_bn_outbound_webhook_log
GROUP BY webhook_id, status;

-- Failed deliveries eligible for retry (past 24h, active endpoint):
SELECT l.id, l.webhook_id, l.event, l.created_at
FROM wp_bn_outbound_webhook_log l
JOIN wp_bn_outbound_webhooks w ON w.id = l.webhook_id
WHERE l.status = 'error'
  AND l.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
  AND w.is_active = 1
ORDER BY l.id ASC;

-- Confirm the retry cron is scheduled:
-- wp cron event list | grep buddynext_webhook_retry
```

## REST surface walked

```
GET    /buddynext/v1/webhooks                 -- 200, array of registered endpoints (events decoded)         [manage_options]
POST   /buddynext/v1/webhooks                 -- 201, { id, secret }                                          [manage_options]
                                                 args: url (required, https), secret (optional, auto-gen),
                                                       events (optional array; [] = all events)
                                                 errors: 422 invalid_url, 422 webhook_limit_reached
DELETE /buddynext/v1/webhooks/{id}            -- 200, { "deleted": true } | 404 not_found                     [manage_options]
GET    /buddynext/v1/webhooks/{id}/log        -- 200, { items: [...], total: n }                              [manage_options]
                                                 args: per_page (1–100, default 20), page (default 1)
POST   /buddynext/v1/webhooks/{id}/test       -- 200 { success:true } | 502 { success:false }                 [manage_options]
```

Delivery transport (outbound, not a BN route): `POST <endpoint url>` with headers
`Content-Type: application/json`, `X-BuddyNext-Event: <slug>`, `X-BuddyNext-Signature: sha256=<hmac>`,
body `{ "event": "<slug>", "timestamp": <unix>, "data": { ... } }`.

Event slugs emitted by the listener: `member.registered`, `member.verified`, `post.created`, `post.deleted`,
`space.joined`, `space.left`, `connection.accepted`, `user.followed`, `reaction.added`, `comment.created`,
`user.suspended`, `user.unsuspended`, `member.ability_granted`, `member.ability_revoked`, plus `ping` (test only).

## Cleanup

```sql
-- Remove all journey log rows first (FK-free but avoids orphans):
DELETE FROM wp_bn_outbound_webhook_log
WHERE webhook_id IN (SELECT id FROM wp_bn_outbound_webhooks);

-- Remove all registered endpoints:
DELETE FROM wp_bn_outbound_webhooks;

-- Remove the test follows created to trigger deliveries:
DELETE FROM wp_bn_follows
WHERE (follower_id, following_id) IN ((MEMBER1_ID, MEMBER2_ID), (MEMBER2_ID, MEMBER1_ID));
```

```bash
# Disable the feature toggle again to restore the default opt-in (off) state:
wp eval '$f = (array) get_option("buddynext_features", array()); $f["webhooks"] = false; update_option("buddynext_features", $f, false);'

# (Optional) clear the scheduled retry event if tearing the site down:
wp cron event delete buddynext_webhook_retry
```

## Known limitations

- **Feature toggle is not enforced at runtime.** `webhooks` is registered as an `opt_in` feature in `FeatureRegistry::catalog()` and rendered in the Settings UI, but `Plugin.php` wires the service (`$container->get('webhooks')->init()`), the listener, and the REST controller **unconditionally** — there is no `is_enabled('webhooks')` guard around the dispatch path or routes. In practice deliveries fire and endpoints can be managed even with the toggle off. Documented here pending a gate.
- **No per-delivery retry cap / backoff.** `retry_failed()` re-sends every `status = error` row from the past 24h on each 5-min run; a persistently failing endpoint is retried repeatedly until auto-deactivation (3 consecutive failures) trips, rather than via exponential backoff. The `attempt` column exists in `bn_outbound_webhook_log` but is always written as the default `1` — it is not incremented on retry.
- **Auto-deactivation is silent.** When an endpoint flips to `is_active = 0` after 3 failures, no notification is sent to the owner and there is no REST route to re-activate it (only delete + re-register, or a manual DB `UPDATE`).
- **Secret is shown once.** The auto-generated secret is returned only in the 201 create response; `GET /webhooks` does return the stored `secret` column, but there is no rotate-secret endpoint.
- **No signature timestamp tolerance / replay guard** is enforced BN-side; the `timestamp` is in the body for the receiver to validate, but BN does not reject or de-duplicate replays.
- **Free cap = 1 endpoint** via `buddynext_outbound_webhook_limit`; lifting it is a Pro concern (filter returns a higher integer).

## Automation notes

- All management calls are curl-automatable with admin basic auth; capture `WEBHOOK_ID` and `secret` from the 201 create response — do not hardcode.
- Use a scriptable HTTPS sink (webhook.site API, `ngrok` + a local listener, or `httpstat.us/<code>` for deterministic failure codes) so the harness can assert on received headers/body and on `status` in the log.
- To assert signing end-to-end, recompute `sha256=HMAC_SHA256(raw_body, secret)` in the test and compare to the captured `X-BuddyNext-Signature` header.
- The retry + auto-deactivation lifecycle is testable without waiting on cron by invoking `wp cron event run buddynext_webhook_retry` between delivery triggers and re-querying `bn_outbound_webhook_log` / `is_active`.
- Trigger events deterministically through their owning REST routes (follow → `user.followed`, create post → `post.created`, join space → `space.joined`) rather than relying on background activity, so each delivery maps to one known log row.
