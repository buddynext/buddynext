<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Deactivation cancels our whole pending queue in a bounded number of queries,
 * whichever Action Scheduler store is active, and touches nothing else.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronScheduler;
use WP_UnitTestCase;

class DeactivationCancelTest extends WP_UnitTestCase {

	private function status( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_clear_events_cancels_a_large_queue_in_few_queries_on_the_hybrid_store(): void {
		$ours = array();
		for ( $i = 1; $i <= 300; $i++ ) {
			$ours[] = (int) as_schedule_single_action( time() + DAY_IN_SECONDS, 'buddynext_test_job', array( $i ), CronScheduler::GROUP );
		}
		$nudge   = (int) as_schedule_single_action( time() + DAY_IN_SECONDS, 'bn_onboarding_nudge_24h', array( 7 ) );
		$theirs  = (int) as_schedule_single_action( time() + DAY_IN_SECONDS, 'other_plugin_job', array( 1 ), 'other-plugin' );
		$running = (int) as_schedule_single_action( time() - 60, 'buddynext_test_job', array( 'run' ), CronScheduler::GROUP );
		\ActionScheduler::store()->log_execution( $running );

		// Action Scheduler clears its "migration complete" flag on every plugin
		// deactivation, so the deactivation request often runs the hybrid store,
		// whose group cancel is one row at a time.
		$prop = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );
		$prop->setAccessible( true );
		$was = $prop->getValue();
		$prop->setValue( null, new \ActionScheduler_HybridStore() );

		$queries = 0;
		$count   = static function ( $q ) use ( &$queries ) {
			++$queries;
			return $q;
		};
		add_filter( 'query', $count );
		try {
			( new CronScheduler() )->clear_events();
		} finally {
			remove_filter( 'query', $count );
			$prop->setValue( null, $was );
		}

		$this->assertLessThan( 60, $queries, 'Bounded queries, not three per pending row.' );
		$this->assertSame( 'canceled', $this->status( $ours[0] ) );
		$this->assertSame( 'canceled', $this->status( $ours[299] ) );
		$this->assertSame( 'canceled', $this->status( $nudge ) );
		$this->assertSame( 'pending', $this->status( $theirs ), 'Another plugin\'s row is untouched.' );
		$this->assertSame( 'in-progress', $this->status( $running ), 'A running row is untouched.' );
		as_unschedule_all_actions( '', array(), 'other-plugin' );
	}
}
