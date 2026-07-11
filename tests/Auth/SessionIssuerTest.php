<?php
/**
 * Tests for SessionIssuer — the single authority for handing out an auth cookie.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\SessionIssuer;
use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Core\Installer;

/**
 * Regression cover for the two critical social-login bypasses. Both were caused
 * by SocialLogin calling wp_set_auth_cookie() raw, which skips the core
 * authenticate chain where the approval hold and 2FA both live.
 *
 * @covers \BuddyNext\Auth\SessionIssuer
 */
class SessionIssuerTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * A member held for admin approval must never receive a session, whichever
	 * door asked for one.
	 */
	public function test_refuses_session_for_pending_approval_member(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_pending_approval', '1' );

		$result = ( new SessionIssuer() )->start( $user_id, false );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_pending_approval', $result->get_error_code() );
		$this->assertSame( 0, get_current_user_id(), 'a pending member must not be signed in' );
	}

	/**
	 * A member with two-factor enabled must be challenged, never signed straight in.
	 */
	public function test_refuses_session_for_2fa_member_and_issues_a_ticket(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bn_2fa_enabled', '1' );
		update_user_meta( $user_id, 'bn_2fa_secret', TwoFactorService::generate_secret() );

		$result = ( new SessionIssuer() )->start( $user_id, false );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_2fa_required', $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_data()['ticket'] ?? '' );
		$this->assertSame( 0, get_current_user_id(), 'a 2FA member must not be signed in without the code' );
	}

	/**
	 * The happy path still signs a clean member in.
	 */
	public function test_issues_session_for_clean_member(): void {
		$user_id = self::factory()->user->create();

		$result = ( new SessionIssuer() )->start( $user_id, false );

		$this->assertTrue( $result );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	// The source-level invariant — that SocialLogin never creates a user or sets
	// a cookie by hand — is asserted in SocialLoginPolicyTest, alongside the
	// rewiring that makes it true.
}
