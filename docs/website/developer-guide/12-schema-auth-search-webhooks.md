# Schema: Auth Tokens, Search Index, and Webhooks

This page documents the `bn_*` tables for verification/security tokens, the unified full-text search index, and the inbound/outbound webhook surface. All tables are created in `BuddyNext\Core\Installer::schema()` via `dbDelta()`. The exact tables that exist in these domains are listed below - none are invented.

## Overview / Contract

Tables documented here, exactly as they exist in the installer:

| Domain | Table | Purpose |
|---|---|---|
| Auth / security tokens | `bn_verify_tokens` | Single token store for email verification, email-change confirmation, and 2FA - discriminated by the `type` column |
| Search | `bn_search_index` | Unified searchable mirror of posts/profiles/spaces with a FULLTEXT index |
| Webhooks (inbound log) | `bn_webhook_log` | Log of inbound webhook/integration events received |
| Webhooks (outbound config) | `bn_outbound_webhooks` | Registered outbound endpoints the site POSTs events to |
| Webhooks (outbound delivery log) | `bn_outbound_webhook_log` | Per-attempt delivery log for outbound webhooks |

> **Note on auth/2FA:** There is exactly one token table, `bn_verify_tokens`. Email verification, email-change confirmation, and two-factor tokens all share it and are distinguished by the `type` column (`type` defaults to `email_verify`). There is no separate `bn_2fa_tokens` / `bn_two_factor` / `bn_auth_codes` table in the installer.

Scale-Contract rules that shaped these tables:

- **FULLTEXT for search, with a transaction-safe fallback.** `bn_search_index` carries a `FULLTEXT KEY ft_search (title, content)` added after `dbDelta()` (dbDelta does not manage FULLTEXT reliably). When the index is absent (notably under the PHPUnit harness, where it is actively dropped), `SearchService` detects this via `has_fulltext_index()` and runs a `LIKE` fallback.
- **Single-row token lookup by unique token.** `bn_verify_tokens.token` is unique so verification is one indexed read.
- **Delivery log split from config.** Outbound webhook endpoints (`bn_outbound_webhooks`) are configuration; each send attempt is appended to `bn_outbound_webhook_log` so retries and failures are auditable without bloating the config row.

## bn_verify_tokens

One-time security tokens. The `type` column lets a single table serve email verification, email-change confirmation, and two-factor flows. Tokens are single-use and time-boxed via `expires_at`.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT(20) UNSIGNED NOT NULL | The member the token belongs to. References `wp_users.ID`. |
| `token` | VARCHAR(64) NOT NULL | The opaque token value sent to the user. Unique. |
| `type` | VARCHAR(32) NOT NULL DEFAULT 'email_verify' | Token purpose, for example `email_verify`, `email_change_confirm`, or a 2FA type. Defaults to `email_verify`. |
| `expires_at` | DATETIME NOT NULL | Hard expiry; expired tokens are rejected and pruned. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the token was issued. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `token (token)` - the verification lookup is a single indexed read on the token value.
- KEY `user_type (user_id, type)` - find/invalidate a user's existing tokens of a given type before issuing a new one.

Relationships: `user_id` -> `wp_users.ID`. The matching email templates (`email_verify`, `email_change_confirm`) live in `bn_email_templates` and reference `{{verify_url}}`, which embeds the token.

## bn_search_index

A denormalized, searchable mirror of indexable content (posts, profiles, spaces, and other object types). Keeping search in its own table lets one query span every content type and lets the FULLTEXT index live where it is needed without touching the source tables. Reindexing is scheduled on activation via `SearchService::schedule_reindex_all()`.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `object_type` | VARCHAR(32) NOT NULL | The kind of indexed object (for example `post`, `profile`, `space`). |
| `object_id` | BIGINT(20) UNSIGNED NOT NULL | Source row id within that object type. |
| `title` | VARCHAR(500) NOT NULL DEFAULT '' | Indexed title text (FULLTEXT). |
| `content` | LONGTEXT DEFAULT NULL | Indexed body text (FULLTEXT). |
| `author_id` | BIGINT(20) UNSIGNED DEFAULT NULL | Author/owner of the object, for filtering. |
| `space_id` | BIGINT(20) UNSIGNED DEFAULT NULL | Owning space, when the object belongs to one. |
| `visibility` | ENUM('public','private') NOT NULL DEFAULT 'public' | Visibility gate applied to search results. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the index row was created. |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Last reindex time; used for ordering. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `object (object_type, object_id)` - one index row per source object; the upsert key for reindexing.
- KEY `visibility_type (visibility, object_type)` - filter results by visibility and type.
- KEY `author (author_id)` - "content by this member".
- KEY `space (space_id)` - "content in this space".
- KEY `updated_order (updated_at)` - recency ordering.
- FULLTEXT KEY `ft_search (title, content)` - added by a direct `ALTER TABLE` after `dbDelta()`. Under the test harness this index is dropped so MATCH/AGAINST is never run against uncommitted fixture rows.

Relationships: `object_id` references the source table named by `object_type`; `author_id` -> `wp_users.ID`; `space_id` -> `bn_spaces.id`.

```sql
-- The FULLTEXT search query (when the index is present)
SELECT object_type, object_id, title
FROM wp_bn_search_index
WHERE MATCH (title, content) AGAINST (%s IN NATURAL LANGUAGE MODE)
  AND visibility = 'public'
ORDER BY updated_at DESC;
```

> **Tip:** Always gate a search through `SearchService::has_fulltext_index()`. On engines or environments without the FULLTEXT index, MATCH/AGAINST errors; the service routes to a `LIKE` query instead so search still works.

## bn_webhook_log

Log of inbound webhook / integration events the site received. This is the receive-side audit trail - distinct from the outbound tables below. Uses signed `BIGINT(20)` ids (not unsigned) and lightweight `VARCHAR` status, consistent with its role as an append-only log.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) NOT NULL AUTO_INCREMENT | Primary key. |
| `source` | VARCHAR(100) NOT NULL DEFAULT '' | Originating integration/source name. |
| `action` | VARCHAR(100) NOT NULL DEFAULT '' | The event/action received. |
| `user_id` | BIGINT(20) NOT NULL DEFAULT 0 | Related member, when applicable; `0` if none. |
| `payload` | LONGTEXT NOT NULL | Raw received payload. |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'success' | Processing outcome, default `success`. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the event was logged. |

Indexes:

- PRIMARY KEY `(id)`.
- KEY `action (action)` - filter by event type.
- KEY `user_id (user_id)` - events related to a member.
- KEY `created_at (created_at)` - time-ordered scans and pruning.

Relationships: `user_id` references `wp_users.ID` when non-zero.

## bn_outbound_webhooks

Registered outbound endpoints. Each row is one destination URL the site POSTs selected events to, with an optional signing secret and a JSON list of subscribed events.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `label` | VARCHAR(100) NOT NULL | Human-readable name for the endpoint. |
| `url` | VARCHAR(2083) NOT NULL | Destination URL the payload is POSTed to. |
| `secret` | VARCHAR(64) DEFAULT NULL | Optional shared secret used to sign deliveries. |
| `events` | JSON DEFAULT NULL | List of event names this endpoint subscribes to. |
| `is_active` | TINYINT(1) NOT NULL DEFAULT 1 | Whether the endpoint receives deliveries. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the endpoint was registered. |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Last edit time. |

Indexes:

- PRIMARY KEY `(id)`. No secondary indexes - the table is small (a handful of configured endpoints).

Relationships: referenced by `bn_outbound_webhook_log.webhook_id`. Delivery and retry are handled reactively by `OutboundWebhookService` (single-event scheduling, not a recurring cron).

## bn_outbound_webhook_log

Per-attempt delivery log for outbound webhooks. One row per send attempt records the target endpoint, the event, the payload, and the HTTP response, so retries and failures are fully auditable.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `webhook_id` | BIGINT(20) UNSIGNED NOT NULL | The endpoint this attempt targeted. References `bn_outbound_webhooks.id`. |
| `event` | VARCHAR(64) NOT NULL | The event delivered. |
| `payload` | JSON DEFAULT NULL | The payload that was sent. |
| `response_code` | SMALLINT UNSIGNED DEFAULT NULL | HTTP status returned by the endpoint. |
| `response_body` | TEXT DEFAULT NULL | Response body (truncated as needed). |
| `status` | ENUM('success','error') NOT NULL DEFAULT 'success' | Delivery outcome. |
| `attempt` | TINYINT UNSIGNED NOT NULL DEFAULT 1 | Retry attempt number for this delivery. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the attempt was made. |

Indexes:

- PRIMARY KEY `(id)`.
- KEY `webhook_event (webhook_id, event, created_at)` - delivery history for an endpoint/event, time-ordered.
- KEY `status_date (status, created_at)` - find recent failures to retry.

Relationships: `webhook_id` -> `bn_outbound_webhooks.id`.

## Notes / gotchas

- **One token table, three purposes.** Do not look for a separate 2FA table - `bn_verify_tokens` serves email verification, email-change confirmation, and 2FA, keyed by `type`. Always include `type` in lookups so flows do not cross-consume each other's tokens.
- **FULLTEXT is created outside dbDelta.** The `ft_search` index is added by a guarded `ALTER TABLE` after table creation and is deliberately dropped in the test environment. Any code that searches must check `has_fulltext_index()` and fall back to `LIKE`.
- **Inbound vs outbound logs are different tables.** `bn_webhook_log` records events received; `bn_outbound_webhook_log` records events sent. They are not interchangeable, and `bn_webhook_log` uses signed `BIGINT` ids while the outbound tables use unsigned.
- **Outbound config has no secondary indexes** because it stays small; the delivery log carries the indexes for history and retry scans.
- **No foreign keys.** Cross-table references (`webhook_id`, `object_id`, `user_id`) are by id only; integrity is enforced in the service layer.
