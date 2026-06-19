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
 * Covers route registration, login, register, verify-resend and verify-status.
 *
 * @covers \BuddyNext\Auth\AuthController
 */
class AuthControllerTest extends \WP_Test_REST_TestCase {

	/**
	 * Reusable verified-flag holder for the resend tests.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Provision the verification feature gate + a fresh user.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Enable the verification feature gate so is_verified() reads usermeta.
		update_option( 'buddynext_email_verify', true );

		$this->user_id = self::factory()->user->create();
	}

	/**
	 * Roll back the verification feature gate after each test.
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_reg_spam_protection' );
		parent::tear_down();
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * POST /auth/verify/resend is registered.
	 */
	public function test_resend_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey(
			'/buddynext/v1/auth/verify/resend',
			$routes,
			'POST /auth/verify/resend must be registered in the REST server.'
		);
	}

	/**
	 * GET /auth/verify/status is registered.
	 */
	public function test_status_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey(
			'/buddynext/v1/auth/verify/status',
			$routes,
			'GET /auth/verify/status must be registered in the REST server.'
		);
	}

	// ── Permission: unauthenticated access ───────────────────────────────────

	/**
	 * Unauthenticated POST /auth/verify/resend returns 401.
	 */
	public function test_resend_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Unauthenticated GET /auth/verify/status returns 401.
	 */
	public function test_status_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── GET /auth/verify/status ───────────────────────────────────────────────

	/**
	 * Newly created user is unverified.
	 */
	public function test_status_returns_unverified_for_new_user(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/auth/verify/status' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'verified', $data );
		$this->assertFalse( $data['verified'] );
	}

	/**
	 * After a token is verified the status endpoint reports verified.
	 */
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

	/**
	 * Resend returns 200 for an unverified user.
	 */
	public function test_resend_returns_200_for_unverified_user(): void {
		wp_set_current_user( $this->user_id );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Resend rejects already-verified users.
	 */
	public function test_resend_returns_400_when_already_verified(): void {
		wp_set_current_user( $this->user_id );

		// Manually mark user as verified.
		update_user_meta( $this->user_id, 'buddynext_email_verified', 1 );

		$request  = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' );
		$response = rest_do_request( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	// ── Route registration: login + register ─────────────────────────────────

	/**
	 * POST /auth/login is registered.
	 */
	public function test_login_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/auth/login', $routes );
	}

	/**
	 * POST /auth/register is registered.
	 */
	public function test_register_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'buddynext/v1' );
		$this->assertArrayHasKey( '/buddynext/v1/auth/register', $routes );
	}

	// ── POST /auth/login ─────────────────────────────────────────────────────

	/**
	 * Login with empty credentials returns 400.
	 */
	public function test_login_requires_credentials(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_param( 'user', '' );
		$request->set_param( 'password', '' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Login with the wrong password returns 401.
	 */
	public function test_login_rejects_bad_password(): void {
		wp_set_current_user( 0 );
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'logintest_' . wp_generate_password( 6, false ),
				'user_email' => 'logintest_' . wp_generate_password( 6, false ) . '@example.com',
				'user_pass'  => 'correct-horse-battery-staple',
			)
		);
		$user    = get_userdata( $user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_param( 'user', $user->user_login );
		$request->set_param( 'password', 'wrong-password' );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Login with valid credentials returns 200 + success payload.
	 */
	public function test_login_accepts_valid_credentials(): void {
		wp_set_current_user( 0 );
		$pw      = 'right-password-123!';
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'logingood_' . wp_generate_password( 6, false ),
				'user_email' => 'logingood_' . wp_generate_password( 6, false ) . '@example.com',
				'user_pass'  => $pw,
			)
		);
		$user    = get_userdata( $user_id );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_param( 'user', $user->user_login );
		$request->set_param( 'password', $pw );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( $user_id, $data['user_id'] );
	}

	/**
	 * Login resolves an email address to the matching username.
	 */
	public function test_login_resolves_email_to_username(): void {
		wp_set_current_user( 0 );
		$pw    = 'right-password-456!';
		$email = 'byemail_' . wp_generate_password( 6, false ) . '@example.com';
		self::factory()->user->create(
			array(
				'user_login' => 'byemail_' . wp_generate_password( 6, false ),
				'user_email' => $email,
				'user_pass'  => $pw,
			)
		);

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_param( 'user', $email );
		$request->set_param( 'password', $pw );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
	}

	// ── POST /auth/register ──────────────────────────────────────────────────

	/**
	 * Register returns 403 when registration is disabled.
	 */
	public function test_register_rejects_when_registration_closed(): void {
		wp_set_current_user( 0 );
		update_option( 'users_can_register', 0 );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', 'new@example.com' );
		$request->set_param( 'user_login', 'newuser_' . wp_generate_password( 6, false ) );
		$request->set_param( 'password', 'longenoughpw' );
		$request->set_param( 'terms_agreed', true );

		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Register returns 422 with per-field errors when the payload is invalid.
	 */
	public function test_register_validates_fields(): void {
		wp_set_current_user( 0 );
		update_option( 'users_can_register', 1 );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', 'not-an-email' );
		// Too short.
		$request->set_param( 'user_login', 'x' );
		$request->set_param( 'password', 'short' );
		$request->set_param( 'terms_agreed', false );

		$response = rest_do_request( $request );
		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'fields', $data['data'] );
		$this->assertArrayHasKey( 'email', $data['data']['fields'] );
		$this->assertArrayHasKey( 'user_login', $data['data']['fields'] );
		$this->assertArrayHasKey( 'password', $data['data']['fields'] );
		$this->assertArrayHasKey( 'terms_agreed', $data['data']['fields'] );
	}

	/**
	 * Register creates a new WP user when the payload is valid.
	 */
	public function test_register_creates_user_with_valid_payload(): void {
		wp_set_current_user( 0 );
		update_option( 'users_can_register', 1 );

		// The in-house RegistrationGuard (rate-limit, human-challenge, time-trap) is
		// default-on and has its own coverage; this test exercises account creation,
		// so disable spam protection rather than mint guard tokens here.
		update_option( 'buddynext_reg_spam_protection', '' );

		$user_login = 'newgood_' . wp_generate_password( 6, false );
		$email      = $user_login . '@example.com';

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', $email );
		$request->set_param( 'user_login', $user_login );
		$request->set_param( 'password', 'longenoughpw' );
		$request->set_param( 'terms_agreed', true );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertGreaterThan( 0, $data['user_id'] );
		$this->assertNotFalse( get_user_by( 'login', $user_login ) );
	}

	/**
	 * Register surfaces a per-field error when the email already exists.
	 */
	public function test_register_rejects_duplicate_email(): void {
		wp_set_current_user( 0 );
		update_option( 'users_can_register', 1 );

		$email = 'dupe_' . wp_generate_password( 6, false ) . '@example.com';
		self::factory()->user->create( array( 'user_email' => $email ) );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', $email );
		$request->set_param( 'user_login', 'dupe_' . wp_generate_password( 6, false ) );
		$request->set_param( 'password', 'longenoughpw' );
		$request->set_param( 'terms_agreed', true );

		$response = rest_do_request( $request );
		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'email', $data['data']['fields'] );
	}
}
