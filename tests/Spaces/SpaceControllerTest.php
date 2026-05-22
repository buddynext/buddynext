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

	/* ── Surface 1 (directory) ─────────────────────────────────────── */

	public function test_create_space_invalid_type_returns_422(): void {
		wp_set_current_user( $this->owner_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_body_params(
			array(
				'name' => 'Bad',
				'slug' => 'bad',
				'type' => 'public-but-wrong',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'params', $data['data'] );
		$this->assertArrayHasKey( 'type', $data['data']['params'] );
	}

	public function test_create_space_duplicate_slug_returns_422(): void {
		wp_set_current_user( $this->owner_id );

		$this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'First',
				'slug' => 'dup-slug-422',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_body_params(
			array(
				'name' => 'Second',
				'slug' => 'dup-slug-422',
				'type' => 'open',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	public function test_list_spaces_filters_by_type_enum(): void {
		$this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Open A',
				'slug' => 'open-a-filter',
				'type' => 'open',
			)
		);
		$this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Private B',
				'slug' => 'private-b-filter',
				'type' => 'private',
			)
		);

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces' );
		$request->set_query_params( array( 'type' => 'private' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data();
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( 'private', $row['type'] );
		}
	}

	public function test_list_spaces_caps_per_page_at_50(): void {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces' );
		$request->set_query_params( array( 'per_page' => '500' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data();
		$this->assertLessThanOrEqual( 50, count( $rows ) );
	}

	public function test_list_spaces_accepts_sort_alias_newest(): void {
		$this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Sort Alias',
				'slug' => 'sort-alias-newest',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces' );
		$request->set_query_params( array( 'orderby' => 'newest' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/* ── Surface 2 (space home) ────────────────────────────────────── */

	public function test_set_notification_pref_persists_for_member(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Notif Space',
				'slug' => 'notif-pref-space',
				'type' => 'open',
			)
		);
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space_id, $user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/notification-pref' );
		$request->set_body_params( array( 'pref' => 'mentions' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'mentions', $response->get_data()['pref'] );
	}

	public function test_set_notification_pref_invalid_returns_422(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Notif Bad',
				'slug' => 'notif-pref-bad',
				'type' => 'open',
			)
		);
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space_id, $user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/notification-pref' );
		$request->set_body_params( array( 'pref' => 'turbo' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	/* ── Surface 3 (settings) ──────────────────────────────────────── */

	public function test_change_member_role_promotes_to_moderator(): void {
		wp_set_current_user( $this->owner_id );

		$target_id = self::factory()->user->create();
		$space_id  = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Role Space',
				'slug' => 'role-space-promote',
				'type' => 'open',
			)
		);
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space_id, $target_id );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/spaces/' . $space_id . '/members/' . $target_id . '/role' );
		$request->set_body_params( array( 'role' => 'moderator' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'moderator', $response->get_data()['role'] );
	}

	public function test_transfer_ownership_updates_owner(): void {
		wp_set_current_user( $this->owner_id );

		$new_owner = self::factory()->user->create();
		$space_id  = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Xfer Space',
				'slug' => 'xfer-space-test',
				'type' => 'open',
			)
		);
		( new \BuddyNext\Spaces\SpaceMemberService() )->join( $space_id, $new_owner );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces/' . $space_id . '/transfer' );
		$request->set_body_params( array( 'new_owner_id' => $new_owner ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$updated = $this->space_service->get( $space_id );
		$this->assertSame( $new_owner, $updated['owner_id'] );
	}

	public function test_delete_space_with_matching_confirm_header_returns_200(): void {
		wp_set_current_user( $this->owner_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Doomed Space',
				'slug' => 'doomed-space',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $space_id );
		$request->add_header( 'X-BN-Confirm-Space-Name', 'Doomed Space' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_delete_space_with_mismatched_confirm_header_returns_422(): void {
		wp_set_current_user( $this->owner_id );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Tough Space',
				'slug' => 'tough-space',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'DELETE', '/buddynext/v1/spaces/' . $space_id );
		$request->add_header( 'X-BN-Confirm-Space-Name', 'Wrong Name' );
		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	public function test_update_permissions_requires_owner(): void {
		$other = self::factory()->user->create();
		wp_set_current_user( $other );

		$space_id = $this->space_service->create(
			$this->owner_id,
			array(
				'name' => 'Perm Space',
				'slug' => 'perm-space-test',
				'type' => 'open',
			)
		);

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/spaces/' . $space_id . '/permissions' );
		$request->set_body_params( array( 'allow_member_posts' => 0 ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
