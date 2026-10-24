<?php
/**
 * Free's uninstall deletes only Free's jobs, and on a network only the main site counts.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * The jobs sweep matched `buddynext%`, which is also Pro's prefix, and the
 * delete-data opt-in was read per site although the community lives on one.
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

	public function test_a_subsite_opt_in_cannot_wipe_network_wide_member_meta(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}

		delete_option( 'buddynext_delete_data_on_uninstall' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_onboarding_done', '1' );

		$site_id = (int) self::factory()->blog->create();
		switch_to_blog( $site_id );
		update_option( 'buddynext_delete_data_on_uninstall', '1' );
		restore_current_blog();

		$this->uninstall();

		$this->assertSame( '1', get_user_meta( $user_id, 'bn_onboarding_done', true ), 'Only the network owner\'s choice reaches user meta.' );
	}

	public function test_the_main_site_opt_in_wipes_member_meta(): void {
		update_option( 'buddynext_delete_data_on_uninstall', '1' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_onboarding_done', '1' );

		$this->uninstall();

		$this->assertSame( '', get_user_meta( $user_id, 'bn_onboarding_done', true ) );
	}

	public function test_uninstall_on_a_network_cleans_the_main_site_and_leaves_subsites_alone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}

		update_option( 'buddynext_delete_data_on_uninstall', '1' );
		$main_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'buddynext_page_activity', $main_page );

		// A subsite has no community, so nothing of ours is there to clean, even
		// if someone set the option by hand.
		$site_id = (int) self::factory()->blog->create();
		switch_to_blog( $site_id );
		update_option( 'buddynext_delete_data_on_uninstall', '1' );
		$site_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'buddynext_page_activity', $site_page );
		restore_current_blog();

		$this->uninstall();

		$this->assertNull( get_post( $main_page ), 'The main site, where the community lives, is wiped.' );
		switch_to_blog( $site_id );
		$this->assertNotNull( get_post( $site_page ), 'A subsite page is not touched.' );
		$this->assertSame( (string) $site_page, (string) get_option( 'buddynext_page_activity' ), 'A subsite option is not touched.' );
		restore_current_blog();
	}
}
