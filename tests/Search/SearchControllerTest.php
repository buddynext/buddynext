<?php
/**
 * Tests for SearchController REST endpoints.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Search\SearchController
 */
class SearchControllerTest extends \WP_Test_REST_TestCase {

	private SearchService $search_service;
	private int $author_id;

	/**
	 * Post written 40 days ago (see seed_old_and_new()).
	 *
	 * @var int
	 */
	private int $old_post = 0;

	/**
	 * Post written today.
	 *
	 * @var int
	 */
	private int $new_post = 0;

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
		$request->set_param( 'type', 'post' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
	}

	public function test_search_finds_indexed_content(): void {
		$this->search_service->index( 'post', 55, 'Unique Search Term XYZ', 'Content here', $this->author_id );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'Unique Search Term XYZ' );
		$request->set_param( 'type', 'post' );
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

	/**
	 * Index a member's searchable content directly, so the test does not depend on
	 * the indexer's exact field mapping.
	 *
	 * @param int    $user_id Member.
	 * @param string $content Public searchable text.
	 * @return void
	 */
	private function index_member_content( int $user_id, string $content ): void {
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'title'           => '',
				'content'         => $content,
				'content_members' => '',
			)
		);
	}

	/**
	 * /search/members filters on `q` as well as `search`.
	 *
	 * The member typeahead sends `?q=`, but the route declared only `search`, so WP
	 * dropped the arg and every keystroke returned the same unfiltered first page -
	 * the search silently did nothing (card 10320545977). `q` is now an accepted
	 * alias, matching the /search and /search/suggest convention.
	 *
	 * @return void
	 */
	public function test_search_members_accepts_q_as_an_alias_for_search(): void {
		$match    = self::factory()->user->create();
		$this->index_member_content( $match, 'Zqxwvudistinct Persson' );
		// A second, non-matching member so an unfiltered page is larger than one.
		$this->index_member_content( self::factory()->user->create(), 'Ordinary Common Member' );

		$by_q = rest_do_request(
			( function () {
				$r = new WP_REST_Request( 'GET', '/buddynext/v1/search/members' );
				$r->set_param( 'q', 'Zqxwvudistinct' );
				return $r;
			} )()
		)->get_data();

		$by_search = rest_do_request(
			( function () {
				$r = new WP_REST_Request( 'GET', '/buddynext/v1/search/members' );
				$r->set_param( 'search', 'Zqxwvudistinct' );
				return $r;
			} )()
		)->get_data();

		$q_ids      = array_map( 'intval', array_column( $by_q['items'], 'user_id' ) );
		$search_ids = array_map( 'intval', array_column( $by_search['items'], 'user_id' ) );

		$this->assertContains( $match, $q_ids, '?q= must filter to the matching member.' );
		$this->assertSame( $search_ids, $q_ids, '?q= and ?search= must return the same members.' );
		$this->assertSame( 1, (int) $by_q['total'], '?q= must actually filter, not return the unfiltered page.' );
	}

	/**
	 * The anti-bug guard: before the alias, `?q=` was dropped and a non-matching
	 * term still returned the full first page. It must now return nothing.
	 *
	 * @return void
	 */
	public function test_search_members_q_that_matches_nothing_returns_nothing(): void {
		$this->index_member_content( self::factory()->user->create(), 'Somebody Findable' );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search/members' );
		$request->set_param( 'q', 'zzzznomatchxyz' );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 0, (int) $data['total'], 'a non-matching ?q= must return no members, not the unfiltered page.' );
	}

	/**
	 * Run a typed search over REST and return the matching object ids, in order.
	 *
	 * @param array<string,string> $params Extra query params.
	 * @return int[]
	 */
	private function search_ids( array $params ): array {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/search' );
		$request->set_param( 'q', 'windowterm' );
		$request->set_param( 'type', 'post' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return array_map( 'intval', array_column( (array) rest_do_request( $request )->get_data()['items'], 'object_id' ) );
	}

	/**
	 * Index two posts matching the same word, one updated a month ago.
	 *
	 * @return void
	 */
	private function seed_old_and_new(): void {
		global $wpdb;
		$posts = new \BuddyNext\Feed\PostService();
		$old   = (int) $posts->create(
			$this->author_id,
			array(
				'type'    => 'text',
				'content' => 'windowterm old windowterm windowterm',
				'privacy' => 'public',
			)
		);
		$new   = (int) $posts->create(
			$this->author_id,
			array(
				'type'    => 'text',
				'content' => 'windowterm new',
				'privacy' => 'public',
			)
		);
		$this->search_service->index( 'post', $old, 'windowterm old', 'windowterm windowterm windowterm', $this->author_id );
		$this->search_service->index( 'post', $new, 'windowterm new', 'windowterm', $this->author_id );
		// The old post was written 40 days ago. Its index row is fresh, as after a
		// re-index: the window must follow the post, not the row.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 40 DAY ) WHERE id = %d", $old ) );
		$this->old_post = $old;
		$this->new_post = $new;
	}

	/**
	 * The date window is applied: the app gets what the page's Date control does.
	 *
	 * @return void
	 */
	public function test_date_window_leaves_out_older_results(): void {
		$this->seed_old_and_new();

		$this->assertEqualsCanonicalizing( array( $this->old_post, $this->new_post ), $this->search_ids( array() ), 'No window: both.' );
		$this->assertSame( array( $this->new_post ), $this->search_ids( array( 'date' => 'week' ) ) );
	}

	/**
	 * Sort by recent puts the newest result first.
	 *
	 * @return void
	 */
	public function test_sort_recent_orders_newest_first(): void {
		$this->seed_old_and_new();

		$this->assertSame( array( $this->new_post, $this->old_post ), $this->search_ids( array( 'sort' => 'recent' ) ) );
	}

	/**
	 * A bridge can link a result whose type is not a WordPress post.
	 *
	 * @return void
	 */
	public function test_result_link_can_be_answered_by_a_bridge(): void {
		$answer = static fn( string $url, string $type, int $id ): string => 'qa_topic' === $type ? 'https://example.test/t/' . $id . '/' : $url;
		add_filter( 'buddynext_search_item_url', $answer, 10, 3 );

		$this->assertSame( 'https://example.test/t/77/', $this->search_service->resolve_item_url( 'qa_topic', 77 ) );
		$this->assertSame( '', $this->search_service->resolve_item_url( 'unknown_type', 999999999 ), 'Unanswered and not a post: no link.' );

		remove_filter( 'buddynext_search_item_url', $answer, 10 );
	}
}
