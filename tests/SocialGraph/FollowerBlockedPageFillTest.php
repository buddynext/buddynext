<?php
/**
 * A block-filtered followers page must never strand the keyset walk, and `page`
 * must be flagged deprecated on the cursor route (card 10284805802).
 *
 * filter_blocked() runs AFTER the keyset page is cut, so a page whose every id is
 * block-hidden returned ids:[] with a NON-null next_cursor. A client that stops on
 * an empty page truncated the whole list. FollowController::filled_keyset_page()
 * now advances through fully-blocked pages so an empty page always carries a null
 * cursor, and the true relationship count stays in `total`.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\FollowController;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\SocialGraph\FollowController::get_followers
 */
class FollowerBlockedPageFillTest extends \WP_UnitTestCase {

	/**
	 * The followed profile.
	 *
	 * @var int
	 */
	private int $profile;

	/**
	 * The viewer who has blocked part of the first page.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * Followers in creation order [oldest, ..., newest].
	 *
	 * @var array<int,int>
	 */
	private array $followers = array();

	/**
	 * Seed a public profile with three followers and route registration.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new FollowController() )->register_routes();

		$this->profile = (int) self::factory()->user->create();
		$this->viewer  = (int) self::factory()->user->create();

		$follows = buddynext_service( 'follows' );
		for ( $i = 0; $i < 3; $i++ ) {
			$follower          = (int) self::factory()->user->create();
			$this->followers[] = $follower;
			$this->assertTrue(
				true === $follows->follow( $follower, $this->profile ),
				'follower fixture must be created (public account = auto-approved)'
			);
		}

		wp_cache_flush();
	}

	/**
	 * Reset the REST server between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Request one page of the profile's followers as the viewer.
	 *
	 * @param int         $per_page Page size.
	 * @param string|null $cursor   Keyset cursor, or null for page 1.
	 * @return \WP_REST_Response
	 */
	private function fetch_followers( int $per_page, ?string $cursor = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->profile}/followers" );
		$request->set_param( 'per_page', $per_page );
		if ( null !== $cursor ) {
			$request->set_param( 'cursor', $cursor );
		}
		return rest_do_request( $request );
	}

	/**
	 * With the whole first keyset page blocked, the response must still surface the
	 * surviving follower and must never return an empty page alongside a non-null
	 * cursor.
	 *
	 * @return void
	 */
	public function test_fully_blocked_first_page_never_strands_the_walk(): void {
		// Keyset is newest-first; per_page 2 puts the two newest followers on page 1.
		$newest = $this->followers[2];
		$middle = $this->followers[1];
		$oldest = $this->followers[0];

		$blocks = buddynext_service( 'blocks' );
		$this->assertTrue( true === $blocks->block( $this->viewer, $newest ), 'block fixture' );
		$this->assertTrue( true === $blocks->block( $this->viewer, $middle ), 'block fixture' );

		wp_set_current_user( $this->viewer );
		wp_cache_flush();

		$response = $this->fetch_followers( 2 );
		$body     = $response->get_data();
		$ids      = array_map( 'intval', (array) ( $body['ids'] ?? array() ) );

		// The invariant: an empty page can never carry a non-null cursor.
		if ( empty( $ids ) ) {
			$this->assertNull(
				$body['next_cursor'],
				'An empty followers page must carry a null cursor, or a client stops early and truncates the list.'
			);
		}

		// The surviving (oldest) follower is reachable on the first request, not lost
		// behind a fully-blocked page.
		$this->assertContains(
			$oldest,
			$ids,
			'The only non-blocked follower must be reachable rather than stranded behind an all-blocked page.'
		);
	}

	/**
	 * The reported total is the true relationship count, independent of the viewer's
	 * blocks (it must not shrink to the block-filtered walk).
	 *
	 * @return void
	 */
	public function test_total_is_the_true_relationship_count(): void {
		$blocks = buddynext_service( 'blocks' );
		$blocks->block( $this->viewer, $this->followers[2] );

		wp_set_current_user( $this->viewer );
		wp_cache_flush();

		$response = $this->fetch_followers( 10 );
		$body     = $response->get_data();

		$this->assertSame(
			3,
			(int) $body['total'],
			'total is the follower count (3), not the block-filtered page count.'
		);
	}

	/**
	 * A request that still sends the retired `page` param gets a loud deprecation
	 * signal — a Deprecation header and a `deprecated` note in the payload.
	 *
	 * @return void
	 */
	public function test_page_param_is_flagged_deprecated(): void {
		$request = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->profile}/followers" );
		$request->set_param( 'page', 2 );
		$response = rest_do_request( $request );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers, 'A page= request must carry a Deprecation header.' );

		$body = $response->get_data();
		$this->assertArrayHasKey( 'deprecated', $body, 'A page= request must carry a deprecated note in the payload.' );
		$this->assertSame( 'page', $body['deprecated']['param'] ?? '' );
	}

	/**
	 * A normal cursor request carries no deprecation signal.
	 *
	 * @return void
	 */
	public function test_cursor_request_has_no_deprecation_signal(): void {
		$response = $this->fetch_followers( 10 );

		$this->assertArrayNotHasKey( 'Deprecation', $response->get_headers() );
		$this->assertArrayNotHasKey( 'deprecated', $response->get_data() );
	}
}
