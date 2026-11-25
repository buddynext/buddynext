<?php
/**
 * The two seams a bridge uses to draw a partner's card from the partner itself: one call per page of items
 * (guests included), and a last word on each REST item.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

/**
 * @covers \BuddyNext\Feed\FeedService::prime_viewer_state
 */
class FeedItemSeamsTest extends \WP_UnitTestCase {

	public function test_a_page_of_items_is_announced_once_for_a_guest_and_for_a_member(): void {
		$seen  = array();
		$track = static function ( $items, $viewer ) use ( &$seen ): void {
			$seen[] = array( count( (array) $items ), (int) $viewer );
		};
		add_action( 'buddynext_feed_items_primed', $track, 10, 2 );

		$items  = array( array( 'id' => 1, 'type' => 'event', 'user_id' => 0 ), array( 'id' => 2, 'type' => 'text', 'user_id' => 0 ) );
		$member = self::factory()->user->create();
		buddynext_service( 'feed' )->prime_viewer_state( $items, 0 );
		buddynext_service( 'feed' )->prime_viewer_state( $items, $member );

		remove_action( 'buddynext_feed_items_primed', $track, 10 );
		$this->assertSame( array( array( 2, 0 ), array( 2, $member ) ), $seen );
	}

	public function test_a_bridge_can_fill_a_rest_feed_item(): void {
		$author = self::factory()->user->create();
		wp_set_current_user( $author );
		$post = buddynext_service( 'post_service' )->create( $author, array( 'type' => 'text', 'content' => 'seam marker post' ) );
		$this->assertNotWPError( $post );

		$filler = static function ( $item ) {
			$item['seam_marker'] = 'filled';
			return $item;
		};
		add_filter( 'buddynext_feed_item_enriched', $filler );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );
		remove_filter( 'buddynext_feed_item_enriched', $filler );

		$this->assertSame( 200, $response->get_status() );
		$items = (array) ( $response->get_data()['items'] ?? $response->get_data() );
		$this->assertNotEmpty( $items, 'The home feed has the post.' );
		$this->assertSame( 'filled', $items[0]['seam_marker'] ?? null );
	}
}
