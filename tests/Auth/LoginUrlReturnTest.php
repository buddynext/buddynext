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
