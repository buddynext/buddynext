<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
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

	public function test_flush_home_cache_invalidates_user_page1(): void {
		$cache = new \BuddyNext\Feed\FeedCache();
		$feed  = new FeedService( $this->follows, $this->posts, $cache );

		$before = $cache->home_page_1_key( $this->alice, 20 );
		$feed->flush_home_cache( $this->alice );
		$after = $cache->home_page_1_key( $this->alice, 20 );

		$this->assertNotSame( $before, $after, 'Dismiss must bump the user page-1 version so the stale cache is bypassed' );
	}

	public function test_flush_all_home_caches_invalidates_everyone(): void {
		$cache = new \BuddyNext\Feed\FeedCache();
		$feed  = new FeedService( $this->follows, $this->posts, $cache );

		$alice_before = $cache->home_page_1_key( $this->alice, 20 );
		$bob_before   = $cache->home_page_1_key( $this->bob, 20 );
		$feed->flush_all_home_caches();

		$this->assertNotSame( $alice_before, $cache->home_page_1_key( $this->alice, 20 ) );
		$this->assertNotSame( $bob_before, $cache->home_page_1_key( $this->bob, 20 ) );
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

		// Excluding unfollowed authors is the contract of the "following" filter;
		// the default For-You feed deliberately blends in public discovery posts.
		$result = $this->feed->home_feed( $this->alice, null, 20, 'following' );

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

	/**
	 * Posts by a suspended member are excluded from the home feed.
	 */
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

		// hide_posts => 1 makes the suspension hide existing content (a plain
		// suspension is an action-restriction that leaves posts visible). Then view
		// as a non-admin: moderators bypass the exclusion so they can review.
		wp_set_current_user( $this->admin_id );
		$this->moderation->suspend_user( $this->bob, $this->admin_id, 'test', array( 'hide_posts' => 1 ) );

		wp_set_current_user( $this->alice );
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

		// hide_posts => 1 hides the suspended author's existing content; view as a
		// non-admin (carol) so the moderator-visibility bypass doesn't apply.
		wp_set_current_user( $this->admin_id );
		$this->moderation->suspend_user( $this->bob, $this->admin_id, 'test', array( 'hide_posts' => 1 ) );

		wp_set_current_user( $this->carol );
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

	/**
	 * The Following filter returns only posts from followed authors.
	 */
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
		// Bob is the space owner — add the active owner membership row too, so
		// SpacePostGuard (who-can-post) lets him post in his own space instead of
		// returning a 'forbidden' WP_Error.
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space_id,
				'user_id'   => $this->bob,
				'role'      => 'owner',
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

	/**
	 * For-you ranks posts from open spaces matching the viewer's picked
	 * interests above the general public stream (tier-then-recency), while a
	 * viewer with no picks keeps the plain chronological order — the additive
	 * interest signal (plan section 4.3).
	 */
	public function test_for_you_ranks_interest_space_posts_first(): void {
		global $wpdb;

		// Open space in a fresh category; bob posts there FIRST (older).
		$cat_id = ( new \BuddyNext\Spaces\SpaceCategoryService() )->create( array( 'name' => 'Tier Test Cat' ) );
		$this->assertNotWPError( $cat_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'        => 'Tier Test Space',
				'slug'        => 'tier-test-space',
				'type'        => 'open',
				'category_id' => (int) $cat_id,
				'owner_id'    => $this->bob,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$space_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space_id,
				'user_id'   => $this->bob,
				'role'      => 'owner',
				'status'    => 'active',
				'joined_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$space_post = $this->posts->create(
			$this->bob,
			array(
				'type'     => 'text',
				'content'  => 'Interest space post',
				'privacy'  => 'public',
				'space_id' => $space_id,
			)
		);
		$plain_post = $this->posts->create(
			$this->carol,
			array(
				'type'    => 'text',
				'content' => 'Newer plain public post',
				'privacy' => 'public',
			)
		);

		// No picks: chronological — the newer plain post outranks the space post.
		$before = array_column( $this->feed->home_feed( $this->alice )['items'], 'id' );
		$this->assertLessThan(
			(int) array_search( $space_post, $before, true ),
			(int) array_search( $plain_post, $before, true ),
			'Without interests the newer plain post must rank first (chronological).'
		);

		// Alice picks the category: the interest-space post moves above it.
		( new \BuddyNext\Onboarding\OnboardingService() )->save_interest_ids( $this->alice, array( (int) $cat_id ) );
		( new \BuddyNext\Feed\FeedCache() )->invalidate_all_users();

		$after = array_column( $this->feed->home_feed( $this->alice )['items'], 'id' );
		$this->assertLessThan(
			(int) array_search( $plain_post, $after, true ),
			(int) array_search( $space_post, $after, true ),
			'With the category picked the interest-space post must rank first.'
		);
	}
}
