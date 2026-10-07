<?php
/**
 * "Log in" links carry the page to come back to.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Core\PageRouter;

/**
 * PageRouter::login_url() (card 10370404355).
 *
 * @covers \BuddyNext\Core\PageRouter::login_url
 */
class LoginUrlReturnTest extends \WP_UnitTestCase {

	/**
	 * Restore the request between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$GLOBALS['wp']->request = '';
		set_query_var( 'bn_hub', '' );
		unset( $_GET['redirect_to'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Read redirect_to off a login URL.
	 *
	 * @param string $url Login URL.
	 * @return string '' when absent.
	 */
	private function redirect_of( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return (string) ( $args['redirect_to'] ?? '' );
	}

	/**
	 * Default: the page being viewed; explicit: that page; '' : none.
	 *
	 * @return void
	 */
	public function test_returns_to_current_page_explicit_page_or_none(): void {
		$GLOBALS['wp']->request = 'members';

		$this->assertSame( home_url( user_trailingslashit( 'members' ) ), $this->redirect_of( PageRouter::login_url() ) );
		$this->assertSame( home_url( '/spaces/' ), $this->redirect_of( PageRouter::login_url( home_url( '/spaces/' ) ) ) );
		$this->assertSame( '', $this->redirect_of( PageRouter::login_url( '' ) ) );
	}

	/**
	 * On login/signup the link passes on the visitor's destination, not the auth page.
	 *
	 * Card 10372616252: /login/signup/?redirect_to=/members/ built a "Log in" link
	 * back to /login/signup/, so signing in from it dropped the destination.
	 *
	 * @return void
	 */
	public function test_auth_screens_pass_on_the_incoming_destination(): void {
		$GLOBALS['wp']->request = 'login/signup';
		set_query_var( 'bn_hub', 'auth' );

		$_GET['redirect_to'] = home_url( '/members/' );
		$this->assertSame( home_url( '/members/' ), $this->redirect_of( PageRouter::login_url() ) );

		$_GET['redirect_to'] = 'https://elsewhere.example/phish';
		$this->assertSame( '', $this->redirect_of( PageRouter::login_url() ), 'An off-site destination is dropped.' );

		unset( $_GET['redirect_to'] );
		$this->assertSame( '', $this->redirect_of( PageRouter::login_url() ), 'No destination: no redirect_to at all.' );
	}

	/**
	 * No page to return to in wp-admin: plain login link.
	 *
	 * @return void
	 */
	public function test_admin_context_adds_no_return(): void {
		$GLOBALS['wp']->request = 'members';
		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->redirect_of( PageRouter::login_url() ) );
	}
}
