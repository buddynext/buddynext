<?php
/**
 * A guest on the verify screen is pointed at something that works.
 *
 * Sending a new link needs a session, so a guest cannot be promised a control
 * only a signed-in member gets.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use WP_UnitTestCase;

/**
 * Guest and signed-in copy on templates/auth/verify.php.
 *
 * @coversNothing
 */
class VerifyGuestStatesTest extends WP_UnitTestCase {

	/**
	 * Render the verify template with these query args.
	 *
	 * @param array<string,string> $get Query args.
	 * @return string
	 */
	private function render( array $get ): string {
		$_GET = $get;
		ob_start();
		buddynext_get_template( 'auth/verify.php' );
		$html = (string) ob_get_clean();
		$_GET = array();
		return $html;
	}

	/**
	 * Expired link, guest: no promise of a control below, and sign-in comes back here.
	 *
	 * @return void
	 */
	public function test_guest_on_an_expired_link_is_sent_to_sign_in(): void {
		wp_set_current_user( 0 );
		$html = $this->render( array( 'bn_verified' => '0' ) );

		$this->assertStringNotContainsString( 'Request a fresh link below', $html );
		$this->assertStringContainsString( 'Sign in to get a fresh link', $html );
		$this->assertStringContainsString( rawurlencode( \BuddyNext\Core\PageRouter::verify_url() ), $html );
		$this->assertStringNotContainsString( 'actions.requestNewLink', $html );
	}

	/**
	 * Pending, guest: not a dead end.
	 *
	 * @return void
	 */
	public function test_guest_on_the_pending_state_gets_a_sign_in_link(): void {
		wp_set_current_user( 0 );
		$html = $this->render( array( 'email' => 'qafix@example.com' ) );

		$this->assertStringContainsString( 'Sign in to get a new link', $html );
		$this->assertStringContainsString( rawurlencode( \BuddyNext\Core\PageRouter::verify_url() ), $html );
		$this->assertStringNotContainsString( 'actions.resendEmail', $html );
	}

	/**
	 * Signed in: the real control and its copy stay.
	 *
	 * @return void
	 */
	public function test_signed_in_member_keeps_the_request_control(): void {
		wp_set_current_user( self::factory()->user->create() );
		$html = $this->render( array( 'bn_verified' => '0' ) );

		$this->assertStringContainsString( 'Request a fresh link below', $html );
		$this->assertStringContainsString( 'actions.requestNewLink', $html );
		$this->assertStringNotContainsString( 'Sign in to get a fresh link', $html );
	}
}
