<?php
/**
 * A hub set as the static front page: only its root is the front page. Deeper
 * routes (/activity/leaderboard/, /members/alice/) are not - otherwise core
 * 301s them home, titles drop the site name and the body gets .home
 * (cards 10342159620, 10343096134).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PageRouter::detach_front_page_from_subroute
 */
class FrontHubCanonicalTest extends WP_UnitTestCase {

	private PageRouter $router;
	private int $page = 0;

	public function set_up(): void {
		parent::set_up();
		$this->router = new PageRouter();
		$this->page   = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'activity' ) );
		update_option( 'buddynext_page_activity', $this->page );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->page );
		set_query_var( 'bn_hub', 'feed' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_option_page_on_front', '__return_zero' );
		parent::tear_down();
	}

	/**
	 * Run the detach step for a request path, as the `wp` action would.
	 *
	 * @param string $request $wp->request (path, no slashes).
	 * @return int page_on_front as the rest of the request would read it.
	 */
	private function front_page_for( string $request ): int {
		global $wp;
		$wp->request = $request;
		remove_filter( 'pre_option_page_on_front', '__return_zero' );
		$this->router->detach_front_page_from_subroute();
		return (int) get_option( 'page_on_front' );
	}

	public function test_deeper_routes_are_not_the_front_page(): void {
		$this->assertSame( 0, $this->front_page_for( 'activity/leaderboard' ) );
		$this->assertSame( 0, $this->front_page_for( 'activity/search' ) );
		$this->assertSame( 0, $this->front_page_for( 'me/account-status' ) );
	}

	public function test_the_hub_root_stays_the_front_page(): void {
		$this->assertSame( $this->page, $this->front_page_for( 'activity' ) );
		$this->assertSame( $this->page, $this->front_page_for( '' ) );
	}

	public function test_nothing_changes_when_the_hub_is_not_on_front(): void {
		update_option( 'page_on_front', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$this->assertNotSame( 0, $this->front_page_for( 'activity/leaderboard' ) );

		set_query_var( 'bn_hub', '' );
		update_option( 'page_on_front', $this->page );
		$this->assertSame( $this->page, $this->front_page_for( 'some-page/child' ) );
	}
}
