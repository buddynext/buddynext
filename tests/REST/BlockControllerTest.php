<?php
/**
 * Tests for BlockController REST endpoints.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use BuddyNext\REST\Controllers\BlockController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\REST\Controllers\BlockController
 */
class BlockControllerTest extends \WP_UnitTestCase {

	private int $alice;
	private int $bob;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new BlockController() )->register_routes();

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

		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/block', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/mute', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/me/blocked', $routes );
	}

	// ── Authentication ─────────────────────────────────────────────────────

	public function test_block_requires_authentication(): void {
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/block" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_blocked_list_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/blocked' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── Happy path ─────────────────────────────────────────────────────────

	public function test_block_returns_200(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/block" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['blocked'] );
	}

	public function test_unblock_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/block" ) );

		$request  = new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$this->bob}/block" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['blocked'] );
	}

	public function test_mute_returns_200(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/mute" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['muted'] );
	}

	public function test_unmute_returns_200(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/mute" ) );

		$request  = new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$this->bob}/mute" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['muted'] );
	}

	public function test_blocked_list_returns_ids(): void {
		wp_set_current_user( $this->alice );
		rest_do_request( new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->bob}/block" ) );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/blocked' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $this->bob, $response->get_data()['ids'] );
	}

	// ── Error cases ────────────────────────────────────────────────────────

	public function test_cannot_block_self_returns_400(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->alice}/block" );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
