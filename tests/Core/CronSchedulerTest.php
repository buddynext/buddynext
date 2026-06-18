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
	 * add_custom_schedules() returns the input array unmodified.
	 */
	public function test_add_custom_schedules_returns_input_unchanged(): void {
		$input  = array( 'daily' => array( 'interval' => DAY_IN_SECONDS, 'display' => 'Once Daily' ) );
		$result = $this->scheduler->add_custom_schedules( $input );
		$this->assertSame( $input, $result );
	}

	/**
	 * Calling schedule_events() registers the daily digest event.
	 */
	public function test_schedule_events_registers_daily_digest(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_daily_digest' ) );
	}

	/**
	 * Calling schedule_events() registers the weekly digest event.
	 */
	public function test_schedule_events_registers_weekly_digest(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_weekly_digest' ) );
	}

	/**
	 * Calling schedule_events() registers the cleanup tokens event.
	 */
	public function test_schedule_events_registers_cleanup_tokens(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_cleanup_tokens' ) );
	}

	/**
	 * Calling schedule_events() registers the notifications cleanup event.
	 */
	public function test_schedule_events_registers_cleanup_notifications(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_cleanup_notifications' ) );
	}

	/**
	 * recount_stats is scheduled at 'daily' recurrence (was buddynext_5min).
	 */
	public function test_recount_stats_is_scheduled_daily(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_recount_stats' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'buddynext_recount_stats' ) );
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
	 * run_cron_migration() clears the removed events.
	 */
	public function test_run_cron_migration_clears_removed_events(): void {
		// Manually seed the legacy events to simulate an existing install.
		wp_schedule_event( time(), 'daily', 'buddynext_publish_scheduled' );
		wp_schedule_event( time(), 'daily', 'buddynext_trending_hashtags' );
		wp_schedule_event( time(), 'daily', 'buddynext_webhook_retry' );

		CronScheduler::run_cron_migration();

		$this->assertFalse( wp_next_scheduled( 'buddynext_publish_scheduled' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_trending_hashtags' ) );
		$this->assertFalse( wp_next_scheduled( 'buddynext_webhook_retry' ) );
	}

	/**
	 * run_cron_migration() migrates recount_stats off a non-daily recurrence.
	 */
	public function test_run_cron_migration_reschedules_recount_stats(): void {
		// Simulate a legacy install: recount_stats is on a fake 5-min interval.
		add_filter(
			'cron_schedules',
			static function ( array $s ): array {
				$s['buddynext_5min'] = array( 'interval' => 300, 'display' => 'Test 5min' );
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
