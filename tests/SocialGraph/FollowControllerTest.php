<?php
/**
 * Tests for FollowController REST endpoints.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\FollowController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\SocialGraph\FollowController
 */
class FollowControllerTest extends \WP_UnitTestCase {

	private int $alice;
	private int $bob;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new FollowController() )->register_routes();

		$this->alice = self::factory()->user->create();
		$this->bob   = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// ── Route registration ─────────────────────────────────────────────────

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/follow', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/followers', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/following', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/follow-suggestions', $routes );
	}

	// ── Authentication ─────────────────────────────────────────────────────

	public function test_follow_requires_authentication(): void {
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/follow" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_unfollow_requires_authentication(): void {
		$request  = new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$this->bob}/follow" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_suggestions_require_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/follow-suggestions' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── Happy path ─────────────────────────────────────────────────────────

	public function test_follow_returns_200(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/follow" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['following'] );
	}

	public function test_unfollow_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/follow" ) );

		$request  = new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$this->bob}/follow" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['following'] );
	}

	public function test_followers_returns_list(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/follow" ) );
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->bob}/followers" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->alice, $response->get_data()['ids'] );
	}

	public function test_following_returns_list(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/follow" ) );
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->alice}/following" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->bob, $response->get_data()['ids'] );
	}

	// ── Error cases ────────────────────────────────────────────────────────

	public function test_cannot_follow_self_returns_400(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/follow" );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// ── Another member's connections ───────────────────────────────────────

	/**
	 * Connect two members through the service (request + accept).
	 *
	 * @param int $a Requester.
	 * @param int $b Recipient.
	 * @return void
	 */
	private function connect( int $a, int $b ): void {
		$conn = buddynext_service( 'connections' );
		$conn->send_request( $a, $b );
		$conn->accept_request( $b, $a );
	}

	public function test_member_connections_are_listed_and_paged(): void {
		$carol = self::factory()->user->create();
		$dave  = self::factory()->user->create();
		$this->connect( $this->alice, $this->bob );
		$this->connect( $carol, $this->bob );
		$this->connect( $dave, $this->bob );

		wp_set_current_user( self::factory()->user->create() );
		$first = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->bob}/connections" ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 3, $first->get_data()['total'] );

		$seen    = array();
		$request = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->bob}/connections" );
		$request->set_param( 'per_page', 2 );
		do {
			$page = rest_do_request( $request )->get_data();
			$seen = array_merge( $seen, $page['ids'] );
			$request->set_param( 'cursor', (string) $page['next_cursor'] );
		} while ( null !== $page['next_cursor'] );

		sort( $seen );
		$expected = array( $this->alice, $carol, $dave );
		sort( $expected );
		$this->assertSame( $expected, $seen, 'Paging walks every connection once.' );
	}

	public function test_member_connections_follow_profile_privacy(): void {
		$this->connect( $this->alice, $this->bob );
		buddynext_service( 'privacy' )->set_preference( $this->bob, 'profile_visibility', 'private' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->bob}/connections" ) )->get_status(), 'A stranger cannot see a private member\'s connections.' );

		wp_set_current_user( $this->bob );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->bob}/connections" ) )->get_status(), 'The member sees their own.' );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/999999/connections' ) )->get_status() );
	}
}
