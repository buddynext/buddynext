<?php
/**
 * Tests for ModerationController REST endpoints (BLOCK 4: suspend / unsuspend / appeals).
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\REST\Controllers\ModerationController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\REST\Controllers\ModerationController
 */
class ModerationControllerTest extends \WP_UnitTestCase {

	private int $admin_id;
	private int $user_id;
	private ModerationService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new ModerationController() )->register_routes();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_id  = self::factory()->user->create();
		$this->service  = new ModerationService();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// ── Route registration ─────────────────────────────────────────────────

	public function test_suspend_and_appeal_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/suspend', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/users/(?P<id>[\d]+)/unsuspend', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/appeals', $routes );
		$this->assertArrayHasKey( '/buddynext/v1/appeals/(?P<id>[\d]+)/resolve', $routes );
	}

	// ── POST /users/{id}/suspend ────────────────────────────────────────────

	public function test_suspend_returns_201_for_admin(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->user_id}/suspend" );
		$request->set_param( 'reason', 'Community guidelines violation.' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayHasKey( 'suspension_id', $response->get_data() );
		$this->assertGreaterThan( 0, $response->get_data()['suspension_id'] );
	}

	public function test_suspend_requires_admin(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->user_id}/suspend" );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_suspend_unauthenticated_returns_401(): void {
		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->user_id}/suspend" );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── POST /users/{id}/unsuspend ──────────────────────────────────────────

	public function test_unsuspend_returns_200_for_admin(): void {
		$this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->user_id}/unsuspend" );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['unsuspended'] );
	}

	public function test_unsuspend_requires_admin(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/users/{$this->user_id}/unsuspend" );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// ── POST /appeals ───────────────────────────────────────────────────────

	public function test_submit_appeal_returns_201_for_suspended_user(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/appeals' );
		$request->set_param( 'suspension_id', $suspension_id );
		$request->set_param( 'message', 'I believe this was a mistake.' );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayHasKey( 'appeal_id', $response->get_data() );
		$this->assertGreaterThan( 0, $response->get_data()['appeal_id'] );
	}

	public function test_submit_appeal_requires_auth(): void {
		// Include required params so args validation passes and auth check runs.
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/appeals' );
		$request->set_param( 'suspension_id', 1 );
		$request->set_param( 'message', 'test message' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_submit_appeal_invalid_suspension_returns_error(): void {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/appeals' );
		$request->set_param( 'suspension_id', 9999 );
		$request->set_param( 'message', 'test' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// ── POST /appeals/{id}/resolve ──────────────────────────────────────────

	public function test_resolve_appeal_returns_200_for_admin(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/appeals/{$appeal_id}/resolve" );
		$request->set_param( 'decision', 'approved' );
		$request->set_param( 'reviewer_note', 'Appeal accepted.' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['resolved'] );
	}

	public function test_resolve_appeal_requires_admin(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'POST', "/buddynext/v1/appeals/{$appeal_id}/resolve" );
		$request->set_param( 'decision', 'denied' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_resolve_appeal_invalid_decision_returns_error(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', "/buddynext/v1/appeals/{$appeal_id}/resolve" );
		$request->set_param( 'decision', 'banana' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
