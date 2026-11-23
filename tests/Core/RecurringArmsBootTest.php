<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Every recurring job the booted plugin arms runs without an error.
 *
 * A caller left on the pre-1.3.0 ensure_recurring() argument order was a fatal
 * ArgumentCountError on every scheduling request (seen on `wp plugin deactivate
 * jetonomy`). This runs the real registered callbacks on a cron request.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\LogRetentionService;
use BuddyNext\Moderation\ModerationListener;
use WP_UnitTestCase;

class RecurringArmsBootTest extends WP_UnitTestCase {

	public function test_every_registered_recurring_arm_runs_and_schedules(): void {
		$hooks = array(
			CronScheduler::JOB_DAILY_DIGEST,
			CronScheduler::JOB_WEEKLY_DIGEST,
			CronScheduler::JOB_RECOUNT_STATS,
			ModerationListener::QUEUE_CHECK_HOOK,
			LogRetentionService::HOOK,
		);
		foreach ( $hooks as $hook ) {
			as_unschedule_all_actions( $hook );
		}
		$this->assertNotFalse( has_action( 'init', array( LogRetentionService::class, 'arm' ) ), 'The retention arm is registered on init.' );

		add_filter( 'wp_doing_cron', '__return_true' );
		LogRetentionService::arm(); // The init arm, called directly: init already ran.
		do_action( 'wp_loaded' );   // Every registered wp_loaded arm (Free and, when loaded, Pro).
		remove_filter( 'wp_doing_cron', '__return_true' );

		foreach ( $hooks as $hook ) {
			$next = as_next_scheduled_action( $hook );
			$this->assertNotFalse( $next, "{$hook} is armed." );
			$this->assertLessThan( time() + 2 * DAY_IN_SECONDS, (int) $next, "{$hook}: the first run is a timestamp, not an interval." );
		}
	}
}
