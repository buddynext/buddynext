<?php
/**
 * Tests for GET /auth/register/config — the native-app signup contract.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * Regression cover for "a native app cannot register a user at all".
 *
 * With spam protection on (the shipped default) an app posting to /auth/register
 * was told to "solve the verification question" — a question it had no endpoint
 * to fetch. The only escape was for the owner to disable spam protection, i.e.
 * to ship an app that requires every site running it to lower its own defences.
 *
 * @covers \BuddyNext\Auth\AuthController
 */
class RegisterConfigTest extends \WP_Test_REST_TestCase {

	/**
	 * Fresh schema, with the shipped defaults: spam protection AND human check on.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );

		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_reg_spam_protection', '1' );
		update_option( 'buddynext_reg_challenge', '1' );
	}

	/**
	 * Solve the human-check question the way a real client does — read it.
	 *
	 * @param string $question e.g. "What is three plus five?".
	 * @return string The numeric answer.
	 */
	private function solve( string $question ): string {
		$words = array(
			'one'   => 1,
			'two'   => 2,
			'three' => 3,
			'four'  => 4,
			'five'  => 5,
			'six'   => 6,
			'seven' => 7,
			'eight' => 8,
			'nine'  => 9,
		);

		$total = 0;
		foreach ( $words as $word => $value ) {
			$total += $value * preg_match_all( '/\b' . $word . '\b/', strtolower( $question ) );
		}

		return (string) $total;
	}

	/**
	 * The route exists and hands the app everything it needs to clear the gate.
	 */
	public function test_config_returns_the_guard_bundle_and_the_contract(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/auth/register/config' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['reg_token'], 'the app needs a time-trap token' );
		$this->assertNotEmpty( $data['honeypot_field'], 'the app needs the honeypot field name' );
		$this->assertNotEmpty( $data['challenge']['token'] );
		$this->assertNotEmpty( $data['challenge']['question'] );
		$this->assertArrayHasKey( 'fields', $data );
		$this->assertArrayHasKey( 'terms', $data );
		$this->assertSame( 'open', $data['mode'] );
	}

	/**
	 * The answer is never sent to the client. The whole point of the HMAC design
	 * is that the client can be told the question and nothing else.
	 */
	public function test_config_never_leaks_the_challenge_answer(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/auth/register/config' ) )->get_data();

		$this->assertArrayNotHasKey( 'answer', $data['challenge'] );
		$this->assertStringNotContainsString( 'answer', wp_json_encode( $data['challenge'] ) );
	}

	/**
	 * THE REGRESSION: an app that fetches the config can complete a registration,
	 * with spam protection and the human check both left ON.
	 */
	public function test_app_can_register_end_to_end_using_the_config_bundle(): void {
		$config = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/auth/register/config' ) )->get_data();

		// The time-trap rejects anything submitted in under MIN_SECONDS.
		sleep( 3 );

		$login = 'appuser_' . wp_generate_password( 6, false );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', $login . '@example.com' );
		$request->set_param( 'user_login', $login );
		$request->set_param( 'password', 'CorrectHorse9!' );
		$request->set_param( 'terms_agreed', true );
		$request->set_param( 'reg_token', $config['reg_token'] );
		$request->set_param( 'challenge_token', $config['challenge']['token'] );
		$request->set_param( 'challenge_answer', $this->solve( $config['challenge']['question'] ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNotFalse( get_user_by( 'login', $login ) );
	}

	/**
	 * And without the bundle it is still refused — we did not "fix" the app by
	 * exempting REST from the guard, which would hand every bot a
	 * protection-free registration endpoint.
	 */
	public function test_registering_without_the_bundle_is_still_blocked(): void {
		$login = 'bot_' . wp_generate_password( 6, false );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/register' );
		$request->set_param( 'email', $login . '@example.com' );
		$request->set_param( 'user_login', $login );
		$request->set_param( 'password', 'CorrectHorse9!' );
		$request->set_param( 'terms_agreed', true );

		$response = rest_do_request( $request );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertFalse( get_user_by( 'login', $login ) );
	}
}
