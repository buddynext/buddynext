<?php
/**
 * GET /spaces/{id}/media — the Space Media tab's endpoint.
 *
 * The web derived a space's media grid from the feed, so the app had no endpoint
 * at all and would have had to scan the feed itself: fragile, and unpaginated.
 *
 * The two things that matter here are the gate and the pagination. The gate is
 * SpaceVisibility::can_view_content() — the same decision point the space feed
 * and the single-post read use — so a private space that refuses its feed cannot
 * leak the same content through its media grid. Pagination is over media-bearing
 * POSTS (an indexed query), because offsetting into a flattened attachment list
 * re-reads every earlier post on every page.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * The space media endpoint.
 *
 * @covers \BuddyNext\Spaces\SpaceController::get_space_media
 * @covers \BuddyNext\Feed\FeedService::space_media_rows
 */
class SpaceMediaEndpointTest extends \WP_UnitTestCase {

	/**
	 * Space owner and post author.
	 *
	 * @var int
	 */
	private $owner = 0;

	/**
	 * An active member of the private space.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * Someone with no membership.
	 *
	 * @var int
	 */
	private $stranger = 0;

	/**
	 * Space ids keyed by type.
	 *
	 * @var array<string, int>
	 */
	private $spaces = array();

	/**
	 * Build an open and a private space, each with media-bearing posts.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wpdb, $wp_rest_server;

		$this->owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$spaces = new SpaceService();

		foreach ( array( 'open', 'private' ) as $type ) {
			$space_id = $spaces->create(
				$this->owner,
				array(
					'name' => ucfirst( $type ) . ' Media Space',
					'slug' => $type . '-media-space',
					'type' => $type,
				)
			);
			$this->assertIsInt( $space_id );
			$this->spaces[ $type ] = $space_id;

			// The endpoint mirrors the web Media tab's condition: engine present
			// (the bootstrap's WPMediaVerse stub), site integration on (option
			// default), and the per-space Media-tab field - which defaults OFF,
			// so every fixture space enables it explicitly.
			update_space_meta( $space_id, 'mvs_media_tab', '1' );

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'bn_space_members',
				array(
					'space_id' => $space_id,
					'user_id'  => $this->member,
					'role'     => 'member',
					'status'   => 'active',
				)
			);
			$this->assertSame( 1, $inserted, 'Membership fixture was not written.' );

			// Three media-bearing posts, plus one text post that must not appear.
			for ( $i = 0; $i < 3; $i++ ) {
				$wrote = $wpdb->insert(
					$wpdb->prefix . 'bn_posts',
					array(
						'user_id'   => $this->owner,
						'space_id'  => $space_id,
						'type'      => 'photo',
						'content'   => 'Media post ' . $i,
						'privacy'   => 'public',
						'status'    => 'published',
						'media_ids' => wp_json_encode( array( 100 + $i, 200 + $i ) ),
					)
				);
				$this->assertSame( 1, $wrote, 'Media post fixture was not written.' );
			}

			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id'  => $this->owner,
					'space_id' => $space_id,
					'type'     => 'text',
					'content'  => 'No attachments here.',
					'privacy'  => 'public',
					'status'   => 'published',
				)
			);
		}

		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatch the endpoint as a given viewer.
	 *
	 * @param string               $type   Space type key.
	 * @param int                  $viewer Viewer user ID (0 = anonymous).
	 * @param array<string, mixed> $params Extra query params.
	 * @return array{status:int,data:array<string,mixed>}
	 */
	private function fetch( string $type, int $viewer, array $params = array() ): array {
		wp_set_current_user( $viewer );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $this->spaces[ $type ] . '/media' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		return array(
			'status' => $response->get_status(),
			'data'   => (array) $response->get_data(),
		);
	}

	/**
	 * An open space's media is public, like its feed.
	 *
	 * @return void
	 */
	public function test_open_space_media_is_readable_by_anyone(): void {
		$result = $this->fetch( 'open', 0 );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 3, $result['data']['total'], 'Only media-bearing posts count toward the total.' );
	}

	/**
	 * The gate that matters: a private space must not leak media to a non-member,
	 * logged in or not — and must not confirm the space exists either.
	 *
	 * @return void
	 */
	public function test_private_space_media_is_gated(): void {
		foreach ( array( 0 => 'an anonymous visitor', $this->stranger => 'a logged-in non-member' ) as $viewer => $who ) {
			$result = $this->fetch( 'private', (int) $viewer );

			$this->assertSame( 404, $result['status'], 'Private space media was readable by ' . $who . '.' );
			$this->assertSame( 'space_not_found', $result['data']['code'] ?? '', 'A 403 would confirm the space exists.' );
		}
	}

	/**
	 * Members and the owner keep access to their own space's media.
	 *
	 * @return void
	 */
	public function test_private_space_media_is_readable_by_members(): void {
		foreach ( array( $this->member, $this->owner ) as $viewer ) {
			$this->assertSame( 200, $this->fetch( 'private', $viewer )['status'] );
		}
	}

	/**
	 * Text posts contribute nothing — the grid is media, not a feed mirror.
	 *
	 * @return void
	 */
	public function test_posts_without_attachments_are_excluded(): void {
		$rows = buddynext_service( 'feed' )->space_media_rows( $this->spaces['open'], 50, 0 );

		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertNotEmpty( $row['media_ids'] );
			$this->assertGreaterThan( 0, $row['post_id'] );
		}
	}

	/**
	 * Pagination is over posts and reports honest totals, so a client can page
	 * without discovering the end by trial and error.
	 *
	 * @return void
	 */
	public function test_pagination_is_bounded_and_reports_totals(): void {
		$page_one = $this->fetch( 'open', 0, array( 'per_page' => 2, 'page' => 1 ) );
		$page_two = $this->fetch( 'open', 0, array( 'per_page' => 2, 'page' => 2 ) );

		$this->assertSame( 3, $page_one['data']['total'] );
		$this->assertSame( 2, $page_one['data']['total_pages'] );
		$this->assertSame( 2, $page_one['data']['per_page'] );

		$this->assertCount( 2, buddynext_service( 'feed' )->space_media_rows( $this->spaces['open'], 2, 0 ) );
		$this->assertCount( 1, buddynext_service( 'feed' )->space_media_rows( $this->spaces['open'], 2, 2 ) );

		$this->assertSame( 200, $page_two['status'] );
	}

	/**
	 * A page past the end is an empty page, not an error — infinite scroll stops
	 * cleanly instead of surfacing a failure to the member.
	 *
	 * @return void
	 */
	public function test_a_page_past_the_end_is_empty_not_an_error(): void {
		$result = $this->fetch( 'open', 0, array( 'per_page' => 10, 'page' => 99 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array(), $result['data']['items'] );
	}

	/**
	 * per_page is capped, so a client cannot ask the server to build one enormous
	 * response on a space with years of photos.
	 *
	 * @return void
	 */
	public function test_per_page_is_capped(): void {
		$this->assertCount(
			3,
			buddynext_service( 'feed' )->space_media_rows( $this->spaces['open'], 5000, 0 ),
			'The cap must clamp, not error.'
		);
	}

	/**
	 * A space whose owner switched the Media tab off must refuse over REST too
	 * (card 10132702949): the endpoint mirrors the web tab's condition, or the
	 * per-space setting silently stops applying the moment a client asks
	 * through the API.
	 *
	 * @return void
	 */
	public function test_space_with_media_tab_off_refuses_over_rest(): void {
		update_space_meta( $this->spaces['open'], 'mvs_media_tab', '0' );

		$result = $this->fetch( 'open', $this->member );

		$this->assertSame( 404, $result['status'] );

		update_space_meta( $this->spaces['open'], 'mvs_media_tab', '1' );
	}

	/**
	 * A site with the media integration disabled entirely must refuse for every
	 * space, regardless of the per-space field.
	 *
	 * @return void
	 */
	public function test_disabled_media_integration_refuses_over_rest(): void {
		update_option( 'buddynext_integration_media_nav', '0' );

		$result = $this->fetch( 'open', $this->member );

		delete_option( 'buddynext_integration_media_nav' );

		$this->assertSame( 404, $result['status'] );
	}
}
