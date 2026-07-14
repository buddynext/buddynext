<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A member must never be blocked by a required field they are not allowed to see.
 *
 * WHY THIS EXISTS — Zoho #40859 (Markus Kaufmann, bn.myblasmusik.de), card 10086461636.
 *
 * The customer, in his own words:
 *
 *     "If you set a profile field as required and assign the section to only one member type,
 *      it won't be displayed for other types, but an error message will appear when editing
 *      the profile stating that required fields are missing (which you cannot see, however)"
 *
 * That is a LOCKOUT, and it is the worst shape a validation bug can take: the member is told they
 * must fill in a field, the field is not on their screen, and there is no action available to them
 * that clears the error. They cannot save their profile again. Ever. There is no workaround a member
 * can find on their own, because the thing they are being asked for does not exist for them.
 *
 * The rule this pins: **an invisible field cannot be a required field.** If a member cannot see it,
 * it cannot block them — on ANY entry point. There are three (REST self-edit, the admin member
 * editor, onboarding), they do not share a validator, and a fix that lands on one is this bug still
 * shipping on the other two.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * Member-type-restricted required fields must not block anyone.
 */
class MemberTypeRequiredLockoutTest extends WP_UnitTestCase {

	/**
	 * Profiles service.
	 *
	 * @var ProfileService
	 */
	private ProfileService $profiles;

	/**
	 * The member who is NOT of the restricted type.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Key of the required field locked to the 'person' type.
	 *
	 * @var string
	 */
	private string $locked_key = 'zz_birthday';

	/**
	 * The restricted member type's id.
	 *
	 * @var int
	 */
	private int $person_id = 0;

	/**
	 * Markus's exact setup: a required field inside a group restricted to one member type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->profiles = new ProfileService();
		$this->member   = (int) $this->factory->user->create();

		// A member type the member does NOT hold. Seeded through the SERVICE, not raw SQL: a raw
		// INSERT skips the cache priming and the counters, and my first attempt at this fixture
		// invented a `label` column that does not exist — so the type was never created and every
		// assertion below passed for the wrong reason.
		$types           = buddynext_service( 'member_types' );
		$this->person_id = (int) $types->create(
			array(
				'slug' => 'person',
				'name' => 'Person',
			)
		);
		$this->assertGreaterThan( 0, $this->person_id, 'fixture: the member type must actually exist' );

		// A profile group visible ONLY to that type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'        => 'zz_person_only',
				'label'            => 'Person only',
				'type'             => 'flat',
				'visibility'       => 'public',
				'type_restriction' => 'person',
				'sort_order'       => 99,
			)
		);
		$group_id = (int) $wpdb->insert_id;

		// …containing a REQUIRED field.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'    => $group_id,
				'field_key'   => $this->locked_key,
				'label'       => 'Birthday',
				'type'        => 'text',
				'is_required' => 1,
				'visibility'  => 'public',
				'sort_order'  => 1,
			)
		);

		wp_cache_flush();
	}

	/**
	 * The per-field errors from a save_profile() result.
	 *
	 * Note save_profile() returns `true|WP_Error`, with the per-field messages under the WP_Error's
	 * data['fields'] — NOT an array with a 'field_errors' key, which is what I assumed on the first
	 * pass. Reading a key that does not exist made the lockout assertions pass while proving
	 * nothing; only the control test (which expected an error and got none) exposed it.
	 *
	 * @param mixed $result save_profile() return value.
	 * @return array<string,string>
	 */
	private function field_errors( $result ): array {
		if ( ! is_wp_error( $result ) ) {
			return array();
		}

		$data = (array) $result->get_error_data();

		return isset( $data['fields'] ) ? (array) $data['fields'] : array();
	}

	/**
	 * The member does not hold the restricted type — so the field is not theirs to fill.
	 *
	 * @return void
	 */
	public function test_the_member_is_not_of_the_restricted_type(): void {
		$types = buddynext_service( 'member_types' );
		$type  = is_object( $types ) && method_exists( $types, 'get_user_type' ) ? $types->get_user_type( $this->member ) : null;

		$this->assertTrue(
			empty( $type['slug'] ) || 'person' !== $type['slug'],
			'fixture: this member must NOT be a "person", or there is no lockout to test'
		);
	}

	/**
	 * THE LOCKOUT. Saving a profile must not demand a field the member cannot see.
	 *
	 * This is the persistence layer — the one every entry point shares (REST self-edit, the admin
	 * member editor, onboarding). The REST controller learned to skip member-type-restricted fields;
	 * this layer did not, and it is the layer the admin editor calls directly.
	 *
	 * @return void
	 */
	public function test_saving_a_profile_does_not_demand_a_field_locked_to_another_member_type(): void {
		// The member submits the fields they were actually shown. The locked field is submitted as
		// EMPTY — which is what happens the moment anything renders it (the profile editor asks the
		// service for the owner's full field set, and the service hands back every group).
		$result = $this->profiles->save_profile(
			$this->member,
			array( $this->locked_key => '' )
		);

		$errors = $this->field_errors( $result );

		$this->assertArrayNotHasKey(
			$this->locked_key,
			$errors,
			'The member is told "Birthday is required." for a field locked to a member type they do '
			. 'not hold. It is not on their screen, and no action available to them can clear it — so '
			. "they can never save their profile again.\n\n"
			. 'An invisible field cannot be a required field. Zoho #40859.'
		);
	}

	/**
	 * ...and the save must actually go through, not merely avoid that one error key.
	 *
	 * @return void
	 */
	public function test_the_profile_save_succeeds_for_a_member_of_another_type(): void {
		$result = $this->profiles->save_profile(
			$this->member,
			array( $this->locked_key => '' )
		);

		$this->assertTrue(
			true === $result,
			'the whole save is rejected, so the member is locked out of their own profile: '
			. wp_json_encode( $this->field_errors( $result ) )
		);
	}

	/**
	 * The guard must not neuter the field for the member it IS for.
	 *
	 * A required field that stops being required for everyone is not a fix, it is a different bug.
	 *
	 * @return void
	 */
	public function test_the_field_is_still_required_for_a_member_of_that_type(): void {
		$types = buddynext_service( 'member_types' );
		$types->assign_type( $this->member, $this->person_id );
		wp_cache_flush();

		$result = $this->profiles->save_profile(
			$this->member,
			array( $this->locked_key => '' )
		);

		$errors = $this->field_errors( $result );

		$this->assertArrayHasKey(
			$this->locked_key,
			$errors,
			'For a member who IS a "person" the field is visible, so it must still be enforced. '
			. 'Skipping it for everyone would trade a lockout for a silently-optional required field.'
		);
	}
}
