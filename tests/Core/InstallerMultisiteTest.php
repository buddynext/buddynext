<?php
/**
 * On a network, BuddyNext installs on the main site only.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * One community lives on the network's main site. Adding a site creates nothing,
 * and network activation installs the main site once. Run with WP_MULTISITE=1.
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

	public function test_a_site_added_to_the_network_gets_no_tables(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		global $wpdb;

		Installer::run();
		$site_id = (int) self::factory()->blog->create();
		$prefix  = $wpdb->get_blog_prefix( $site_id );

		$this->assertTrue( $this->table_exists( $wpdb->get_blog_prefix( get_main_site_id() ) . 'bn_posts' ), 'The main site is installed.' );
		foreach ( Installer::OWNED_TABLES as $table ) {
			$this->assertFalse( $this->table_exists( $prefix . $table ), "{$table} must not exist on a subsite." );
		}
		$this->assertFalse( $this->table_exists( $prefix . 'actionscheduler_actions' ), 'No Action Scheduler tables on a subsite.' );
		wp_delete_site( $site_id );
	}

	public function test_the_per_site_install_hooks_are_gone(): void {
		$this->assertFalse( method_exists( Installer::class, 'register_multisite' ) );
		$this->assertFalse( method_exists( Installer::class, 'install_new_site' ) );
		$this->assertFalse( method_exists( Installer::class, 'site_tables' ) );
		$this->assertFalse( has_action( 'wp_initialize_site', array( Installer::class, 'install_new_site' ) ) );
	}

	public function test_install_if_missing_installs_once_and_then_does_nothing(): void {
		delete_option( 'buddynext_schema_version' );

		Installer::install_if_missing();
		$stamped = get_option( 'buddynext_schema_version' );
		$this->assertNotFalse( $stamped, 'First call installs and stamps the schema version.' );

		$calls = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( &$calls ) {
				if ( preg_match( '/^\s*CREATE TABLE/i', (string) $sql ) ) {
					++$calls;
				}
				return $sql;
			}
		);
		Installer::install_if_missing();
		$this->assertSame( 0, $calls, 'Once installed it is a single option read.' );
	}
}
