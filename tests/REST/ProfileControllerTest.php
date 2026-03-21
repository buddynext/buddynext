<?php
/**
 * Tests for ProfileController REST endpoints.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\REST\Controllers\ProfileController
 */
class ProfileControllerTest extends \WP_Test_REST_TestCase {

	private int $user_id;
	private ProfileService $profile_service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->profile_service = new ProfileService();
		$this->user_id         = self::factory()->user->create();
	}

	public function test_get_profile_returns_200(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->user_id . '/profile' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_profile_includes_user_id(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->user_id . '/profile' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertSame( $this->user_id, $data['user_id'] );
	}

	public function test_get_profile_returns_404_for_nonexistent_user(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/999999/profile' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_profile_requires_auth(): void {
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_body_params( array( 'bio' => 'Hello' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

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

		$profile      = $this->profile_service->get_profile( $this->user_id, $this->user_id );
		$field_values = array_column( $profile['fields'], 'value', 'field_key' );
		$this->assertSame( 'I am Alice', $field_values['bio'] );
	}
}
