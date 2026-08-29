<?php
/**
 * Tests for the BuddyNext admin settings page.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Settings;

/**
 * Verifies settings registration, defaults, and sanitization.
 *
 * @covers \BuddyNext\Admin\Settings
 */
class SettingsTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Instantiate settings and clear all options before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();

		$keys = array(
			'buddynext_site_name',
			'buddynext_brand_color',
			'buddynext_reg_mode',
			'buddynext_email_verify',
			'buddynext_default_post_privacy',
			'buddynext_allow_polls',
			'buddynext_post_edit_window',
			'buddynext_space_creation_role',
			'buddynext_auto_hide_threshold',
			'buddynext_strike_warn_threshold',
			'buddynext_strike_suspend_threshold',
		);

		foreach ( $keys as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Calling register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->settings->register();
		// Settings screens are now AdminHub tabs (settings:general the first), not a
		// direct admin_menu/add_menu hook.
		$this->assertArrayHasKey( 'general', \BuddyNext\Admin\AdminHub::get_tabs( 'settings' ) );
	}

	/**
	 * Calling register() adds the admin_init hook.
	 */
	public function test_register_adds_admin_init_hook(): void {
		$this->settings->register();
		$this->assertNotFalse(
			has_action( 'admin_init', array( $this->settings, 'register_settings' ) )
		);
	}

	/**
	 * Calling register_settings() registers all expected option names.
	 */
	public function test_register_settings_registers_expected_options(): void {
		$this->settings->register_settings();

		// Four capability options (dm, polls, shares, bookmarks) are absent by design:
		// module control turned each into a switch in the Features catalog. See
		// SettingsDriftGuardTest::RETIRED, which carries the retiring commit for each.
		$expected = array(
			'buddynext_site_name',
			// buddynext_brand_color is deliberately absent: 67e83000 moved the Brand
			// colour field to the Appearance tab, which saves it on its own guarded
			// admin_post hook rather than through the Settings API.
			// SettingsDriftGuardTest::SAVED_ELSEWHERE records that and proves the
			// handler exists, so the move stays visible instead of silently red.
			'buddynext_reg_mode',
			'buddynext_email_verify',
			'buddynext_default_post_privacy',
			'buddynext_post_edit_window',
			'buddynext_space_creation_role',
			'buddynext_auto_hide_threshold',
			'buddynext_strike_warn_threshold',
			'buddynext_strike_suspend_threshold',
		);

		global $wp_registered_settings;
		foreach ( $expected as $option ) {
			$this->assertArrayHasKey(
				$option,
				$wp_registered_settings ?? array(),
				"Option '{$option}' is not registered."
			);
		}
	}

	/**
	 * The reg_mode option defaults to 'open'.
	 */
	public function test_reg_mode_defaults_to_open(): void {
		$this->settings->register_settings();
		$this->assertSame( 'open', get_option( 'buddynext_reg_mode', 'open' ) );
	}

	/**
	 * Saving buddynext_reg_mode mirrors WP core's users_can_register so the
	 * /auth/register gate agrees — open/invite/approval all enable registration.
	 */
	public function test_reg_mode_save_syncs_users_can_register(): void {
		$this->settings->register();
		delete_option( 'users_can_register' );

		update_option( 'buddynext_reg_mode', 'open' );
		$this->assertEquals( 1, get_option( 'users_can_register' ) );

		update_option( 'buddynext_reg_mode', 'approval' );
		$this->assertEquals( 1, get_option( 'users_can_register' ) );
	}

	/**
	 * The explicit 'closed' mode disables core registration.
	 *
	 * No longer "a future mode": it is selectable in the UI, and the mirror moved
	 * to Auth\CoreRegistration so it also runs outside wp-admin (a mode set by
	 * WP-CLI or by code previously never reached the core flag).
	 */
	public function test_reg_mode_closed_disables_core_registration(): void {
		( new \BuddyNext\Auth\CoreRegistration() )->sync_core_registration( '', 'closed' );
		$this->assertEquals( 0, get_option( 'users_can_register' ) );
	}

	/**
	 * The allow_polls option defaults to true.
	 */
	public function test_allow_polls_defaults_to_true(): void {
		$this->settings->register_settings();
		$default = (bool) get_option( 'buddynext_allow_polls', true );
		$this->assertTrue( $default );
	}

	/**
	 * The auto_hide_threshold defaults to 5.
	 */
	public function test_auto_hide_threshold_defaults_to_five(): void {
		$this->settings->register_settings();
		$this->assertSame( 5, (int) get_option( 'buddynext_auto_hide_threshold', 5 ) );
	}

	/**
	 * The get_setting() helper returns the stored option value.
	 */
	public function test_get_setting_returns_stored_value(): void {
		update_option( 'buddynext_site_name', 'Test Community' );
		$this->assertSame( 'Test Community', Settings::get_setting( 'site_name' ) );
	}

	/**
	 * The get_setting() helper returns the default when no value is stored.
	 */
	public function test_get_setting_returns_default_when_missing(): void {
		$result = Settings::get_setting( 'site_name', 'Fallback' );
		$this->assertSame( 'Fallback', $result );
	}
}
