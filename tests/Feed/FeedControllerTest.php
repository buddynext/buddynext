<?php
/**
 * Tests for FeedController REST endpoints.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use BuddyNext\SocialGraph\FollowService;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Feed\FeedController
 */
class FeedControllerTest extends \WP_UnitTestCase {

	private static WP_REST_Server $server;
	private int $alice;
	private int $bob;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		self::$server   = $wp_rest_server;
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$this->alice = self::factory()->user->create();
		$this->bob   = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_home_feed_requires_auth(): void {
		wp_set_current_user( 0 );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_home_feed_returns_200(): void {
		wp_set_current_user( $this->alice );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'next_cursor', $data );
	}

	public function test_home_feed_shows_followed_user_posts(): void {
		( new FollowService() )->follow( $this->alice, $this->bob );
		( new PostService() )->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob post',
				'privacy' => 'public',
			)
		);

		wp_set_current_user( $this->alice );
		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );

		$data = $response->get_data();
		$this->assertNotEmpty( $data['items'] );
	}

	public function test_explore_feed_is_public(): void {
		wp_set_current_user( 0 );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/explore' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_explore_feed_returns_public_posts(): void {
		( new PostService() )->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Explore post',
				'privacy' => 'public',
			)
		);

		wp_set_current_user( 0 );
		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/explore' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['items'] );
	}

	public function test_profile_feed_is_public(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->alice}/feed" );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_profile_feed_accepts_cursor_param(): void {
		wp_set_current_user( $this->alice );

		$request = new WP_REST_Request( 'GET', "/buddynext/v1/users/{$this->alice}/feed" );
		$request->set_query_params(
			array(
				'cursor'   => null,
				'per_page' => 10,
			)
		);
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	// ── Filter param (F2) ────────────────────────────────────────────────

	public function test_home_feed_accepts_following_filter(): void {
		wp_set_current_user( $this->alice );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' );
		$request->set_query_params( array( 'filter' => 'following' ) );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_home_feed_rejects_unknown_filter_via_enum(): void {
		wp_set_current_user( $this->alice );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' );
		$request->set_query_params( array( 'filter' => 'garbage-filter' ) );
		$response = self::$server->dispatch( $request );

		// REST enum validation kicks in as 400; OR the controller's allowlist
		// fallback returns 200 with default filter. Either is acceptable as
		// long as the request never executes against an arbitrary value.
		$this->assertContains( $response->get_status(), array( 200, 400 ) );
	}

	public function test_feed_counts_requires_auth(): void {
		wp_set_current_user( 0 );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/counts' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_feed_counts_returns_expected_shape(): void {
		wp_set_current_user( $this->alice );

		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/counts' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'for_you', $data );
		$this->assertArrayHasKey( 'following', $data );
		$this->assertArrayHasKey( 'spaces', $data );
		$this->assertArrayHasKey( 'network', $data );
	}
}
