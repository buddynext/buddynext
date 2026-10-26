<?php
/**
 * A select setting stores only one of its declared choices.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings\Field;

/**
 * @covers \BuddyNext\Admin\Settings\Field::sanitize_select
 */
class SelectChoiceSanitizeTest extends \WP_UnitTestCase {

	private function field(): Field {
		return new Field(
			array(
				'key'     => 'bn_test_select_mode',
				'type'    => 'select',
				'default' => 'open',
				'choices' => array(
					'open'     => 'Open',
					'approval' => 'Approval',
					'closed'   => 'Closed',
				),
			)
		);
	}

	public function tear_down(): void {
		delete_option( 'bn_test_select_mode' );
		global $wp_settings_errors;
		$wp_settings_errors = array();
		parent::tear_down();
	}

	public function test_a_declared_choice_is_stored(): void {
		$this->assertSame( 'closed', call_user_func( $this->field()->sanitizer(), 'closed' ) );
	}

	public function test_an_unknown_value_keeps_the_saved_choice_and_says_so(): void {
		update_option( 'bn_test_select_mode', 'approval' );
		$this->assertSame( 'approval', call_user_func( $this->field()->sanitizer(), 'invalid_unauthorized_mode' ) );
		$this->assertSame( array( 'invalid_choice' ), wp_list_pluck( get_settings_errors( 'bn_test_select_mode' ), 'code' ) );
	}

	public function test_an_unknown_value_with_nothing_saved_falls_back_to_the_default(): void {
		$this->assertSame( 'open', call_user_func( $this->field()->sanitizer(), 'nope' ) );
	}
}
