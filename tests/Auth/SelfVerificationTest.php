<?php
/**
 * A member who was never asked to verify must still be able to.
 *
 * `is_verified()` grandfathers everyone who registered before verification was
 * switched on, so that enabling the setting on an existing community does not lock
 * that community out. `resend()` short-circuited on `is_verified()` — so for exactly
 * those members it answered `already_verified` and refused to send anything.
 *
 * The result: on any site that turned verification on after launch, the entire
 * existing membership — the owner and every admin included — could never prove their
 * address, and after the badge fix could never earn the verified badge either. There
 * was no route to it: the only way to get the usermeta is to click a token link, and
 * the only thing that issues one refused to.
 *
 * The correction is to ask the right question. `resend()` decides "do you still need
 * to prove your address?", and the grandfather rule is about ACCESS, not about
 * whether anyone proved anything — the same distinction has_verified_badge() draws.
 * So it keys off the meta: refuse the member who has genuinely verified, serve
 * everyone else.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationService;

/**
 * Requesting verification on demand.
 *
 * @covers \BuddyNext\Auth\VerificationService::resend
 */
class SelfVerificationTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var VerificationService
	 */
	private VerificationService $service;

	/**
	 * Turn verification on, feature and setting both.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new VerificationService();

		update_option( 'buddynext_email_verify', 1 );
		$features                 = (array) get_option( 'buddynext_features', array() );
		$features['verification'] = true;
		update_option( 'buddynext_features', $features );
	}

	/**
	 * Reset the options this test drives.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_email_verify_enabled_at' );
		delete_option( 'buddynext_features' );
		parent::tearDown();
	}

	/**
	 * A member who registered before verification was switched on.
	 *
	 * @return int
	 */
	private function grandfathered_member(): int {
		$user_id = (int) $this->factory->user->create(
			array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS ) )
		);
		update_option( 'buddynext_email_verify_enabled_at', time() - DAY_IN_SECONDS );

		return $user_id;
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * A grandfathered member can ask to verify, and gets a token.
	 *
	 * This is the support ticket: the site owner and every admin on a community that
	 * enabled verification after launch had no way to prove their address.
	 *
	 * @return void
	 */
	public function test_a_grandfathered_member_can_request_verification(): void {
		$user_id = $this->grandfathered_member();

		$this->assertTrue(
			$this->service->is_verified( $user_id ),
			'precondition: this member is grandfathered past the access gate'
		);

		$result = $this->service->resend( $user_id );

		$this->assertNotWPError(
			$result,
			'A member grandfathered past the ACCESS gate was refused the chance to prove their '
			. 'address, so they could never earn the verified badge and no route to it existed.'
		);
		$this->assertNotSame( '', (string) $result, 'a token must actually be issued' );
	}

	/**
	 * ...and the request really sends the mail.
	 *
	 * The token alone is useless if nothing delivers it, and the send is an action
	 * rather than a return value, so it needs its own assertion.
	 *
	 * @return void
	 */
	public function test_requesting_verification_sends_the_email(): void {
		$user_id = $this->grandfathered_member();

		$sent = 0;
		add_action(
			'buddynext_send_verification_email',
			static function () use ( &$sent ): void {
				++$sent;
			}
		);

		$this->service->resend( $user_id );

		$this->assertSame( 1, $sent, 'requesting verification must dispatch the verification email' );
	}

	/**
	 * Verifying then earns the badge.
	 *
	 * Closes the loop the support ticket actually asked about: the button exists so
	 * that the check appears.
	 *
	 * @return void
	 */
	public function test_verifying_earns_the_badge(): void {
		$user_id = $this->grandfathered_member();

		$this->assertFalse(
			$this->service->has_verified_badge( $user_id ),
			'precondition: a grandfathered member starts with no badge'
		);

		$token = $this->service->resend( $user_id );
		$this->assertNotWPError( $token, 'precondition: a token must be issued' );

		$this->service->verify( (string) $token );

		$this->assertTrue(
			$this->service->has_verified_badge( $user_id ),
			'a member who proves their address must get the badge'
		);
	}

	// ── What must keep working ───────────────────────────────────────────────────

	/**
	 * A member who has genuinely verified is still refused.
	 *
	 * Guards against "fixing" this by removing the check: re-issuing tokens to
	 * already-verified members is a mail-flood and a pointless attack surface.
	 *
	 * @return void
	 */
	public function test_an_already_verified_member_is_still_refused(): void {
		$user_id = (int) $this->factory->user->create();
		update_user_meta( $user_id, 'buddynext_email_verified', 1 );

		$result = $this->service->resend( $user_id );

		$this->assertWPError( $result, 'a member who already verified must not be sent another link' );
		$this->assertSame( 'already_verified', $result->get_error_code() );
	}

	/**
	 * An ordinary unverified member is unaffected.
	 *
	 * The regression guard for the three surfaces that already call this route — the
	 * verify screen, the composer and the post card — all of which serve members who
	 * are being held at the gate.
	 *
	 * @return void
	 */
	public function test_an_ordinary_unverified_member_still_gets_a_token(): void {
		$user_id = (int) $this->factory->user->create();

		$this->assertFalse(
			$this->service->is_verified( $user_id ),
			'precondition: a member who registered after the setting was on is not verified'
		);

		$this->assertNotWPError(
			$this->service->resend( $user_id ),
			'the existing resend path must keep working for a member held at the gate'
		);
	}
}
