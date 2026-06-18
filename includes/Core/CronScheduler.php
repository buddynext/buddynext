<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WP-Cron job registration for BuddyNext background tasks.
 *
 * Registers custom cron schedules and ensures all recurring events are
 * scheduled at boot.  All job handles are defined here so that other code
 * can reference them by constant rather than bare string.
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

		// 4. If recount_stats is still on buddynext_5min, migrate it to 'daily'.
		$recur = wp_get_schedule( 'buddynext_recount_stats' );
		if ( false !== $recur && 'daily' !== $recur ) {
			wp_clear_scheduled_hook( 'buddynext_recount_stats' );
			// Let schedule_events() re-add it at 'daily' on the next wp_loaded.
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Schedule an event if it is not already scheduled.
	 *
	 * @param string $hook  WP-Cron event hook.
	 * @param string $recur Recurrence slug (e.g. 'daily', 'weekly').
	 * @return void
	 */
	private function maybe_schedule( string $hook, string $recur ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $recur, $hook );
		}
	}
}
