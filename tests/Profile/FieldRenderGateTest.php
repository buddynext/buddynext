<?php
/**
 * Tests for the class-level profile-field render gate (card 10134744714).
 *
 * Every profile surface renders through FieldType::is_profile_field_active()
 * / render_profile_input(), so render and save consult the SAME
 * `buddynext_profile_field_is_active` predicate. These tests pin the helper:
 * if the gate stops answering the filter, or the inactive branch starts
 * emitting an editable control again, they fail by name.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;

/**
 * @covers \BuddyNext\Profile\FieldType
 */
class FieldRenderGateTest extends \WP_UnitTestCase {

	private const FIELD = array(
		'field_key'   => 'gate_probe',
		'type'        => 'text',
		'label'       => 'Gate Probe',
		'is_required' => 1,
	);

	public function test_active_field_renders_an_editable_control(): void {
		$html = FieldType::render_profile_input( self::FIELD, 'v', 'gate_probe', 5 );

		$this->assertStringContainsString( '<input', $html );
		$this->assertStringNotContainsString( 'bn-ep-field-locked', $html );
	}

	public function test_inactive_field_renders_locked_message_and_no_control(): void {
		add_filter( 'buddynext_profile_field_is_active', '__return_false' );

		$html = FieldType::render_profile_input( self::FIELD, 'v', 'gate_probe', 5 );

		remove_filter( 'buddynext_profile_field_is_active', '__return_false' );

		$this->assertStringContainsString( 'bn-ep-field-locked', $html );
		// No editable/postable control of any kind - nothing may be posted
		// for an inactive field, and `required` must not be injectable onto it.
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<select', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_predicate_passes_field_and_user_to_the_filter(): void {
		$seen = array();

		$probe = function ( $active, $field, $data, $user_id ) use ( &$seen ) {
			$seen = array( $field['field_key'], $user_id );
			return $active;
		};

		add_filter( 'buddynext_profile_field_is_active', $probe, 10, 4 );
		$active = FieldType::is_profile_field_active( self::FIELD, 42 );
		remove_filter( 'buddynext_profile_field_is_active', $probe );

		$this->assertTrue( $active );
		$this->assertSame( array( 'gate_probe', 42 ), $seen );
	}

	public function test_anonymous_user_id_zero_reaches_the_filter(): void {
		// Signup / account-completion render for a user that does not exist
		// yet; the gate must hand the filter user 0, not the current user.
		$seen_uid = null;

		$probe = function ( $active, $field, $data, $user_id ) use ( &$seen_uid ) {
			$seen_uid = $user_id;
			return $active;
		};

		add_filter( 'buddynext_profile_field_is_active', $probe, 10, 4 );
		FieldType::is_profile_field_active( self::FIELD, 0 );
		remove_filter( 'buddynext_profile_field_is_active', $probe );

		$this->assertSame( 0, $seen_uid );
	}
}
