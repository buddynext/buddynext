<?php
/**
 * The Space new-posts count answers only a viewer who may READ the Space's content.
 *
 * Seeing a private Space's shell is not enough: a non-member, a banned member and a probe of a secret Space all
 * get the same empty answer, and no post id or count leaks anywhere in the payload.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * GET /feed/new-count?space_id=.
 */
class SpaceNewCountVisibilityTest extends WP_UnitTestCase {

	/**
	 * Space owner (and author of the post).
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Create the tables and the owner.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner = self::factory()->user->create();
	}

	/**
	 * Make a Space of a type with one published post in it.
	 *
	 * @param string $type open, private or secret.
	 * @return int Space id.
	 */
	private function space_with_post( string $type ): int {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Count ' . $type,
				'slug' => 'count-' . $type . '-' . wp_rand( 10000, 99999 ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		$post = buddynext_service( 'post_service' )->create(
			$this->owner,
			array(
				'content'  => 'A post in a ' . $type . ' space',
				'space_id' => (int) $id,
				'type'     => 'text',
			)
		);
		$this->assertIsInt( $post );
		return (int) $id;
	}

	/**
	 * What the endpoint tells a given viewer.
	 *
	 * @param int $viewer   Viewer id.
	 * @param int $space_id Space id.
	 * @return array<string,mixed>
	 */
	private function answer( int $viewer, int $space_id ): array {
		wp_set_current_user( $viewer );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/feed/new-count' );
		$request->set_param( 'space_id', $space_id );
		$request->set_param( 'after_id', 0 );
		$response = rest_do_request( $request );
		return (array) $response->get_data();
	}

	/**
	 * Members read it; a non-member and a banned member get nothing from a private Space; a secret Space is silent.
	 *
	 * @return void
	 */
	public function test_only_a_reader_gets_the_count(): void {
		$outsider = self::factory()->user->create();
		$member   = self::factory()->user->create();
		$banned   = self::factory()->user->create();

		foreach ( array( 'private', 'secret' ) as $type ) {
			$space = $this->space_with_post( $type );
			$svc   = new SpaceMemberService();
			$svc->join( $space, $member );
			$svc->join( $space, $banned );
			$svc->ban_from_space( $space, $banned, $this->owner, 'test' );

			$this->assertSame( 1, (int) $this->answer( $member, $space )['count'], "$type: a member sees the count" );
			foreach ( array( 'outsider' => $outsider, 'banned' => $banned ) as $who => $viewer ) {
				$got = $this->answer( $viewer, $space );
				$this->assertSame( 0, (int) ( $got['count'] ?? 0 ), "$type: $who gets 0" );
				$this->assertSame( 0, (int) ( $got['newest_id'] ?? 0 ), "$type: $who gets no post id" );
				$this->assertSame( array( 'count', 'newest_id' ), array_keys( $got ), "$type: $who gets nothing else" );
			}
		}
	}

	/**
	 * An open Space is readable by anyone signed in.
	 *
	 * @return void
	 */
	public function test_an_open_space_counts_for_any_signed_in_viewer(): void {
		$space = $this->space_with_post( 'open' );
		$this->assertSame( 1, (int) $this->answer( self::factory()->user->create(), $space )['count'] );
	}
}
