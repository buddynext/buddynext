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

	public function test_a_kept_job_that_failed_while_the_plugin_was_off_is_requeued_once(): void {
		global $wp_filter;
		$hook  = 'buddynext_purge_member_continue';
		$saved = $wp_filter[ $hook ] ?? null;
		$args  = array( 42, 'cursor' );
		$lost  = (int) as_schedule_single_action( time() - 1, $hook, $args, CronScheduler::GROUP );
		$real  = (int) as_schedule_single_action( time() - 1, $hook, array( 43, 'cursor' ), CronScheduler::GROUP );
		( new CronScheduler() )->clear_events();

		// Plugin off: another plugin's runner finds no listener.
		remove_all_actions( $hook );
		\ActionScheduler::runner()->process_action( $lost, 'test' );
		// Plugin on, and this job really errors.
		add_action(
			$hook,
			static function () {
				throw new \RuntimeException( 'database gone away' );
			}
		);
		\ActionScheduler::runner()->process_action( $real, 'test' );
		$this->assertSame( 'failed', $this->status( $lost ) );
		$this->assertSame( 'failed', $this->status( $real ) );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 1, CronScheduler::requeue_orphaned( CronScheduler::DEACTIVATION_KEEP ) );
		$this->assertSame( 0, CronScheduler::requeue_orphaned( CronScheduler::DEACTIVATION_KEEP ), 'Once.' );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( '', $this->status( $lost ), 'The failed row is gone.' );
		$this->assertSame( 'failed', $this->status( $real ), 'A job that really ran and errored stays failed.' );
		$pending = as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'group' => CronScheduler::GROUP ) );
		$this->assertCount( 1, $pending );
		$this->assertSame( $args, array_values( reset( $pending )->get_args() ) );

		$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		as_unschedule_all_actions( $hook );
	}

	public function test_requeue_is_gated_bounded_by_age_and_never_drops_a_row_it_could_not_requeue(): void {
		global $wpdb;
		$hook = 'buddynext_resync_hashtags';
		add_action( $hook, '__return_null' );
		$ids = array();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$ids[ $n ] = (int) as_schedule_single_action( time() - 1, $hook, array( $n ), CronScheduler::GROUP );
			$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'status' => 'failed', 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s' ) ), array( 'action_id' => $ids[ $n ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $wpdb->prefix . 'actionscheduler_logs', array( 'action_id' => $ids[ $n ], 'message' => "action failed via WP Cron: Scheduled action for {$hook} will not be executed as no callbacks are registered.", 'log_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'log_date_local' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// Row 3 failed a month ago: never replayed.
		$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'last_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'action_id' => $ids[3] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_transient( CronScheduler::REQUEUE_FLAG );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( 0, CronScheduler::requeue_after_activation( CronScheduler::REQUEUE_FLAG, array( $hook ) ), 'No pass owed.' );

		// The store refuses the copy for row 1: its failed row stays.
		$refuse = static fn( $pre, $h, $args ) => array( 1 ) === array_values( (array) $args ) ? 0 : $pre;
		add_filter( 'pre_as_enqueue_async_action', $refuse, 10, 3 );
		CronScheduler::flag_requeue();
		$this->assertSame( 1, CronScheduler::requeue_after_activation( CronScheduler::REQUEUE_FLAG, array( $hook ) ) );
		remove_filter( 'pre_as_enqueue_async_action', $refuse, 10 );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertFalse( get_transient( CronScheduler::REQUEUE_FLAG ), 'Fewer than a batch: the flag is cleared.' );
		$this->assertSame( 'failed', $this->status( $ids[1] ), 'Not re-queued, so not deleted.' );
		$this->assertSame( '', $this->status( $ids[2] ) );
		$this->assertSame( 'failed', $this->status( $ids[3] ), 'Older than REQUEUE_DAYS.' );
		$this->assertTrue( CronScheduler::has_pending( $hook, array( 2 ) ) );
		$this->assertTrue( CronScheduler::enqueue_once( $hook, array( 2 ) ), 'True when a copy is already waiting.' );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => $hook, 'args' => array( 2 ), 'status' => 'pending' ), 'ids' ) );
		remove_action( $hook, '__return_null' );
		as_unschedule_all_actions( $hook );
	}

	public function test_a_row_whose_copy_already_ran_is_not_queued_again_by_a_slower_request(): void {
		global $wpdb;
		$hook = 'buddynext_resync_hashtags';
		$runs = 0;
		$cb   = static function () use ( &$runs ) {
			++$runs;
		};
		remove_all_actions( $hook );
		$id = (int) as_schedule_single_action( time() - 1, $hook, array( 9 ), CronScheduler::GROUP );
		\ActionScheduler::runner()->process_action( $id, 'test' ); // Plugin off: fails through the real DBStore.
		$this->assertSame( 'failed', $this->status( $id ) );
		add_action( $hook, $cb );

		// Request B has read the failed row. Before its next query runs, request A
		// re-queues the same row and a runner completes A's copy.
		$armed = false;
		$race  = static function ( $sql ) use ( &$armed, $hook ) {
			if ( false !== strpos( $sql, 'EXISTS ( SELECT 1' ) && 0 === did_action( 'bn_test_race_done' ) && ! $armed ) {
				$armed = true; // B's SELECT; the race runs before B's next query.
				return $sql;
			}
			if ( $armed ) {
				$armed = false;
				do_action( 'bn_test_race_done' );
				CronScheduler::requeue_orphaned( array( $hook ) ); // Request A.
				foreach ( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending' ), 'ids' ) as $copy ) {
					\ActionScheduler::runner()->process_action( (int) $copy, 'test' );
				}
			}
			return $sql;
		};
		add_filter( 'query', $race );
		CronScheduler::requeue_orphaned( array( $hook ) ); // Request B.
		remove_filter( 'query', $race );
		foreach ( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending' ), 'ids' ) as $copy ) {
			\ActionScheduler::runner()->process_action( (int) $copy, 'test' );
		}

		$this->assertSame( 1, $runs, 'The job ran once, not once per request.' );
		$this->assertCount( 0, as_get_scheduled_actions( array( 'hook' => $hook, 'status' => array( 'failed', 'pending' ) ), 'ids' ) );
		remove_action( $hook, $cb );
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
