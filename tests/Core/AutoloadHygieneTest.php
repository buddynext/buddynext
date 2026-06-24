<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the autoload-hygiene migration (change-index item G).
 *
 * Per-space settings (bn_space_*) and the custom-CSS blob were historically
 * autoloaded, so they loaded into alloptions on every request. The v8 migration
 * flips them to autoload=off. These tests pin: the right options flip, unrelated
 * options are untouched, and get_option() reads are unaffected (autoload-agnostic).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Autoload migration behaviour.
 */
class AutoloadHygieneTest extends WP_UnitTestCase {

	/**
	 * Whether an option row is currently autoloaded (cross-WP-version values).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_autoloaded( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return in_array( (string) $v, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * Invoke the private migration.
	 *
	 * @return void
	 */
	private function run_migration(): void {
		$m = new ReflectionMethod( Installer::class, 'maybe_fix_autoload' );
		$m->setAccessible( true );
		$m->invoke( null );
	}

	/**
	 * The migration flips per-space + custom-CSS options off and leaves others on.
	 *
	 * @return void
	 */
	public function test_migration_flips_targeted_options_only(): void {
		add_option( 'bn_space_7_who_can_post', 1 );    // default autoload on.
		add_option( 'buddynext_custom_css', 'body{}' ); // default on.
		add_option( 'unrelated_keep_on', 'x' );         // control — must stay on.

		$this->assertTrue( $this->is_autoloaded( 'bn_space_7_who_can_post' ), 'Precondition: space option autoloaded.' );

		$this->run_migration();

		$this->assertFalse( $this->is_autoloaded( 'bn_space_7_who_can_post' ), 'Space option must flip off.' );
		$this->assertFalse( $this->is_autoloaded( 'buddynext_custom_css' ), 'Custom CSS must flip off.' );
		$this->assertTrue( $this->is_autoloaded( 'unrelated_keep_on' ), 'Unrelated option must stay on.' );
	}

	/**
	 * Reads are autoload-agnostic — values survive the flip unchanged.
	 *
	 * @return void
	 */
	public function test_reads_unaffected_by_autoload_flip(): void {
		add_option( 'bn_space_9_require_join_approval', 1 );
		add_option( 'buddynext_custom_css', '.x{color:red}' );

		$this->run_migration();

		$this->assertSame( '1', (string) get_option( 'bn_space_9_require_join_approval' ) );
		$this->assertSame( '.x{color:red}', get_option( 'buddynext_custom_css' ) );
	}

	/**
	 * The migration is idempotent — a second run is a harmless no-op.
	 *
	 * @return void
	 */
	public function test_migration_idempotent(): void {
		add_option( 'bn_space_3_banned_words', 'spam' );

		$this->run_migration();
		$this->run_migration();

		$this->assertFalse( $this->is_autoloaded( 'bn_space_3_banned_words' ) );
		$this->assertSame( 'spam', get_option( 'bn_space_3_banned_words' ) );
	}
}
