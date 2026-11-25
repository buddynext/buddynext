<?php
/**
 * The Space Media tab pages by posts on a keyset cursor, so older media is reachable and page 50 costs what page 1 does.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

/**
 * @covers \BuddyNext\Feed\FeedService::space_media_page
 * @covers \BuddyNext\Feed\FeedService::space_media_ids
 */
class SpaceMediaPageTest extends \WP_UnitTestCase {

	/**
	 * Insert a published public post with pictures into a space.
	 *
	 * @param int      $space_id Space.
	 * @param int[]    $media    Media ids.
	 * @param int      $age      Seconds old.
	 * @return void
	 */
	private function post( int $space_id, array $media, int $age ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => self::factory()->user->create(),
				'space_id'   => $space_id,
				'type'       => 'photo',
				'content'    => 'p',
				'status'     => 'published',
				'privacy'    => 'public',
				'media_ids'  => wp_json_encode( $media ),
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age ),
			)
		);
	}

	public function test_pages_walk_older_media_without_overlap_and_end_cleanly(): void {
		$space = 9301;
		$this->post( $space, array( 11, 12 ), 10 );
		$this->post( $space, array( 21 ), 20 );
		$this->post( $space, array( 31, 32, 33 ), 30 );
		$this->post( $space, array( 41 ), 40 );
		$this->post( $space, array( 51 ), 50 );

		$feed = buddynext_service( 'feed' );
		$p1   = $feed->space_media_page( $space, 0, 2 );
		$this->assertSame( array( 11, 12, 21 ), $p1['ids'], 'Newest two posts, their pictures in order.' );
		$this->assertNotNull( $p1['next_cursor'] );

		$p2 = $feed->space_media_page( $space, 0, 2, $p1['next_cursor'] );
		$this->assertSame( array( 31, 32, 33, 41 ), $p2['ids'] );
		$this->assertNotNull( $p2['next_cursor'] );

		$p3 = $feed->space_media_page( $space, 0, 2, $p2['next_cursor'] );
		$this->assertSame( array( 51 ), $p3['ids'] );
		$this->assertNull( $p3['next_cursor'], 'The last page has no next cursor.' );
	}

	public function test_an_empty_space_and_the_flat_list_wrapper(): void {
		$feed = buddynext_service( 'feed' );
		$this->assertSame( array(), $feed->space_media_page( 9302, 0 )['ids'] );
		$this->assertNull( $feed->space_media_page( 9302, 0 )['next_cursor'] );

		$this->post( 9303, array( 1, 2, 3, 4, 5 ), 5 );
		$this->assertSame( array( 1, 2, 3 ), $feed->space_media_ids( 9303, 0, 3 ), 'The flat list stays within its limit.' );
	}
}
