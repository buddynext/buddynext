<?php
/**
 * Tests for ConnectionController REST endpoints.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\SocialGraph\ConnectionController
 */
class ConnectionControllerTest extends \WP_UnitTestCase {

	private int $alice;
	private int $bob;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new ConnectionController() )->register_routes();

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

		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/connect', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/connect/accept', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/connect/decline', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/me/connections', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/me/connection-requests', $routes );
	}

	// ── Authentication ─────────────────────────────────────────────────────

	public function test_send_request_requires_authentication(): void {
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_connections_list_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/connections' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── Happy path ─────────────────────────────────────────────────────────

	public function test_send_request_returns_200(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['status'] );
	}

	public function test_accept_request_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" ) );

		wp_set_current_user( $this->bob );
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/connect/accept" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'accepted', $response->get_data()['status'] );
	}

	public function test_decline_request_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" ) );

		wp_set_current_user( $this->bob );
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/connect/decline" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'declined', $response->get_data()['status'] );
	}

	public function test_withdraw_request_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" ) );

		$request  = new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$this->bob}/connect" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_connections_list_returns_ids(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" ) );

		wp_set_current_user( $this->bob );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/connect/accept" ) );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/connections' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->alice, $response->get_data()['ids'] );
	}

	public function test_connection_requests_returns_pending_senders(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/connect" ) );

		wp_set_current_user( $this->bob );
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/connection-requests' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->alice, $response->get_data()['ids'] );
	}

	// ── Error cases ────────────────────────────────────────────────────────

	public function test_cannot_connect_self_returns_400(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/connect" );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
