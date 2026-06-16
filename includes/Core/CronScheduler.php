<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WP-Cron job registration for BuddyNext background tasks.
 *
 * Registers custom cron schedules and ensures all recurring events are
 * scheduled at boot.  All job handles are defined here so that other code
 * can reference them by constant rather than bare string.
 *
 * Jobs defined (matches spec 19 Action Scheduler table):
 *   buddynext_daily_digest         — daily  08:00 UTC
 *   buddynext_weekly_digest        — weekly Monday 08:00 UTC
 *   buddynext_cleanup_tokens       — daily
 *   buddynext_cleanup_notifications— weekly (prune 90-day-old read rows)
 *   buddynext_trending_hashtags    — every 30 min
 *   buddynext_recount_stats        — every 5 min
 *   buddynext_publish_scheduled    — every 1 min
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
	 * Trending hashtags refresh job hook.
	 */
	public const JOB_TRENDING_HASHTAGS = 'buddynext_trending_hashtags';

	/**
	 * Counter recount job hook.
	 */
	public const JOB_RECOUNT_STATS = 'buddynext_recount_stats';

	/**
	 * Scheduled post publisher job hook.
	 */
	public const JOB_PUBLISH_SCHEDULED = 'buddynext_publish_scheduled';

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
		add_action( self::JOB_TRENDING_HASHTAGS, array( $handlers, 'handle_trending_hashtags' ) );
		add_action( self::JOB_RECOUNT_STATS, array( $handlers, 'handle_recount_stats' ) );
		add_action( self::JOB_PUBLISH_SCHEDULED, array( $handlers, 'handle_publish_scheduled' ) );
	}

	/**
	 * Add custom recurrence intervals required by BuddyNext jobs.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['buddynext_1min'] ) ) {
			$schedules['buddynext_1min'] = array( // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute', 'buddynext' ),
			);
		}

		if ( ! isset( $schedules['buddynext_5min'] ) ) {
			$schedules['buddynext_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes', 'buddynext' ),
			);
		}

		if ( ! isset( $schedules['buddynext_30min'] ) ) {
			$schedules['buddynext_30min'] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 30 minutes', 'buddynext' ),
			);
		}

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
		$this->maybe_schedule( self::JOB_TRENDING_HASHTAGS, 'buddynext_30min' );
		$this->maybe_schedule( self::JOB_RECOUNT_STATS, 'buddynext_5min' );
		$this->maybe_schedule( self::JOB_PUBLISH_SCHEDULED, 'buddynext_1min' );
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
			self::JOB_TRENDING_HASHTAGS,
			self::JOB_RECOUNT_STATS,
			self::JOB_PUBLISH_SCHEDULED,
			'buddynext_webhook_retry',
		);

		foreach ( $jobs as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Schedule an event if it is not already scheduled.
	 *
	 * @param string $hook     WP-Cron event hook.
	 * @param string $recur    Recurrence slug (e.g. 'daily', 'buddynext_5min').
	 * @return void
	 */
	private function maybe_schedule( string $hook, string $recur ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $recur, $hook );
		}
	}
}
