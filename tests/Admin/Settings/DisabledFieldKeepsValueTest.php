<?php
/**
 * A greyed-out control is not submitted, so saving its screen keeps what is stored.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings;
use BuddyNext\Admin\Settings\Field;

/**
 * Card 10461175127: Media links (disabled without MediaVerse) reported "That
 * choice is not available" on every General save, and saving Social with
 * Reactions off reset the owner's reaction choice to all of them.
 *
 * @covers \BuddyNext\Admin\Settings\Field::sanitizer
 * @covers \BuddyNext\Admin\Settings::sanitize_enabled_reactions
 */
class DisabledFieldKeepsValueTest extends \WP_UnitTestCase {

	private bool $disabled = true;

	private function field(): Field {
		return new Field(
			array(
				'key'               => 'bn_test_gated_select',
				'type'              => 'select',
				'default'           => 'activity',
				'choices'           => array(
					'activity'  => 'Activity',
					'dedicated' => 'Dedicated',
				),
				'disabled_callback' => fn() => $this->disabled,
			)
		);
	}

	private function set_reactions_feature( bool $on ): void {
		$features              = (array) get_option( 'buddynext_features', array() );
		$features['reactions'] = $on;
		update_option( 'buddynext_features', $features );
	}

	public function tear_down(): void {
		delete_option( 'bn_test_gated_select' );
		delete_option( 'buddynext_enabled_reactions' );
		delete_option( 'buddynext_features' );
		global $wp_settings_errors;
		$wp_settings_errors = array();
		parent::tear_down();
	}

	public function test_disabled_select_keeps_its_value_without_an_error(): void {
		update_option( 'bn_test_gated_select', 'dedicated' );

		// options.php passes null for a field the browser did not send.
		$this->assertSame( 'dedicated', call_user_func( $this->field()->sanitizer(), null ) );
		$this->assertSame( array(), get_settings_errors( 'bn_test_gated_select' ) );
	}

	public function test_enabled_select_still_saves_and_still_rejects_unknown_values(): void {
		$this->disabled = false;

		$this->assertSame( 'dedicated', call_user_func( $this->field()->sanitizer(), 'dedicated' ) );
		$this->assertSame( 'activity', call_user_func( $this->field()->sanitizer(), 'nope' ) );
		$this->assertSame( array( 'invalid_choice' ), wp_list_pluck( get_settings_errors( 'bn_test_gated_select' ), 'code' ) );
	}

	public function test_reaction_choice_survives_a_save_while_reactions_are_off(): void {
		update_option( 'buddynext_enabled_reactions', array( 'like', 'love' ) );
		$this->set_reactions_feature( false );

		// The disabled palette posts nothing.
		$this->assertSame( array( 'like', 'love' ), ( new Settings() )->sanitize_enabled_reactions( null ) );
	}

	public function test_unticking_every_reaction_still_keeps_them_all_on(): void {
		$this->set_reactions_feature( true );
		$all = \BuddyNext\Reactions\ReactionService::available_reaction_types();

		$this->assertSame( $all, ( new Settings() )->sanitize_enabled_reactions( null ) );
		$this->assertSame( array( 'like', 'wow' ), ( new Settings() )->sanitize_enabled_reactions( array( 'wow', 'like' ) ) );
	}
}
