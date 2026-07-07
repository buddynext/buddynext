<?php
/**
 * Tests for ProfileController REST endpoints.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;
use WP_REST_Request;

/**
 * REST endpoint tests for reading and updating member profiles, including the
 * authorization boundary that only the member themselves or an admin may write
 * a member's fields.
 *
 * @covers \BuddyNext\Profile\ProfileController
 */
class ProfileControllerTest extends \WP_Test_REST_TestCase {

	/**
	 * Member whose profile is exercised.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Profile service used to seed fields and read back values.
	 *
	 * @var ProfileService
	 */
	private ProfileService $profile_service;

	/**
	 * Fresh install + a member before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->profile_service = new ProfileService();
		$this->user_id         = self::factory()->user->create();
	}

	/**
	 * GET /users/{id}/profile responds 200 for an existing member.
	 */
	public function test_get_profile_returns_200(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->user_id . '/profile' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The profile payload echoes the requested user id.
	 */
	public function test_get_profile_includes_user_id(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->user_id . '/profile' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertSame( $this->user_id, $data['user_id'] );
	}

	/**
	 * GET /users/{id}/profile responds 404 for a user that does not exist.
	 */
	public function test_get_profile_returns_404_for_nonexistent_user(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/999999/profile' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * PUT /me/profile requires authentication (401 when anonymous).
	 */
	public function test_update_profile_requires_auth(): void {
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_body_params( array( 'bio' => 'Hello' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A member saving their own profile persists the field value.
	 */
	public function test_update_profile_saves_field(): void {
		wp_set_current_user( $this->user_id );

		// Ensure the bio field exists.
		$this->profile_service->create_field(
			array(
				'field_key'  => 'bio',
				'label'      => 'Bio',
				'type'       => 'textarea',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_body_params( array( 'bio' => 'I am Alice' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'saved', $data );
		$this->assertTrue( $data['saved'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertSame( array(), $data['errors'] );

		$profile      = $this->profile_service->get_profile( $this->user_id, $this->user_id );
		$field_values = array_column( $profile['fields'], 'value', 'field_key' );
		$this->assertSame( 'I am Alice', $field_values['bio'] );
	}

	/**
	 * A logged-in member may NOT write another member's profile fields. The
	 * /users/{id}/profile write route is gated by the edit-any capability, which
	 * a regular member does not hold — locking the "only the member themselves
	 * or an admin can update a member's fields" contract.
	 */
	public function test_member_cannot_edit_another_members_fields(): void {
		$this->profile_service->create_field(
			array(
				'field_key'  => 'bio',
				'label'      => 'Bio',
				'type'       => 'textarea',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$victim   = $this->user_id;
		$attacker = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $attacker );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/users/' . $victim . '/profile' );
		$request->set_body_params( array( 'bio' => 'hacked by another member' ) );
		$response = rest_do_request( $request );

		// Rejected by require_edit_any_profile (auth + edit-any capability).
		$this->assertContains( $response->get_status(), array( 401, 403 ) );

		// And nothing was written to the victim's record.
		$profile = $this->profile_service->get_profile( $victim, $victim );
		$values  = array_column( (array) ( $profile['fields'] ?? array() ), 'value', 'field_key' );
		$this->assertNotSame( 'hacked by another member', $values['bio'] ?? null );
	}

	/**
	 * An administrator MAY write another member's profile fields via the same
	 * route — the admin half of the same contract.
	 */
	public function test_admin_can_edit_another_members_fields(): void {
		$this->profile_service->create_field(
			array(
				'field_key'  => 'bio',
				'label'      => 'Bio',
				'type'       => 'textarea',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$target = $this->user_id;
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/users/' . $target . '/profile' );
		$request->set_body_params( array( 'bio' => 'set by admin' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$profile = $this->profile_service->get_profile( $target, $target );
		$values  = array_column( (array) ( $profile['fields'] ?? array() ), 'value', 'field_key' );
		$this->assertSame( 'set by admin', $values['bio'] ?? null );
	}

	/**
	 * A full-write payload saves every provided field in one request.
	 */
	public function test_update_profile_accepts_full_payload(): void {
		wp_set_current_user( $this->user_id );

		// Register the canonical fields the production payload references.
		foreach ( array( 'headline', 'location', 'website', 'pronouns', 'bio' ) as $key ) {
			$this->profile_service->create_field(
				array(
					'field_key'  => $key,
					'label'      => ucfirst( $key ),
					'type'       => 'bio' === $key ? 'textarea' : 'text',
					'visibility' => 'public',
					'group_name' => 'basic_info',
					'sort_order' => 0,
				)
			);
		}

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'display_name' => 'Alice Example',
					'headline'     => 'Engineer at Acme',
					'location'     => 'Berlin, DE',
					'website'      => 'https://example.com',
					'pronouns'     => 'they/them',
					'bio'          => 'Hello world.',
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['saved'] );

		// display_name must round-trip through wp_users.
		$user = get_userdata( $this->user_id );
		$this->assertSame( 'Alice Example', $user->display_name );
	}

	/**
	 * A blank display_name is rejected with a 422 field-level error.
	 */
	public function test_update_profile_rejects_blank_display_name_with_422(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'display_name' => '   ',
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'display_name', $data['errors'] );
		$this->assertFalse( $data['saved'] );
	}

	/**
	 * An invalid website URL is rejected with a 422 field-level error payload.
	 */
	public function test_update_profile_rejects_bad_website_url_with_422(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'website' => 'javascript:alert(1)', // phpcs:ignore
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'website', $data['errors'] );
	}

	/**
	 * An empty website value clears the field without tripping URL validation.
	 */
	public function test_update_profile_allows_empty_website(): void {
		wp_set_current_user( $this->user_id );

		// Empty strings clear the field and must not trip URL validation.
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'website' => '',
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * A website entered without a scheme is normalised to include one on save.
	 */
	public function test_update_profile_normalises_url_without_protocol(): void {
		wp_set_current_user( $this->user_id );

		$this->profile_service->create_field(
			array(
				'field_key'  => 'website',
				'label'      => 'Website',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'basic_info',
				'sort_order' => 0,
			)
		);

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'website' => 'example.com',
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$profile = $this->profile_service->get_profile( $this->user_id, $this->user_id );
		$values  = array_column( $profile['fields'], 'value', 'field_key' );
		$this->assertStringStartsWith( 'https://', (string) ( $values['website'] ?? '' ) );
	}
}
