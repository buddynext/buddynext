<?php
/**
 * The verification x 2FA gate pair must not redirect into each other.
 *
 * Same bug class GatePrecedenceTest pins for onboarding x 2FA (card
 * 10143075880): VerificationListener::maybe_gate_unverified() (priority 6)
 * exempts only /verify, TwoFactorService::enforce_enrolment() (priority 7)
 * exempts only /settings. A member who is BOTH unverified (full enforcement)
 * AND 2FA-required ping-ponged /verify -> /settings -> /verify forever.
 *
 * The precedence pinned here: identity before enrolment. The 2FA gate stands
 * down while a verification hold is live (VerificationListener::holds()) and
 * resumes once the member verifies.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Auth\VerificationListener;

/**
 * @covers \BuddyNext\Auth\TwoFactorService::enforce_enrolment
 * @covers \BuddyNext\Auth\VerificationListener::holds
 */
class VerificationTwoFactorPrecedenceTest extends \WP_UnitTestCase {

	/**
	 * Member subject to both holds.
	 *
	 * @var int
	 */
	private $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		// Verification on, full enforcement, switched on long before this
		// member registered - so the grandfather clause does not verify them.
		update_option( 'buddynext_email_verify', 1 );
		update_option( 'buddynext_verify_enforcement', 'full' );
		update_option( 'buddynext_email_verify_enabled_at', 1 );

		// Require 2FA of everyone, so this member is held by that gate too.
		update_option( 'buddynext_2fa_required', 'all' );

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_verify_enforcement' );
		delete_option( 'buddynext_email_verify_enabled_at' );
		delete_option( 'buddynext_2fa_required' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Fixture guard: both holds are genuinely live, or everything below passes
	 * vacuously.
	 */
	public function test_the_member_is_under_both_holds(): void {
		$this->assertTrue(
			VerificationListener::holds( $this->user_id ),
			'Fixture is wrong: no verification hold is live, so nothing is being tested.'
		);
		$this->assertTrue(
			TwoFactorService::is_required_for( get_userdata( $this->user_id ) ),
			'Fixture is wrong: 2FA is not required of this member.'
		);
		$this->assertFalse(
			TwoFactorService::is_enabled( $this->user_id ),
			'Fixture is wrong: the member is already enrolled.'
		);
	}

	/**
	 * The regression: while the verification hold is live the 2FA gate must
	 * stand down, or the pair redirect into each other forever.
	 */
	public function test_two_factor_stands_down_while_a_verification_hold_is_live(): void {
		$this->assertFalse(
			$this->two_factor_would_redirect(),
			'The 2FA gate fired while a verification hold was live - the /verify <-> /settings redirect loop is back.'
		);
	}

	/**
	 * ...and it must resume once the member verifies, or the stand-down would
	 * have quietly disabled forced enrolment on every verification-gated site.
	 */
	public function test_two_factor_resumes_once_the_member_is_verified(): void {
		update_user_meta( $this->user_id, 'buddynext_email_verified', 1 );

		$this->assertFalse(
			VerificationListener::holds( $this->user_id ),
			'Verification fixture did not take.'
		);
		$this->assertTrue(
			$this->two_factor_would_redirect(),
			'The 2FA gate stayed down after the verification hold cleared - forced enrolment is now unreachable.'
		);
	}

	/**
	 * Admins are exempt from the verification hold, so the 2FA gate must treat
	 * them as un-held and keep enforcing enrolment (no white screen, and no
	 * silently un-enforced admin 2FA either).
	 */
	public function test_admin_is_not_verification_held_and_two_factor_still_enforces(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertFalse(
			VerificationListener::holds( $admin ),
			'An administrator must never be under the verification hold.'
		);
		$this->assertTrue(
			$this->two_factor_would_redirect(),
			'The 2FA gate stood down for an admin who is not verification-held.'
		);
	}

	/**
	 * Would the 2FA gate redirect the current member right now? Observed at the
	 * gate's final filter, mirroring GatePrecedenceTest.
	 *
	 * @return bool
	 */
	private function two_factor_would_redirect(): bool {
		$fired = false;

		$spy = static function () use ( &$fired ) {
			$fired = true;
			// Never actually redirect inside the test process.
			return false;
		};

		add_filter( 'buddynext_enforce_2fa_enrolment', $spy );
		TwoFactorService::enforce_enrolment();
		remove_filter( 'buddynext_enforce_2fa_enrolment', $spy );

		return $fired;
	}
}
