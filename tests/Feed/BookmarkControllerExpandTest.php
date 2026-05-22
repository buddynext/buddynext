<?php
/**
 * Tests for BookmarkController expanded GET /me/bookmarks endpoint.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Feed\BookmarkController
 */
class BookmarkControllerExpandTest extends \WP_UnitTestCase {

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

	public function test_legacy_default_returns_ids_only(): void {
		$posts = new PostService();
		$bm    = new BookmarkService();

		$pid = $posts->create( $this->bob, array( 'type' => 'text', 'content' => 'B', 'privacy' => 'public' ) );
		$bm->bookmark( $this->alice, $pid );

		wp_set_current_user( $this->alice );
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/me/bookmarks' );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'ids', $data );
		$this->assertSame( array( $pid ), array_map( 'intval', $data['ids'] ) );
	}

	public function test_expand_posts_returns_hydrated_records(): void {
		$posts = new PostService();
		$bm    = new BookmarkService();

		$pid_1 = $posts->create( $this->bob, array( 'type' => 'text', 'content' => 'First', 'privacy' => 'public' ) );
		$pid_2 = $posts->create( $this->bob, array( 'type' => 'text', 'content' => 'Second', 'privacy' => 'public' ) );

		$bm->bookmark( $this->alice, $pid_1 );
		$bm->bookmark( $this->alice, $pid_2 );

		wp_set_current_user( $this->alice );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/bookmarks' );
		$request->set_param( 'expand', 'posts' );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertArrayHasKey( 'has_more', $data );
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 2, (int) $data['total'] );
	}

	public function test_expand_posts_excludes_private_posts_from_other_users(): void {
		$posts = new PostService();
		$bm    = new BookmarkService();

		// Alice bookmarks one of Bob's posts. Bob later marks it private.
		$pid = $posts->create( $this->bob, array( 'type' => 'text', 'content' => 'Initially public', 'privacy' => 'public' ) );
		$bm->bookmark( $this->alice, $pid );

		// Switch to private — alice should no longer see it in expanded results.
		$posts->update( $pid, $this->bob, array( 'privacy' => 'private' ) );

		wp_set_current_user( $this->alice );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/bookmarks' );
		$request->set_param( 'expand', 'posts' );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// Total reflects bookmark row count; items is filtered.
		$this->assertSame( 1, (int) $data['total'] );
		$this->assertCount( 0, $data['items'] );
	}

	public function test_expand_posts_requires_auth(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/bookmarks' );
		$request->set_param( 'expand', 'posts' );
		$response = self::$server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_pagination_per_page_works(): void {
		$posts = new PostService();
		$bm    = new BookmarkService();

		$created_ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$pid = $posts->create( $this->bob, array( 'type' => 'text', 'content' => "P{$i}", 'privacy' => 'public' ) );
			$bm->bookmark( $this->alice, $pid );
			$created_ids[] = $pid;
		}

		wp_set_current_user( $this->alice );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/bookmarks' );
		$request->set_param( 'expand', 'posts' );
		$request->set_param( 'per_page', 2 );
		$request->set_param( 'page', 1 );

		$data = self::$server->dispatch( $request )->get_data();

		$this->assertCount( 2, $data['items'] );
		$this->assertTrue( (bool) $data['has_more'] );
		$this->assertSame( 5, (int) $data['total'] );
	}
}
