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

	public function test_deactivating_free_leaves_pro_jobs_in_the_buddynext_group(): void {
		$free = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'buddynext_zz_free', array(), 'buddynext' );
		$pro  = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'buddynext_pro_evnm_replace_cards', array( 3 ), 'buddynext' );
		$pro2 = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'buddynextpro_ai_mod_sweep', array(), 'buddynext' );

		( new CronScheduler() )->clear_events();

		$this->assertSame( 'canceled', $this->status( $free ) );
		$this->assertSame( 'pending', $this->status( $pro ), 'Pro\'s hook in Free\'s group is Pro\'s to clear.' );
		$this->assertSame( 'pending', $this->status( $pro2 ) );
		as_unschedule_all_actions( 'buddynext_pro_evnm_replace_cards' );
		as_unschedule_all_actions( 'buddynextpro_ai_mod_sweep' );
	}

	public function test_deactivation_leaves_a_pending_gdpr_purge_continuation(): void {
		$purge  = (int) as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'buddynext_purge_member_continue', array( 42, 'cursor' ), CronScheduler::GROUP );
		$drive  = (int) as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'buddynext_space_drive_media_trash', array( 9, 100, 0 ), CronScheduler::GROUP );
		$digest = (int) as_schedule_single_action( time() + MINUTE_IN_SECONDS, CronScheduler::JOB_DAILY_DIGEST, array(), CronScheduler::GROUP );

		( new CronScheduler() )->clear_events();

		$this->assertSame( 'pending', $this->status( $purge ), 'A committed hard delete is finished after reactivation.' );
		$this->assertSame( 'pending', $this->status( $drive ) );
		$this->assertSame( 'canceled', $this->status( $digest ), 'Recomputable jobs still go.' );
		as_unschedule_all_actions( 'buddynext_purge_member_continue' );
		as_unschedule_all_actions( 'buddynext_space_drive_media_trash' );
	}

	public function test_every_kept_hook_is_one_this_plugin_schedules(): void {
		$src = '';
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' ) ) as $file ) {
			if ( str_ends_with( (string) $file, '.php' ) ) {
				$src .= file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		foreach ( CronScheduler::DEACTIVATION_KEEP as $hook ) {
			$this->assertGreaterThan( 1, substr_count( $src, "'" . $hook . "'" ) + substr_count( $src, $hook ), $hook . ' is a real hook.' );
		}
	}
}
