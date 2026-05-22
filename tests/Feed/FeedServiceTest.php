<?php
/**
 * Tests for FeedService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\SocialGraph\FollowService;

/**
 * @covers \BuddyNext\Feed\FeedService
 */
class FeedServiceTest extends \WP_UnitTestCase {

	private FeedService $feed;
	private PostService $posts;
	private FollowService $follows;
	private ModerationService $moderation;
	private int $alice;
	private int $bob;
	private int $carol;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->follows    = new FollowService();
		$this->posts      = new PostService();
		$this->feed       = new FeedService( $this->follows, $this->posts );
		$this->moderation = new ModerationService();
		$this->alice      = self::factory()->user->create();
		$this->bob        = self::factory()->user->create();
		$this->carol      = self::factory()->user->create();
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_home_feed_shows_followed_user_posts(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$post_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob post',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice );

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_shows_own_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice own post',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice );

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_excludes_unfollowed_users(): void {
		$post_id = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Carol post',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice );

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_excludes_private_posts_from_others(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$post_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Private post',
				'privacy' => 'private',
			)
		);

		$result = $this->feed->home_feed( $this->alice );

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_includes_own_private_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'My private',
				'privacy' => 'private',
			)
		);

		$result = $this->feed->home_feed( $this->alice );

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_cursor_pagination(): void {
		$this->follows->follow( $this->alice, $this->bob );

		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->posts->create(
				$this->bob,
				array(
					'type'    => 'text',
					'content' => "Post {$i}",
					'privacy' => 'public',
				)
			);
		}

		$page1 = $this->feed->home_feed( $this->alice, null, 3 );
		$this->assertCount( 3, $page1['items'] );
		$this->assertNotNull( $page1['next_cursor'] );

		$page2 = $this->feed->home_feed( $this->alice, $page1['next_cursor'], 3 );
		$this->assertCount( 2, $page2['items'] );
		$this->assertNull( $page2['next_cursor'] );

		$all_ids = array_merge(
			array_column( $page1['items'], 'id' ),
			array_column( $page2['items'], 'id' )
		);
		$this->assertCount( 5, array_unique( $all_ids ) );
	}

	public function test_explore_feed_returns_public_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Public post',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->explore_feed();

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_explore_feed_excludes_non_public(): void {
		$private_id   = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Private',
				'privacy' => 'private',
			)
		);
		$followers_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Followers only',
				'privacy' => 'followers',
			)
		);

		$result = $this->feed->explore_feed();
		$ids    = array_column( $result['items'], 'id' );

		$this->assertNotContains( $private_id, $ids );
		$this->assertNotContains( $followers_id, $ids );
	}

	public function test_profile_feed_returns_user_public_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice public',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->profile_feed( $this->alice, 0 );

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_profile_feed_owner_sees_own_private_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice private',
				'privacy' => 'private',
			)
		);

		$result = $this->feed->profile_feed( $this->alice, $this->alice );

		$this->assertContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_profile_feed_visitor_cannot_see_private_posts(): void {
		$post_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice private',
				'privacy' => 'private',
			)
		);

		$result = $this->feed->profile_feed( $this->alice, $this->bob );

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_feed_returns_next_cursor_null_when_no_more(): void {
		$this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Solo post',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->explore_feed( null, 20 );

		$this->assertNull( $result['next_cursor'] );
	}

	// ── Suspension + shadow-ban filtering ──────────────────────────────────

	public function test_home_feed_excludes_suspended_user_posts(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$post_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob suspended post',
				'privacy' => 'public',
			)
		);

		wp_set_current_user( $this->admin_id );
		$this->moderation->suspend_user( $this->bob, $this->admin_id, 'test', array() );

		$result = $this->feed->home_feed( $this->alice );

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_explore_feed_excludes_suspended_user_posts(): void {
		$post_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob public post',
				'privacy' => 'public',
			)
		);

		wp_set_current_user( $this->admin_id );
		$this->moderation->suspend_user( $this->bob, $this->admin_id, 'test', array() );

		$result = $this->feed->explore_feed();

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_home_feed_excludes_shadow_banned_user_posts(): void {
		$this->follows->follow( $this->alice, $this->carol );
		$post_id = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Carol shadow post',
				'privacy' => 'public',
			)
		);

		// Shadow-ban carol via usermeta.
		update_user_meta( $this->carol, 'bn_shadow_banned', '1' );

		$result = $this->feed->home_feed( $this->alice );

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	public function test_explore_feed_excludes_shadow_banned_user_posts(): void {
		$post_id = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Carol public shadow post',
				'privacy' => 'public',
			)
		);

		update_user_meta( $this->carol, 'bn_shadow_banned', '1' );

		$result = $this->feed->explore_feed();

		$this->assertNotContains( $post_id, array_column( $result['items'], 'id' ) );
	}

	// ── Home-feed filter tabs (F2) ─────────────────────────────────────────

	public function test_home_feed_following_filter_only_returns_followed_authors(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$bob_id   = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob followed',
				'privacy' => 'public',
			)
		);
		$carol_id = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Carol not-followed',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'following' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertContains( $bob_id, $ids );
		$this->assertNotContains( $carol_id, $ids );
	}

	public function test_home_feed_following_filter_excludes_own_posts(): void {
		// For-you includes own; following must not.
		$own_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice own',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'following' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertNotContains( $own_id, $ids );
	}

	public function test_home_feed_spaces_filter_returns_only_joined_space_posts(): void {
		global $wpdb;
		// Create a space and join Alice to it.
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'         => 'Test Space',
				'slug'         => 'test-space',
				'type'         => 'open',
				'owner_id'     => $this->bob,
				'member_count' => 1,
				'created_at'   => current_time( 'mysql', 1 ),
			)
		);
		$space_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space_id,
				'user_id'   => $this->alice,
				'role'      => 'member',
				'status'    => 'active',
				'joined_at' => current_time( 'mysql', 1 ),
			)
		);
		$in_id  = $this->posts->create(
			$this->bob,
			array(
				'type'     => 'text',
				'content'  => 'Bob in space',
				'privacy'  => 'public',
				'space_id' => $space_id,
			)
		);
		$out_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob outside',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'spaces' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertContains( $in_id, $ids );
		$this->assertNotContains( $out_id, $ids );
	}

	public function test_home_feed_network_filter_returns_only_connected_users(): void {
		$connections = new \BuddyNext\SocialGraph\ConnectionService();
		$connections->send_request( $this->alice, $this->bob );
		$connections->accept_request( $this->bob, $this->alice );

		$bob_id   = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob connection',
				'privacy' => 'public',
			)
		);
		$carol_id = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Carol stranger',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'network' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertContains( $bob_id, $ids );
		$this->assertNotContains( $carol_id, $ids );
	}

	public function test_home_feed_for_you_filter_blends_all_sources(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$own_id      = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Alice own',
				'privacy' => 'public',
			)
		);
		$followed_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob followed',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'for-you' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertContains( $own_id, $ids );
		$this->assertContains( $followed_id, $ids );
	}

	public function test_home_feed_unknown_filter_falls_back_to_for_you(): void {
		$this->follows->follow( $this->alice, $this->bob );
		$followed_id = $this->posts->create(
			$this->bob,
			array(
				'type'    => 'text',
				'content' => 'Bob followed',
				'privacy' => 'public',
			)
		);

		$result = $this->feed->home_feed( $this->alice, null, 20, 'garbage-filter' );
		$ids    = array_column( $result['items'], 'id' );

		$this->assertContains( $followed_id, $ids );
	}

	public function test_home_feed_counts_returns_expected_shape(): void {
		$counts = $this->feed->home_feed_counts( $this->alice );

		$this->assertIsArray( $counts );
		$this->assertArrayHasKey( 'for_you', $counts );
		$this->assertArrayHasKey( 'following', $counts );
		$this->assertArrayHasKey( 'spaces', $counts );
		$this->assertArrayHasKey( 'network', $counts );
		foreach ( $counts as $value ) {
			$this->assertIsInt( $value );
		}
	}

	public function test_home_feed_counts_zero_for_guest(): void {
		$counts = $this->feed->home_feed_counts( 0 );

		$this->assertSame( 0, $counts['for_you'] );
		$this->assertSame( 0, $counts['following'] );
		$this->assertSame( 0, $counts['spaces'] );
		$this->assertSame( 0, $counts['network'] );
	}
}
