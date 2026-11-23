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
		// Scheduled actions outlive a test's transaction: start each test with none,
		// and in the cron runner, the request where jobs are armed.
		as_unschedule_all_actions( '', array(), CronScheduler::GROUP );
		add_filter( 'wp_doing_cron', '__return_true' );
	}

	/**
	 * Clear the cron context.
	 */
	public function tear_down(): void {
		remove_filter( 'wp_doing_cron', '__return_true' );
		parent::tear_down();
	}

	/**
	 * An ordinary front-end, REST or AJAX request never checks or arms schedules:
	 * that lookup used to run on every request, images and heartbeats included.
	 */
	public function test_ordinary_request_does_not_touch_schedules(): void {
		remove_filter( 'wp_doing_cron', '__return_true' );
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->scheduler->schedule_events();
		$this->assertSame( $before, $wpdb->num_queries, 'No database work on an ordinary request.' );
		$this->assertFalse( as_has_scheduled_action( 'buddynext_daily_digest', array(), CronScheduler::GROUP ) );
		$this->assertFalse( CronScheduler::is_scheduling_request() );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertTrue( CronScheduler::is_scheduling_request() );
		$this->scheduler->schedule_events();
		$this->assertTrue( as_has_scheduled_action( 'buddynext_daily_digest', array(), CronScheduler::GROUP ), 'The cron runner arms it.' );
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

	public function test_no_custom_intervals_registered(): void {
		$schedules = wp_get_schedules();
		$this->assertArrayNotHasKey( 'buddynext_1min', $schedules );
		$this->assertArrayNotHasKey( 'buddynext_5min', $schedules );
		$this->assertArrayNotHasKey( 'buddynext_30min', $schedules );
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
	 * Token and report cleanup run inside the one daily retention job: the upgrade
	 * cancels their own recurring actions and nothing re-arms them.
	 */
	public function test_cleanup_jobs_ride_the_daily_retention_job(): void {
		as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'buddynext_cleanup_tokens', array(), CronScheduler::GROUP );
		as_schedule_recurring_action( time(), WEEK_IN_SECONDS, 'buddynext_cleanup_reports', array(), CronScheduler::GROUP );
		$this->scheduler->init();
		CronScheduler::run_cron_migration(); // The 1.3.0 upgrade step.
		$this->scheduler->schedule_events();

		$this->assertFalse( as_has_scheduled_action( 'buddynext_cleanup_tokens', array(), CronScheduler::GROUP ) );
		$this->assertFalse( as_has_scheduled_action( 'buddynext_cleanup_reports', array(), CronScheduler::GROUP ) );
		$this->assertNotFalse( has_action( \BuddyNext\Core\LogRetentionService::HOOK ), 'The retention job carries the cleanup.' );

		// Reports are pruned on every daily run, so a missed run never skips a week.
		$daily = false;
		foreach ( $GLOBALS['wp_filter'][ \BuddyNext\Core\LogRetentionService::HOOK ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$daily = $daily || ( is_array( $cb['function'] ) && 'handle_cleanup_reports' === $cb['function'][1] );
			}
		}
		$this->assertTrue( $daily, 'Report cleanup runs on every daily run, not one weekday.' );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_verify_tokens',
			array(
				'user_id'    => 1,
				'token'      => 'old-' . wp_rand(),
				'type'       => 'email',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
		do_action( \BuddyNext\Core\LogRetentionService::HOOK );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_verify_tokens WHERE expires_at < UTC_TIMESTAMP()" ), 'Expired tokens are pruned by the daily retention run.' );
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
	/**
	 * Site Health names a stalled background queue in plain words, and is green
	 * when nothing of ours is overdue (card 10501591033).
	 */
	public function test_site_health_reports_a_stalled_background_queue(): void {
		$this->assertSame( 'good', CronScheduler::site_health_queue_test()['status'] );

		as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, 'buddynext_async_index_hashtags', array( 'post', 1, '' ), CronScheduler::GROUP );
		$result = CronScheduler::site_health_queue_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'server cron job', $result['description'] );

		$tests = CronScheduler::register_site_health( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'buddynext_background_tasks', $tests['direct'] );
	}

	/**
	 * A task due a minute ago is a working queue catching up, not a stall.
	 */
	public function test_site_health_ignores_a_task_that_is_only_just_due(): void {
		as_schedule_single_action( time() - MINUTE_IN_SECONDS, 'buddynext_async_index_hashtags', array( 'post', 2, '' ), CronScheduler::GROUP );
		$this->assertSame( 'good', CronScheduler::site_health_queue_test()['status'] );
	}

	public function test_ensure_recurring_arms_once_and_returns_the_new_id(): void {
		$first = CronScheduler::ensure_recurring( 'buddynext_zz_recurring', 0, DAY_IN_SECONDS );
		$again = CronScheduler::ensure_recurring( 'buddynext_zz_recurring', 0, DAY_IN_SECONDS );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $again, 'Already armed: nothing scheduled.' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => 'buddynext_zz_recurring', 'status' => 'pending' ), 'ids' ) );
	}

	public function test_a_concurrent_arm_that_passed_the_check_still_adds_no_second_row(): void {
		CronScheduler::ensure_recurring( 'buddynext_zz_race', 0, DAY_IN_SECONDS );
		// The other request already passed as_next_scheduled_action(); its insert carries unique.
		$second = as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'buddynext_zz_race', array(), CronScheduler::GROUP, true );

		$this->assertSame( 0, (int) $second );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => 'buddynext_zz_race', 'status' => 'pending' ), 'ids' ) );
	}

	public function test_ensure_recurring_does_nothing_on_an_ordinary_request(): void {
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 0, CronScheduler::ensure_recurring( 'buddynext_zz_front', 0, DAY_IN_SECONDS ) );
		$this->assertFalse( as_next_scheduled_action( 'buddynext_zz_front' ) );
		add_filter( 'wp_doing_cron', '__return_true' );
	}
}
