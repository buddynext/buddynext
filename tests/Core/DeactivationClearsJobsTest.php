<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Deactivating BuddyNext leaves no recurring job behind to fail.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\LogRetentionService;

/**
 * @covers \BuddyNext\Core\CronScheduler::clear_events
 */
class DeactivationClearsJobsTest extends \WP_UnitTestCase {

	private const HOOKS = array(
		CronScheduler::JOB_DAILY_DIGEST,
		CronScheduler::JOB_WEEKLY_DIGEST,
		CronScheduler::JOB_CLEANUP_TOKENS,
		CronScheduler::JOB_CLEANUP_REPORTS,
		CronScheduler::JOB_RECOUNT_STATS,
		'buddynext_daily_queue_check',
		LogRetentionService::HOOK,
	);

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( '', array(), CronScheduler::GROUP );
	}

	public function tear_down(): void {
		as_unschedule_all_actions( '', array(), CronScheduler::GROUP );
		parent::tear_down();
	}

	public function test_deactivation_unschedules_every_recurring_job(): void {
		foreach ( self::HOOKS as $hook ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, $hook, array(), CronScheduler::GROUP );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
		}

		do_action( 'deactivate_' . plugin_basename( BUDDYNEXT_FILE ) );

		foreach ( self::HOOKS as $hook ) {
			$this->assertFalse( as_has_scheduled_action( $hook, array(), CronScheduler::GROUP ), "{$hook} is still on Action Scheduler." );
			$this->assertFalse( wp_next_scheduled( $hook ), "{$hook} is still on WP-Cron." );
		}
	}
}
