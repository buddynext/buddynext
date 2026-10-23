<?php
/**
 * Free's uninstall deletes only Free's jobs, and each site keeps its own choice.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * The jobs sweep matched `buddynext%`, which is also Pro's prefix, and the
 * delete-data opt-in was read once on the main site for the whole network.
 * The network case runs with WP_MULTISITE=1.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallScopeTest extends \WP_UnitTestCase {

	/**
	 * Run Free's real uninstall script.
	 *
	 * @return void
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'buddynext/buddynext.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_pro_jobs_survive_a_free_uninstall(): void {
		global $wpdb;
		delete_option( 'buddynext_delete_data_on_uninstall' );
		foreach ( array( 'buddynext_digest_weekly', 'buddynextpro_drip_tick', 'buddynext_pro_learnomy_backfill_chunk' ) as $hook ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'actionscheduler_actions',
				array(
					'hook'                 => $hook,
					'status'               => 'pending',
					'scheduled_date_gmt'   => gmdate( 'Y-m-d H:i:s' ),
					'scheduled_date_local' => gmdate( 'Y-m-d H:i:s' ),
					'args'                 => '[]',
				)
			);
		}

		$this->uninstall();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hooks = $wpdb->get_col( "SELECT hook FROM {$wpdb->prefix}actionscheduler_actions" );
		$this->assertNotContains( 'buddynext_digest_weekly', $hooks, 'Free\'s own job goes.' );
		$this->assertContains( 'buddynextpro_drip_tick', $hooks );
		$this->assertContains( 'buddynext_pro_learnomy_backfill_chunk', $hooks );
	}

	public function test_each_site_of_a_network_keeps_its_own_delete_data_choice(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}

		// Main site opted in; a second site did not. Hub pages go only on opt-in.
		update_option( 'buddynext_delete_data_on_uninstall', '1' );
		$main_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'buddynext_page_activity', $main_page );

		$site_id = (int) self::factory()->blog->create();
		switch_to_blog( $site_id );
		delete_option( 'buddynext_delete_data_on_uninstall' );
		$site_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'buddynext_page_activity', $site_page );
		restore_current_blog();

		$this->uninstall();

		$this->assertNull( get_post( $main_page ), 'The site that opted in is wiped.' );
		switch_to_blog( $site_id );
		$this->assertNotNull( get_post( $site_page ), 'The site that did not opt in keeps its pages.' );
		restore_current_blog();
	}
}
