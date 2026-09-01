<?php
/**
 * Login and 2FA must not hand the browser an off-site destination.
 *
 * Reproduces card 10227862947 as a code flow.
 *
 * `GET /login/?redirect_to=https://evil.example/phish` is carried into
 * `POST /buddynext/v1/auth/login`, and the handler returns it as
 * `esc_url_raw( $redirect_to )`. `esc_url_raw()` sanitises characters; it does
 * not restrict the HOST. `assets/js/auth/login-store.js:126` then does
 * `window.location.href = data.redirect_to` — after `complete_login()` has
 * already set the session cookie.
 *
 * So the member signs in successfully on the real site and lands on the
 * attacker's, one keystroke from a convincing "your session expired, sign in
 * again" page. The same applies to `/auth/2fa`, which repeats the pattern.
 *
 * ## The precedent is already in the same class
 *
 * `AuthController` line ~1672 handles parked social signup and does it correctly:
 *
 *     // Same-origin only - wp_validate_redirect() returns '' for anything
 *     $parked_destination = wp_validate_redirect( (string) ( $pending['redirect_to'] ?? '' ), '' );
 *
 * One path guarded, two not. This is a missing call, not a missing idea.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Post-login redirect destinations.
 *
 * @covers \BuddyNext\Auth\AuthController::login
 */
class LoginRedirectMustBeSameOriginTest extends WP_UnitTestCase {

	/**
	 * A member who can sign in.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Their password.
	 *
	 * @var string
	 */
	private const PASSWORD = 'correct-horse-battery-staple';

	/**
	 * Register the REST routes and create the member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );

		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'redirect_probe',
				'user_pass'  => self::PASSWORD,
				'user_email' => 'redirect_probe@example.com',
			)
		);
	}

	/**
	 * Sign in through the REST route and return the destination handed back.
	 *
	 * @param string $redirect_to Value supplied by the caller.
	 * @return string
	 */
	private function login_and_get_destination( string $redirect_to ): string {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_param( 'user', 'redirect_probe' );
		$request->set_param( 'password', self::PASSWORD );
		$request->set_param( 'redirect_to', $redirect_to );

		$response = rest_get_server()->dispatch( $request );
		$data     = (array) $response->get_data();

		$this->assertTrue(
			! empty( $data['success'] ),
			'Precondition: the login itself must succeed, or this proves nothing about the redirect. Got: ' . wp_json_encode( $data )
		);

		return (string) ( $data['redirect_to'] ?? '' );
	}

	/**
	 * An off-site destination must not survive the round trip.
	 *
	 * @return void
	 */
	public function test_an_offsite_destination_is_refused(): void {
		$destination = $this->login_and_get_destination( 'https://evil.example/phish' );

		$host = (string) wp_parse_url( $destination, PHP_URL_HOST );

		$this->assertNotSame(
			'evil.example',
			$host,
			'The login response handed the browser an attacker-controlled URL, and the JS assigns it to window.location.href after the session cookie is already set.'
		);
	}

	/**
	 * A protocol-relative URL is off-site too, and looks local.
	 *
	 * `//evil.example/x` has no scheme, reads as a path to the eye, and is the
	 * form a naive "does it start with /" check lets through.
	 *
	 * @return void
	 */
	public function test_a_protocol_relative_destination_is_refused(): void {
		$destination = $this->login_and_get_destination( '//evil.example/phish' );

		$host = (string) wp_parse_url( $destination, PHP_URL_HOST );

		$this->assertNotSame( 'evil.example', $host, 'A protocol-relative URL reached the browser as a destination.' );
	}

	/**
	 * An on-site path must still work. Guards the guard.
	 *
	 * A fix that empties every destination would pass both tests above and drop
	 * every member on the default page regardless of where they were headed.
	 *
	 * @return void
	 */
	public function test_an_onsite_destination_still_works(): void {
		$destination = $this->login_and_get_destination( home_url( '/spaces/' ) );

		$this->assertStringContainsString(
			'/spaces/',
			$destination,
			'A legitimate same-origin destination was discarded, so members no longer return to where they were going.'
		);
	}
}
