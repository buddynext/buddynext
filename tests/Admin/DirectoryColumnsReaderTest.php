<?php
/**
 * Directory templates read the column setting through the sanitizer.
 *
 * The fix for a bad value landed on save only: the templates had their own fallback to
 * 'auto', so a value that skipped the form (WP-CLI, an import) still rendered auto.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Settings;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Admin\Settings::dir_columns
 */
class DirectoryColumnsReaderTest extends WP_UnitTestCase {

	private const OPTIONS = array( 'buddynext_spaces_dir_columns', 'buddynext_member_dir_columns' );

	public function test_each_stored_value_reads_back_correctly(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
			$this->assertSame( '3', Settings::dir_columns( $option ), 'unset' );

			foreach ( array( 'bogus' => '3', '' => '3', '7' => '3', 'auto' => 'auto', '2' => '2', '3' => '3', '4' => '4' ) as $stored => $read ) {
				global $wpdb;
				// Straight to the table: update_option() would run the registered sanitizer.
				$wpdb->replace( $wpdb->options, array( 'option_name' => $option, 'option_value' => (string) $stored, 'autoload' => 'on' ) );
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$this->assertSame( $read, Settings::dir_columns( $option ), $option . ' stored ' . var_export( (string) $stored, true ) );
			}
		}
	}

	public function test_member_directory_grid_renders_three_for_junk(): void {
		global $wpdb;
		$wpdb->replace( $wpdb->options, array( 'option_name' => 'buddynext_member_dir_columns', 'option_value' => 'bogus', 'autoload' => 'on' ) );
		wp_cache_delete( 'buddynext_member_dir_columns', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/parts/member-directory-grid.php' );
		$this->assertStringContainsString( "Settings::dir_columns( 'buddynext_member_dir_columns' )", $source );
		$spaces = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/spaces/directory.php' );
		$this->assertSame( 2, substr_count( $spaces, "Settings::dir_columns( 'buddynext_spaces_dir_columns' )" ) );
		$this->assertStringNotContainsString( "get_option( 'buddynext_spaces_dir_columns'", $spaces, 'no second reader' );
	}
}
