<?php
/**
 * Tests that the command-palette index emits field-level entries.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\AdminNavIndex;
use BuddyNext\Admin\Settings;
use BuddyNext\Admin\Settings\SettingsRegistry;

/**
 * Verifies individual settings become searchable, deep-linked entries.
 *
 * @covers \BuddyNext\Admin\AdminNavIndex
 */
class AdminNavIndexFieldTest extends \WP_UnitTestCase {

	/**
	 * Register the free Settings page and an admin user for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		SettingsRegistry::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// register() wires both the AdminHub tabs (needed for field deep-link URLs)
		// and the SettingsRegistry entry the index walks.
		( new Settings() )->register();
	}

	/**
	 * Leave the registry empty for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		SettingsRegistry::reset();
		parent::tear_down();
	}

	/**
	 * The cookie-consent field is indexed and deep-linked to its anchor.
	 *
	 * @return void
	 */
	public function test_cookie_field_is_indexed_with_anchor(): void {
		$index  = AdminNavIndex::build();
		$labels = array_column( $index, 'label' );
		$this->assertContains( 'Show cookie consent notice', $labels );

		$matches = array_filter(
			$index,
			static fn( $entry ) => 'Show cookie consent notice' === $entry['label']
		);
		$entry   = array_shift( $matches );
		$this->assertStringContainsString( '#bn-opt-buddynext_cookie_consent', (string) $entry['url'] );
	}

	/**
	 * The new editable notice-text field is searchable too.
	 *
	 * @return void
	 */
	public function test_notice_text_field_is_indexed(): void {
		$labels = array_column( AdminNavIndex::build(), 'label' );
		$this->assertContains( 'Notice text', $labels );
	}
}
