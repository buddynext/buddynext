<?php
/**
 * A Space hidden from a member (buddynext_feed_hidden_space_ids) is hidden on every feed.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\SocialGraph\FollowService;

/**
 * Pro hides a plan-gated Space from a joined member whose plan lapsed. The filter was
 * read only by the home feed, so the same post stayed readable on the author's profile
 * feed and on the hashtag feed. Every feed now reads membership through
 * SpaceVisibility::member_spaces_sql(), which applies the filter.
 *
 * @covers \BuddyNext\Spaces\SpaceVisibility::member_spaces_sql
 */
class HiddenSpaceAllFeedsTest extends \WP_UnitTestCase {

	private FeedService $feed;
	private int $author;
	private int $member;
	private int $space;
	private int $post;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$this->feed   = new FeedService( new FollowService(), new PostService() );
		$this->author = self::factory()->user->create();
		$this->member = self::factory()->user->create();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $wpdb->prefix . 'bn_spaces', array( 'name' => 'Gated', 'slug' => 'gated-' . wp_rand(), 'type' => 'private', 'owner_id' => $this->author ) );
		$this->space = (int) $wpdb->insert_id;
		foreach ( array( $this->author, $this->member ) as $uid ) {
			$wpdb->insert( $wpdb->prefix . 'bn_space_members', array( 'space_id' => $this->space, 'user_id' => $uid, 'role' => 'member', 'status' => 'active' ) );
		}
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->author,
				'space_id'   => $this->space,
				'type'       => 'text',
				'content'    => 'Gated post #lapsecheck',
				'status'     => 'published',
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$this->post = (int) $wpdb->insert_id;
		( new HashtagService() )->sync( 'post', $this->post, array( 'lapsecheck' ) );
	}

	/**
	 * Post ids the member sees on each feed route's service call.
	 *
	 * @return array<string, int[]>
	 */
	private function seen(): array {
		$ids = static fn( array $feed ): array => array_map( 'intval', wp_list_pluck( (array) ( $feed['items'] ?? array() ), 'id' ) );
		wp_set_current_user( $this->member );
		return array(
			'home'    => $ids( $this->feed->home_feed( $this->member, null, 50, 'for-you' ) ),
			'spaces'  => $ids( $this->feed->home_feed( $this->member, null, 50, 'spaces' ) ),
			'profile' => $ids( $this->feed->profile_feed( $this->author, $this->member, null, 50 ) ),
			'hashtag' => $ids( ( new HashtagService() )->get_feed( 'lapsecheck', array( 'viewer_id' => $this->member ) ) ),
		);
	}

	public function test_member_sees_the_post_on_every_feed_until_the_space_is_hidden(): void {
		foreach ( $this->seen() as $route => $ids ) {
			$this->assertContains( $this->post, $ids, "Entitled member must see the post on the {$route} feed." );
		}

		$space = $this->space;
		$hide  = static fn( array $hidden ): array => array_merge( $hidden, array( $space ) );
		add_filter( 'buddynext_feed_hidden_space_ids', $hide );
		$this->reset_memo();

		foreach ( $this->seen() as $route => $ids ) {
			$this->assertNotContains( $this->post, $ids, "Lapsed member must not see the post on the {$route} feed." );
		}
		remove_filter( 'buddynext_feed_hidden_space_ids', $hide );
		$this->reset_memo();
	}

	/**
	 * Clear the per-request memo of hidden Spaces so the filter change is read.
	 *
	 * @return void
	 */
	private function reset_memo(): void {
		if ( property_exists( \BuddyNext\Spaces\SpaceVisibility::class, 'hidden_memo' ) ) {
			( new \ReflectionProperty( \BuddyNext\Spaces\SpaceVisibility::class, 'hidden_memo' ) )->setValue( null, array() );
		}
	}
}
