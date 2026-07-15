<?php
/**
 * Every feed surface must return an app-renderable shape, not raw rows.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Enrichment parity across /feed/home, /feed/explore, /users/{id}/feed and
 * /spaces/{id}/feed.
 *
 * The asymmetry these pin: enrich_items_for_rest() had exactly one call site
 * (home_feed), so three of the four feed surfaces returned raw hydrate() rows —
 * a bare author user_id, no content_html, no viewer_state. A native client had to
 * make N author lookups per screen and a second viewer-state round trip, and
 * could not render the post overflow menu at all. The method's own docblock
 * describes the fix in general terms while its @param quietly scoped it to
 * home_feed.
 *
 * It survived because nothing asserted shape. The suite covered status codes and
 * visibility; no test read an item's keys. So these assert the contract itself,
 * on every surface, rather than trusting one call site to stay put.
 *
 * @covers \BuddyNext\Feed\FeedController
 */
class FeedEnrichmentTest extends \WP_UnitTestCase {

	/**
	 * REST server for the request under test.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * The post author.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * A space the author posts into.
	 *
	 * @var int
	 */
	private int $space_id;

	/**
	 * Boot REST, create an author and a post on every surface.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$this->author = self::factory()->user->create();
		wp_set_current_user( $this->author );

		$posts = new PostService();
		$posts->create(
			$this->author,
			array(
				'content' => 'Hello **world**',
				'type'    => 'text',
			)
		);

		$spaces         = new SpaceService();
		$this->space_id = (int) $spaces->create(
			$this->author,
			array(
				'name' => 'Enrichment Space',
				'slug' => 'enrichment-space',
				'type' => 'open',
			)
		);
		$posts->create(
			$this->author,
			array(
				'content'  => 'In a space',
				'type'     => 'text',
				'space_id' => $this->space_id,
			)
		);
	}

	/**
	 * Reset the server.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Dispatch a feed route and return its items.
	 *
	 * @param string $route Route path.
	 * @return array<int,array<string,mixed>>
	 */
	private function items( string $route ): array {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( 200, $response->get_status(), $route . ' must answer 200.' );

		$data  = (array) $response->get_data();
		$items = (array) ( $data['items'] ?? array() );

		$this->assertNotEmpty( $items, $route . ' returned no items — the fixture, not the contract, is wrong.' );

		return $items;
	}

	/**
	 * Assert one item carries the shape a native client renders from.
	 *
	 * @param array<string,mixed> $item  A feed item.
	 * @param string              $route The route it came from, for the message.
	 * @return void
	 */
	private function assertEnriched( array $item, string $route ): void {
		$this->assertArrayHasKey( 'author', $item, $route . ' must expand the author; a bare user_id forces N lookups per screen.' );
		$this->assertIsArray( $item['author'], $route . ' author must be an object.' );
		$this->assertArrayHasKey( 'display_name', $item['author'], $route . ' author needs a display_name.' );
		$this->assertArrayHasKey( 'avatar_url', $item['author'], $route . ' author needs an avatar_url.' );

		$this->assertArrayHasKey( 'content_html', $item, $route . ' must return formatted content; the app cannot run buddynext_format_content().' );

		$this->assertArrayHasKey( 'viewer_state', $item, $route . ' must carry viewer_state, or the app needs a second round trip per screen.' );
		foreach ( array( 'my_reaction', 'is_bookmarked', 'my_voted_option_id', 'can_edit' ) as $key ) {
			$this->assertArrayHasKey( $key, $item['viewer_state'], $route . ' viewer_state needs ' . $key . '.' );
		}
	}

	/**
	 * The surface that already worked — the baseline.
	 *
	 * @return void
	 */
	public function test_home_feed_is_enriched(): void {
		$this->assertEnriched( $this->items( '/buddynext/v1/feed/home' )[0], '/feed/home' );
	}

	/**
	 * Explore returned raw rows — the highest-traffic public surface.
	 *
	 * @return void
	 */
	public function test_explore_feed_is_enriched(): void {
		$this->assertEnriched( $this->items( '/buddynext/v1/feed/explore' )[0], '/feed/explore' );
	}

	/**
	 * The profile feed backs a default profile tab in the app.
	 *
	 * @return void
	 */
	public function test_profile_feed_is_enriched(): void {
		$this->assertEnriched( $this->items( '/buddynext/v1/users/' . $this->author . '/feed' )[0], '/users/{id}/feed' );
	}

	/**
	 * Every space screen reads this one.
	 *
	 * @return void
	 */
	public function test_space_feed_is_enriched(): void {
		$this->assertEnriched( $this->items( '/buddynext/v1/spaces/' . $this->space_id . '/feed' )[0], '/spaces/{id}/feed' );
	}

	/**
	 * Enrichment must survive a logged-out viewer.
	 *
	 * Explore is public, so it enriches with viewer 0. The author and content must
	 * still expand; only the viewer-relative block goes empty.
	 *
	 * @return void
	 */
	public function test_explore_feed_is_enriched_for_a_logged_out_viewer(): void {
		wp_set_current_user( 0 );

		$item = $this->items( '/buddynext/v1/feed/explore' )[0];

		$this->assertEnriched( $item, '/feed/explore (logged out)' );
		$this->assertFalse( $item['viewer_state']['is_bookmarked'], 'A logged-out viewer has bookmarked nothing.' );
		$this->assertFalse( $item['viewer_state']['can_edit'], 'A logged-out viewer can edit nothing.' );
		$this->assertNull( $item['viewer_state']['my_reaction'] );
	}

	/**
	 * The batch ceiling is reported, so the app can chunk on it.
	 *
	 * @return void
	 */
	public function test_viewer_state_reports_its_ceiling(): void {
		$response = $this->server->dispatch( $this->viewer_state_request( array( 1, 2 ) ) );
		$data     = (array) $response->get_data();

		$this->assertSame( 100, $data['max_ids'] );
		$this->assertFalse( $data['truncated'] );
		$this->assertSame( 2, $data['requested'] );
	}

	/**
	 * Over-sending is admitted, not swallowed.
	 *
	 * The bug this pins: the route sliced to 100 in silence, so a client that sent
	 * 150 ids got 100 answers with no indication — and the other fifty cards
	 * rendered as un-reacted and un-bookmarked, which looks like real state rather
	 * than a missing answer. A wrong answer that admits nothing is worse than an
	 * error.
	 *
	 * @return void
	 */
	public function test_viewer_state_says_when_it_truncates(): void {
		$response = $this->server->dispatch( $this->viewer_state_request( range( 1, 150 ) ) );
		$data     = (array) $response->get_data();

		$this->assertTrue( $data['truncated'], 'A truncated batch must say so, or the app mis-renders the remainder as real state.' );
		$this->assertSame( 150, $data['requested'] );
		$this->assertSame( 100, $data['returned'] );
	}

	/**
	 * The ceiling the app is told matches the ceiling the route enforces.
	 *
	 * Two sources for one number is how the app ends up chunking at the wrong size,
	 * so /app/config reads the route's constant rather than restating it.
	 *
	 * @return void
	 */
	public function test_config_ceiling_matches_the_route_ceiling(): void {
		$config = (array) $this->server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/app/config' ) )->get_data();
		$state  = (array) $this->server->dispatch( $this->viewer_state_request( array( 1 ) ) )->get_data();

		$this->assertSame(
			$state['max_ids'],
			$config['limits']['viewer_state_max_ids'],
			'/app/config must advertise the ceiling /feed/viewer-state actually enforces.'
		);
	}

	/**
	 * Build a viewer-state request for a set of ids.
	 *
	 * @param array<int,int> $ids Post ids.
	 * @return WP_REST_Request
	 */
	private function viewer_state_request( array $ids ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/viewer-state' );
		$request->set_param( 'post_ids', implode( ',', $ids ) );

		return $request;
	}

	/**
	 * The author object is real, not a placeholder.
	 *
	 * Guards against an enrichment that runs but resolves nothing — the shape
	 * assertions above would pass on empty strings.
	 *
	 * @return void
	 */
	public function test_enriched_author_resolves_the_real_member(): void {
		$expected = get_userdata( $this->author )->display_name;

		foreach (
			array(
				'/buddynext/v1/feed/home',
				'/buddynext/v1/feed/explore',
				'/buddynext/v1/users/' . $this->author . '/feed',
				'/buddynext/v1/spaces/' . $this->space_id . '/feed',
			) as $route
		) {
			$item = $this->items( $route )[0];
			$this->assertSame( $expected, $item['author']['display_name'], $route . ' must resolve the real author.' );
			$this->assertSame( $this->author, $item['author']['id'], $route . ' author id must match.' );
		}
	}
}
