<?php
/**
 * Profile-editor labels point at controls that exist.
 *
 * Regression cover for the WCAG 2.1 SC 1.3.1 / 4.1.2 defect where every dynamic
 * profile field's `<label for>` referenced an id nothing carried. The id was
 * derived in two files that cannot see each other — render_input() built
 * `bn-field-` + sanitize_html_class( $name ), the editor template built `bn-ep-`
 * + the key with underscores dashed — so they differed twice over. Clicking a
 * label focused nothing and a screen reader could not name the control.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;

/**
 * The id contract shared by render_input() and the editor template.
 *
 * @covers \BuddyNext\Profile\FieldType::input_id
 * @covers \BuddyNext\Profile\FieldType::has_labelable_control
 */
class FieldLabelAssociationTest extends \WP_UnitTestCase {

	/**
	 * The id helper matches the id render_input() actually emits.
	 *
	 * This is the assertion that stops the two sides drifting again: it reads the
	 * real rendered markup rather than trusting the helper in isolation.
	 *
	 * @return void
	 */
	public function test_input_id_matches_the_rendered_control_id(): void {
		foreach ( array( 'bio', 'work_experience[0][work_company]' ) as $name ) {
			$html = FieldType::render_input( array( 'type' => 'text' ), '', $name );
			$id   = FieldType::input_id( $name );

			$this->assertStringContainsString(
				'id="' . $id . '"',
				$html,
				"render_input() must emit the id input_id() promises for {$name}."
			);
		}
	}

	/**
	 * Repeater entries get distinct ids per entry index.
	 *
	 * @return void
	 */
	public function test_repeater_entries_get_distinct_ids(): void {
		$first  = FieldType::input_id( 'work_experience[0][work_company]' );
		$second = FieldType::input_id( 'work_experience[1][work_company]' );

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Group types have no labelable control, so their label must omit `for`.
	 *
	 * These render a <fieldset> of individually-labelled options — no element
	 * carries the field-level id, and pointing a label at a fieldset is invalid
	 * HTML that gives the group no accessible name.
	 *
	 * @return void
	 */
	public function test_group_types_have_no_labelable_control(): void {
		foreach ( array( 'radio', 'multiselect', 'category_multiselect', 'member_type_multiselect', 'member_type' ) as $type ) {
			$this->assertFalse( FieldType::has_labelable_control( $type ), "{$type} renders a fieldset." );
		}
	}

	/**
	 * A boolean self-labels, so the editor must not add a second label.
	 *
	 * @return void
	 */
	public function test_boolean_self_labels(): void {
		$this->assertFalse( FieldType::has_labelable_control( 'boolean' ) );
		$this->assertStringContainsString(
			'<label',
			FieldType::render_input( array( 'type' => 'boolean' ), '', 'work_current' ),
			'The boolean control carries its own implicit label.'
		);
	}

	/**
	 * Ordinary single-control types DO get a `for`.
	 *
	 * Mutation guard: an implementation that returned false for everything would
	 * satisfy every assertion above and strip `for` from the whole form.
	 *
	 * @return void
	 */
	public function test_single_control_types_are_labelable(): void {
		foreach ( array( 'text', 'textarea', 'url', 'email', 'phone', 'number', 'date', 'select', 'color' ) as $type ) {
			$this->assertTrue( FieldType::has_labelable_control( $type ), "{$type} has one labelable control." );
		}
	}
}
