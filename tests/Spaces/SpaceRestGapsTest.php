<?php
/**
 * Tests for the Spaces REST gaps: category update + cancel join request.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceCategoryController::update_category
 * @covers \BuddyNext\Spaces\SpaceController::cancel_join_request
 */
class SpaceRestGapsTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	private function seed_category(): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_categories',
			array( 'name' => 'Old Name', 'slug' => 'old-name', 'description' => '', 'sort_order' => 0 ),
			array( '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public function test_admin_can_update_category(): void {
		$id    = $this->seed_category();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'PUT', "/buddynext/v1/space-categories/{$id}" );
		$request->set_body_params( array( 'name' => 'New Name', 'sort_order' => 5 ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New Name', $response->get_data()['name'] );
		$this->assertSame( 5, $response->get_data()['sort_order'] );
	}

	public function test_non_admin_cannot_update_category(): void {
		$id = $this->seed_category();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request = new WP_REST_Request( 'PUT', "/buddynext/v1/space-categories/{$id}" );
		$request->set_body_params( array( 'name' => 'X' ) );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
	}

	public function test_member_can_cancel_pending_join_request(): void {
		$owner     = self::factory()->user->create();
		$requester = self::factory()->user->create();

		$space_id = (int) ( new SpaceService() )->create(
			$owner,
			array( 'name' => 'Private', 'slug' => 'private-cancel', 'type' => 'private' )
		);

		$members = new SpaceMemberService();
		$members->request_join( $space_id, $requester );
		$this->assertNotEmpty( $members->get_pending_requests( $space_id ) );

		wp_set_current_user( $requester );
		$response = rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/spaces/{$space_id}/join/cancel" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $members->get_pending_requests( $space_id ) );
	}
}
