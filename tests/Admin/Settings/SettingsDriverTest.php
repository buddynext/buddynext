<?php
/**
 * Tests for the SettingsDriver registration + save-group derivation.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings\Field;
use BuddyNext\Admin\Settings\Section;
use BuddyNext\Admin\Settings\SettingsDriver;
use BuddyNext\Admin\Settings\SettingsRegistry;

/**
 * Verifies register_setting() derivation and save-group resolution.
 *
 * @covers \BuddyNext\Admin\Settings\SettingsDriver
 */
class SettingsDriverTest extends \WP_UnitTestCase {

	/**
	 * Start each test with an empty registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		SettingsRegistry::reset();
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
	 * Build a page with a single cookie-consent toggle under the privacy tab.
	 *
	 * @return StubSettingsPage
	 */
	private function page(): StubSettingsPage {
		return new StubSettingsPage(
			array(
				new Section(
					'privacy',
					'Cookie',
					array(
						new Field(
							array(
								'key'   => 'buddynext_cookie_consent',
								'type'  => 'toggle',
								'label' => 'X',
							)
						),
					)
				),
			)
		);
	}

	/**
	 * Registers each field under its tab's option group.
	 *
	 * @return void
	 */
	public function test_register_page_registers_each_field_under_tab_group(): void {
		$page = $this->page();
		SettingsRegistry::register( $page );
		SettingsDriver::register_page( $page, 'buddynext' );

		global $wp_registered_settings;
		$this->assertArrayHasKey( 'buddynext_cookie_consent', $wp_registered_settings );
		$this->assertSame(
			'buddynext_privacy',
			SettingsDriver::save_group_of( 'buddynext_cookie_consent', 'buddynext' )
		);
	}

	/**
	 * An unknown key resolves to the bare prefix group.
	 *
	 * @return void
	 */
	public function test_unknown_key_falls_back_to_prefix_group(): void {
		SettingsRegistry::register( $this->page() );
		$this->assertSame( 'buddynext', SettingsDriver::save_group_of( 'not_a_key', 'buddynext' ) );
	}
}
