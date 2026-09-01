<?php
/**
 * A value nothing can decode must not reach any surface.
 *
 * When Pro is deactivated — a lapsed licence, a plan downgrade, or simply a site
 * that never bought it — fields keep their stored type (`location`,
 * `multi_select_advanced`, …) while the code that reads those types is gone.
 * `resolve_type()` degrades them to `text`, and a text value is printed verbatim.
 * So a location field becomes:
 *
 *   {"address":"Pune, Maharashtra, India","lat":18.5204,"lng":73.8567}
 *
 * `is_unreadable_payload()` exists for exactly this, and three of the four places
 * that turn a value into output called it: `render_input()`, `display_text()` and
 * `searchable_text()`. The fourth — `render_display()`, which renders the profile
 * About tab — did not.
 *
 * That is the one that faced the public. Measured on a Pro-deactivated site, the
 * About tab printed the JSON above to any visitor, logged out included, because
 * the field's visibility is `public`. A member who chose to share "Pune" had
 * their exact coordinates published instead, and no screen in the product told
 * anyone it was happening.
 *
 * ## Why this is driven off all four entry points
 *
 * A test that only covered `render_display()` would have passed before the bug
 * existed and after it was fixed, and taught the next person nothing about why
 * the other three matter. The guard is a property of the boundary, not of one
 * function, so every function on that boundary is asserted here — and a fifth
 * one added later has an obvious place to be added.
 *
 * ## The half that is easy to get wrong
 *
 * Not every value of an unregistered type is unreadable. A `date_extended`
 * holding `1985-11-03`, a `number_advanced` holding `42.5`, a `file` holding a
 * URL — all perfectly readable strings that must keep displaying, or
 * deactivating Pro would blank swathes of every member's profile. Only
 * STRUCTURED storage is hidden. Both halves are asserted.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_UnitTestCase;

/**
 * Undecodable payloads on every output boundary.
 *
 * @covers \BuddyNext\Profile\FieldType::render_display
 * @covers \BuddyNext\Profile\FieldType::is_unreadable_payload
 */
class UnreadablePayloadNeverSurfacesTest extends WP_UnitTestCase {

	/**
	 * A location value exactly as Pro stores it.
	 *
	 * @var string
	 */
	private const PAYLOAD = '{"address":"Pune, Maharashtra, India","lat":18.5204,"lng":73.8567}';

	/**
	 * A field definition of a type nothing has registered.
	 *
	 * @return array<string, mixed>
	 */
	private function orphaned_field(): array {
		return array(
			'field_key'  => 'fa_location',
			'label'      => 'Where I am',
			'type'       => 'location',
			'visibility' => 'public',
		);
	}

	/**
	 * The premise: with Pro absent, `location` is not a registered type.
	 *
	 * Free's suite does not load Pro, so this is the real condition rather than a
	 * simulation of it. If Pro ever were loaded here, every assertion below would
	 * pass for the wrong reason.
	 *
	 * @return void
	 */
	public function test_the_premise_holds(): void {
		$this->assertFalse(
			FieldType::is_registered_type( 'location' ),
			'Pro appears to be loaded in Free\'s suite; these tests would prove nothing.'
		);
		$this->assertTrue(
			FieldType::is_unreadable_payload( $this->orphaned_field(), self::PAYLOAD ),
			'The guard does not recognise a stored location payload as unreadable.'
		);
	}

	/**
	 * The public profile renderer shows nothing. This is the bug.
	 *
	 * @return void
	 */
	public function test_the_public_profile_shows_no_payload(): void {
		$this->assertSame(
			'',
			FieldType::render_display( $this->orphaned_field(), self::PAYLOAD ),
			'The About tab is publishing storage internals - a member who shared a city name has had their exact coordinates put on a public page.'
		);
	}

	/**
	 * Every other output boundary agrees.
	 *
	 * @return void
	 */
	public function test_no_output_boundary_leaks_the_payload(): void {
		$field = $this->orphaned_field();

		$this->assertSame( '', FieldType::display_text( $field, self::PAYLOAD ), 'display_text() leaked the payload.' );
		$this->assertSame( '', FieldType::searchable_text( $field, self::PAYLOAD ), 'searchable_text() would index the payload, making members findable by searching "lat".' );

		$input = FieldType::render_input( $field, self::PAYLOAD, 'fa_location' );
		$this->assertStringContainsString(
			'bn-field--unavailable',
			$input,
			'The edit control is not the protective panel.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input(?![^>]*type="hidden")[^>]*value="[^"]*address/',
			$input,
			'The payload is in a VISIBLE input, one keystroke from being destroyed.'
		);
	}

	/**
	 * A READABLE value of the same unregistered type still displays.
	 *
	 * Guards the guard, and it is the half that would hurt most if it broke:
	 * hiding everything whose type is unregistered would blank a large part of
	 * every member's profile the moment a licence lapsed.
	 *
	 * @return void
	 */
	public function test_readable_values_of_an_unregistered_type_still_display(): void {
		$cases = array(
			'date_extended'         => '1985-11-03',
			'number_advanced'       => '42.5',
			'file'                  => 'https://example.com/cv.pdf',
			'multi_select_advanced' => 'Alpha,Beta',
		);

		foreach ( $cases as $type => $value ) {
			$field = array(
				'field_key'  => 'fa_' . $type,
				'label'      => strtoupper( $type ),
				'type'       => $type,
				'visibility' => 'public',
			);

			$this->assertFalse(
				FieldType::is_unreadable_payload( $field, $value ),
				sprintf( 'A plain %s value was judged unreadable.', $type )
			);
			$this->assertStringContainsString(
				$value,
				FieldType::render_display( $field, $value ),
				sprintf( 'A readable %s value vanished from the profile because its type is unregistered.', $type )
			);
		}
	}

	/**
	 * A JSON payload on a REGISTERED type is untouched.
	 *
	 * The guard keys on the type being unregistered, not on the value looking like
	 * JSON. A registered type that legitimately stores structured data must keep
	 * rendering through its own branch.
	 *
	 * @return void
	 */
	public function test_a_registered_type_is_not_caught_by_the_guard(): void {
		$field = array(
			'field_key'  => 'interests',
			'label'      => 'Interests',
			'type'       => 'multiselect',
			'visibility' => 'public',
		);

		$this->assertFalse(
			FieldType::is_unreadable_payload( $field, '["alpha","beta"]' ),
			'A registered type was treated as unreadable, which would hide working fields.'
		);
	}
}
