<?php
/**
 * The second step of a two-factor sign-in must replay the same gates as the first.
 *
 * A challenge ticket lives up to five minutes. A member put on hold in that window
 * used to get a session anyway, because both completions set the cookie by hand.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\SessionIssuer;
use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Auth\SessionIssuer::finish_second_factor
 * @covers \BuddyNext\Auth\AuthController::verify_two_factor
 */
class TwoFactorCompletionGatesTest extends \WP_UnitTestCase {

	/**
	 * Mails the case asked WordPress to send.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mails = array();

	/**
	 * Fresh schema, and a mailer that records instead of sending.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->mails = array();
		add_filter(
			'wp_mail',
			function ( $args ) {
				$this->mails[] = $args;
				return $args;
			},
			999
		);
		add_filter( 'pre_wp_mail', '__return_true', 999 );
	}

	/**
	 * A member with a ticket and the emailed code that will verify it.
	 *
	 * @return array{0:int,1:string,2:string} User id, ticket token, code.
	 */
	private function member_with_ticket(): array {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_2fa_enabled', '1' );
		update_user_meta( $user_id, 'bn_2fa_secret', TwoFactorService::generate_secret() );

		$token = TwoFactorService::issue_login_challenge( $user_id, false );
		TwoFactorService::send_email_code( $user_id );
		preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( (string) end( $this->mails )['message'] ), $m );

		return array( $user_id, $token, (string) ( $m[1] ?? '' ) );
	}

	/**
	 * Post the code to the REST second step.
	 *
	 * @param string $token Ticket.
	 * @param string $code  Emailed code.
	 * @return \WP_REST_Response
	 */
	private function submit( string $token, string $code ) {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/auth/2fa' );
		$request->set_param( 'twofa_token', $token );
		$request->set_param( 'code', $code );

		return rest_do_request( $request );
	}

	/**
	 * Held after the ticket was issued: the right code must not open a session.
	 */
	public function test_member_held_after_ticket_is_refused_at_the_code_step(): void {
		list( $user_id, $token, $code ) = $this->member_with_ticket();
		update_user_meta( $user_id, 'bn_pending_approval', '1' );

		$response = $this->submit( $token, $code );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_2fa_refused', $response->as_error()->get_error_code() );
		$this->assertSame( 0, get_current_user_id(), 'a held member must not be signed in' );
	}

	/**
	 * A network refusal is never spelled out.
	 */
	public function test_refusal_message_hides_a_network_refusal(): void {
		$blocked = new \WP_Error( 'bn_blocked_ip', 'Your IP is on the list.' );
		$held    = new \WP_Error( 'bn_pending_approval', 'Awaiting approval.' );

		$this->assertSame( 'Sign-in is not available right now.', SessionIssuer::refusal_message( $blocked ) );
		$this->assertSame( 'Awaiting approval.', SessionIssuer::refusal_message( $held ) );
	}

	/**
	 * The gate must not break the normal path: a clean member still gets in.
	 */
	public function test_clean_member_signs_in_with_the_right_code(): void {
		list( $user_id, $token, $code ) = $this->member_with_ticket();

		$response = $this->submit( $token, $code );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * Same door, direct: the issuer refuses a held member without a challenge.
	 */
	public function test_finish_second_factor_refuses_a_held_member(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_pending_approval', '1' );

		$result = ( new SessionIssuer() )->finish_second_factor( $user_id, false );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_pending_approval', $result->get_error_code() );
		$this->assertSame( 0, get_current_user_id() );
	}
}
