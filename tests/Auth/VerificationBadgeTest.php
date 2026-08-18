<?php
/**
 * The verified badge is a claim, and it must only be made where it means something.
 *
 * `templates/parts/profile-hero.php` decided the blue check by reading one thing:
 *
 *     $bn_pf_is_verified = (bool) get_user_meta( $uid, 'buddynext_email_verified', true );
 *
 * The meta is permanent, so once a site had ever run email verification, every member
 * who verified kept a badge forever — including after the owner turned verification
 * off. On a site where verification is off, "Verified account" is a claim about a
 * check the site does not perform, sitting next to members who never had the chance
 * to make it. It also cannot be earned any more, so the badge silently becomes a
 * marker of when someone joined.
 *
 * The obvious gate is the wrong one. `VerificationService::is_verified()` returns
 * TRUE for everyone when the setting is off — that is deliberate, it is an access
 * gate and nobody should be locked out by a disabled feature — so wiring the badge to
 * it would put a check on every profile on the site instead of removing it.
 *
 * `is_verified()` also grandfathers members who registered before verification was
 * switched on, so that a migrated site does not lock out its existing members. Those
 * members are "verified" for access and have proved nothing, so they must not carry
 * the badge either. Access and evidence are different questions and this is the line
 * between them.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationService;

/**
 * Who gets the verified badge.
 *
 * @covers \BuddyNext\Auth\VerificationService::has_verified_badge
 */
class VerificationBadgeTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var VerificationService
	 */
	private VerificationService $service;

	/**
	 * A member carrying the verified usermeta.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Seed a member who has genuinely verified.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new VerificationService();
		$this->user_id = (int) $this->factory->user->create();

		update_user_meta( $this->user_id, 'buddynext_email_verified', 1 );
		$this->enable_verification();
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
	 * Turn the verification setting AND the feature on.
	 *
	 * @return void
	 */
	private function enable_verification(): void {
		update_option( 'buddynext_email_verify', 1 );

		$features                 = (array) get_option( 'buddynext_features', array() );
		$features['verification'] = true;
		update_option( 'buddynext_features', $features );
	}

	// ── The complaint ────────────────────────────────────────────────────────────

	/**
	 * Verification switched off: nobody wears the badge, meta or no meta.
	 *
	 * @return void
	 */
	public function test_no_badge_when_the_verification_setting_is_off(): void {
		update_option( 'buddynext_email_verify', 0 );

		$this->assertFalse(
			$this->service->has_verified_badge( $this->user_id ),
			'A site with email verification switched off still shows "Verified account" on profiles, '
			. 'claiming a check the site does not perform.'
		);
	}

	/**
	 * Same when the whole feature is disabled, which is the other half of the gate.
	 *
	 * A site can leave the setting on and switch the Verification FEATURE off; the
	 * setting alone is not the source of truth, which is why VerificationListener
	 * checks both before it enforces anything.
	 *
	 * @return void
	 */
	public function test_no_badge_when_the_verification_feature_is_disabled(): void {
		$features                 = (array) get_option( 'buddynext_features', array() );
		$features['verification'] = false;
		update_option( 'buddynext_features', $features );

		$this->assertFalse(
			$this->service->has_verified_badge( $this->user_id ),
			'the badge must follow the feature switch, not only the sub-setting'
		);
	}

	// ── What must keep working ───────────────────────────────────────────────────

	/**
	 * Verification on and the member verified: the badge is exactly right.
	 *
	 * Guards against "fixing" this by never showing the badge.
	 *
	 * @return void
	 */
	public function test_a_verified_member_wears_the_badge_when_verification_is_on(): void {
		$this->assertTrue(
			$this->service->has_verified_badge( $this->user_id ),
			'a member who verified on a site that runs verification must keep their badge'
		);
	}

	/**
	 * Verification on, member never verified: no badge.
	 *
	 * @return void
	 */
	public function test_an_unverified_member_has_no_badge(): void {
		$other = (int) $this->factory->user->create();

		$this->assertFalse(
			$this->service->has_verified_badge( $other ),
			'a member who has not verified must not be shown as verified'
		);
	}

	// ── The distinction that makes this subtle ───────────────────────────────────

	/**
	 * A grandfathered member counts as verified for ACCESS but earns no badge.
	 *
	 * `is_verified()` returns true for members who registered before verification was
	 * switched on, so a migrated site does not lock out everyone it already had. They
	 * have proved nothing, so the badge must not follow that leniency — this is the
	 * test that fails if anyone later "simplifies" the badge to call `is_verified()`.
	 *
	 * @return void
	 */
	public function test_a_grandfathered_member_is_verified_for_access_but_wears_no_badge(): void {
		// Registered a year ago; verification switched on yesterday.
		$old = (int) $this->factory->user->create(
			array( 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS ) )
		);
		update_option( 'buddynext_email_verify_enabled_at', time() - DAY_IN_SECONDS );

		$this->assertTrue(
			$this->service->is_verified( $old ),
			'precondition: a pre-existing member must not be locked out by a newly enabled setting'
		);

		$this->assertFalse(
			$this->service->has_verified_badge( $old ),
			'A member who was grandfathered past the access gate never proved their address, '
			. 'so they must not be shown a badge that says they did.'
		);
	}

	/**
	 * With verification off, `is_verified()` says true for everyone — and that is
	 * exactly why the badge cannot be wired to it.
	 *
	 * Pins the trap rather than describing it: this asserts the two answers diverge.
	 *
	 * @return void
	 */
	public function test_is_verified_is_not_a_substitute_for_the_badge_check(): void {
		update_option( 'buddynext_email_verify', 0 );

		$nobody = (int) $this->factory->user->create();

		$this->assertTrue(
			$this->service->is_verified( $nobody ),
			'precondition: a disabled setting treats everyone as verified for access'
		);

		$this->assertFalse(
			$this->service->has_verified_badge( $nobody ),
			'Wiring the badge to is_verified() would put a blue check on EVERY profile on a site '
			. 'that has verification switched off — the opposite of the bug, and worse.'
		);
	}
}
