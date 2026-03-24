<?php
/**
 * Tests for SpaceController REST endpoints.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceController
 */
class SpaceControllerTest extends \WP_Test_REST_TestCase {

	private int $owner_id;
	private SpaceService $space_service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->space_service = new SpaceService();
		$this->owner_id      = self::factory()->user->create();
	}

	public function test_create_space_requires_auth(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_body_params(
			array(
				'name' => 'Test',
				'slug' => 'test',
				'type' => 'open',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_create_space_returns_201(): void {
		wp_set_current_user( $this->owner_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_body_params(
			array(
				'name' => 'New Space',
				'slug' => 'new-space-201',
				'type' => 'open',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_get_space_returns_200(): void {
		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Public Space',
				'slug' => 'public-space-get',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_space_returns_404_for_missing(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/spaces/999999' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_space_requires_auth(): void {
		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Space',
				'slug' => 'space-auth-check',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/spaces/' . $space_id );
		$request->set_body_params( array( 'name' => 'Updated' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_delete_space_by_owner_returns_200(): void {
		wp_set_current_user( $this->owner_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Delete Space',
				'slug' => 'delete-space-rest',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $space_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_join_space_requires_auth(): void {
		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Join Space',
				'slug' => 'join-space-auth',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/join' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_join_space_returns_200(): void {
		$user_id  = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Joinable Space',
				'slug' => 'joinable-space',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/join' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['joined'] );
	}

	public function test_join_private_space_returns_requested(): void {
		$user_id  = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Private Space',
				'slug' => 'private-space-join',
				'type' => 'private',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/join' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['requested'] );
	}

	public function test_join_secret_space_without_invite_returns_403(): void {
		$user_id  = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Secret Space',
				'slug' => 'secret-space-join',
				'type' => 'secret',
			)
		);

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/join' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_list_spaces_returns_200(): void {
		$this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Listed Space',
				'slug' => 'listed-space',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/spaces' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	public function test_get_space_members_returns_200(): void {
		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Members Space',
				'slug' => 'members-space',
				'type' => 'open',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space_id . '/members' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
