<?php
/**
 * Tests for SearchController REST endpoints.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\REST\Controllers\SearchController
 */
class SearchControllerTest extends \WP_Test_REST_TestCase {

	private SearchService $search_service;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->search_service = new SearchService();
		$this->author_id      = self::factory()->user->create();
	}

	public function test_search_requires_query(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_search_returns_200_with_query(): void {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'hello' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_search_response_shape(): void {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'test' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
	}

	public function test_search_finds_indexed_content(): void {
		$this->search_service->index( 'post', 55, 'Unique Search Term XYZ', 'Content here', $this->author_id );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'Unique Search Term XYZ' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$object_ids = array_column( $data['items'], 'object_id' );
		$this->assertContains( 55, $object_ids );
	}

	public function test_search_accepts_type_filter(): void {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'test' );
		$request->set_param( 'type', 'user' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_member_directory_returns_200(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/members' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_member_directory_response_shape(): void {
		$request  = new WP_REST_Request( 'GET', '/buddynext/v1/members' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'next_cursor', $data );
	}
}
