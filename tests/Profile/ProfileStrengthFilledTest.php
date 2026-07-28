<?php
/**
 * Profile completeness counts only what the member actually entered.
 *
 * Regression cover for the "Profile Strength ticks sections I never filled"
 * report. `boolean` is the only field type whose sanitiser turns EMPTY input into
 * a non-empty stored value — FieldType::sanitize() returns '0' for an unticked
 * box — and both completeness predicates (get_strength()'s closure and
 * get_completion_score()'s SQL) tested only for a non-empty string. The seeded
 * schema's only booleans are work_current and edu_current, which is exactly why
 * Work Experience and Education ticked on an empty profile while Social Links,
 * Skills and Interests did not.
 *
 * The engine already disagreed with itself: render_display() returns '' for a
 * falsy boolean, so the profile VIEW showed the section empty while the strength
 * widget called it complete. FieldType::is_filled() is the single answer all three
 * now share.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use BuddyNext\Profile\ProfileService;

/**
 * Completeness scoring vs the boolean sanitiser.
 *
 * @covers \BuddyNext\Profile\FieldType::is_filled
 * @covers \BuddyNext\Profile\ProfileService::get_strength
 */
class ProfileStrengthFilledTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Create the schema + seeds and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Named tasks that are currently ticked.
	 *
	 * @param int $user_id Member.
	 * @return array<int, string>
	 */
	private function ticked_tasks( int $user_id ): array {
		wp_cache_flush();
		$strength = $this->service->get_strength( $user_id );
		$done     = array();

		foreach ( (array) ( $strength['tasks'] ?? array() ) as $task ) {
			if ( ! empty( $task['done'] ) ) {
				$done[] = (string) ( $task['label'] ?? '' );
			}
		}

		return $done;
	}

	// ── FieldType::is_filled ─────────────────────────────────────────────────

	/**
	 * An unticked boolean is not filled; a ticked one is.
	 *
	 * @return void
	 */
	public function test_boolean_zero_is_not_filled(): void {
		$field = array( 'type' => 'boolean' );

		$this->assertFalse( FieldType::is_filled( $field, '0' ) );
		$this->assertFalse( FieldType::is_filled( $field, '' ) );
		$this->assertTrue( FieldType::is_filled( $field, '1' ) );
	}

	/**
	 * '0' is a real entry for every OTHER type.
	 *
	 * Guards against a fix that treats all falsy values as empty — a member whose
	 * answer is the number zero has filled that field in.
	 *
	 * @return void
	 */
	public function test_zero_is_filled_for_non_boolean_types(): void {
		$this->assertTrue( FieldType::is_filled( array( 'type' => 'number' ), '0' ) );
		$this->assertTrue( FieldType::is_filled( array( 'type' => 'text' ), '0' ) );
	}

	/**
	 * Whitespace and empty arrays are not filled.
	 *
	 * @return void
	 */
	public function test_blank_shapes_are_not_filled(): void {
		$field = array( 'type' => 'text' );

		$this->assertFalse( FieldType::is_filled( $field, '   ' ) );
		$this->assertFalse( FieldType::is_filled( $field, array() ) );
		$this->assertFalse( FieldType::is_filled( $field, array( '', '' ) ) );
		$this->assertTrue( FieldType::is_filled( $field, array( '', 'x' ) ) );
	}

	// ── The reported behaviour ───────────────────────────────────────────────

	/**
	 * Opening the edit form and saving without typing anything ticks nothing.
	 *
	 * The form posts every field in the schema, including the phantom repeater
	 * entry get_profile() fabricates, so this payload is what an untouched save
	 * actually sends.
	 *
	 * @return void
	 */
	public function test_saving_an_untouched_form_ticks_no_section(): void {
		$user_id = self::factory()->user->create();

		$this->assertSame( array(), $this->ticked_tasks( $user_id ), 'A new profile starts at zero.' );

		$this->service->save_profile(
			$user_id,
			array(
				'work_experience' => array( 0 => array( 'work_company' => '', 'work_current' => '' ) ),
				'education'       => array( 0 => array( 'edu_institution' => '', 'edu_current' => '' ) ),
			)
		);

		$this->assertSame(
			array(),
			$this->ticked_tasks( $user_id ),
			'Saving a form the member never typed in must not mark any section complete.'
		);
	}

	/**
	 * A genuinely filled section still ticks.
	 *
	 * Mutation guard: an implementation that called nothing filled would pass
	 * every assertion above and fail here.
	 *
	 * @return void
	 */
	public function test_a_really_filled_section_still_ticks(): void {
		$user_id = self::factory()->user->create();

		$this->service->save_profile(
			$user_id,
			array( 'work_experience' => array( 0 => array( 'work_company' => 'Acme', 'work_current' => '1' ) ) )
		);

		$this->assertContains( 'Add Work Experience', $this->ticked_tasks( $user_id ) );
	}

	/**
	 * Ticking ONLY the boolean in a repeater entry counts as filled.
	 *
	 * The member did interact with the section, so it is complete — the bug was
	 * counting a box they never touched, not counting a box they ticked.
	 *
	 * @return void
	 */
	public function test_ticking_only_the_checkbox_counts_as_filled(): void {
		$user_id = self::factory()->user->create();

		$this->service->save_profile(
			$user_id,
			array( 'work_experience' => array( 0 => array( 'work_company' => '', 'work_current' => '1' ) ) )
		);

		$this->assertContains( 'Add Work Experience', $this->ticked_tasks( $user_id ) );
	}

	/**
	 * Filling a section then clearing it flips the tick back off.
	 *
	 * This is acceptance criterion 2 on the original report, which the old
	 * predicate could never satisfy for a repeater holding a boolean.
	 *
	 * @return void
	 */
	public function test_clearing_a_filled_section_unticks_it(): void {
		$user_id = self::factory()->user->create();

		$this->service->save_profile(
			$user_id,
			array( 'work_experience' => array( 0 => array( 'work_company' => 'Acme', 'work_current' => '1' ) ) )
		);
		$this->assertContains( 'Add Work Experience', $this->ticked_tasks( $user_id ) );

		$this->service->save_profile(
			$user_id,
			array( 'work_experience' => array( 0 => array( 'work_company' => '', 'work_current' => '' ) ) )
		);

		$this->assertNotContains(
			'Add Work Experience',
			$this->ticked_tasks( $user_id ),
			'Clearing a section must flip its tick off again.'
		);
	}

	/**
	 * A profile already carrying the junk '0' rows reads as empty.
	 *
	 * The fix is on the READ side, so profiles corrupted before it shipped are
	 * corrected without a data migration. If this ever needs one, this test is
	 * where that will show up.
	 *
	 * @return void
	 */
	public function test_profiles_corrupted_before_the_fix_read_as_empty(): void {
		global $wpdb;

		$user_id  = self::factory()->user->create();
		$field_id = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'work_current'"
		);

		$this->assertGreaterThan( 0, $field_id, 'work_current must exist in the seeded schema.' );

		// Exactly what the old write path left behind.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'          => $user_id,
				'field_id'         => $field_id,
				'entry_index'      => 0,
				'value'            => '0',
				'entry_visibility' => 'public',
			)
		);

		// Without this the test would pass vacuously against a profile that simply
		// has no rows, proving nothing about the corrupted state it exists to cover.
		$this->assertSame( 1, $inserted, 'The junk row must actually be inserted: ' . $wpdb->last_error );
		$this->assertSame(
			'0',
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d",
					$user_id,
					$field_id
				)
			)
		);

		$this->assertNotContains( 'Add Work Experience', $this->ticked_tasks( $user_id ) );
	}
}
