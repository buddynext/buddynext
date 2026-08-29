<?php
/**
 * The profile feed must publish only published posts.
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
 * Every other feed query in FeedService has always carried status = 'published'.
 * The profile feed was the one that did not, so it returned whatever status a row
 * happened to hold: a post held by pre-moderation and a post its author had
 * DELETED were both served to another member viewing the profile.
 *
 * That made pre-moderation ornamental on this surface - the hold kept a post out
 * of the home and space feeds and left it fully readable on the author's profile.
 *
 * @covers \BuddyNext\Feed\FeedService::profile_feed
 */
class ProfileFeedStatusTest extends \WP_UnitTestCase {

	private FeedService $feed;
	private int $author;
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->feed   = new FeedService( new FollowService(), new PostService() );
		$this->author = self::factory()->user->create();
		$this->viewer = self::factory()->user->create();
	}

	/**
	 * @param string $status Post status.
	 * @return int
	 */
	private function seed_post( string $status ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->author,
				'type'       => 'text',
				'content'    => 'Post in status ' . $status,
				'status'     => $status,
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $viewer_id Viewer.
	 * @return int[]
	 */
	private function feed_ids( int $viewer_id ): array {
		$feed = $this->feed->profile_feed( $this->author, $viewer_id, null, 50 );
		return array_map( 'intval', wp_list_pluck( (array) ( $feed['items'] ?? array() ), 'id' ) );
	}

	public function test_a_post_held_for_moderation_is_not_served_to_another_member(): void {
		$held      = $this->seed_post( 'pending' );
		$published = $this->seed_post( 'published' );

		$ids = $this->feed_ids( $this->viewer );

		$this->assertNotContains( $held, $ids, 'A post held for review must not appear on the profile.' );
		$this->assertContains( $published, $ids );
	}

	public function test_a_held_post_is_hidden_from_its_own_author_too(): void {
		// Not an oversight: the author reads it on the owner-only Pending tab,
		// which is the surface built for it. The Posts tab is the public one.
		$held = $this->seed_post( 'pending' );

		$this->assertNotContains( $held, $this->feed_ids( $this->author ) );
	}

	public function test_a_deleted_post_is_not_served_to_anyone(): void {
		$deleted = $this->seed_post( 'deleted' );

		$this->assertNotContains( $deleted, $this->feed_ids( $this->viewer ) );
		$this->assertNotContains( $deleted, $this->feed_ids( $this->author ) );
	}

	public function test_draft_and_under_review_posts_stay_off_the_profile(): void {
		$draft        = $this->seed_post( 'draft' );
		$under_review = $this->seed_post( 'under_review' );

		$ids = $this->feed_ids( $this->viewer );

		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $under_review, $ids );
	}
}
