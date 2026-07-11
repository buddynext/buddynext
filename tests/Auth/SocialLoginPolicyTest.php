<?php
/**
 * Regression tests for the social-login bypasses.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\PendingSignup;
use BuddyNext\Auth\SessionIssuer;
use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Core\Installer;

/**
 * Social login used to reimplement registration: it called wp_create_user() and
 * wp_set_auth_cookie() directly, so it inherited none of the owner's policy and
 * skipped the whole authenticate chain.
 *
 * @covers \BuddyNext\Auth\SocialLogin
 */
class SocialLoginPolicyTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema, open registration.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
	}

	/**
	 * THE INVARIANT THE WHOLE WAVE RESTS ON.
	 *
	 * Nothing in SocialLogin may create a member or issue a session by hand. Both
	 * critical bypasses were direct consequences of it doing so:
	 *
	 *   - wp_set_auth_cookie() skips the core authenticate chain, where the
	 *     admin-approval hold and two-factor both live.
	 *   - wp_create_user() skips RegistrationService, so the DM-privacy seed, the
	 *     required profile fields and the canonical hooks were silently missed.
	 */
	public function test_social_login_never_creates_members_or_sessions_by_hand(): void {
		$source = (string) file_get_contents( BUDDYNEXT_DIR . 'includes/Auth/SocialLogin.php' );

		$this->assertStringNotContainsString(
			'wp_set_auth_cookie',
			$source,
			'SocialLogin must issue sessions through SessionIssuer, never a raw cookie'
		);
		$this->assertStringNotContainsString(
			'wp_create_user',
			$source,
			'SocialLogin must create members through RegistrationService, never directly'
		);
	}

	/**
	 * REGRESSION — the approval bypass.
	 *
	 * Clicking the social button twice used to sign you in: the provider link was
	 * written BEFORE the "awaiting approval" branch returned, so the second call
	 * matched an already-linked owner and returned early, past the gate. A member
	 * held for approval must never receive a session, whichever door asks.
	 */
	public function test_a_member_held_for_approval_is_never_signed_in(): void {
		update_option( 'buddynext_reg_mode', 'approval' );

		$user_id = self::factory()->user->create( array( 'user_email' => 'social@example.com' ) );
		update_user_meta( $user_id, 'bn_pending_approval', '1' );
		// The link exists — this is the state after the first social click.
		update_user_meta( $user_id, 'bn_social_google_id', '12345' );

		// Exactly what callback() now does for an already-linked owner.
		$session = ( new SessionIssuer() )->start( $user_id, true );

		$this->assertWPError( $session );
		$this->assertSame( 'bn_pending_approval', $session->get_error_code() );
		$this->assertSame( 0, get_current_user_id(), 'the second click must not sign them in' );
	}

	/**
	 * REGRESSION — the 2FA bypass.
	 *
	 * TwoFactorLoginGuard bails unless $pagenow is wp-login.php, and the OAuth
	 * callback runs on template_redirect — so a member with 2FA on had it fully
	 * bypassed by signing in with a linked provider.
	 */
	public function test_a_member_with_2fa_is_challenged_not_signed_in(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_2fa_enabled', '1' );
		update_user_meta( $user_id, 'bn_2fa_secret', TwoFactorService::generate_secret() );
		update_user_meta( $user_id, 'bn_social_google_id', '67890' );

		$session = ( new SessionIssuer() )->start( $user_id, true );

		$this->assertWPError( $session );
		$this->assertSame( 'bn_2fa_required', $session->get_error_code() );
		$this->assertNotEmpty( $session->get_error_data()['ticket'] ?? '' );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * A parked signup creates NO user. That ordering is the root fix: nothing
	 * exists to be matched, linked, or signed in until the signup completes.
	 */
	public function test_parking_a_signup_creates_no_account(): void {
		$token = PendingSignup::park(
			array(
				'provider'       => 'google',
				'uid'            => '12345',
				'email'          => 'parked@example.com',
				'email_verified' => true,
				'name'           => 'Parked Person',
				'picture'        => '',
			)
		);

		$this->assertNotEmpty( $token );
		$this->assertFalse( get_user_by( 'email', 'parked@example.com' ), 'no account may exist while a signup is pending' );

		$read = PendingSignup::get( $token );
		$this->assertSame( 'parked@example.com', $read['email'] );

		$consumed = PendingSignup::consume( $token );
		$this->assertSame( 'google', $consumed['provider'] );

		$this->assertNull( PendingSignup::get( $token ), 'the token must be single-use' );
	}
}
