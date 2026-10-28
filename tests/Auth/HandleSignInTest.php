<?php
/**
 * Signing in with the @handle the community shows a member.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationService;

/**
 * Dotted logins get a hyphenated handle; both the new-login shape and the
 * `authenticate` fallback for existing members are covered here.
 */
class HandleSignInTest extends \WP_UnitTestCase {

	public function test_new_login_matches_the_handle_wordpress_derives(): void {
		$login = RegistrationService::unique_login( 'Markus.Kaufmann@example.com' );
		$this->assertSame( 'markus-kaufmann', $login );
		$this->assertSame( $login, sanitize_title( $login ), 'login and nicename must be the same string' );
	}

	public function test_existing_dotted_member_signs_in_with_handle(): void {
		$id = self::factory()->user->create(
			array(
				'user_login' => 'qa.dot.check',
				'user_pass'  => 'right-pass-123',
			)
		);
		$this->assertSame( 'qa-dot-check', get_userdata( $id )->user_nicename );

		$ok = wp_authenticate( 'qa-dot-check', 'right-pass-123' );
		$this->assertInstanceOf( \WP_User::class, $ok );
		$this->assertSame( $id, $ok->ID );

		$this->assertWPError( wp_authenticate( 'qa-dot-check', 'wrong-pass' ) );
		$this->assertSame( 'invalid_username', wp_authenticate( 'nobody-here', 'x' )->get_error_code() );
	}
}
