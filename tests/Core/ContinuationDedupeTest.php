<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A cursor continuation queued twice from the same cursor leaves one pending job.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\CronService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\CronService
 */
class ContinuationDedupeTest extends WP_UnitTestCase {

	/**
	 * Pending rows of a hook sharing group and args (the owner's duplicate query, for
	 * one hook: the test database keeps other suites' leftover rows).
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	private function pending_duplicates( string $hook ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE status = 'pending' AND hook = %s
				 GROUP BY hook, group_id, args HAVING COUNT(*) > 1 ) d",
				$hook
			)
		);
	}

	/**
	 * Two digest runs that stop at the same member queue one continuation.
	 *
	 * @return void
	 */
	public function test_digest_continuation_from_the_same_cursor_is_queued_once(): void {
		as_unschedule_all_actions( CronScheduler::JOB_DAILY_DIGEST );
		$chain = new \ReflectionMethod( CronService::class, 'chain_next_digest_chunk' );
		$cap   = ( new \ReflectionClassConstant( CronService::class, 'DIGEST_USER_CAP' ) )->getValue();
		$ids   = range( 1, $cap );

		$chain->invoke( new CronService(), CronScheduler::JOB_DAILY_DIGEST, $ids );
		$chain->invoke( new CronService(), CronScheduler::JOB_DAILY_DIGEST, $ids );

		$this->assertTrue( as_has_scheduled_action( CronScheduler::JOB_DAILY_DIGEST, array( $cap ), CronScheduler::GROUP ), 'The digest still continues.' );
		$this->assertSame( 0, $this->pending_duplicates( CronScheduler::JOB_DAILY_DIGEST ) );
	}
}
