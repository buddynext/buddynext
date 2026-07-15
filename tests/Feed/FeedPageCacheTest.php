<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A9 — the space + profile feeds cache page 1, and MUST NOT leak across viewers.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedListener;
use BuddyNext\Feed\PostService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * The riskiest cache in the plan: the feed applies blocks, mutes, privacy and moderation.
 *
 * A space or profile feed is not the same for two viewers -- it drops the posts of members
 * they have blocked or muted, and a profile feed is gated by the owner's privacy. So a
 * cache key that does not carry the viewer would serve one member the posts of someone
 * they blocked, which is the one thing a block is for. That is disclosure, not staleness,
 * and it is why these tests spend almost all their effort on the leak, not the speed-up.
 *
 * Only page 1 is cached (cursor null); deeper pages are keyset and each cursor is unique.
 *
 * @covers \BuddyNext\Feed\FeedService::space_feed
 * @covers \BuddyNext\Feed\FeedService::profile_feed
 * @covers \BuddyNext\Feed\FeedCache
 */
class FeedPageCacheTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache, listener wired.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		( new FeedListener( buddynext_service( 'feed_cache' ) ) )->register();
	}

	/**
	 * Post ids in a feed payload.
	 *
	 * @param array<string, mixed> $feed Feed payload.
	 * @return array<int, int>
	 */
	private function ids( array $feed ): array {
		return array_map(
			static fn( $p ): int => (int) ( is_array( $p ) ? ( $p['id'] ?? 0 ) : 0 ),
			(array) ( $feed['items'] ?? array() )
		);
	}

	/**
	 * Make a public space with the given owner.
	 *
	 * @param int $owner Owner.
	 * @return int
	 */
	private function make_space( int $owner ): int {
		$id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Feed Cache Space',
				'slug' => 'feed-cache-space-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * THE ONE THAT MATTERS: a blocked member's post does not leak into another viewer's
	 * cached space feed.
	 *
	 * @return void
	 */
	public function test_a_block_does_not_leak_across_viewers_in_a_space_feed(): void {
		$owner    = self::factory()->user->create();
		$nuisance = self::factory()->user->create();
		$innocent = self::factory()->user->create();

		$feed     = buddynext_service( 'feed' );
		$space_id = $this->make_space( $owner );

		$members = buddynext_service( 'space_members' );
		$members->join( $space_id, $nuisance );
		$members->join( $space_id, $innocent );

		$post_id = (int) ( new PostService() )->create( $nuisance, array( 'content' => 'from the nuisance', 'space_id' => $space_id ) );

		// The BLOCKER warms the cache first, having blocked the nuisance.
		buddynext_service( 'blocks' )->block( $owner, $nuisance );
		$this->assertNotContains(
			$post_id,
			$this->ids( $feed->space_feed( $space_id, $owner ) ),
			'The blocker should not see the blocked member post at all.'
		);

		// The INNOCENT viewer, who blocked nobody, reads the same space feed. If the two
		// shared a cache entry, the blocker's filtered result would be served here.
		$this->assertContains(
			$post_id,
			$this->ids( $feed->space_feed( $space_id, $innocent ) ),
			"The blocker's filtered space feed was served to a viewer who blocked nobody. The cache key does not carry the viewer -- one member is deciding what another is allowed to see."
		);
	}

	/**
	 * A new post in a space shows up for a viewer whose feed was already cached.
	 *
	 * @return void
	 */
	public function test_a_new_space_post_appears_immediately(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$feed     = buddynext_service( 'feed' );
		$space_id = $this->make_space( $owner );
		buddynext_service( 'space_members' )->join( $space_id, $viewer );

		// Warm the viewer's space feed while it is empty.
		$this->assertSame( array(), $this->ids( $feed->space_feed( $space_id, $viewer ) ) );

		$post_id = (int) ( new PostService() )->create( $owner, array( 'content' => 'brand new', 'space_id' => $space_id ) );

		$this->assertContains(
			$post_id,
			$this->ids( $feed->space_feed( $space_id, $viewer ) ),
			'A new post in the space did not appear. buddynext_space_posts_changed must bust the space feed cache.'
		);
	}

	/**
	 * Deleting a space post removes it from a cached feed at once.
	 *
	 * @return void
	 */
	public function test_a_deleted_space_post_disappears_immediately(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$feed     = buddynext_service( 'feed' );
		$space_id = $this->make_space( $owner );
		buddynext_service( 'space_members' )->join( $space_id, $viewer );

		$posts   = new PostService();
		$post_id = (int) $posts->create( $owner, array( 'content' => 'to be removed', 'space_id' => $space_id ) );

		$this->assertContains( $post_id, $this->ids( $feed->space_feed( $space_id, $viewer ) ) );

		$posts->delete( $post_id, $owner );

		$this->assertNotContains(
			$post_id,
			$this->ids( $feed->space_feed( $space_id, $viewer ) ),
			'A DELETED space post is still in the cached feed. The delete path fires buddynext_space_posts_changed with the space id read before the row is gone - it must bust.'
		);
	}

	/**
	 * The profile owner's new post appears on their profile for a viewer immediately.
	 *
	 * @return void
	 */
	public function test_a_profile_owners_new_post_appears_immediately(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$feed = buddynext_service( 'feed' );

		// Warm the viewer's view of the owner's profile while empty.
		$this->assertSame( array(), $this->ids( $feed->profile_feed( $owner, $viewer ) ) );

		$post_id = (int) ( new PostService() )->create( $owner, array( 'content' => 'on my profile' ) );

		$this->assertContains(
			$post_id,
			$this->ids( $feed->profile_feed( $owner, $viewer ) ),
			"The owner's new post did not appear on their profile feed. invalidate_writer bumps the profile version, which the key uses."
		);
	}

	/**
	 * A privacy flip takes effect immediately, even with a warm cache.
	 *
	 * The gate runs on every request, outside the cache, so the moment the owner goes
	 * private the next request is denied and never serves the cached feed.
	 *
	 * @return void
	 */
	public function test_going_private_denies_a_previously_allowed_viewer_at_once(): void {
		$owner  = self::factory()->user->create();
		$viewer = self::factory()->user->create();

		$feed = buddynext_service( 'feed' );
		( new PostService() )->create( $owner, array( 'content' => 'public for now' ) );

		// Public: the viewer sees the post, and the feed is cached.
		$this->assertNotEmpty( $this->ids( $feed->profile_feed( $owner, $viewer ) ), 'Precondition: a public profile feed should have the post.' );

		// Owner goes private (approved-followers-only, and the viewer is not a follower).
		update_user_meta( $owner, 'bn_account_private', 1 );

		$result = $feed->profile_feed( $owner, $viewer );

		$this->assertTrue(
			! empty( $result['private'] ),
			'A viewer who could see the profile a moment ago is still being served the cached feed after the owner went private. The privacy gate must run before the cache, on every request.'
		);
		$this->assertSame( array(), $this->ids( $result ), 'A now-private profile still returned posts from cache.' );
	}
}
