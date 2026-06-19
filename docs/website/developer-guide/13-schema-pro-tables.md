# Schema: Pro Tables

This page documents every table created by `BuddyNextPro\Core\Installer`, grouped by domain, with a column reference for each. It is for developers querying or extending Pro data. The same Scale-Contract rules that shape Free tables apply here - denormalized aggregates, an index for every WHERE column, and a `(created_at, id)` cursor key for paginated lists. See Data Model Overview for the naming convention and the master table index, and Schema: Core Tables for the Free tables.

## Contract: ownership and the Free/Pro boundary

Pro tables use the same `bn_` prefix as Free (there is no `bnpro_` prefix). Pro's Installer creates only Pro-owned tables and never re-creates a Free table. Two boundary rules govern this surface:

1. **Pro never creates or modifies Free tables.** When a Pro feature needs an extra column, it is added to a Pro-owned table, or - for additive columns on Pro tables - through `Installer::maybe_alter_tables()` behind an `INFORMATION_SCHEMA` guard so re-runs are no-ops. The membership tier columns (`price`, `currency`, `billing_type`, `billing_interval`, `trial_days`, `is_free`, `status`, `entitlements`) are back-filled this way onto `bn_membership_tiers`.
2. **`bn_mod_appeals` does not exist.** The Pro moderation spec named an appeals table `bn_mod_appeals`, but Pro reuses Free's `bn_appeals` for the appeal workflow. The two names are the same logical table; Pro does not duplicate it.

> **Note:** The Pro manifest's `tables` array re-lists five Free tables (see Shared Free tables below). Those are read or extended by Pro but created and owned by Free. The authoritative list of what Pro *creates* is the `schema()` method in `BuddyNextPro\Core\Installer` - this page follows the Installer, which creates more tables than the manifest snapshot enumerates.

## Membership

The revenue layer. Tier definitions, per-user subscriptions, gateway price mapping, and the billing-support tables (invoices, coupons, tax rules).

### `bn_membership_tiers`

Tier definitions. The base table is created by `schema_core()`; the pricing/billing columns are added by `maybe_alter_tables()` on upgrade.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `slug` | VARCHAR(100) | Unique key `slug` |
| `name` | VARCHAR(255) | Display name |
| `description` | TEXT | Nullable |
| `sort_order` | INT | Default 0; indexed (`sort`) |
| `price` | DECIMAL(10,2) | Default 0 (added via ALTER) |
| `currency` | CHAR(3) | Default `USD` (added via ALTER) |
| `billing_type` | ENUM('recurring','one_time') | Default `recurring` (added via ALTER) |
| `billing_interval` | ENUM('month','year','once') | Default `month` (added via ALTER) |
| `trial_days` | INT | Default 0 (added via ALTER) |
| `is_free` | TINYINT(1) | Default 0 (added via ALTER) |
| `status` | ENUM('active','inactive','archived') | Default `inactive` (added/widened via ALTER) |
| `entitlements` | LONGTEXT | JSON entitlement payload, nullable (added via ALTER) |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE slug (slug)`, `KEY sort (sort_order)`.

### `bn_subscriptions`

Per-user subscription rows linked to a tier and a gateway. The unique `external_id` lets the gateway webhook upsert idempotently.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT(20) UNSIGNED | Subscriber |
| `tier_id` | BIGINT(20) UNSIGNED | References `bn_membership_tiers.id` |
| `status` | ENUM('active','expired','cancelled','past_due','trialing') | Default `active` |
| `source` | ENUM('woocommerce','stripe','manual') | Default `manual` |
| `started_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `expires_at` | DATETIME | Nullable; indexed (`expires`) |
| `external_id` | VARCHAR(255) | Gateway subscription ID, nullable; unique |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE external_id (external_id)`, `KEY user_status (user_id, status)`, `KEY tier_status (tier_id, status)`, `KEY expires (expires_at)`.

### `bn_plan_gateway_map`

One row per `(plan, gateway, mode)` triple. Caches the provider's price ID so repeat checkouts skip the lazy-provision round-trip.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `plan_id` | BIGINT UNSIGNED | References a tier |
| `gateway` | VARCHAR(40) | Gateway slug (e.g. `stripe`) |
| `mode` | VARCHAR(20) | `live` or `test` |
| `provider_price_id` | VARCHAR(191) | Gateway price ID, nullable |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE plan_gateway_mode (plan_id, gateway, mode)`.

### `bn_invoices`

Purchase receipts. The `meta` JSON blob carries line items and billing address for future PDF generation.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT UNSIGNED | Indexed (`user_id`) |
| `plan_id` | BIGINT UNSIGNED | Indexed (`plan_id`) |
| `gateway` | VARCHAR(40) | Gateway slug |
| `amount` | DECIMAL(10,2) | Default 0 |
| `tax` | DECIMAL(10,2) | Default 0 |
| `discount` | DECIMAL(10,2) | Default 0 |
| `total` | DECIMAL(10,2) | Default 0 |
| `currency` | CHAR(3) | Default `USD` |
| `status` | VARCHAR(20) | Default `paid` |
| `number` | VARCHAR(50) | Human invoice number, nullable |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `meta` | LONGTEXT | JSON line items + address, nullable |

Keys: `PRIMARY (id)`, `KEY user_id (user_id)`, `KEY plan_id (plan_id)`.

### `bn_coupons`

Discount codes. `applies_to` is a JSON array of plan IDs (null means all plans).

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `code` | VARCHAR(60) | Unique |
| `type` | ENUM('percent','fixed') | Default `percent` |
| `value` | DECIMAL(10,2) | Default 0 |
| `applies_to` | LONGTEXT | JSON plan-ID array, nullable |
| `max_redemptions` | INT | Default 0 (0 = unlimited) |
| `redeemed` | INT | Default 0 |
| `expires_at` | DATETIME | Nullable |
| `active` | TINYINT(1) | Default 1 |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE code (code)`.

### `bn_tax_rules`

Local tax-rule fallback used when the active gateway provides no native tax calculation.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `region` | VARCHAR(100) | Region identifier |
| `rate` | DECIMAL(6,3) | Default 0 |
| `inclusive` | TINYINT(1) | Default 0 |
| `label` | VARCHAR(100) | Display label, nullable |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`.

## AI

Behavioral signals for ranked feed and cached embeddings for semantic search.

### `bn_ai_signals`

One row per engagement event, written by `SignalsCollector`. The `signal_type` ENUM was widened from the original set to include `view` and `dwell`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT(20) UNSIGNED | The actor who generated the signal |
| `signal_type` | ENUM('view','react','comment','follow','bookmark','dwell') | Per-type weight applied at write |
| `object_type` | VARCHAR(32) | Target object type |
| `object_id` | BIGINT(20) UNSIGNED | Target object ID |
| `weight` | FLOAT | Default 1.0 |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `KEY user_signal_date (user_id, signal_type, created_at)`, `KEY object (object_type, object_id)`, `KEY created (created_at)`.

### `bn_ai_embeddings`

Cached vector embedding per object for semantic search. The unique `(object_type, object_id)` key makes indexing an upsert; `text_hash` detects content changes so unchanged objects are not re-embedded.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `object_type` | VARCHAR(32) | Object type (e.g. `post`) |
| `object_id` | BIGINT(20) UNSIGNED | Object ID |
| `embedding` | LONGTEXT | Serialized vector |
| `text_hash` | CHAR(64) | Hash of the embedded text |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE uniq_object (object_type, object_id)`.

## Push

Mobile push device registry.

### `bn_push_tokens`

FCM / APNs device tokens per user. Registration is an UPSERT on the unique `token` column (updates `last_seen_at` and platform on conflict); the push client deletes tokens that return 404/410.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT(20) UNSIGNED | Owner; indexed (`idx_user`) |
| `platform` | VARCHAR(16) | Default `web` (`web` / `ios` / `android`) |
| `token` | VARCHAR(512) | Device token; unique on a 255-char prefix |
| `device_label` | VARCHAR(128) | Friendly device name, nullable |
| `last_seen_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE uniq_token (token(255))`, `KEY idx_user (user_id)`.

## Analytics

### `bn_analytics_events`

Raw analytics event stream written by `AnalyticsCollector` (which listens to 25+ Free actions). The query API (`AnalyticsService`) aggregates DAU/WAU/MAU, top content, top members, and space health from this table.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `event_type` | VARCHAR(64) | Event name |
| `actor_id` | BIGINT(20) UNSIGNED | Acting user, nullable |
| `target_type` | VARCHAR(32) | Target object type, nullable |
| `target_id` | BIGINT(20) UNSIGNED | Target object ID, nullable |
| `properties` | JSON | Arbitrary event payload, nullable |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `KEY event_date (event_type, created_at)`, `KEY actor_date (actor_id, created_at)`, `KEY target (target_type, target_id)`, `KEY created (created_at)`.

## Email

Broadcast campaigns and drip sequences, each split into a definition table and a per-recipient/per-enrollment table.

### `bn_email_campaigns`

Broadcast campaign definitions. `segment_filter` is JSON describing the audience segment.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `name` | VARCHAR(255) | Internal name |
| `subject` | VARCHAR(255) | Email subject |
| `body_html` | LONGTEXT | HTML body |
| `status` | ENUM('draft','scheduled','sending','sent') | Default `draft` |
| `segment_filter` | JSON | Audience segment, nullable |
| `scheduled_at` | DATETIME | Nullable |
| `sent_at` | DATETIME | Nullable |
| `created_by` | BIGINT(20) UNSIGNED | Author; indexed (`created_by`) |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `KEY status_date (status, scheduled_at)`, `KEY created_by (created_by)`.

### `bn_campaign_recipients`

Per-recipient delivery and engagement tracking. The unique `(campaign_id, user_id)` key prevents double-send.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `campaign_id` | BIGINT(20) UNSIGNED | References `bn_email_campaigns.id` |
| `user_id` | BIGINT(20) UNSIGNED | Recipient |
| `status` | ENUM('queued','sent','opened','clicked','bounced','unsubscribed') | Default `queued` |
| `sent_at` | DATETIME | Nullable |
| `opened_at` | DATETIME | Nullable |
| `clicked_at` | DATETIME | Nullable |

Keys: `PRIMARY (id)`, `UNIQUE campaign_user (campaign_id, user_id)`, `KEY campaign_status (campaign_id, status)`, `KEY user_campaigns (user_id)`.

### `bn_drip_sequences`

Drip sequence definitions. `steps` is a JSON array of step objects (`delay_days`, `subject`, `body_html`, etc.). `trigger` is a reserved word, quoted with backticks in the schema.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `name` | VARCHAR(255) | Sequence name |
| `trigger` | ENUM('user_register','onboarding_completed','manual') | Default `manual` |
| `enabled` | TINYINT(1) | Default 1; indexed (`enabled`) |
| `steps` | LONGTEXT | JSON step array, nullable |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `KEY enabled (enabled)`.

### `bn_drip_enrollments`

Per-user enrollment in a drip sequence, with the current step pointer. Unique `(sequence_id, user_id)` prevents double-enrollment.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `sequence_id` | BIGINT(20) UNSIGNED | References `bn_drip_sequences.id` |
| `user_id` | BIGINT(20) UNSIGNED | Enrolled user |
| `current_step` | SMALLINT UNSIGNED | Default 0 |
| `status` | ENUM('active','completed','unsubscribed') | Default `active` |
| `enrolled_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `last_step_at` | DATETIME | Nullable |
| `completed_at` | DATETIME | Nullable |

Keys: `PRIMARY (id)`, `UNIQUE sequence_user (sequence_id, user_id)`, `KEY sequence_status (sequence_id, status)`, `KEY user_enrollments (user_id)`.

## Moderation

### `bn_mod_rules`

Auto-moderation rule definitions. `config` is JSON whose shape depends on `rule_type`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `name` | VARCHAR(255) | Rule name |
| `rule_type` | ENUM('keyword_block','link_block','rate_limit','threshold_remove') | Rule kind |
| `config` | JSON | Rule parameters |
| `enabled` | TINYINT(1) | Default 1 |
| `priority` | INT | Default 0 |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |
| `updated_at` | DATETIME | `ON UPDATE CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `KEY enabled_priority (enabled, priority)`, `KEY rule_type (rule_type)`.

> **Note:** Auto-moderation appeals are not stored in a Pro table. Pro reuses Free's `bn_appeals` (see the boundary contract above). There is no `bn_mod_appeals`.

## Labels

Custom member labels and their assignments. Pro seeds three starter labels (Verified, Expert, Staff) on install.

### `bn_member_labels`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `slug` | VARCHAR(100) | Unique |
| `name` | VARCHAR(255) | Display name |
| `color` | VARCHAR(7) | Hex color; default `#0073aa` |
| `icon` | VARCHAR(100) | Icon slug, nullable |
| `sort_order` | INT | Default 0; indexed (`sort`) |
| `created_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE slug (slug)`, `KEY sort (sort_order)`.

### `bn_member_label_assignments`

Unique `(user_id, label_id)` prevents assigning the same label twice.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT(20) UNSIGNED | Labeled user |
| `label_id` | BIGINT(20) UNSIGNED | References `bn_member_labels.id` |
| `assigned_by` | BIGINT(20) UNSIGNED | Admin who assigned; indexed (`assigned_by`) |
| `assigned_at` | DATETIME | Default `CURRENT_TIMESTAMP` |

Keys: `PRIMARY (id)`, `UNIQUE user_label (user_id, label_id)`, `KEY label_users (label_id)`, `KEY assigned_by (assigned_by)`.

## Search

### `bn_saved_searches`

Per-user saved search definitions (capped at 50 per user in the service). `query_args` is a JSON blob mirroring the args accepted by `buddynext/v1/search`; it feeds the `buddynext_search_query_args` filter for automatic criteria merging.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `user_id` | BIGINT UNSIGNED | Owner |
| `name` | VARCHAR(120) | Saved-search name |
| `query_args` | LONGTEXT | JSON query args |
| `created_at` | DATETIME | Set on create |
| `last_run_at` | DATETIME | Nullable; updated on run |

Keys: `PRIMARY (id)`, `KEY user_id (user_id, created_at)`.

## Space

### `bn_space_meta`

Generic per-space key/value store. Used by the white-label per-space brand override (`meta_key = 'buddynextpro_space_brand'`, value is a JSON brand blob) and available for any other per-space metadata. Unique `(space_id, meta_key)` makes writes an upsert.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT(20) UNSIGNED | Primary key, auto-increment |
| `space_id` | BIGINT(20) UNSIGNED | References Free's `bn_spaces.id` |
| `meta_key` | VARCHAR(191) | Meta key; indexed (`meta_key`) |
| `meta_value` | LONGTEXT | Value, nullable |

Keys: `PRIMARY (id)`, `UNIQUE space_key (space_id, meta_key)`, `KEY meta_key (meta_key)`.

## Shared Free tables (re-listed by the Pro manifest)

The Pro `audit/manifest.json` `tables` array includes five tables that Pro does **not** create. They are created and owned by Free's Installer; Pro reads them, and in two cases extends behavior around them. Do not let the manifest re-listing suggest Pro ownership - their columns are documented under Schema: Core Tables, and queries against them go through Free services where one exists.

| Table | Owner / creator | What Pro does with it |
|-------|-----------------|------------------------|
| `bn_user_suspensions` | Free | Read to extend bulk and auto-suspend flows |
| `bn_appeals` | Free | Read and write for the appeal workflow (this is the table the spec called `bn_mod_appeals`) |
| `bn_space_bans` | Free | Read for moderation bulk actions |
| `bn_outbound_webhooks` | Free | Pro lifts Free's 1-endpoint cap via the `buddynext_outbound_webhook_limit` filter - it does not own the table |
| `bn_outbound_webhook_log` | Free | Read for outbound webhook delivery history |

> **Warning:** A Pro feature that needs data from one of these tables must read it through the Free service that owns it (for example suspensions and appeals via Free's moderation services) rather than issuing raw SQL, except where a documented Pro-only read is unavoidable. Pro must never `CREATE` or `ALTER` any of these tables.

## Notes and gotchas

- **Installer is the source of truth, not the manifest snapshot.** `BuddyNextPro\Core\Installer::schema()` creates 19 Pro-owned tables (the six membership tables, two AI tables, push tokens, analytics, four email tables, mod rules, two label tables, saved searches, and space meta). The manifest `tables` array is a hand-maintained snapshot and lags the Installer for `bn_plan_gateway_map`, `bn_invoices`, `bn_coupons`, `bn_tax_rules`, `bn_space_meta`, `bn_push_tokens`, and `bn_ai_embeddings`.
- **Additive columns come through `maybe_alter_tables()`.** `dbDelta` cannot add columns to an existing table reliably, so column back-fills (the membership pricing columns, the `signal_type` ENUM widening) run as `INFORMATION_SCHEMA`-guarded `ALTER` statements that are idempotent on re-run. The back-fill is gated by a `buddynextpro_schema_alters` version marker so the schema is not probed on every request once converged.
- **Pro boots after Free.** Pro initializes at `plugins_loaded:20` (Free at 15), and Pro's `Installer::maybe_upgrade()` runs from `Plugin::init()` so existing installs pick up new tables and columns on the next boot without a re-activation.
