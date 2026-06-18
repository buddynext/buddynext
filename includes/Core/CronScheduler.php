<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Recurring background-job registration for BuddyNext.
 *
 * Recurring jobs run on Action Scheduler (group 'buddynext') when available —
 * one observable, retrying queue that can be driven off real system cron so
 * nothing executes inside a visitor request — and fall back to native WP-Cron
 * when AS is absent. All job handles are defined here so other code references
 * them by constant rather than bare string.
 *
 * Jobs defined:
 *   buddynext_daily_digest         — daily (first run at activation time, then every 24h)
 *   buddynext_weekly_digest        — weekly (first run at activation time, then every 7 days)
 *   buddynext_cleanup_tokens       — daily
 *   buddynext_cleanup_notifications— weekly (prune 90-day-old read rows)
 *   buddynext_cleanup_activity_log — weekly (honours data-retention window)
 *   buddynext_recount_stats        — daily (counters maintained incrementally on write;
 *                                    daily run is a reconcile pass only)
 *
 * Removed recurring jobs (converted to lazy/reactive):
 *   buddynext_trending_hashtags    — dropped; HashtagService::get_trending() computes
 *                                    lazily on first read and caches via transient (~30 min).
 *   buddynext_publish_scheduled    — no longer a recurring 1-min poll; now a single
 *                                    on-demand event armed at the next due post's time by
 *                                    Feed\ScheduledPostsPublisher (Free-owned, runs without Pro).
 *   buddynext_webhook_retry        — dropped; OutboundWebhookService schedules a single
 *                                    wp_schedule_single_event per failed delivery with backoff.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Manages registration of BuddyNext WP-Cron events.
 */
class CronScheduler {

	// ── Job handle constants ──────────────────────────────────────────────────

	/**
	 * Daily digest job hook.
	 */
	public const JOB_DAILY_DIGEST = 'buddynext_daily_digest';

	/**
	 * Weekly digest job hook.
	 */
	public const JOB_WEEKLY_DIGEST = 'buddynext_weekly_digest';

	/**
	 * Daily token cleanup job hook.
	 */
	public const JOB_CLEANUP_TOKENS = 'buddynext_cleanup_tokens';

	/**
	 * Weekly notification pruning job hook.
	 */
	public const JOB_CLEANUP_NOTIFICATIONS = 'buddynext_cleanup_notifications';

	/**
	 * Weekly activity-log pruning job hook (honours the data-retention window).
	 */
	public const JOB_CLEANUP_ACTIVITY_LOG = 'buddynext_cleanup_activity_log';

	/**
	 * Counter recount job hook. Runs daily — counters are maintained
	 * incrementally on every write; this is a reconcile pass only.
	 */
	public const JOB_RECOUNT_STATS = 'buddynext_recount_stats';

	/**
	 * Action Scheduler group for every BuddyNext recurring job, so all jobs are
	 * observable together (Tools -> Scheduled Actions) and bulk-cancelable.
	 */
	public const GROUP = 'buddynext';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Attach scheduler hooks and wire all job handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'wp_loaded', array( $this, 'schedule_events' ) );

		// Wire cron job handlers — one action per job defined above.
		$handlers = new CronService();
		add_action( self::JOB_DAILY_DIGEST, array( $handlers, 'handle_daily_digest' ) );
		add_action( self::JOB_WEEKLY_DIGEST, array( $handlers, 'handle_weekly_digest' ) );
		add_action( self::JOB_CLEANUP_TOKENS, array( $handlers, 'handle_cleanup_tokens' ) );
		add_action( self::JOB_CLEANUP_NOTIFICATIONS, array( $handlers, 'handle_cleanup_notifications' ) );
		add_action( self::JOB_CLEANUP_ACTIVITY_LOG, array( $handlers, 'handle_cleanup_activity_log' ) );
		add_action( self::JOB_RECOUNT_STATS, array( $handlers, 'handle_recount_stats' ) );
	}

	/**
	 * Add custom recurrence intervals required by BuddyNext jobs.
	 *
	 * All three sub-minute / sub-hour intervals (buddynext_1min, buddynext_5min,
	 * buddynext_30min) have been removed because no Free job uses them after the
	 * cron-minimisation pass:
	 *   - publish_scheduled moved to Pro (ScheduledPostsService, buddynextpro_5min).
	 *   - recount_stats moved to built-in 'daily'.
	 *   - trending_hashtags dropped (lazy transient on HashtagService::get_trending).
	 *   - webhook_retry dropped (single-event backoff in OutboundWebhookService::deliver).
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_custom_schedules( array $schedules ): array {
		// No additional intervals needed — all remaining Free jobs use built-in
		// WordPress recurrences ('daily', 'weekly').
		return $schedules;
	}

	/**
	 * Ensure every recurring event is scheduled.
	 *
	 * Called on wp_loaded — safe to call on every request because
	 * wp_schedule_event is a no-op when the event is already scheduled.
	 *
	 * @return void
	 */
	public function schedule_events(): void {
		$this->maybe_schedule( self::JOB_DAILY_DIGEST, 'daily' );
		$this->maybe_schedule( self::JOB_WEEKLY_DIGEST, 'weekly' );
		$this->maybe_schedule( self::JOB_CLEANUP_TOKENS, 'daily' );
		$this->maybe_schedule( self::JOB_CLEANUP_NOTIFICATIONS, 'weekly' );
		$this->maybe_schedule( self::JOB_CLEANUP_ACTIVITY_LOG, 'weekly' );
		$this->maybe_schedule( self::JOB_RECOUNT_STATS, 'daily' );
	}

	/**
	 * Unschedule all BuddyNext cron events (called on plugin deactivation).
	 *
	 * Clears both the Action Scheduler actions (current) and any native WP-Cron
	 * events (legacy installs / AS-absent fallback) so nothing is left armed.
	 *
	 * @return void
	 */
	public function clear_events(): void {
		$jobs = array(
			self::JOB_DAILY_DIGEST,
			self::JOB_WEEKLY_DIGEST,
			self::JOB_CLEANUP_TOKENS,
			self::JOB_CLEANUP_NOTIFICATIONS,
			self::JOB_CLEANUP_ACTIVITY_LOG,
			self::JOB_RECOUNT_STATS,
			// Legacy hooks — may still be scheduled on older installs; clear them too.
			'buddynext_trending_hashtags',
			'buddynext_publish_scheduled',
			'buddynext_webhook_retry',
		);

		foreach ( $jobs as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * One-time idempotent migration for existing installs.
	 *
	 * Removes events that were replaced or dropped in the cron-minimisation pass
	 * and reschedules recount_stats to 'daily' when it is still running on the
	 * old buddynext_5min recurrence. Safe to call repeatedly — each step is
	 * guarded by a condition that is false once the migration has been applied.
	 *
	 * Called from Installer::maybe_upgrade() when SCHEMA_VERSION advances.
	 *
	 * @return void
	 */
	public static function run_cron_migration(): void {
		// 1. Drop the legacy recurring 1-min scheduled-post publisher, then hand
		// off to the on-demand publisher: it re-arms a single event at the next
		// due post's time (or stays disarmed when none are pending). Free owns
		// this so existing scheduled posts keep publishing with Pro absent.
		wp_clear_scheduled_hook( 'buddynext_publish_scheduled' );
		\BuddyNext\Feed\ScheduledPostsPublisher::arm();

		// 2. Remove the 30-min trending hashtags recount (lazy on read now).
		wp_clear_scheduled_hook( 'buddynext_trending_hashtags' );

		// 3. Remove the recurring 5-min webhook retry poll (single-event now).
		wp_clear_scheduled_hook( 'buddynext_webhook_retry' );

		// 4. The six surviving recurring jobs migrate from native WP-Cron to
		// Action Scheduler automatically: schedule_events() -> maybe_schedule()
		// clears each legacy WP-Cron event and registers the AS action on the
		// next wp_loaded. Nothing to do here beyond the legacy drops above.
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Schedule a recurring job, preferring Action Scheduler.
	 *
	 * Action Scheduler gives one observable queue (Tools -> Scheduled Actions),
	 * automatic retries, and a single runner that can be driven off real system
	 * cron (see DISABLE_WP_CRON guidance in docs/plans/cron-as-audit.md) so jobs
	 * never execute inside a visitor request. When AS is unavailable the method
	 * falls back to a native WP-Cron event so the job still runs.
	 *
	 * Idempotent: a no-op once the action/event exists. On an existing install it
	 * clears the legacy native WP-Cron event before registering the AS action so
	 * the job never double-runs across both systems.
	 *
	 * @param string $hook  Job hook (also the action hook the handler listens on).
	 * @param string $recur Recurrence slug ('daily', 'weekly').
	 * @return void
	 */
	private function maybe_schedule( string $hook, string $recur ): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				// Don't double-run alongside a legacy native WP-Cron event.
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
				}
				as_schedule_recurring_action( time(), self::recurrence_seconds( $recur ), $hook, array(), self::GROUP );
			}
			return;
		}

		// Fallback: native WP-Cron when Action Scheduler is unavailable.
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $recur, $hook );
		}
	}

	/**
	 * Map a WordPress recurrence slug to an Action Scheduler interval in seconds.
	 *
	 * @param string $recur Recurrence slug ('daily', 'weekly').
	 * @return int Interval in seconds (defaults to daily for unknown slugs).
	 */
	private static function recurrence_seconds( string $recur ): int {
		switch ( $recur ) {
			case 'weekly':
				return WEEK_IN_SECONDS;
			case 'hourly':
				return HOUR_IN_SECONDS;
			case 'daily':
			default:
				return DAY_IN_SECONDS;
		}
	}
}
