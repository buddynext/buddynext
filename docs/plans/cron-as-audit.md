# Cron + Action Scheduler Audit (Free + Pro)

> Read-only audit, 2026-06-18. Goal: minimal cron per [[performance-first-fast-community]]. Recurring jobs that poll unconditionally are the target; reactive async + single-events are fine.

## Recurring jobs inventory

### Free — central `CronScheduler` (all scheduled unconditionally at wp_loaded)
| Job | Interval | Verdict |
|---|---|---|
| `buddynext_daily_digest` | daily | KEEP (cheap; gate on digest enabled) |
| `buddynext_weekly_digest` | weekly | KEEP |
| `buddynext_cleanup_tokens` | daily | KEEP (maintenance) |
| `buddynext_cleanup_notifications` | weekly | KEEP |
| `buddynext_cleanup_activity_log` | weekly | KEEP |
| `buddynext_trending_hashtags` | **30 min** | CONVERT - compute lazily on-read + cache 30 min; drop the perpetual cron |
| `buddynext_recount_stats` | **5 min** | CONVERT (biggest win) - counters are already maintained incrementally on write; a 5-min full recount is a constant redundant reconcile. Move to **daily** (or on-demand) reconciliation |
| `buddynext_publish_scheduled` | **1 min** | CONVERT - 1-minute is too aggressive AND overlaps Pro's publisher (see Duplicate). Drop or merge |

Custom intervals registered: `buddynext_1min`, `buddynext_5min`, `buddynext_30min`. Drop `buddynext_1min` once publish-scheduled moves off it.

### Free — other recurring
| Job | Interval | Verdict |
|---|---|---|
| `buddynext_webhook_retry` (OutboundWebhookService) | **5 min** | CONVERT to reactive - schedule a single retry with backoff only when a delivery fails; unschedule when none pending |
| `buddynext_daily_queue_check` (ModerationListener) | daily | KEEP (cheap; optionally only when reports exist) |

### Pro
| Job | Interval | Verdict |
|---|---|---|
| `buddynextpro_publish_scheduled` (ScheduledPostsService) | **5 min** | CONVERT - only schedule when scheduled posts exist; self-unschedule when none. DEDUPE with Free's publisher |
| `buddynextpro_broadcast_send_pending` (BroadcastService) | **5 min** | CONVERT - schedule only when broadcasts are pending; self-unschedule when the queue drains (re-arm on broadcast create) |
| `buddynextpro_drip_tick` (DripEnrollmentService) | hourly | GATE - only when active drip enrollments exist; unschedule when none |
| `buddynextpro_expire_subscriptions` (SubscriptionService) | daily | KEEP (cheap; only matters once tiers exist) |
| `AiModerationSweep` SWEEP (AS) + CLEANUP (AS daily) | configurable | KEEP - already self-(un)schedules on the AI-moderation toggle (good pattern; the model to copy) |

## Reactive / one-off (NO change — already correct)
- `as_enqueue_async_action`: search index, reindex-all, hashtag index, space-fanout notifications, async email send, embeddings, AI report/scan. Fire on trigger only.
- `wp_schedule_single_event`: reindex (+30s), webhook deliver, onboarding nudges (24h/72h).

## Duplicate / overlap found
- **Two scheduled-post publishers**: Free `buddynext_publish_scheduled` (1 min) + Pro `buddynextpro_publish_scheduled` (5 min). Scheduled posts is a Pro feature - Free's job is likely a legacy stub. Resolve to ONE (Pro owns it; remove Free's, or have Free's no-op when Pro is active).

## Recommended changes (priority order)
1. **`recount_stats` 5 min -> daily** (counters already incremental) - removes the heaviest frequent poll. ★
2. **`publish_scheduled`**: drop Free's 1-min job, keep ONE Pro publisher at 5 min, scheduled only when `status='scheduled'` rows exist (self-unschedule when none). ★
3. **`broadcast_send_pending`**: self-unscheduling - run only while broadcasts are pending. ★
4. **`webhook_retry`**: reactive single-event with backoff on failure (no perpetual 5-min poll). ★
5. **`trending_hashtags`**: lazy compute-on-read + cache; drop the 30-min cron.
6. **`drip_tick`**: gate to active sequences only.
7. Drop the `buddynext_1min` custom interval afterward.

## Net effect
Frequent unconditional polls (1x 1-min, 3x 5-min, 1x 30-min) -> mostly daily/weekly + a few self-unscheduling-when-empty + reactive. The "self-(un)schedule on demand" pattern already used by `AiModerationSweep` is the template to apply across the converts.

## Pattern to standardize
A small helper: schedule a recurring job ONLY when there's pending work, and unschedule inside the handler when the queue is empty; re-arm from the write path that creates work. Document it so new features follow it (minimal-cron by default).

## AS-consolidation pass (2026-06-18) — DONE

Goal: one observable, retrying queue, runnable off real cron. Scoped deliberately by job shape:

- **Always-on recurring jobs -> Action Scheduler** (they run regardless, so AS is the right home — retries + Tools -> Scheduled Actions visibility + single real-cron runner):
  - Free `CronScheduler`: daily/weekly digests, 3 cleanups, `recount_stats` -> `as_schedule_recurring_action`, group `buddynext`, AS-first with native WP-Cron fallback when AS is unavailable. `maybe_schedule()` clears any legacy native event before registering the AS action so a job never double-runs; `clear_events()` clears both.
  - Free `ModerationListener::buddynext_daily_queue_check` -> same pattern (group `buddynext`).
  - Pro `SubscriptionService::buddynextpro_expire_subscriptions` -> same pattern (group `buddynextpro`) + a `deactivate_cron()` that clears both AS + WP-Cron.
  - Handlers are unchanged: AS fires the same action hook the `add_action()` handlers already listen on.
- **On-demand self-(un)scheduling jobs stay event-driven** (already the minimal pattern — dormant unless pending work exists, so there is nothing to consolidate and no idle polling):
  - Free `ScheduledPostsPublisher` (single event armed at the next due time).
  - Pro `BroadcastService` + `DripEnrollmentService` (self-arm on write, self-unschedule when the queue drains). A future phase may move these to AS purely for send-retry observability; not done now to avoid churning working email features pre-beta.
- **Reactive single events stay native** (`OutboundWebhookService` deliver/retry, `OnboardingListener` 24h/72h nudges, `SearchService` reindex-all): already single-shot + on-demand; native `wp_schedule_single_event` is fine.

### Fast by default — a GENERAL solution, no site-specific config required
BuddyNext must be light on a vanilla WordPress install with zero environment changes. We do NOT set, require, or recommend `DISABLE_WP_CRON` — that is a site-wide constant affecting every plugin (WooCommerce, backups, email queues, ...) and silently breaks them all if the owner has not separately wired a real system cron. It is the site owner's server-level decision, never the plugin's. BuddyNext stays fast out of the box because:

1. **No idle polling** — no recurring job fires unless there is actual work (always-on jobs are daily/weekly; everything else is on-demand or reactive).
2. **On-demand self-(un)scheduling** — scheduled-post publisher, broadcast, drip arm only when work is created and disarm when the queue drains.
3. **Action Scheduler is non-blocking by design** — its queue runner processes a bounded batch (default ~25 actions, time-capped) in a separate async loopback request, NOT in the visitor's page-load thread. So even on a default WP-Cron site, members' requests are not doing background work.

`DISABLE_WP_CRON` + a system cron driving `wp action-scheduler run` remains a legitimate OPTIONAL optimisation a site owner may apply at the server level on their own — but it is outside the plugin and must never be a requirement for BuddyNext to be fast.

Also prune AS tables (`actionscheduler_*`) so completed/failed logs don't bloat — AS's built-in retention handles this by default; verify it's on.
