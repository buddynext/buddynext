<?php
/**
 * Deleting a post deindexes its reshares from search.
 *
 * Regression cover for card 10264292524: a reshare of a deleted post is a dead end
 * in search (it opens to an "unavailable" card). Dropping it at read time left the
 * search count and pager counting a row the list dropped ("6" over 4 cards, empty
 * pages). It is now removed at the SOURCE — when the original is deleted, its
 * reshares leave the index — so the count is right. The reshare POST still exists
 * and still renders in the feed as unavailable; it only leaves search.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;
use BuddyNext\Search\SearchIndexListener;
use WP_UnitTestCase;

/**
 * A reshare's index row is removed when its shared original is deleted.
 *
 * @covers \BuddyNext\Search\SearchIndexListener::on_post_deleted
 */
class ReshareDeindexedOnOriginalDeleteTest extends WP_UnitTestCase {

	/**
	 * Count the search-index rows for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function index_rows( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_reshare_leaves_the_index_when_the_original_is_deleted(): void {
		Installer::run();

		$posts  = new PostService();
		$shares = new ShareService();
		$author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$sharer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$original = (int) $posts->create( $author, array( 'type' => 'text', 'content' => 'reshare me', 'privacy' => 'public' ) );
		$reshare  = $shares->share( $sharer, $original, 'my take' );
		$this->assertIsInt( $reshare, 'The reshare should be created.' );

		$listener = new SearchIndexListener();
		$listener->async_index_post( $original, $author );
		$listener->async_index_post( $reshare, $sharer );
		$this->assertSame( 1, $this->index_rows( $reshare ), 'The reshare should be indexed to begin with.' );

		// on_post_deleted() finds the reshares of the deleted post and dispatches a
		// deindex for each. Action Scheduler queues these, so assert the reshare's
		// deindex was scheduled, then run the worker and confirm the row is gone.
		$listener->on_post_deleted( $original );

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$this->assertTrue(
				as_has_scheduled_action( 'buddynext_async_deindex_post', array( $reshare ), 'buddynext' ),
				'on_post_deleted() must schedule a deindex for the reshare of the deleted post.'
			);
		}

		$listener->async_deindex_post( $reshare );

		$this->assertSame( 0, $this->index_rows( $reshare ), 'The reshare must be deindexed once its original is gone.' );
		$this->assertNotNull( $posts->get( $reshare ), 'The reshare POST still exists — it only leaves search.' );
	}
}
