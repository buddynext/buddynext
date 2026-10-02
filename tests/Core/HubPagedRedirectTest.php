<?php
/**
 * Core's ?paged=N -> /page/N/ redirect only lands where a BuddyNext page route answers.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * redirect_canonical() turns `?paged=N` into `/…/page/N/` on any URL. On a hub
 * with no page rule that address 404s or renders the wrong view (Explore, the
 * search hub, a space tab), so the router lets the redirect through only when
 * the target matches one of its own page rules.
 *
 * @covers \BuddyNext\Core\PageRouter::keep_paged_query_without_page_route
 */
class HubPagedRedirectTest extends \WP_UnitTestCase {

	/**
	 * Router under test.
	 *
	 * @var PageRouter
	 */
	private PageRouter $router;

	/**
	 * Pretty permalinks and BuddyNext's rules.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->router = new PageRouter();
		$this->router->register_rewrites();
		flush_rewrite_rules();
		set_query_var( 'bn_hub', 'feed' );
	}

	/**
	 * Clear the hub flag.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		set_query_var( 'bn_hub', '' );
		parent::tear_down();
	}

	/**
	 * Run the filter the way redirect_canonical() would.
	 *
	 * @param string $requested Requested path + query.
	 * @param string $target    Path core wants to redirect to.
	 * @return string|false
	 */
	private function redirect( string $requested, string $target ) {
		return $this->router->keep_paged_query_without_page_route( home_url( $target ), home_url( $requested ) );
	}

	/**
	 * Hubs with a page rule get the pretty address.
	 *
	 * @return void
	 */
	public function test_hubs_with_a_page_rule_redirect_to_page_n(): void {
		$this->assertSame( home_url( '/notifications/page/2/' ), $this->redirect( '/notifications/?paged=2', '/notifications/page/2/' ) );
		$this->assertSame( home_url( '/members/page/3/' ), $this->redirect( '/members/?paged=3', '/members/page/3/' ) );
		$this->assertSame( home_url( '/spaces/design/feed/page/2/' ), $this->redirect( '/spaces/design/feed/?paged=2', '/spaces/design/feed/page/2/' ) );
	}

	/**
	 * Hubs without one keep ?paged instead of landing on a 404.
	 *
	 * @return void
	 */
	public function test_hubs_without_a_page_rule_keep_the_query(): void {
		$this->assertFalse( $this->redirect( '/activity/explore/?paged=2', '/activity/explore/page/2/' ) );
		$this->assertFalse( $this->redirect( '/activity/search/?q=a&paged=2', '/activity/search/page/2/?q=a' ) );
		// Space Members pages by cursor; a page-2 address would show page 1.
		$this->assertFalse( $this->redirect( '/spaces/design/members/?paged=2', '/spaces/design/members/page/2/' ) );
	}

	/**
	 * A BuddyNext page address is never "corrected": core drops the space Feed
	 * tab's /feed/ as if it were the RSS endpoint.
	 *
	 * @return void
	 */
	public function test_a_page_route_is_already_canonical(): void {
		$this->assertFalse( $this->redirect( '/spaces/design/feed/page/2/?bn_sf_q=a', '/spaces/design/page/2/?bn_sf_q=a' ) );
	}

	/**
	 * Non-hub requests and non-paging redirects are untouched.
	 *
	 * @return void
	 */
	public function test_other_redirects_pass_through(): void {
		$this->assertSame( home_url( '/members/' ), $this->redirect( '/members', '/members/' ) );

		set_query_var( 'bn_hub', '' );
		$this->assertSame( home_url( '/blog/page/2/' ), $this->redirect( '/blog/?paged=2', '/blog/page/2/' ) );
	}
}
