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
		$this->assertStringContainsString( 'Settings saved.', $html );
	}
}
