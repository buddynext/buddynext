<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the Action Scheduler retention cap (change-index item S3b).
 *
 * CronScheduler::init() registers an action_scheduler_retention_period filter that
 * caps the completed/failed-action retention at 14 days (only lowers, never raises).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use WP_UnitTestCase;

/**
 * AS retention-period filter behaviour.
 */
class CronRetentionTest extends WP_UnitTestCase {

	/**
	 * Register the CronScheduler filters once.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		( new CronScheduler() )->init();
	}

	/**
	 * A larger period is capped to 14 days; a smaller one is left alone.
	 *
	 * @return void
	 */
	public function test_retention_period_capped_at_14_days(): void {
		$cap = 14 * DAY_IN_SECONDS;

		$this->assertSame( $cap, apply_filters( 'action_scheduler_retention_period', 30 * DAY_IN_SECONDS ), 'A 30-day window must be capped to 14.' );
		$this->assertSame( 7 * DAY_IN_SECONDS, apply_filters( 'action_scheduler_retention_period', 7 * DAY_IN_SECONDS ), 'A 7-day window must be left as-is.' );
		$this->assertSame( $cap, apply_filters( 'action_scheduler_retention_period', 0 ), 'An empty/zero default falls back to 14 days.' );
	}
}
