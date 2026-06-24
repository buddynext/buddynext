# Scale Readiness — 100k Users (Audit + Roadmap)

Rationale and source-of-truth audit behind three standards:
[`CACHING.md`](../standards/CACHING.md), [`DATA-AT-SCALE.md`](../standards/DATA-AT-SCALE.md),
[`REST-API-BOUNDARY.md`](../standards/REST-API-BOUNDARY.md). Background work is covered by
the existing [`BACKGROUND-JOBS.md`](../standards/BACKGROUND-JOBS.md).

Audit run: 1.0.3 branch, Free + Pro pair, five parallel read-only audits (REST wiring,
object cache, background work, realtime delivery, raw scale).

## Scorecard

| Dimension | Verdict | Standard |
|---|---|---|
| REST wiring (frontend → API) | **100%, CI-enforced** | REST-API-BOUNDARY |
| Background work (cron / AS / heartbeat) | **Clean & uniform** | BACKGROUND-JOBS |
| Notification delivery (logged-in) | **Won't miss updates** (needs Redis) | CACHING |
| Object cache uniformity | **Works but not uniform** | CACHING |
| Raw scale (writes / usermeta / autoload) | **3 real hotspots** | DATA-AT-SCALE |
| Native-app auth | **No token/JWT path** (gap) | REST-API-BOUNDARY |

## What's already right (protect it)

- **REST:** zero frontend `admin-ajax` in app code, one shared `rest-client.js`
  transport, uniform `X-WP-Nonce` + auto stale-nonce recovery, enforced by
  `bin/check-rest-boundary.sh`.
- **Background:** WordPress Heartbeat API not used at all; AS-first with WP-Cron
  fallback; drain-gated/self-disarming recurring jobs; distinct Free/Pro AS groups; no
  per-request recount/cleanup work.
- **Schema:** no missing indexes on the core social/feed/notification tables — composite
  indexes match the actual query columns.
- **Notifications:** bell polls site-wide (30s cold / 5s hot / paused on hidden tab);
  unread count object-cached per-user (30s TTL) with event-based invalidation; Pro adds
  Soketi WebSocket + FCM web/native push. A logged-in user does not miss updates.

## The 3 worst scale risks (ranked)

1. **Per-impression synchronous analytics INSERT (Pro).** Verified: `buddynext_post_impression`
   fires per item in 4 feed loops (`FeedService.php:345,861,982,1088`) →
   `AnalyticsCollector::on_post_impression()` (`:90`) → `record()` → `$wpdb->insert`
   (`AnalyticsCollector.php:489`). No batching, sampling, or retention. → Offload to
   **Action Scheduler** (one batched async job/request), sample, add retention cron. Fix per
   [`DATA-AT-SCALE.md` Rule 4](../standards/DATA-AT-SCALE.md).
   *Blast radius:* low — the hook signature stays; only the listener changes. *Also check:*
   confirm `bn_analytics_events` has no existing prune before adding one (avoid a dup cron).
2. **Non-sargable presence scans.** Verified: `MemberDirectoryService.php:274` (filter),
   `:331` (sort), `:584/:622/:636` (online-id + widget) all `CAST(meta_value AS UNSIGNED)`
   over `wp_usermeta` `bn_last_active`. → `bn_presence(user_id PK, last_active INT,
   INDEX(last_active))` or object-cached online-id set. [Rule 2](../standards/DATA-AT-SCALE.md).
   *Blast radius:* medium — new table in `Installer` + migration, dual-write from
   `PresenceService` first, then switch reads; producer (`bn_last_active` meta) can stay
   during transition.
3. **Autoloaded per-space options.** Verified: `SpaceController.php:384`
   `update_option('bn_space_<id>_<opt>', …)` with no autoload arg → autoloads. → set
   `autoload=false` + one-time migration. [Rule 1](../standards/DATA-AT-SCALE.md).
   *Blast radius:* `bn_space_meta` exists in **Pro only** — Free has no meta table, so the
   Free fix is `update_option(..., false)` + a migration that rewrites existing
   `bn_space_*` rows to `autoload='no'` (the autoload flag of existing rows does not change
   on a plain `update_option`). Read paths (`get_option`) are unaffected.

## Cache uniformity (the "no 10 engines" problem) — verified at code level

**Corrected after reading the code (not the audit summary):** a real, dominant convention
already exists — 25+ services declare `private const CACHE_GROUP = 'buddynext_<domain>'` +
`CACHE_TTL` and call `wp_cache_*` inline (71 call sites). That IS the standard
([`CACHING.md`](../standards/CACHING.md)) — descriptive of good code already here, not a
migration target.

- `includes/Core/CacheService.php` is **not** the path services use (`remember()` = 0
  callers). Two narrow real roles only: backing `MemberTypeService`, and the admin
  "Clear cache" button (`ToolsTab::forget_group()` ×2). Don't route service caching
  through it; **do** remove its dead typed methods (`get_notification_count()`,
  `*_trending_hashtags`, …) — each duplicates the owning service's own cache, 0 callers.
- Free groups uniform (`buddynext_*`). **Pro drift, verified:** `SubscriptionService`
  `'buddynextpro'`, `EmbeddingProvider` `'buddynext_pro_embeddings'`, plus `'buddynext-pro'` /
  Free-group reuse. Converge on `buddynextpro_<domain>` (group const + every
  `wp_cache_delete` in the same commit, or invalidation silently breaks).
- Transient vs object cache ad-hoc; trending hashtags cached **both** in `HashtagService`
  (group `buddynext_hashtags`) and `CacheService` (group `buddynext`). HashtagService
  canonical; remove the CacheService copy.
- `forget_group()` → `wp_cache_flush_group()` no-op without Redis/Memcached, so it is
  correct only as the admin manual-flush; per-write invalidation must be **key-based**.
- Uncached hot reads to route through the canonical pattern: `Core/CounterService`,
  `Core/PermissionService`, `Feed/PollService`, `Feed/ShareService`; Pro
  `AI/AiRankedFeedService`, `Analytics/AnalyticsService`, `Members/LabelService`,
  `Email/SegmentService`.

## Realtime / app-readiness gaps

- **Object cache is load-bearing but unenforced** — unread-count protection only holds
  with a persistent object cache. Document Redis/Memcached as a requirement + Tools
  health check.
- **DM poll** is a flat 5s with no hidden-tab pause — adopt the notifications hot/cold +
  `document.hidden` pattern, or push DMs over the Pro WS.
- **No first-class token/JWT auth** — cookie+nonce / App Passwords only. Needed before a
  native app ships.
- **FCM is the only off-site channel** — single-vendor (Google) dependency.

## Roadmap

The roadmap is now the triaged, code-verified, right-sized punch-list in
**[`scale-readiness-change-index.md`](./scale-readiness-change-index.md)** —
**DO-NOW 33 / DEFER 8 / SKIP 11**, every item with a file:line, a safe-execution
rule, and (for caches) a frequency verdict. The companion by-functionality grind
list is [`scale-readiness-by-domain.md`](./scale-readiness-by-domain.md).

Headline decisions captured there:
- **Already safe, do NOT touch:** notification fan-out (batched AS keyset), external-API
  degradation guards, REST boundary (CI-enforced), background-job conformance.
- **Cache by access frequency, not by existence** — only 6 of 17 candidate reads are real
  cross-request caches; 3 are within-request memoize; 7 cut as single-use overhead.
- **C1 permission caching = memoize-only, never cross-request** (stale role/ban = security
  bug). **F presence migration = 4-phase across all 8 readers.** **Counter-row contention
  deferred** (needs sharded/buffered design; nightly recount is the safety net).
