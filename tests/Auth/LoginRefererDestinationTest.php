<?php
/**
 * A login opened without ?redirect_to= returns the visitor to the page they came from.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AuthController;
use BuddyNext\Core\PageRouter;

/**
 * AuthController::referer_destination().
 *
 * @covers \BuddyNext\Auth\AuthController::referer_destination
 */
class LoginRefererDestinationTest extends \WP_UnitTestCase {

	/**
	 * Resolve the destination for a given referrer.
	 *
	 * @param string $referer Referrer URL ('' for none).
	 * @return string
	 */
	private function from( string $referer ): string {
		$_SERVER['REQUEST_URI'] = '/login/';
		if ( '' === $referer ) {
			unset( $_SERVER['HTTP_REFERER'] );
		} else {
			$_SERVER['HTTP_REFERER'] = $referer;
		}
		return AuthController::referer_destination();
	}

	/**
	 * Same-site pages count; the front page, auth pages, admin and other sites do not.
	 *
	 * @return void
	 */
	public function test_only_a_same_site_page_is_returned_to(): void {
		$page = home_url( '/spaces/book-club/' );

		$this->assertSame( $page, $this->from( $page ) );
		$this->assertSame( '', $this->from( '' ), 'No referrer: the owner setting decides.' );
		$this->assertSame( '', $this->from( home_url( '/' ) ), 'The front page leaves the owner setting in charge.' );
		$this->assertSame( '', $this->from( PageRouter::auth_url() ) );
		$this->assertSame( '', $this->from( PageRouter::signup_url() ) );
		$this->assertSame( '', $this->from( admin_url( 'index.php' ) ) );
		$this->assertSame( '', $this->from( 'https://evil.example/phish' ) );

		add_filter( 'buddynext_default_login_redirect', '__return_empty_string' );
		$this->assertSame( '', $this->from( $page ), 'Owners can opt out.' );
		remove_filter( 'buddynext_default_login_redirect', '__return_empty_string' );
		unset( $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * wp-login.php keeps the page in its hidden redirect_to; an explicit value is untouched.
	 *
	 * @return void
	 */
	public function test_wp_login_form_carries_the_page(): void {
		$page = home_url( '/members/' );
		unset( $_REQUEST['redirect_to'] );
		$this->from( $page );
		\BuddyNext\Core\RedirectSettings::seed_wp_login_return();
		$this->assertSame( $page, $_REQUEST['redirect_to'] );

		$_REQUEST['redirect_to'] = home_url( '/spaces/' );
		\BuddyNext\Core\RedirectSettings::seed_wp_login_return();
		$this->assertSame( home_url( '/spaces/' ), $_REQUEST['redirect_to'], 'An explicit redirect wins.' );

		unset( $_REQUEST['redirect_to'], $_SERVER['HTTP_REFERER'] );
		\BuddyNext\Core\RedirectSettings::seed_wp_login_return();
		$this->assertArrayNotHasKey( 'redirect_to', $_REQUEST, 'No referrer: core default stays.' );
	}
}
