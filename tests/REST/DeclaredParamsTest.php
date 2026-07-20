<?php
/**
 * A route must declare the parameters it reads.
 *
 * @package BuddyNext\Tests\REST
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\REST\Router;
use WP_REST_Server;

/**
 * The openapi.json document is generated from the route registry, so a parameter
 * that a handler reads but never declares is invisible to every client reading
 * route truth.
 *
 * Three real consequences, all found in the 1.0.9 audit and all fixed by the
 * commit these tests accompany:
 *
 *   - GET /spaces read TWELVE params and declared none, so the whole directory
 *     advertised itself as unpaginated and unfilterable.
 *   - GET /me/notifications declared `offset` only, steering clients onto O(n)
 *     paging while the keyset `cursor` the handler supports stayed undiscoverable.
 *   - ConnectionController declared per_page/page on /connection/status, which
 *     never reads them, while /mutual-connections read them and declared nothing —
 *     so the dispatcher never filled the default and per_page arrived as 0.
 *
 * These assert the contract per route rather than re-deriving it, because the
 * failure mode is silent: nothing breaks, the client simply cannot find the
 * feature.
 *
 * @covers \BuddyNext\REST\Router
 */
class DeclaredParamsTest extends \WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Boot the real route registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		( new Router() )->register();
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Return the declared args for a route's first handler.
	 *
	 * @param string $route  Route path.
	 * @param string $method HTTP method to match.
	 * @return array<string,mixed>
	 */
	private function args_for( string $route, string $method = 'GET' ): array {
		$routes = $this->server->get_routes( 'buddynext/v1' );

		$this->assertArrayHasKey( $route, $routes, $route . ' must be registered.' );

		foreach ( $routes[ $route ] as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) {
				return (array) ( $handler['args'] ?? array() );
			}
		}

		$this->fail( $route . ' has no ' . $method . ' handler.' );
	}

	/**
	 * The spaces directory advertises its pagination and its filters.
	 *
	 * @return void
	 */
	public function test_spaces_directory_declares_what_it_reads(): void {
		$args = $this->args_for( '/buddynext/v1/spaces' );

		foreach ( array( 'per_page', 'page', 'paginate', 'orderby', 'order', 'type', 'category_id', 'search' ) as $param ) {
			$this->assertArrayHasKey(
				$param,
				$args,
				'/spaces reads ' . $param . ', so it must declare it — otherwise the directory reads as unpaginated and unfilterable in the spec.'
			);
		}
	}

	/**
	 * Notifications offer the cursor, not just the offset.
	 *
	 * @return void
	 */
	public function test_notifications_declare_the_cursor_they_support(): void {
		$args = $this->args_for( '/buddynext/v1/me/notifications' );

		$this->assertArrayHasKey(
			'cursor',
			$args,
			'The handler supports a keyset cursor; declaring only offset sends every client down the O(n) path.'
		);
		$this->assertArrayHasKey( 'per_page', $args );
	}

	/**
	 * Pagination is declared on the route that reads it.
	 *
	 * @return void
	 */
	public function test_mutual_connections_declares_its_own_pagination(): void {
		$args = $this->args_for( '/buddynext/v1/users/(?P<id>[\d]+)/mutual-connections' );

		$this->assertArrayHasKey(
			'per_page',
			$args,
			'mutual_connections() reads per_page; the declaration lived on /connection/status, so the default never fired.'
		);
		$this->assertSame( 20, $args['per_page']['default'] ?? null, 'The default must be reachable by the dispatcher.' );
	}

	/**
	 * And not on the route that ignores it.
	 *
	 * A declared parameter a handler never reads is a documented feature that does
	 * nothing — the same lie as an undeclared one, pointing the other way.
	 *
	 * @return void
	 */
	public function test_connection_status_does_not_declare_pagination_it_ignores(): void {
		$args = $this->args_for( '/buddynext/v1/users/(?P<id>[\d]+)/connection/status' );

		$this->assertArrayNotHasKey(
			'per_page',
			$args,
			'/connection/status returns a single status object and never reads per_page; declaring it advertises a page size that does nothing.'
		);
	}

	/**
	 * The feed surfaces declare their pagination (TG1.3 guard, kept here with the
	 * rest of the contract so one file answers "does the registry tell the truth").
	 *
	 * @return void
	 */
	public function test_feed_surfaces_declare_pagination(): void {
		foreach (
			array(
				'/buddynext/v1/feed/home',
				'/buddynext/v1/feed/explore',
				'/buddynext/v1/users/(?P<id>[\d]+)/feed',
				'/buddynext/v1/spaces/(?P<id>[\d]+)/feed',
			) as $route
		) {
			$args = $this->args_for( $route );
			$this->assertArrayHasKey( 'cursor', $args, $route . ' must declare cursor.' );
			$this->assertArrayHasKey( 'per_page', $args, $route . ' must declare per_page.' );
		}
	}
}
