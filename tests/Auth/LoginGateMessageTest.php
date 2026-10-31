<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A member with the right password is told the real reason they cannot sign in.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\Installer;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Over real HTTP REST_REQUEST is defined, so once any application password exists core's
 * wp_authenticate_application_password() overwrites a gate's error with incorrect_password.
 * rest_do_request() has no REST_REQUEST, which is why this needs the two filters below.
 *
 * @covers \BuddyNext\Auth\AuthController::login
 */
class LoginGateMessageTest extends WP_UnitTestCase {

	/**
	 * Password used by every account here.
	 */
	private const PASSWORD = 'qafix-Pass-1!';

	/**
	 * Fresh schema, and the conditions core masks under.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		rest_get_server();
		update_network_option( get_main_network_id(), \WP_Application_Passwords::OPTION_KEY_IN_USE, 1 );
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Clean filters and the option.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_network_option( get_main_network_id(), \WP_Application_Passwords::OPTION_KEY_IN_USE );
		remove_all_filters( 'application_password_is_api_request' );
		remove_all_filters( 'wp_is_application_passwords_available' );
		parent::tear_down();
	}

	/**
	 * POST /auth/login.
	 *
	 * @param string $user     Body key `user`.
	 * @param string $password Password.
	 * @return \WP_REST_Response
	 */
	private function login( string $user, string $password ) {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/login' );
		$request->set_body_params(
			array(
				'user'     => $user,
				'password' => $password,
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * An account with a known password.
	 *
	 * @param string $login Login.
	 * @return int
	 */
	private function account( string $login ): int {
		return self::factory()->user->create(
			array(
				'user_login' => $login,
				'user_pass'  => self::PASSWORD,
				'user_email' => "{$login}@example.test",
			)
		);
	}

	/**
	 * Pending with the right password hears the truth; with a wrong one, nothing about state.
	 *
	 * @return void
	 */
	public function test_pending_member(): void {
		$uid = $this->account( 'qafix_pending' );
		update_user_meta( $uid, 'bn_pending_approval', 1 );

		$right = $this->login( 'qafix_pending', self::PASSWORD );
		$this->assertSame( 403, $right->get_status() );
		$this->assertSame( 'bn_pending_approval', $right->get_data()['code'] );
		$this->assertStringContainsString( 'awaiting', $right->get_data()['message'] );

		$this->assert_generic( $this->login( 'qafix_pending', 'not-the-password' ) );
		$this->assertSame( 403, $this->login( 'qafix_pending@example.test', self::PASSWORD )->get_status(), 'By email too.' );
	}

	/**
	 * The same response whether the account is pending, ordinary or absent.
	 *
	 * @return void
	 */
	public function test_wrong_password_reveals_no_state(): void {
		$pending = $this->account( 'qafix_held' );
		update_user_meta( $pending, 'bn_pending_approval', 1 );
		$this->account( 'qafix_plain' );

		$this->assert_generic( $this->login( 'qafix_held', 'wrong' ) );
		$this->assert_generic( $this->login( 'qafix_plain', 'wrong' ) );
		$this->assert_generic( $this->login( 'qafix_nobody', 'wrong' ) );
	}

	/**
	 * Any other plugin's gate gets the same treatment, on either filter.
	 *
	 * @return void
	 */
	public function test_any_gate_is_reported_only_to_the_password_holder(): void {
		$this->account( 'qafix_gated' );

		$gate = static fn( $user ) => $user instanceof \WP_User && 'qafix_gated' === $user->user_login
			? new WP_Error( 'other_plugin_unverified', '<b>Confirm your account first.</b>' )
			: $user;

		add_filter( 'wp_authenticate_user', $gate );
		$right = $this->login( 'qafix_gated', self::PASSWORD );
		$this->assert_generic( $this->login( 'qafix_gated', 'wrong' ) );
		remove_filter( 'wp_authenticate_user', $gate );

		$this->assertSame( 403, $right->get_status() );
		$this->assertSame( 'other_plugin_unverified', $right->get_data()['code'] );
		$this->assertSame( 'Confirm your account first.', $right->get_data()['message'] );

		add_filter( 'authenticate', $gate, 30 );
		$late = $this->login( 'qafix_gated', self::PASSWORD );
		remove_filter( 'authenticate', $gate, 30 );
		$this->assertSame( 403, $late->get_status() );
		$this->assertSame( 'other_plugin_unverified', $late->get_data()['code'] );
	}

	/**
	 * Ordinary members still sign in, in the same conditions.
	 *
	 * @return void
	 */
	public function test_ordinary_member_still_signs_in(): void {
		$this->account( 'qafix_ok' );
		$this->assertSame( 200, $this->login( 'qafix_ok', self::PASSWORD )->get_status() );
	}

	/**
	 * The one generic failure.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return void
	 */
	private function assert_generic( $response ): void {
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_login_failed', $response->get_data()['code'] );
		$this->assertSame( 'That email, username or password is not right.', $response->get_data()['message'] );
	}
}
