<?php
/**
 * A site added to or deleted from a network gets, or loses, its BuddyNext tables.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * Network-activated BuddyNext used to create tables for a new site only on its
 * first wp-admin visit, so front-end requests before that hit missing tables, and
 * a deleted site left every bn_* table behind. Run with WP_MULTISITE=1.
 */
class InstallerMultisiteTest extends \WP_UnitTestCase {

	/**
	 * Whether a table can be read. The test suite creates TEMPORARY tables, which
	 * SHOW TABLES does not list, so ask the table itself.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = false !== $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 1" );
		$wpdb->suppress_errors( $suppress );
		return $ok;
	}

	public function test_site_tables_lists_every_owned_and_legacy_table_with_the_site_prefix(): void {
		global $wpdb;

		$tables = Installer::site_tables( array( 'core_table' ), 7 );
		$prefix = $wpdb->get_blog_prefix( 7 );

		$this->assertContains( 'core_table', $tables, 'Core\'s own list is kept.' );
		foreach ( array_merge( Installer::OWNED_TABLES, Installer::LEGACY_TABLES ) as $table ) {
			$this->assertContains( $prefix . $table, $tables );
		}
	}

	public function test_a_new_site_gets_its_tables_and_a_deleted_site_drops_them(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		global $wpdb;

		$network_plugins                     = (array) get_site_option( 'active_sitewide_plugins', array() );
		$network_plugins[ BUDDYNEXT_BASENAME ] = time();
		update_site_option( 'active_sitewide_plugins', $network_plugins );

		$site_id = (int) self::factory()->blog->create();
		$table   = $wpdb->get_blog_prefix( $site_id ) . 'bn_posts';

		$this->assertTrue( $this->table_exists( $table ), 'Created with the site.' );
		switch_to_blog( $site_id );
		$this->assertTrue( as_has_scheduled_action( 'buddynext_reindex_all' ), 'First-run jobs are queued on the new site, not dropped.' );
		restore_current_blog();

		wp_delete_site( $site_id );

		$this->assertFalse( $this->table_exists( $table ), 'Dropped with the site.' );
	}

	public function test_network_activation_installs_every_existing_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		global $wpdb;

		$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $network_plugins[ BUDDYNEXT_BASENAME ] );
		update_site_option( 'active_sitewide_plugins', $network_plugins );

		$site_id = (int) self::factory()->blog->create();
		$table   = $wpdb->get_blog_prefix( $site_id ) . 'bn_posts';
		$this->assertFalse( $this->table_exists( $table ), 'Not installed before activation.' );

		Installer::activate( true );

		$this->assertTrue( $this->table_exists( $table ), 'Installed by network activation.' );
		wp_delete_site( $site_id );
	}

	public function test_a_new_site_on_a_network_without_buddynext_active_is_left_alone(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		global $wpdb;

		$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $network_plugins[ BUDDYNEXT_BASENAME ] );
		update_site_option( 'active_sitewide_plugins', $network_plugins );

		$site_id = (int) self::factory()->blog->create();
		$table   = $wpdb->get_blog_prefix( $site_id ) . 'bn_posts';

		$this->assertFalse( $this->table_exists( $table ) );
		wp_delete_site( $site_id );
	}
}
