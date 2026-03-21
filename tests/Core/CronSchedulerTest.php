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
	 * Custom schedules are registered when not present.
	 */
	public function test_custom_schedules_are_added(): void {
		$schedules = $this->scheduler->add_custom_schedules( array() );
		$this->assertArrayHasKey( 'buddynext_5min', $schedules );
		$this->assertArrayHasKey( 'buddynext_30min', $schedules );
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
	 * Calling schedule_events() registers the trending hashtags event.
	 */
	public function test_schedule_events_registers_trending_hashtags(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_trending_hashtags' ) );
	}

	/**
	 * Calling schedule_events() registers the recount stats event.
	 */
	public function test_schedule_events_registers_recount_stats(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_recount_stats' ) );
	}

	/**
	 * Calling schedule_events() registers the scheduled post publisher event.
	 */
	public function test_schedule_events_registers_publish_scheduled(): void {
		$this->scheduler->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( 'buddynext_publish_scheduled' ) );
	}
}
