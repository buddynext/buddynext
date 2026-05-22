<?php
/**
 * Tests for the GET /users/{id}/followers REST endpoint.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\SocialGraph\FollowController::get_followers
 */
class FollowersControllerTest extends \WP_Test_REST_TestCase {

	private int $owner_id;
	private int $follower_a;
	private int $follower_b;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->owner_id   = self::factory()->user->create();
		$this->follower_a = self::factory()->user->create();
		$this->follower_b = self::factory()->user->create();

		\buddynext_service( 'follows' )->follow( $this->follower_a, $this->owner_id );
		\buddynext_service( 'follows' )->follow( $this->follower_b, $this->owner_id );
	}

	public function test_get_followers_returns_200_and_ids_array(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/followers' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'ids', $data );
		$this->assertIsArray( $data['ids'] );
		$this->assertCount( 2, $data['ids'] );
		$this->assertContains( $this->follower_a, $data['ids'] );
		$this->assertContains( $this->follower_b, $data['ids'] );
	}

	public function test_get_followers_filters_blocked_relationships(): void {
		$viewer = self::factory()->user->create();
		wp_set_current_user( $viewer );

		// Viewer blocks follower_a — the public list must omit them.
		\buddynext_service( 'blocks' )->block( $viewer, $this->follower_a );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/followers' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotContains( $this->follower_a, $data['ids'] );
		$this->assertContains( $this->follower_b, $data['ids'] );
	}

	public function test_get_followers_is_public(): void {
		// Anonymous viewer.
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->owner_id . '/followers' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
