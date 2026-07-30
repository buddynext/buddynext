<?php
/**
 * The email one-time code is a real second factor, and it says so.
 *
 * The send/verify pair has worked since the login guard shipped, and had no test
 * and no mention in any API response. So the only way to discover it was to read
 * PHP - and QA, probing /account/2fa/request-code, /account/2fa/verify and
 * /account/2fa/send-code, reported email 2FA as "still 404, all three routes
 * missing". None of those routes has ever existed: requesting and submitting a code
 * happen DURING a login challenge, so they live under /auth/, where there is no
 * authenticated session yet.
 *
 * These tests cover both halves of that: the factor works, and GET /account/2fa
 * advertises it.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\TwoFactorController;
use BuddyNext\Auth\TwoFactorService;

/**
 * @covers \BuddyNext\Auth\TwoFactorService::send_email_code
 * @covers \BuddyNext\Auth\TwoFactorService::verify_email_code
 * @covers \BuddyNext\Auth\TwoFactorService::available_methods
 * @covers \BuddyNext\Auth\TwoFactorService::email_fallback_available
 * @covers \BuddyNext\Auth\TwoFactorController::status
 */
class TwoFactorEmailFallbackTest extends \WP_UnitTestCase {

	/**
	 * Mails wp_mail() was asked to send during a test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'wp_mail',
			function ( $args ) {
				$this->mails[] = $args;
				return $args;
			},
			999
		);
		// Short-circuit the actual mailer: the assertions are about what we asked to
		// send, not about SMTP.
		add_filter( 'pre_wp_mail', '__return_true', 999 );
	}

	/**
	 * Pull the 6-digit code out of the last mail sent.
	 */
	private function last_code(): string {
		$this->assertNotEmpty( $this->mails, 'no mail was sent, so there is no code to read' );
		$body = wp_strip_all_tags( (string) end( $this->mails )['message'] );
		preg_match( '/\b(\d{6})\b/', $body, $m );

		return (string) ( $m[1] ?? '' );
	}

	/**
	 * The full cycle: a code is emailed, the right one passes, and it is single-use.
	 *
	 * Single-use is the assertion that matters most. A one-time code that can be
	 * replayed is not a second factor — anyone who reads the member's inbox once
	 * keeps a working key until it expires.
	 */
	public function test_email_code_verifies_once_and_is_then_consumed(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( TwoFactorService::send_email_code( $user_id ) );

		// Assert on the LAST mail, not on how many were sent: creating the user
		// fixture also mails (the new-user notification), so a count here would be
		// asserting something about WordPress rather than about this feature.
		$this->assertStringContainsString(
			'sign-in code',
			(string) end( $this->mails )['subject'],
			'the most recent mail should be the 2FA code'
		);

		$code = $this->last_code();
		$this->assertMatchesRegularExpression( '/\A\d{6}\z/', $code, 'the email must carry a 6-digit code' );

		$this->assertFalse( TwoFactorService::verify_email_code( $user_id, '000000' ), 'a wrong code must not verify' );
		$this->assertTrue( TwoFactorService::verify_email_code( $user_id, $code ), 'the emailed code must verify' );
		$this->assertFalse( TwoFactorService::verify_email_code( $user_id, $code ), 'the code must be consumed after one use' );
	}

	/**
	 * A code issued for one member cannot be used by another.
	 */
	public function test_email_code_is_bound_to_its_member(): void {
		$owner     = self::factory()->user->create();
		$other     = self::factory()->user->create();

		TwoFactorService::send_email_code( $owner );
		$code = $this->last_code();

		$this->assertFalse( TwoFactorService::verify_email_code( $other, $code ), 'a code must not verify for a different member' );
		$this->assertTrue( TwoFactorService::verify_email_code( $owner, $code ) );
	}

	/**
	 * The fallback needs an address, and nothing else — there is deliberately no
	 * owner switch for it, because one would let a site lock its own members out.
	 */
	public function test_email_fallback_requires_an_address(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$this->assertTrue( TwoFactorService::email_fallback_available( $user_id ) );

		// Straight to the column: wp_update_user() refuses an empty email, but a row
		// can still reach this state (an import, a direct edit), and the guard has
		// to hold when it does.
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );

		$this->assertFalse( TwoFactorService::email_fallback_available( $user_id ) );
		$this->assertFalse( TwoFactorService::send_email_code( $user_id ), 'nothing to send to, so nothing is sent' );
	}

	/**
	 * available_methods() reports what the member can actually finish a challenge
	 * with — and reports nothing at all when there is no challenge to finish.
	 */
	public function test_available_methods_tracks_what_the_member_can_use(): void {
		$user_id = self::factory()->user->create();

		$this->assertSame( array(), TwoFactorService::available_methods( $user_id ), '2FA off means no methods to offer' );

		$this->enable_2fa( $user_id );

		$methods = TwoFactorService::available_methods( $user_id );
		$this->assertContains( 'totp', $methods );
		$this->assertContains( 'email', $methods );
		$this->assertSame( 'totp', $methods[0], 'the authenticator the member set up is offered first' );
	}

	/**
	 * GET /account/2fa advertises the factors and where to use them.
	 *
	 * This is the half that was missing: the payload carried enabled / required /
	 * backup_remaining and never named a method or a route, so a client could not
	 * discover the email option existed.
	 */
	public function test_status_payload_advertises_the_email_fallback(): void {
		$user_id = self::factory()->user->create();
		$this->enable_2fa( $user_id );
		wp_set_current_user( $user_id );

		$data = ( new TwoFactorController() )->status()->get_data();

		$this->assertTrue( $data['enabled'] );
		$this->assertTrue( $data['email_fallback'], 'the email fallback must be discoverable' );
		$this->assertContains( 'email', $data['methods'] );

		// The routes a client needs, named rather than guessed. These are the ones QA
		// could not find; asserting them here means a rename cannot silently
		// un-document the feature again.
		$this->assertArrayHasKey( 'challenge', $data );
		$this->assertStringContainsString( '/auth/2fa/email-code', (string) $data['challenge']['request_email_code'] );
		$this->assertStringContainsString( '/auth/2fa', (string) $data['challenge']['submit_code'] );
	}

	/**
	 * Turn on 2FA for a member the way enrolment does, so `is_enabled()` agrees.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function enable_2fa( int $user_id ): void {
		update_user_meta( $user_id, 'bn_2fa_secret', 'JBSWY3DPEHPK3PXP' );
		update_user_meta( $user_id, 'bn_2fa_enabled', '1' );

		$this->assertTrue(
			TwoFactorService::is_enabled( $user_id ),
			'fixture did not enable 2FA, so the assertions below would prove nothing'
		);
	}
}
