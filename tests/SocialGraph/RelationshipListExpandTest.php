<?php
/**
 * /me/blocked, /me/muted and /me/restricted can return hydrated members.
 *
 * The three relationship-list endpoints returned a bare array of user ids and
 * nothing else, so every client had to follow up with one member lookup per id
 * just to draw a name and an avatar. BaseRestController already ships the
 * batch-primed expansion helper — these routes simply never called it, and they
 * declared no `args` at all, so neither per_page/page nor the expansion were
 * visible to a generated client.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * Shape of the three relationship lists.
 *
 * @covers \BuddyNext\SocialGraph\BlockController
 */
class RelationshipListExpandTest extends \WP_UnitTestCase {

	/**
	 * Viewer performing the blocks.
	 *
	 * @var int
	 */
	private $viewer = 0;

	/**
	 * Target of the block/mute/restrict.
	 *
	 * @var int
	 */
	private $target = 0;

	/**
	 * Create the schema, the users and boot the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->target = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Blocked Person',
			)
		);

		wp_set_current_user( $this->viewer );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatch one of the three relationship routes.
	 *
	 * @param string $route  Route tail (blocked|muted|restricted).
	 * @param bool   $expand Whether to ask for member expansion.
	 * @return array<string, mixed>
	 */
	private function fetch( string $route, bool $expand ): array {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/' . $route );
		if ( $expand ) {
			$request->set_param( 'expand', 'members' );
		}

		return (array) rest_do_request( $request )->get_data();
	}

	/**
	 * Hydrated rows are the DEFAULT.
	 *
	 * These three routes exist for the app's Blocked / Muted / Restricted
	 * screens, all of which need a name and an avatar. Shipping ids by default
	 * made the only consumer opt in to the thing it always wants, and a client
	 * that forgot rendered a list of tombstones — which is exactly what the app
	 * team reported after the first pass. `ids` is still present, so a reader of
	 * that key is unaffected.
	 *
	 * @return void
	 */
	public function test_members_are_hydrated_by_default(): void {
		buddynext_service( 'blocks' )->block( $this->viewer, $this->target );

		$data = $this->fetch( 'blocked', false );

		$this->assertSame( array( 'ids', 'members' ), array_keys( $data ) );
		$this->assertSame( array( $this->target ), $data['ids'], 'ids must survive the default change.' );
		$this->assertSame( 'Blocked Person', $data['members'][0]['display_name'] );
	}

	/**
	 * A client that wants the light payload can still ask for it.
	 *
	 * @return void
	 */
	public function test_an_empty_expand_returns_ids_only(): void {
		buddynext_service( 'blocks' )->block( $this->viewer, $this->target );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/me/blocked' );
		$request->set_param( 'expand', '' );
		$data = (array) rest_do_request( $request )->get_data();

		$this->assertSame( array( 'ids' ), array_keys( $data ) );
	}

	/**
	 * ?expand=members adds display name and avatar for every id.
	 *
	 * @return void
	 */
	public function test_expand_members_hydrates_blocked_rows(): void {
		buddynext_service( 'blocks' )->block( $this->viewer, $this->target );

		$data = $this->fetch( 'blocked', true );

		$this->assertArrayHasKey( 'members', $data );
		$this->assertCount( 1, $data['members'] );
		$this->assertSame( $this->target, (int) $data['members'][0]['user_id'] );
		$this->assertSame( 'Blocked Person', $data['members'][0]['display_name'] );
		$this->assertNotSame( '', (string) $data['members'][0]['avatar_url'] );
	}

	/**
	 * Muted uses the same shape — the helper is shared, not copy-pasted.
	 *
	 * @return void
	 */
	public function test_expand_members_hydrates_muted_rows(): void {
		buddynext_service( 'blocks' )->mute( $this->viewer, $this->target );

		$data = $this->fetch( 'muted', true );

		$this->assertSame( array( $this->target ), $data['ids'] );
		$this->assertSame( 'Blocked Person', $data['members'][0]['display_name'] );
	}

	/**
	 * Restricted uses the same shape.
	 *
	 * @return void
	 */
	public function test_expand_members_hydrates_restricted_rows(): void {
		buddynext_service( 'blocks' )->restrict( $this->viewer, $this->target );

		$data = $this->fetch( 'restricted', true );

		$this->assertSame( array( $this->target ), $data['ids'] );
		$this->assertSame( 'Blocked Person', $data['members'][0]['display_name'] );
	}

	/**
	 * An empty list still returns a members array rather than omitting the key,
	 * so a client can bind to it without a null check.
	 *
	 * @return void
	 */
	public function test_empty_list_expands_to_an_empty_members_array(): void {
		$data = $this->fetch( 'blocked', true );

		$this->assertSame( array(), $data['ids'] );
		$this->assertArrayHasKey( 'members', $data );
		$this->assertSame( array(), $data['members'] );
	}

	/**
	 * per_page / page / expand are declared on all three routes, so the OpenAPI
	 * catalogue and any generated client can see them.
	 *
	 * @return void
	 */
	public function test_all_three_routes_declare_their_params(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( array( 'blocked', 'muted', 'restricted' ) as $tail ) {
			$route = '/buddynext/v1/me/' . $tail;
			$this->assertArrayHasKey( $route, $routes );

			$args = $routes[ $route ][0]['args'];
			$this->assertArrayHasKey( 'per_page', $args, $route . ' does not declare per_page.' );
			$this->assertArrayHasKey( 'page', $args, $route . ' does not declare page.' );
			$this->assertArrayHasKey( 'expand', $args, $route . ' does not declare expand.' );
			$this->assertSame( 100, $args['per_page']['maximum'], $route . ' declares a per_page cap list_window() does not honour.' );
		}
	}
}
