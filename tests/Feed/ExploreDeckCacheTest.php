<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The Explore deck is cached — and must never show you somebody you blocked.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\ExploreListener;
use BuddyNext\Feed\ExploreService;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * A1 — the Explore deck was uncached on the busiest discovery surface on the site.
 *
 * The deck() method takes a filter, a cursor and a page size. Nothing in that signature says
 * "viewer" — but the deck IS viewer-dependent: it drops users the viewer has blocked (in
 * either direction) and garnishes the "all" deck with spaces from the viewer's own
 * interests. A cache key without the viewer in it would show a member the content of
 * somebody they had blocked, which is the one thing a block exists to prevent.
 *
 * And a block cannot wait out a TTL. Blocking someone and still being shown their posts
 * for the next five minutes is not a stale cache, it is the safety control failing. So
 * the deck is busted on a block rather than left to expire.
 *
 * @covers \BuddyNext\Feed\ExploreService::deck
 * @covers \BuddyNext\Feed\ExploreListener
 */
class ExploreDeckCacheTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache, listener wired.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		( new ExploreListener() )->register();
	}

	/**
	 * Post ids present in a deck result.
	 *
	 * @param array<string, mixed> $deck Deck payload.
	 * @return array<int, int>
	 */
	private function post_ids( array $deck ): array {
		$ids = array();

		foreach ( (array) ( $deck['items'] ?? array() ) as $card ) {
			$post = is_array( $card ) ? ( $card['post'] ?? null ) : null;

			if ( is_array( $post ) && ! empty( $post['id'] ) ) {
				$ids[] = (int) $post['id'];
			}
		}

		return $ids;
	}

	/**
	 * A member's own new post shows on Explore at once, not after the TTL.
	 *
	 * @return void
	 */
	public function test_a_new_post_appears_on_the_deck_immediately(): void {
		$author = self::factory()->user->create();
		wp_set_current_user( $author );

		$explore = new ExploreService();

		// Warm the deck without the post.
		$explore->deck( 'posts' );

		$post_id = ( new PostService() )->create( $author, array( 'content' => 'Fresh explore post' ) );
		$this->assertIsInt( $post_id );

		$this->assertContains(
			(int) $post_id,
			$this->post_ids( $explore->deck( 'posts' ) ),
			'A brand-new post is missing from the Explore deck. Posting and not seeing it reads as the post having been lost, and the member posts it again.'
		);
	}

	/**
	 * THE ONE THAT MATTERS: blocking somebody takes their posts off your deck at once.
	 *
	 * @return void
	 */
	public function test_blocking_someone_removes_their_posts_from_the_deck_immediately(): void {
		$viewer   = self::factory()->user->create();
		$nuisance = self::factory()->user->create();

		$post_id = ( new PostService() )->create( $nuisance, array( 'content' => 'Content you do not want' ) );
		$this->assertIsInt( $post_id );

		wp_set_current_user( $viewer );
		$explore = new ExploreService();

		// Warm the viewer's deck WITH the nuisance's post in it.
		$this->assertContains(
			(int) $post_id,
			$this->post_ids( $explore->deck( 'posts' ) ),
			'Precondition: the post should be on the deck before the block.'
		);

		buddynext_service( 'blocks' )->block( $viewer, $nuisance );

		$this->assertNotContains(
			(int) $post_id,
			$this->post_ids( $explore->deck( 'posts' ) ),
			'A BLOCKED member\'s post is still on the deck. Blocking someone and still being shown their content is the block failing at the only job it has - it cannot wait out a cache TTL.'
		);
	}

	/**
	 * The "people to discover" aside rotates its picks instead of showing the
	 * same static faces on every load.
	 *
	 * The pool (the newest limit*4 members) is cached, but the display slice is
	 * sampled OUTSIDE the cache each call, so within one TTL the viewer still sees
	 * variety. The bug was a deterministic top-N that never changed.
	 *
	 * @covers \BuddyNext\Feed\ExploreService::suggested_member_ids
	 * @return void
	 */
	public function test_discover_aside_rotates_within_the_newest_pool(): void {
		// More members than the sampling pool (limit*4 = 12) so it can rotate.
		for ( $i = 0; $i < 20; $i++ ) {
			self::factory()->user->create();
		}

		$explore = new ExploreService();
		$limit   = 3;

		$seen = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$picks = $explore->suggested_member_ids( $limit );
			$this->assertCount( $limit, $picks, 'the aside shows exactly the display limit' );
			foreach ( $picks as $id ) {
				$seen[ (int) $id ] = true;
			}
		}

		// Rotation: across many loads the aside surfaces MORE than one static set of
		// `limit` faces (the bug showed identical picks every load).
		$this->assertGreaterThan(
			$limit,
			count( $seen ),
			'The discover aside showed the same members on every load — no rotation within the pool.'
		);

		// Quality: every surfaced member stays within the newest pool (limit*4).
		$this->assertLessThanOrEqual( $limit * 4, count( $seen ) );
	}

	/**
	 * One viewer's deck is not served to another viewer.
	 *
	 * The blocks that shape a deck belong to the person looking at it. If two viewers
	 * shared a cache entry, the first one to load Explore would decide what the second
	 * one is allowed to see.
	 *
	 * @return void
	 */
	public function test_one_viewers_deck_is_not_served_to_another(): void {
		$alice    = self::factory()->user->create();
		$bob      = self::factory()->user->create();
		$nuisance = self::factory()->user->create();

		$post_id = ( new PostService() )->create( $nuisance, array( 'content' => 'Divisive post' ) );
		$this->assertIsInt( $post_id );

		// Alice blocks the author, then loads Explore — her deck must not contain it.
		buddynext_service( 'blocks' )->block( $alice, $nuisance );
		wp_set_current_user( $alice );
		$this->assertNotContains( (int) $post_id, $this->post_ids( ( new ExploreService() )->deck( 'posts' ) ) );

		// Bob has blocked nobody. He must still see it — Alice's deck is not his deck.
		wp_set_current_user( $bob );

		$this->assertContains(
			(int) $post_id,
			$this->post_ids( ( new ExploreService() )->deck( 'posts' ) ),
			"Alice's deck was served to Bob. The cache key does not carry the viewer, so the first member to load Explore decides what everybody else is allowed to see."
		);
	}
}
