<?php
/**
 * Tests for the WP-Cron job scheduler.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;

/**
 * Verifies that CronScheduler registers all expected cron events.
 *
 * @covers \BuddyNext\Core\CronScheduler
 */
class CronSchedulerTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var CronScheduler
	 */
	private CronScheduler $scheduler;

	/**
	 * Create a fresh scheduler before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->scheduler = new CronScheduler();
	}

	/**
	 * Calling init() attaches the schedule action.
	 */
	public function test_init_adds_wp_loaded_hook(): void {
		$this->scheduler->init();
		$this->assertNotFalse(
			has_action( 'wp_loaded', array( $this->scheduler, 'schedule_events' ) )
		);
	}

	/**
	 * No custom intervals are registered — all remaining Free jobs use built-in
	 * WordPress recurrences so no sub-minute / sub-hour intervals are needed.
	 */
	public function test_no_removed_custom_intervals_registered(): void {
		$schedules = $this->scheduler->add_custom_schedules( array() );
		$this->assertArrayNotHasKey( 'buddynext_1min', $schedules );
		$this->assertArrayNotHasKey( 'buddynext_5min', $schedules );
		$this->assertArrayNotHasKey( 'buddynext_30min', $schedules );
	}

	/**
	 * The add_custom_schedules() filter returns the input array unmodified.
	 */
	public function test_add_custom_schedules_returns_input_unchanged(): void {
		$input  = array(
			'daily' => array(
				'interval' => DAY_IN_SECONDS,
				'display'  => 'Once Daily',
			),
		);
		$result = $this->scheduler->add_custom_schedules( $input );
		$this->assertSame( $input, $result );
	}

	/**
	 * Calling schedule_events() registers the daily digest event.
	 */
	public function test_schedule_events_registers_daily_digest(): void {
		$this->scheduler->schedule_events();
		$this->assertTrue( as_has_scheduled_action( 'buddynext_daily_digest', array(), CronScheduler::GROUP ) );
	}

	/**
	 * Calling schedule_events() registers the weekly digest event.
	 */
	public function test_schedule_events_registers_weekly_digest(): void {
		$this->scheduler->schedule_events();
		$this->assertTrue( as_has_scheduled_action( 'buddynext_weekly_digest', array(), CronScheduler::GROUP ) );
	}

	/**
	 * Calling schedule_events() registers the cleanup tokens event.
	 */
	public function test_schedule_events_registers_cleanup_tokens(): void {
		$this->scheduler->schedule_events();
		$this->assertTrue( as_has_scheduled_action( 'buddynext_cleanup_tokens', array(), CronScheduler::GROUP ) );
	}

	/**
	 * The retired log-retention jobs are NOT scheduled any more.
	 *
	 * This assertion used to be the exact inverse — it required the scheduler to register
	 * `buddynext_cleanup_notifications`. That job pruned bn_notifications and bn_email_log
	 * weekly on `buddynext_data_retention_days`, while LogRetentionService pruned the SAME
	 * two tables daily on a different option. Two owners, and the daily sweep always won,
	 * so the setting the admin could actually see did nothing. LogRetentionService is now
	 * the sole owner; scheduling these again would restore the duplicate.
	 */
	public function test_retired_log_retention_jobs_are_not_scheduled(): void {
		$this->scheduler->schedule_events();
		$this->assertFalse( as_has_scheduled_action( 'buddynext_cleanup_notifications', array(), CronScheduler::GROUP ) );
		$this->assertFalse( as_has_scheduled_action( 'buddynext_cleanup_email_log', array(), CronScheduler::GROUP ) );
	}

	/**
	 * The recount_stats job is a recurring Action Scheduler action (the
	 * scheduler migrated off native WP-Cron / the old buddynext_5min recurrence).
	 */
	public function test_recount_stats_is_scheduled_daily(): void {
		$this->scheduler->schedule_events();
		$this->assertTrue( as_has_scheduled_action( 'buddynext_recount_stats', array(), CronScheduler::GROUP ) );
	}

	/**
	 * Removed jobs are not scheduled by schedule_events().
	 */
	public function test_removed_jobs_are_not_scheduled(): void {
		$this->scheduler->schedule_events();
		$this->assertFalse( wp_next_scheduled( 'buddynext_trending_hashtags' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_publish_scheduled' ) );
	}

	/**
	 * The run_cron_migration() upgrade routine clears the removed events.
	 */
	public function test_run_cron_migration_clears_removed_events(): void {
		// Manually seed the legacy events to simulate an existing install.
		wp_schedule_event( time(), 'daily', 'buddynext_publish_scheduled' );
		wp_schedule_event( time(), 'daily', 'buddynext_trending_hashtags' );
		wp_schedule_event( time(), 'daily', 'buddynext_webhook_retry' );
		// The retired duplicate-retention jobs. An existing install already has these
		// armed, so deleting the handlers is not enough — an unhandled job would just
		// fire into nothing forever. The migration has to unschedule them.
		wp_schedule_event( time(), 'daily', 'buddynext_cleanup_notifications' );
		wp_schedule_event( time(), 'daily', 'buddynext_cleanup_email_log' );

		CronScheduler::run_cron_migration();

		$this->assertFalse( wp_next_scheduled( 'buddynext_publish_scheduled' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_trending_hashtags' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_webhook_retry' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_cleanup_notifications' ), 'The retired notifications-cleanup job is still armed on an upgraded install.' );
		$this->assertFalse( wp_next_scheduled( 'buddynext_cleanup_email_log' ), 'The retired email-log-cleanup job is still armed on an upgraded install.' );
	}

	/**
	 * The run_cron_migration() upgrade routine migrates recount_stats off a non-daily recurrence.
	 */
	public function test_run_cron_migration_reschedules_recount_stats(): void {
		// Simulate a legacy install: recount_stats is on a fake 5-min interval.
		add_filter(
			'cron_schedules',
			static function ( array $s ): array {
				$s['buddynext_5min'] = array(
					'interval' => 300,
					'display'  => 'Test 5min',
				);
				return $s;
			}
		);
		wp_schedule_event( time(), 'buddynext_5min', 'buddynext_recount_stats' );
		$this->assertSame( 'buddynext_5min', wp_get_schedule( 'buddynext_recount_stats' ) );

		CronScheduler::run_cron_migration();

		// After migration the event is cleared; schedule_events() will re-add at 'daily'.
		// For this unit test, verify it is no longer on the old recurrence.
		$this->assertNotSame( 'buddynext_5min', wp_get_schedule( 'buddynext_recount_stats' ) );
	}
}
