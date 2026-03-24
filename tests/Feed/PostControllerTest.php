<?php
/**
 * Tests for PostController REST endpoints.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Feed\PostController
 */
class PostControllerTest extends \WP_UnitTestCase {

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

	public function test_create_post_returns_201(): void {
		wp_set_current_user( $this->alice );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$request->set_body_params(
			array(
				'type'    => 'text',
				'content' => 'Hello REST',
				'privacy' => 'public',
			)
		);

		$response = self::$server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	public function test_create_post_requires_auth(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$request->set_body_params( array( 'type' => 'text', 'content' => 'No auth' ) );

		$response = self::$server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_post_returns_200(): void {
		wp_set_current_user( $this->alice );

		// Create via REST.
		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Readable', 'privacy' => 'public' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		wp_set_current_user( 0 );
		$get      = new WP_REST_Request( 'GET', "/buddynext/v1/posts/{$post_id}" );
		$response = self::$server->dispatch( $get );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post_id, $response->get_data()['id'] );
	}

	public function test_get_private_post_returns_403_for_other_user(): void {
		wp_set_current_user( $this->alice );

		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Private', 'privacy' => 'private' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		wp_set_current_user( $this->bob );
		$get      = new WP_REST_Request( 'GET', "/buddynext/v1/posts/{$post_id}" );
		$response = self::$server->dispatch( $get );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_update_post_returns_200(): void {
		wp_set_current_user( $this->alice );

		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Before', 'privacy' => 'public' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		$update = new WP_REST_Request( 'PUT', "/buddynext/v1/posts/{$post_id}" );
		$update->set_body_params( array( 'content' => 'After' ) );
		$response = self::$server->dispatch( $update );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'After', $response->get_data()['content'] );
	}

	public function test_update_post_as_non_owner_returns_403(): void {
		wp_set_current_user( $this->alice );
		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Alice post', 'privacy' => 'public' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		wp_set_current_user( $this->bob );
		$update = new WP_REST_Request( 'PUT', "/buddynext/v1/posts/{$post_id}" );
		$update->set_body_params( array( 'content' => 'Bob changes' ) );
		$response = self::$server->dispatch( $update );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_delete_post_returns_200(): void {
		wp_set_current_user( $this->alice );

		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Delete me', 'privacy' => 'public' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		$delete   = new WP_REST_Request( 'DELETE', "/buddynext/v1/posts/{$post_id}" );
		$response = self::$server->dispatch( $delete );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_delete_post_as_non_owner_returns_403(): void {
		wp_set_current_user( $this->alice );
		$create = new WP_REST_Request( 'POST', '/buddynext/v1/posts' );
		$create->set_body_params( array( 'type' => 'text', 'content' => 'Alice post', 'privacy' => 'public' ) );
		$post_id = self::$server->dispatch( $create )->get_data()['id'];

		wp_set_current_user( $this->bob );
		$delete   = new WP_REST_Request( 'DELETE', "/buddynext/v1/posts/{$post_id}" );
		$response = self::$server->dispatch( $delete );

		$this->assertSame( 403, $response->get_status() );
	}
}
