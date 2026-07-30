<?php
/**
 * An account hold must reach a partner's REST surface, not just ours.
 *
 * RestHoldGate::gate() only ever inspected buddynext(-pro)/v1, so every hold was
 * simply ABSENT on any namespace we do not own. Measured before the fix, with
 * "full" email-verification enforcement and a genuinely unverified member:
 *
 *   GET /buddynext/v1/feed  -> 403 buddynext_email_unverified   (held, correct)
 *   GET /mvs/v1/media       -> 200                              (served)
 *
 * The card reported it for DMs; the hole was the whole mvs/v1 surface, so an
 * unverified member could also upload media, write tags and edit their
 * MediaVerse profile.
 *
 * The fix drives the partner's own gate through the two filters it publishes,
 * rather than pattern-matching foreign routes from our side. These tests
 * therefore assert on the FILTER CONTRACT — that is the seam, and it stays
 * meaningful whether or not MediaVerse is installed in the test environment.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

/**
 * Hold propagation to a partner REST surface.
 *
 * @covers \BuddyNext\Auth\RestHoldGate::partner_gate_engaged
 * @covers \BuddyNext\Auth\RestHoldGate::partner_gate_allows
 */
class RestHoldPartnerSurfaceTest extends \WP_UnitTestCase {

	/**
	 * Turn on "full" enforcement and register the gate.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'buddynext_email_verify', 1 );
		update_option( 'buddynext_verify_enforcement', 'full' );

		( new \BuddyNext\Auth\RestHoldGate() )->register();
	}

	/**
	 * Drop the filters this test registered so they cannot leak.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'mvs_rest_require_auth' );
		remove_all_filters( 'mvs_rest_can_access' );

		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_verify_enforcement' );

		parent::tear_down();
	}

	/**
	 * A member who registers AFTER verification was switched on, so they are
	 * genuinely unverified rather than grandfathered.
	 *
	 * is_verified() deliberately exempts accounts that predate the setting — a
	 * migration must not retroactively lock everyone out — so a fixture that
	 * reuses an existing user reads as VERIFIED and the test proves nothing. That
	 * caught me once while verifying this.
	 *
	 * @param string $role Role to create.
	 * @return int
	 */
	private function unverified_member( string $role = 'subscriber' ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		delete_user_meta( $user_id, 'buddynext_email_verified' );
		wp_update_user(
			array(
				'ID'              => $user_id,
				'user_registered' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $user_id;
	}

	/**
	 * Ask the partner contract what it would decide for the current member.
	 *
	 * @return array{require:bool, allow:bool}
	 */
	private function partner_verdict(): array {
		return array(
			'require' => (bool) apply_filters( 'mvs_rest_require_auth', false, null ),
			'allow'   => (bool) apply_filters( 'mvs_rest_can_access', is_user_logged_in(), null ),
		);
	}

	/**
	 * The regression: a held member is refused on the partner surface.
	 *
	 * @return void
	 */
	public function test_a_held_member_is_refused_on_a_partner_surface(): void {
		$member = $this->unverified_member();
		$this->assertFalse(
			buddynext_service( 'verification' )->is_verified( $member ),
			'Fixture is wrong: the member reads as verified, so nothing is being held.'
		);

		wp_set_current_user( $member );
		$verdict = $this->partner_verdict();

		$this->assertTrue( $verdict['require'], 'The partner gate was never engaged, so can_access is not consulted.' );
		$this->assertFalse( $verdict['allow'], 'A held member was allowed onto the partner surface.' );
	}

	/**
	 * An administrator is never trapped out of their own site — the same carve-out
	 * gate() makes.
	 *
	 * @return void
	 */
	public function test_an_administrator_is_never_held(): void {
		wp_set_current_user( $this->unverified_member( 'administrator' ) );

		$verdict = $this->partner_verdict();

		$this->assertFalse( $verdict['require'] );
		$this->assertTrue( $verdict['allow'] );
	}

	/**
	 * A verified member is untouched.
	 *
	 * @return void
	 */
	public function test_a_verified_member_is_untouched(): void {
		$member = $this->unverified_member();
		update_user_meta( $member, 'buddynext_email_verified', 1 );

		wp_set_current_user( $member );
		$verdict = $this->partner_verdict();

		$this->assertFalse( $verdict['require'] );
		$this->assertTrue( $verdict['allow'] );
	}

	/**
	 * Nothing is held when enforcement is not "full" — the restricted tier is
	 * enforced in the services and must not lose partner access.
	 *
	 * @return void
	 */
	public function test_restricted_enforcement_does_not_hold_the_partner_surface(): void {
		update_option( 'buddynext_verify_enforcement', 'restricted' );

		wp_set_current_user( $this->unverified_member() );
		$verdict = $this->partner_verdict();

		$this->assertFalse( $verdict['require'] );
		$this->assertTrue( $verdict['allow'] );
	}

	/**
	 * The mutation guard: this filter may only ever REMOVE access. If a prior
	 * filter (e.g. PrivateCommunity locking a private community) already said no,
	 * our answer must not turn it into a yes — otherwise adding hold enforcement
	 * would quietly make a private community public.
	 *
	 * @return void
	 */
	public function test_it_never_widens_access_another_filter_denied(): void {
		wp_set_current_user( $this->unverified_member() );
		update_user_meta( get_current_user_id(), 'buddynext_email_verified', 1 );

		// A verified member, so we contribute nothing — but something else denied.
		$this->assertFalse(
			(bool) apply_filters( 'mvs_rest_can_access', false, null ),
			'A denial from another filter was overturned.'
		);
	}

	/**
	 * A logged-out visitor is not "held" — that is PrivateCommunity's decision,
	 * and answering it here would double-gate guests with the wrong message.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_not_held(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( (bool) apply_filters( 'mvs_rest_require_auth', false, null ) );
	}
}
