<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * A job that queues its next pass from inside its own run is not blocked by itself.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Bridges\JetonomyBridge;
use WP_UnitTestCase;

class SelfRequeueChainTest extends WP_UnitTestCase {

	public function test_the_anonymous_repair_requeues_while_its_own_run_is_in_progress(): void {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		as_unschedule_all_actions( JetonomyBridge::REPAIR_ANONYMOUS_HOOK );
		$running = as_enqueue_async_action( JetonomyBridge::REPAIR_ANONYMOUS_HOOK, array(), 'buddynext' );
		\ActionScheduler::store()->log_execution( $running ); // Now in-progress, as inside the callback.

		$enqueue = new \ReflectionMethod( JetonomyBridge::class, 'enqueue_anonymous_repair' );
		$enqueue->setAccessible( true );
		$enqueue->invoke( null );

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => JetonomyBridge::REPAIR_ANONYMOUS_HOOK,
				'status' => 'pending',
			),
			'ids'
		);
		$this->assertCount( 1, $pending, 'The next pass is queued; the running one does not count.' );

		$enqueue->invoke( null );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => JetonomyBridge::REPAIR_ANONYMOUS_HOOK, 'status' => 'pending' ), 'ids' ), 'Still one pending: no duplicates.' );
	}
}
