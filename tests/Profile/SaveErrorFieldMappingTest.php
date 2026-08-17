<?php
/**
 * A rejected profile save has to be able to say WHICH field was wrong.
 *
 * `ProfileService::save_profile()` is atomic (see SaveProfileAtomicityTest): one bad
 * field means nothing at all is written. That makes the error map the difference
 * between a usable form and an unusable one — "not saved" with no attribution leaves
 * the person resubmitting to find out which field, on a form with dozens.
 *
 * The mapping existed, as a PRIVATE method on ProfileController. So the REST editor
 * could paint inline errors and the admin member editor had nothing to call — which
 * is exactly why it ignored save_profile()'s return and reported "Profile updated
 * successfully." over a save that wrote nothing. Moving it onto the service is what
 * lets both callers answer the same question the same way.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\ProfileService;

/**
 * save_profile() rejections map to field => message.
 *
 * @covers \BuddyNext\Profile\ProfileService::map_save_error_to_fields
 */
class SaveErrorFieldMappingTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * A member to save against.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Seed the service and a member.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new ProfileService();
		$this->user_id = (int) $this->factory->user->create();
	}

	/**
	 * The mapping is reachable from outside ProfileController.
	 *
	 * This is the whole point of the change: it was private on the REST controller,
	 * so the admin editor could not report which field failed and reported success
	 * instead. A test that only checked the return value would still pass if someone
	 * made it private again and duplicated it.
	 *
	 * @return void
	 */
	public function test_the_mapping_is_public_on_the_service(): void {
		$method = new \ReflectionMethod( ProfileService::class, 'map_save_error_to_fields' );

		$this->assertTrue(
			$method->isPublic(),
			'Every caller of save_profile() needs this mapping - REST, the admin member editor, '
			. 'onboarding. Private means the next caller duplicates it or, as the admin editor '
			. 'did, reports success over a failed save.'
		);
	}

	/**
	 * A per-field rejection is returned field-keyed, untouched.
	 *
	 * @return void
	 */
	public function test_per_field_rejections_are_returned_as_a_field_map(): void {
		$error = new \WP_Error(
			'profile_fields_invalid',
			'Some fields were invalid.',
			array(
				'fields' => array( 'website' => 'Website must be a valid URL.' ),
				'status' => 422,
			)
		);

		$this->assertSame(
			array( 'website' => 'Website must be a valid URL.' ),
			$this->service->map_save_error_to_fields( $error, array( 'website' => 'nope' ), $this->user_id ),
			'the per-field messages save_profile() already produced must survive the mapping'
		);
	}

	/**
	 * An error carrying no field data maps to an empty array, not a crash.
	 *
	 * An empty map is a valid outcome — the caller still shows the WP_Error's own
	 * message. It must never be mistaken for success, which is why the caller keys
	 * off is_wp_error() and not off this being non-empty.
	 *
	 * @return void
	 */
	public function test_an_unattributed_error_maps_to_an_empty_array(): void {
		$error = new \WP_Error( 'some_failure', 'Something went wrong.' );

		$this->assertSame(
			array(),
			$this->service->map_save_error_to_fields( $error, array(), $this->user_id ),
			'an error with nothing to attribute must map to an empty array'
		);
	}

	/**
	 * The real rejection produced by save_profile() maps to a real field name.
	 *
	 * End to end rather than against a hand-built WP_Error: this is the shape the
	 * admin editor actually receives, so it is the shape worth pinning. A `url` field
	 * given an unusable value is rejected by the field-type engine.
	 *
	 * @return void
	 */
	public function test_a_real_save_rejection_names_the_field(): void {
		$result = $this->service->save_profile( $this->user_id, array( 'website' => 'javascript:alert(1)' ) );

		if ( ! is_wp_error( $result ) ) {
			$this->markTestSkipped( 'This install has no url-typed `website` field to reject against.' );
		}

		$fields = $this->service->map_save_error_to_fields(
			$result,
			array( 'website' => 'javascript:alert(1)' ),
			$this->user_id
		);

		$this->assertArrayHasKey(
			'website',
			$fields,
			'a rejected url field must be named in the map, or the editor can only say "not saved"'
		);
		$this->assertNotSame( '', (string) $fields['website'], 'the named field must carry a message' );
	}
}
