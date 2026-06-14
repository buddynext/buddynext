<?php
/**
 * Tests for SocialGraph relationship-inspection REST endpoints.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\SocialGraph\FollowController
 * @covers \BuddyNext\SocialGraph\ConnectionController
 */
class RelationshipStatusTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_follow_status(): void {
		$me     = self::factory()->user->create();
		$target = self::factory()->user->create();
		( new FollowService() )->follow( $me, $target );

		wp_set_current_user( $me );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$target}/follow/status" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_following'] );
		$this->assertFalse( $response->get_data()['is_pending'] );
	}

	public function test_follow_status_requires_login(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/1/follow/status' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_connection_status(): void {
		$me   = self::factory()->user->create();
		$peer = self::factory()->user->create();
		$conn = new ConnectionService();
		$conn->send_request( $me, $peer, '' );

		wp_set_current_user( $me );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$peer}/connection/status" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['status'] );
	}

	public function test_mutual_connections(): void {
		$me     = self::factory()->user->create();
		$peer   = self::factory()->user->create();
		$shared = self::factory()->user->create();
		$conn   = new ConnectionService();
		// me<->shared and peer<->shared both accepted.
		$conn->send_request( $me, $shared, '' );
		$conn->accept_request( $shared, $me );
		$conn->send_request( $peer, $shared, '' );
		$conn->accept_request( $shared, $peer );

		wp_set_current_user( $me );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$peer}/mutual-connections" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $shared, array_map( 'intval', $response->get_data()['ids'] ) );
	}

	public function test_account_type_public(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, 'bn_account_private', '1' );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/account-type" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_private'] );
	}

	public function test_follow_requests_count(): void {
		$me        = self::factory()->user->create();
		$requester = self::factory()->user->create();
		update_user_meta( $me, 'bn_account_private', '1' );
		( new FollowService() )->follow( $requester, $me ); // becomes a pending request

		wp_set_current_user( $me );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/follow-requests/count' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['count'] );
	}
}
