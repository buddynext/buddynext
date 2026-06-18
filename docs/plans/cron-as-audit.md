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
