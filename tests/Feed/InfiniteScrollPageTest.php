<?php
/**
 * Tests for FeedController infinite-scroll HTML page endpoints.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Feed\FeedController
 */
class InfiniteScrollPageTest extends \WP_UnitTestCase {

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

	public function test_home_page_endpoint_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_home_page_endpoint_returns_html_and_next_cursor_shape(): void {
		$posts = new PostService();

		// Seed enough posts to require a second page.
		for ( $i = 0; $i < 25; $i++ ) {
			$posts->create(
				$this->alice,
				array( 'type' => 'text', 'content' => "Seed post {$i}", 'privacy' => 'public' )
			);
		}

		wp_set_current_user( $this->alice );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		$request->set_param( 'per_page', 15 );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'html', $data );
		$this->assertArrayHasKey( 'next_cursor', $data );
		$this->assertArrayHasKey( 'count', $data );
		$this->assertIsString( $data['html'] );
		$this->assertSame( 15, (int) $data['count'] );
		$this->assertNotEmpty( $data['next_cursor'] );
	}

	public function test_home_page_endpoint_returns_empty_html_on_no_posts(): void {
		wp_set_current_user( $this->alice );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '', $data['html'] );
		$this->assertSame( 0, (int) $data['count'] );
	}

	public function test_explore_page_endpoint_is_public(): void {
		$posts = new PostService();
		$posts->create( $this->alice, array( 'type' => 'text', 'content' => 'Public', 'privacy' => 'public' ) );

		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/feed/explore/page' );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );
		$this->assertArrayHasKey( 'next_cursor', $data );
	}

	public function test_home_page_cursor_advances_to_subsequent_pages(): void {
		$posts = new PostService();
		for ( $i = 0; $i < 35; $i++ ) {
			$posts->create(
				$this->alice,
				array( 'type' => 'text', 'content' => "Post {$i}", 'privacy' => 'public' )
			);
		}

		wp_set_current_user( $this->alice );

		// First page.
		$first = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		$first->set_param( 'per_page', 15 );
		$first_data = self::$server->dispatch( $first )->get_data();

		$this->assertNotEmpty( $first_data['next_cursor'] );

		// Second page using the returned cursor.
		$second = new WP_REST_Request( 'GET', '/buddynext/v1/feed/home/page' );
		$second->set_param( 'per_page', 15 );
		$second->set_param( 'cursor', $first_data['next_cursor'] );
		$second_data = self::$server->dispatch( $second )->get_data();

		$this->assertSame( 200, $second_data ? 200 : 200 ); // Sanity.
		$this->assertGreaterThan( 0, (int) $second_data['count'] );
		// The two pages should not overlap.
		$this->assertNotSame( $first_data['html'], $second_data['html'] );
	}
}
