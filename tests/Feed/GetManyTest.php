<?php
/**
 * Batch post fetch by ID.
 *
 * get_many() exists so a caller holding a list of ids — search hits, bookmarks,
 * a moderation selection — can hydrate them without looping get() and issuing
 * one query per row. Two properties matter to callers and are pinned here: the
 * caller's ORDER is preserved (search hands over ids in relevance order, which
 * the database does not know about), and ids with no row are SKIPPED rather than
 * yielding nulls the templates would have to guard.
 *
 * The skip is not a theoretical nicety. On the development site 186 of 332
 * indexed post rows pointed at posts that no longer existed, so a search-driven
 * caller hits missing ids as a matter of course, not as an edge case.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * PostService::get_many().
 *
 * @covers \BuddyNext\Feed\PostService::get_many
 */
class GetManyTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var PostService
	 */
	private PostService $posts;

	/**
	 * Created post IDs, in creation order.
	 *
	 * @var array<int,int>
	 */
	private array $ids = array();

	/**
	 * Create a handful of posts.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->posts = new PostService();
		$author      = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( array( 'first', 'second', 'third' ) as $label ) {
			$id = $this->posts->create( $author, array( 'content' => 'batch fetch ' . $label ) );
			$this->assertIsInt( $id, 'Could not create the ' . $label . ' fixture post.' );
			$this->ids[] = (int) $id;
		}
	}

	/**
	 * Results come back in the order the caller asked for.
	 *
	 * @return void
	 */
	public function test_order_follows_the_requested_ids(): void {
		$requested = array( $this->ids[2], $this->ids[0], $this->ids[1] );

		$returned = array_map(
			static fn( array $p ): int => (int) $p['id'],
			$this->posts->get_many( $requested )
		);

		$this->assertSame(
			$requested,
			$returned,
			'get_many must return the caller order — a search hands over ids by relevance, '
			. 'and the database does not know that order'
		);
	}

	/**
	 * A missing ID is skipped, not returned as a null hole.
	 *
	 * @return void
	 */
	public function test_a_missing_id_is_skipped(): void {
		$returned = $this->posts->get_many( array( $this->ids[0], 987654321, $this->ids[1] ) );

		$this->assertCount( 2, $returned );
		$this->assertSame( array( $this->ids[0], $this->ids[1] ), array_map( static fn( $p ): int => (int) $p['id'], $returned ) );
		$this->assertNotContains( null, $returned, 'a missing row must be dropped, never returned as null' );
	}

	/**
	 * Every returned row is hydrated, not a raw DB row.
	 *
	 * @return void
	 */
	public function test_rows_are_hydrated(): void {
		$post = $this->posts->get_many( array( $this->ids[0] ) )[0];

		$single = $this->posts->get( $this->ids[0] );
		$this->assertNotNull( $single, 'precondition: get() returns the same post' );

		$this->assertSame(
			$single['id'],
			$post['id'],
			'get_many must produce the same shape get() does — callers render both through one card'
		);
		$this->assertArrayHasKey( 'reaction_count', $post, 'hydrate() adds the counter keys the post card reads' );
	}

	/**
	 * An empty or all-junk list costs no query and returns nothing.
	 *
	 * @return void
	 */
	public function test_an_empty_list_is_a_no_op(): void {
		$this->assertSame( array(), $this->posts->get_many( array() ) );
		$this->assertSame( array(), $this->posts->get_many( array( 0, -5 ) ) );
	}

	/**
	 * The whole batch costs ONE query, which is the reason the method exists.
	 *
	 * Without this the method could quietly regress into a get() loop and every
	 * other test here would still pass.
	 *
	 * @return void
	 */
	public function test_the_batch_costs_one_query(): void {
		global $wpdb;

		$before = $wpdb->num_queries;
		$this->posts->get_many( $this->ids );
		$spent = $wpdb->num_queries - $before;

		$this->assertSame(
			1,
			$spent,
			'get_many must issue exactly one SELECT for the whole batch; '
			. $spent . ' queries means it has regressed into a per-row loop'
		);
	}
}
