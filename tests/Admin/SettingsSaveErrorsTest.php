<?php
/**
 * A sanitizer's message survives the options.php redirect onto BuddyNext tabs.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Settings;

/**
 * @covers \BuddyNext\Admin\Settings
 */
class SettingsSaveErrorsTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		unset( $_GET['settings-updated'] );
		delete_transient( 'settings_errors' );
		global $wp_settings_errors;
		$wp_settings_errors = array();
		parent::tear_down();
	}

	public function test_a_field_message_from_the_save_is_shown_on_the_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// What options.php stores before redirecting back with ?settings-updated=true.
		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => 'buddynext_reg_mode',
					'code'    => 'invalid_choice',
					'message' => 'That choice is not available, so the setting was not changed.',
					'type'    => 'error',
				),
			),
			30
		);
		$_GET['settings-updated'] = 'true';

		$render = new \ReflectionMethod( Settings::class, 'render_settings_tab' );
		ob_start();
		$render->invoke( new Settings(), 'privacy' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'That choice is not available, so the setting was not changed.', $html );
		// As in core: a refused value and a green confirmation never appear together.
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	public function test_an_email_setting_that_is_not_an_email_keeps_its_value_and_says_so(): void {
		update_option( 'buddynext_admin_alert_email', 'owner@example.test' );
		$field = new \BuddyNext\Admin\Settings\Field(
			array(
				'key'      => 'buddynext_admin_alert_email',
				'type'     => 'text',
				'sanitize' => 'sanitize_email',
			)
		);
		$clean = $field->sanitizer();

		$this->assertSame( 'owner@example.test', $clean( 'not-an-email' ), 'The stored address is kept, not emptied.' );
		$codes = wp_list_pluck( get_settings_errors( 'buddynext_admin_alert_email' ), 'code' );
		$this->assertContains( 'invalid_email', $codes );

		$this->assertSame( 'new@example.test', $clean( ' new@example.test ' ), 'A valid address is saved.' );
		$this->assertSame( '', $clean( '' ), 'Empty stays empty: the field uses the WordPress admin email then.' );
	}
}
