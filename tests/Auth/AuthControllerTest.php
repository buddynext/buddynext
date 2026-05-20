<?php
/**
 * Tests for AuthController REST endpoints.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Auth\AuthController
 */
class AuthControllerTest extends \WP_Test_REST_TestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Enable the verification feature gate so is_verified() reads usermeta.
		update_option( 'buddynext_email_verify', true );

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		parent::tear_down();
	}

	// ── Route registration ────────────────────────────────────────────────────

	public function test_resend_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey(
			'/buddynext/v1/auth/verify/resend',
			$routes,
			'POST /auth/verify/resend must be registered in the REST server.'
		);
	}

	public function test_status_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey(
			'/buddynext/v1/auth/verify/status',
			$routes,
			'GET /auth/verify/status must be registered in the REST server.'
		);
	}

	// ── Permission: unauthenticated access ───────────────────────────────────

	public function test_resend_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_status_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── GET /auth/verify/status ───────────────────────────────────────────────

	public function test_status_returns_unverified_for_new_user(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'verified', $data );
		$this->assertFalse( $data['verified'] );
	}

	public function test_status_returns_verified_after_token_verification(): void {
		wp_set_current_user( $this->user_id );

		$svc   = new VerificationService();
		$token = $svc->create_token( $this->user_id );
		$svc->verify( $token );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['verified'] );
	}

	// ── POST /auth/verify/resend ──────────────────────────────────────────────

	public function test_resend_returns_200_for_unverified_user(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'message', $data );
	}

	public function test_resend_returns_400_when_already_verified(): void {
		wp_set_current_user( $this->user_id );

		// Manually mark user as verified.
		update_user_meta( $this->user_id, 'buddynext_email_verified', 1 );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}
}
