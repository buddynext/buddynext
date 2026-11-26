<?php
/**
 * ResponseSchema must describe what the routes really return.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Dates;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\ResponseSchema;
use WP_REST_Request;

/**
 * The OpenAPI spec is generated from ResponseSchema, and bin/check-openapi.php
 * only re-checks list routes it can call without arguments. These pin the shapes
 * that gate failed on in 1.3.0, against real dispatched responses:
 *
 *   - GET /comments/{id} was mapped by its route regex, which never matches the
 *     templated path the generator emits, so it shipped with no response schema
 *     (and the comment schema had never declared viewer_reported).
 *   - card_unavailable rode every feed item undeclared in post and feed_explore.
 *   - search/members returned headline undeclared.
 *
 * @covers \BuddyNext\REST\ResponseSchema
 */
class ResponseSchemaConformanceTest extends \WP_UnitTestCase {

	/**
	 * Post author.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * Boot the tables and routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );
		$this->author = self::factory()->user->create();
		wp_set_current_user( $this->author );
	}

	/**
	 * Dispatch a GET and return its data.
	 *
	 * @param string               $route  Route under /buddynext/v1.
	 * @param array<string,mixed>  $params Query params.
	 * @return array<mixed>
	 */
	private function get( string $route, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), $route . ' must answer 200.' );
		return (array) rest_get_server()->response_to_data( $response, false );
	}

	/**
	 * Assert a live row and a resource schema name the same fields, the way the
	 * drift gate compares them.
	 *
	 * @param string              $resource ResponseSchema method.
	 * @param array<string,mixed> $row      Live row.
	 * @return void
	 */
	private function assert_row_matches( string $resource, array $row ): void {
		$properties = (array) ( ResponseSchema::$resource()['properties'] ?? array() );
		$declared   = array_keys( $properties );
		$ts_keys    = Dates::timestamp_keys();

		$undocumented = array_values(
			array_filter(
				array_diff( array_keys( $row ), $declared ),
				static fn( string $field ): bool => ! ( str_ends_with( $field, '_gmt' ) && in_array( substr( $field, 0, -4 ), $ts_keys, true ) )
			)
		);
		$missing      = array_values(
			array_filter(
				array_diff( $declared, array_keys( $row ) ),
				static fn( string $field ): bool => empty( $properties[ $field ]['x-bn-conditional'] ) && ! str_ends_with( $field, '_gmt' )
			)
		);

		$this->assertSame( array(), $undocumented, $resource . ' returns fields its schema does not declare.' );
		$this->assertSame( array(), $missing, $resource . ' declares fields the route does not return.' );
	}

	/**
	 * Map paths are the generator's templates, never a route regex: a regex path
	 * matches no operation, so its route silently goes untyped.
	 *
	 * @return void
	 */
	public function test_map_paths_are_templates_not_route_regexes(): void {
		foreach ( ResponseSchema::map() as $entry ) {
			$this->assertStringNotContainsString( '(?P<', (string) $entry['path'], $entry['method'] . ' ' . $entry['path'] . ' must use {param} placeholders.' );
		}
	}

	/**
	 * GET /comments/{id} is mapped to the comment schema, and that schema is the
	 * full shape the route returns.
	 *
	 * @return void
	 */
	public function test_single_comment_matches_the_comment_schema(): void {
		$mapped = array_filter(
			ResponseSchema::map(),
			static fn( array $entry ): bool => 'GET' === $entry['method'] && '/comments/{id}' === $entry['path']
		);
		$this->assertCount( 1, $mapped, 'GET /comments/{id} must be in the map.' );
		$this->assertSame( 'comment', array_values( $mapped )[0]['resource'] );

		$post_id    = (int) ( new PostService() )->create(
			$this->author,
			array(
				'content' => 'parent',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);
		$comment_id = (int) ( new CommentService() )->create( $this->author, 'post', $post_id, 'hello' );

		$this->assert_row_matches( 'comment', $this->get( '/comments/' . $comment_id ) );
	}

	/**
	 * A feed item carries card_unavailable on every surface, so post and
	 * feed_explore both declare it.
	 *
	 * @return void
	 */
	public function test_feed_items_declare_card_unavailable(): void {
		$post_id = (int) ( new PostService() )->create(
			$this->author,
			array(
				'content' => 'hello feed',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);

		$row = $this->get( '/posts/' . $post_id );
		$this->assertIsBool( $row['card_unavailable'] );
		$this->assert_row_matches( 'post', $row );

		$this->assertSame( array( 'type' => 'boolean' ), ResponseSchema::feed_explore()['properties']['card_unavailable'] ?? null );
	}

	/**
	 * A member search row matches search_members, headline included.
	 *
	 * @return void
	 */
	public function test_member_search_rows_match_the_schema(): void {
		$data = $this->get( '/search/members', array( 'per_page' => 5 ) );
		$rows = array_values( array_filter( (array) ( $data['items'] ?? array() ), 'is_array' ) );
		$this->assertNotEmpty( $rows, 'the directory must return the seeded member.' );

		$this->assertIsString( $rows[0]['headline'] );
		$this->assert_row_matches( 'search_members', $rows[0] );
	}
}
