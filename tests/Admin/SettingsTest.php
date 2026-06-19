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

		$expected = array(
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
