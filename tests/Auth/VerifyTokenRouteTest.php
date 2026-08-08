<?php
/**
 * POST /auth/verify — the app's email-verification path.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Guards the REST twin of the ?bn_verify link handler.
 *
 * The web path answers with a redirect, which a native app cannot consume, so
 * this route is what lets a member finish signing up without leaving the app.
 * It is deliberately public — the token is the credential — which is exactly
 * why its failure modes need a test rather than a manual check.
 */
final class VerifyTokenRouteTest extends WP_UnitTestCase {

	/**
	 * Boot the REST server so routes are registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a redeem request as an anonymous caller.
	 *
	 * @param string|null $token Token to send, or null to omit it entirely.
	 * @return \WP_REST_Response
	 */
	private function redeem( ?string $token ): \WP_REST_Response {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify' );
		if ( null !== $token ) {
			$request->set_param( 'token', $token );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A valid token verifies the member without any authentication.
	 *
	 * @return void
	 */
	public function test_valid_token_verifies_anonymously(): void {
		$user_id = self::factory()->user->create();
		$service = new VerificationService();
		$token   = $service->create_token( $user_id );

		$response = $this->redeem( $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( (bool) $response->get_data()['verified'] );
		$this->assertSame( $user_id, (int) $response->get_data()['user_id'] );
		$this->assertTrue(
			$service->is_verified( $user_id ),
			'The route must change the stored state, not merely answer 200.'
		);
	}

	/**
	 * A token is single-use.
	 *
	 * Without this, an intercepted link stays redeemable forever.
	 *
	 * @return void
	 */
	public function test_token_cannot_be_replayed(): void {
		$user_id = self::factory()->user->create();
		$token   = ( new VerificationService() )->create_token( $user_id );

		$this->assertSame( 200, $this->redeem( $token )->get_status() );
		$this->assertSame( 400, $this->redeem( $token )->get_status() );
	}

	/**
	 * An unknown token fails, and fails the SAME way an expired one does.
	 *
	 * Distinguishing them to an unauthenticated caller would turn the route into
	 * an oracle for probing which tokens exist.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_indistinguishable_from_expired(): void {
		$user_id = self::factory()->user->create();
		$used    = ( new VerificationService() )->create_token( $user_id );
		$this->redeem( $used );

		$spent   = $this->redeem( $used );
		$unknown = $this->redeem( 'definitely-not-a-token' );

		$this->assertSame( 400, $spent->get_status() );
		$this->assertSame( 400, $unknown->get_status() );
		$this->assertSame(
			$spent->get_data()['code'],
			$unknown->get_data()['code'],
			'A spent token and an unknown token must not be tellable apart.'
		);
	}

	/**
	 * The shared action fires, so every listener runs on the app path too.
	 *
	 * This is the assertion that stops the REST and web paths diverging: welcome
	 * mail, approval and onboarding all hang off this hook.
	 *
	 * @return void
	 */
	public function test_verified_action_fires(): void {
		$user_id = self::factory()->user->create();
		$token   = ( new VerificationService() )->create_token( $user_id );

		$fired = array();
		add_action(
			'buddynext_email_verified',
			static function ( $id ) use ( &$fired ): void {
				$fired[] = (int) $id;
			}
		);

		$this->redeem( $token );

		$this->assertSame( array( $user_id ), $fired );
	}
}
