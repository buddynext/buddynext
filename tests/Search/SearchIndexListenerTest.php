<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * One post or space is indexed and de-indexed in the request that changed it.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceService;

/**
 * Post search was empty on hosts whose background queue never runs: indexing one
 * post (and removing one) was queued on Action Scheduler even though it is a
 * single SELECT and upsert (card 10501591033). Action Scheduler is loaded in this
 * suite, so these assertions would see nothing if the work were still queued.
 *
 * @covers \BuddyNext\Search\SearchIndexListener
 */
class SearchIndexListenerTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ), 'the queue is available, as on a real site' );
	}

	private function indexed( string $type, int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = %s AND object_id = %d", $type, $id ) );
	}

	public function test_a_post_is_searchable_and_removed_in_the_same_request(): void {
		$author  = self::factory()->user->create();
		$posts   = new PostService();
		$post_id = (int) $posts->create( $author, array( 'content' => 'Searchable straight away' ) );

		$this->assertSame( 1, $this->indexed( 'post', $post_id ), 'indexed on create' );

		$posts->update( $post_id, $author, array( 'content' => 'Edited words' ) );
		global $wpdb;
		$this->assertStringContainsString( 'Edited words', (string) $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d", $post_id ) ), 're-indexed on edit' );

		$posts->delete( $post_id, $author );
		$this->assertSame( 0, $this->indexed( 'post', $post_id ), 'gone from search on delete' );
	}

	public function test_a_space_is_searchable_and_removed_in_the_same_request(): void {
		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$spaces   = new SpaceService();
		$space_id = (int) $spaces->create(
			$owner,
			array(
				'name' => 'Inline indexed space',
				'slug' => 'inline-indexed-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertSame( 1, $this->indexed( 'space', $space_id ), 'indexed on create' );

		do_action( 'buddynext_space_deleted', $space_id );
		$this->assertSame( 0, $this->indexed( 'space', $space_id ), 'gone from search on delete' );
	}
}
