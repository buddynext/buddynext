<?php
/**
 * Closing a space must close its already-indexed content.
 *
 * index() applies the space visibility ceiling as content is written, which
 * covers everything indexed AFTER a space became private. Nothing re-applied it
 * to rows already in the index, and flipping a space's type did not rewrite
 * them: on_space_updated() re-indexed the SPACE row and nothing else.
 *
 * So an open space full of public posts, switched to private or secret, kept
 * every one of those posts at visibility 'public'. The guest search gate is
 * literally `visibility = 'public'`, so an anonymous visitor could still pull
 * the titles and bodies of posts out of a space they cannot open. Reproduced
 * against real data before the fix: three posts, all three returned to a
 * logged-out search.
 *
 * These tests assert the INVARIANT rather than the mechanism - what a guest can
 * see, given the space's current type - so they keep holding if the clamp is
 * later replaced by a re-index, a queue job, or anything else.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;
use BuddyNext\Spaces\SpaceService;

/**
 * Space type flips and the search index.
 *
 * @covers \BuddyNext\Search\SearchService::clamp_space_visibility
 * @covers \BuddyNext\Search\SearchService::flush_space_ceiling
 */
class SpaceVisibilityFlipTest extends \WP_UnitTestCase {

	/**
	 * Space owner.
	 *
	 * @var int
	 */
	private $owner = 0;

	/**
	 * Space under test.
	 *
	 * @var int
	 */
	private $space_id = 0;

	/**
	 * Post ids created inside the space.
	 *
	 * @var int[]
	 */
	private $post_ids = array();

	/**
	 * An open space holding one public post, indexed.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$space_id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Flip test space',
				'slug' => 'flip-test-space-' . wp_generate_password( 6, false ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id, 'Space fixture failed to create.' );
		$this->space_id = (int) $space_id;

		$this->post_ids[] = $this->indexed_post( 'A public post about telescopes' );
	}

	/**
	 * Insert a published public post in the space and index it.
	 *
	 * @param string $content Post body.
	 * @return int Post id.
	 */
	private function indexed_post( string $content ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->owner,
				'content'    => $content,
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => $this->space_id,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$post_id = (int) $wpdb->insert_id;

		buddynext_service( 'search' )->index(
			'post',
			$post_id,
			'',
			$content,
			$this->owner,
			'public',
			$this->space_id
		);

		return $post_id;
	}

	/**
	 * The stored index visibility for this space's posts.
	 *
	 * @return string[]
	 */
	private function stored_visibility(): array {
		global $wpdb;

		$ids = implode( ',', array_map( 'absint', $this->post_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_col(
			"SELECT visibility FROM {$wpdb->prefix}bn_search_index
			  WHERE object_type = 'post' AND object_id IN ({$ids})"
		);
	}

	/**
	 * Change the space type through the service, as the owner would.
	 *
	 * @param string $type New space type.
	 * @return void
	 */
	private function set_type( string $type ): void {
		wp_set_current_user( $this->owner );
		( new SpaceService() )->update( $this->space_id, $this->owner, array( 'type' => $type ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Baseline: an open space's public post is publicly indexed. Without this the
	 * assertions below could pass on content that was never public to begin with.
	 *
	 * @return void
	 */
	public function test_an_open_space_indexes_its_posts_publicly(): void {
		$this->assertSame( array( 'public' ), $this->stored_visibility() );
	}

	/**
	 * The regression: closing the space must close its existing content.
	 *
	 * @return void
	 */
	public function test_closing_a_space_closes_its_already_indexed_posts(): void {
		$this->set_type( 'private' );

		$this->assertSame(
			array( 'private' ),
			$this->stored_visibility(),
			'A post indexed while the space was open stayed publicly searchable after the space was made private.'
		);
	}

	/**
	 * Secret behaves exactly like private here - both require membership, so both
	 * must clamp. Guards against a fix that special-cased only one type.
	 *
	 * @return void
	 */
	public function test_a_secret_space_clamps_the_same_way(): void {
		$this->set_type( 'secret' );

		$this->assertSame( array( 'private' ), $this->stored_visibility() );
	}

	/**
	 * ...and re-opening must restore it, or the fix would quietly bury content
	 * every time an owner toggled a space shut and open again.
	 *
	 * @return void
	 */
	public function test_re_opening_a_space_restores_its_posts(): void {
		$this->set_type( 'private' );
		$this->assertSame( array( 'private' ), $this->stored_visibility(), 'Clamp did not run.' );

		$this->set_type( 'open' );
		( new \BuddyNext\Search\SearchIndexListener() )->async_reindex_space_posts( $this->space_id, 0 );

		$this->assertSame(
			array( 'public' ),
			$this->stored_visibility(),
			'A re-opened space left its posts clamped shut.'
		);
	}

	/**
	 * The ceiling is memoised per request. If nothing invalidates that memo, a
	 * request that resolved the ceiling BEFORE the type changed writes the old
	 * visibility afterwards - which is how the clamp can be made to write
	 * 'public' back over content that was just closed.
	 *
	 * @return void
	 */
	public function test_a_ceiling_read_before_the_flip_does_not_survive_it(): void {
		// Warm the memo while the space is still open.
		$this->indexed_post( 'Second public post, indexed before the flip' );
		$this->post_ids[] = (int) $GLOBALS['wpdb']->insert_id;

		$this->set_type( 'private' );

		foreach ( $this->stored_visibility() as $visibility ) {
			$this->assertSame(
				'private',
				$visibility,
				'A ceiling memoised before the flip leaked through and left content public.'
			);
		}
	}
}
