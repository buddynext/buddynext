<?php
/**
 * A post hidden by reports is still listed for its author on their own profile.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;

/**
 * Under-review profile tests.
 *
 * @covers \BuddyNext\Feed\FeedService::profile_feed
 */
class UnderReviewOwnProfileTest extends \WP_UnitTestCase {

	/**
	 * Only the author sees their under-review post on their profile.
	 *
	 * @return void
	 */
	public function test_only_the_author_sees_their_under_review_post(): void {
		global $wpdb;
		Installer::run();
		$posts  = new PostService();
		$feed   = new FeedService( new FollowService(), $posts );
		$author = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id     = (int) $posts->create(
			$author,
			array(
				'type'    => 'text',
				'content' => 'Reported post',
				'privacy' => 'public',
			)
		);
		$wpdb->update( $wpdb->prefix . 'bn_posts', array( 'status' => 'under_review' ), array( 'id' => $id ) );
		wp_cache_flush();

		$ids = static fn( int $viewer ): array => array_map( 'intval', array_column( $feed->profile_feed( $author, $viewer )['items'], 'id' ) );

		$this->assertContains( $id, $ids( $author ), 'The author still sees it.' );
		$this->assertNotContains( $id, $ids( $other ), 'Another member does not.' );
		$this->assertNotContains( $id, $ids( 0 ), 'A visitor does not.' );
		$this->assertNotContains( $id, $ids( $admin ), 'Nor does it sit on the public profile for staff; they review it in the queue.' );
	}
}
