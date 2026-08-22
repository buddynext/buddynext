<?php
/**
 * Redirect gates must not redirect to each other.
 *
 * Two `template_redirect` gates each guarded their OWN destination and neither
 * guarded the other's:
 *
 *   - OnboardingListener::maybe_redirect_to_onboarding() (priority 5) sent an
 *     unfinished member to /onboarding/, exempting only the onboarding + auth
 *     hubs.
 *   - TwoFactorService::enforce_enrolment() (priority 7) sent a member whose
 *     role requires 2FA to /settings/account, exempting only /settings.
 *
 * Individually both were loop-safe. Together they were mutually recursive: the
 * onboarding gate hijacked /settings, so the one screen that could clear the 2FA
 * hold was unreachable, and the member got an infinite
 * /settings -> /onboarding -> /settings bounce - a dead white page, no way out,
 * no explanation.
 *
 * The rule these tests pin: a gate must never hijack another gate's destination,
 * and a security hold outranks a welcome wizard. They assert the PRECEDENCE, not
 * the redirect mechanics, so they keep holding if either gate is rewritten.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Auth\TwoFactorService;

/**
 * Onboarding stands down while a 2FA enrolment hold is live.
 *
 * @covers \BuddyNext\Onboarding\OnboardingListener::maybe_redirect_to_onboarding
 */
class GatePrecedenceTest extends \WP_UnitTestCase {

	/**
	 * Member subject to both gates.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * A member who has finished neither onboarding nor 2FA enrolment.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		// Pin the grandfather cut-off BEFORE this member registered.
		//
		// The gate only redirects members who registered at or after the moment
		// onboarding went live, and it stamps that moment with time() the first
		// time it runs. Left unset, the fixture races the clock: the member is
		// created in one second and the stamp taken in the next, so `registered <
		// gate_since` and the member is treated as part of the back catalogue -
		// the gate stands down and the test fails for a reason that has nothing to
		// do with what it is testing.
		//
		// It failed roughly one run in four that way. Stating the precondition is
		// what a fixture is for; the production rule is correct as written.
		update_option( 'buddynext_onboarding_gate_since', time() - HOUR_IN_SECONDS );

		// Require 2FA of everyone, so this member is held.
		update_option( 'buddynext_2fa_required', 'all' );
	}

	/**
	 * Restore the requirement so neighbouring suites see the default.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_2fa_required' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Mark 2FA as fully enrolled for the current member.
	 *
	 * @return void
	 */
	private function enrol_2fa(): void {
		$ref = new \ReflectionClass( TwoFactorService::class );
		update_user_meta( $this->user_id, (string) $ref->getConstant( 'META_ENABLED' ), '1' );
		update_user_meta( $this->user_id, (string) $ref->getConstant( 'META_SECRET' ), 'JBSWY3DPEHPK3PXP' );
	}

	/**
	 * The precondition the whole bug rests on: this member IS held by the 2FA
	 * gate. If this ever stops being true the tests below would pass vacuously.
	 *
	 * @return void
	 */
	public function test_the_member_is_actually_held_by_the_two_factor_gate(): void {
		$user = get_userdata( $this->user_id );

		$this->assertTrue(
			TwoFactorService::is_required_for( $user ),
			'Fixture is wrong: 2FA is not required of this member, so nothing is being tested.'
		);
		$this->assertFalse(
			TwoFactorService::is_enabled( $this->user_id ),
			'Fixture is wrong: the member is already enrolled, so no hold exists.'
		);
	}

	/**
	 * While the hold is live the onboarding gate must stand down. This is the
	 * regression: if it fires, it redirects the 2FA setup screen away and the
	 * member is back in the infinite bounce.
	 *
	 * @return void
	 */
	public function test_onboarding_stands_down_while_a_two_factor_hold_is_live(): void {
		$this->assertFalse(
			$this->onboarding_would_redirect(),
			'The onboarding gate fired while a 2FA enrolment hold was live — it will hijack the 2FA setup screen and the redirect loop is back.'
		);
	}

	/**
	 * ...and it must resume once the hold clears, or standing down would have
	 * quietly disabled the wizard for every member on a 2FA-required site.
	 *
	 * @return void
	 */
	public function test_onboarding_resumes_once_two_factor_is_enrolled(): void {
		$this->enrol_2fa();

		$this->assertTrue(
			TwoFactorService::is_enabled( $this->user_id ),
			'Enrolment fixture did not take.'
		);
		$this->assertTrue(
			$this->onboarding_would_redirect(),
			'The onboarding gate stayed down after the 2FA hold cleared — the welcome wizard is now unreachable.'
		);
	}

	/**
	 * With 2FA not required at all, the onboarding gate is unaffected. Guards
	 * against the stand-down being written too broadly.
	 *
	 * @return void
	 */
	public function test_onboarding_is_untouched_when_two_factor_is_not_required(): void {
		update_option( 'buddynext_2fa_required', 'none' );

		$this->assertTrue(
			$this->onboarding_would_redirect(),
			'The onboarding gate stood down on a site that does not require 2FA at all.'
		);
	}

	/**
	 * Would the onboarding gate redirect this member right now?
	 *
	 * Asks the gate's own decision rather than driving a request: the filter it
	 * consults last is the honest observation point, and it keeps the test free
	 * of header/exit mechanics.
	 *
	 * @return bool
	 */
	private function onboarding_would_redirect(): bool {
		$fired = false;

		$spy = static function ( $should_redirect ) use ( &$fired ) {
			$fired = true;
			// Never actually redirect inside the test process.
			return false;
		};

		add_filter( 'buddynext_onboarding_should_redirect', $spy );
		( new \BuddyNext\Onboarding\OnboardingListener() )->maybe_redirect_to_onboarding();
		remove_filter( 'buddynext_onboarding_should_redirect', $spy );

		return $fired;
	}
}
