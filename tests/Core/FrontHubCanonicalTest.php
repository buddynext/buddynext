<?php
/**
 * A hub set as the static front page keeps its deeper routes: core's
 * "front page -> /" canonical redirect applies to the hub root only
 * (card 10342159620: /activity/leaderboard/ 301'd to "/").
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PageRouter::keep_front_hub_subroutes
 */
class FrontHubCanonicalTest extends WP_UnitTestCase {

	private PageRouter $router;

	public function set_up(): void {
		parent::set_up();
		$this->router = new PageRouter();
		$page         = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'activity' ) );
		update_option( 'buddynext_page_activity', $page );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );

		global $wp_query;
		$wp_query->queried_object_id = $page;
		$wp_query->queried_object    = get_post( $page );
		set_query_var( 'bn_hub', 'feed' );
	}

	/**
	 * Run the filter as core would for a request path.
	 *
	 * @param string       $request  $wp->request (path, no slashes).
	 * @param string|false $redirect Where core wants to go.
	 * @return string|false
	 */
	private function filter( string $request, $redirect ) {
		global $wp;
		$wp->request = $request;
		return $this->router->keep_front_hub_subroutes( $redirect );
	}

	public function test_subroutes_of_the_front_hub_are_kept(): void {
		$home = home_url( '/' );
		$this->assertFalse( $this->filter( 'activity/leaderboard', $home ) );
		$this->assertFalse( $this->filter( 'me/account-status', $home ) );
	}

	public function test_the_hub_root_still_canonicalises_to_home(): void {
		$home = home_url( '/' );
		$this->assertSame( $home, $this->filter( 'activity', $home ) );
	}

	public function test_other_redirects_pass_through(): void {
		$this->assertSame( 'http://example.org/activity/x/', $this->filter( 'activity/x', 'http://example.org/activity/x/' ) );
		set_query_var( 'bn_hub', '' );
		$this->assertSame( home_url( '/' ), $this->filter( 'some-page/child', home_url( '/' ) ) );
	}
}
