<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Bucket [A] remainder — interests, reshares, sub-spaces, pinned posts.
 *
 * @package BuddyNext\Tests\Cache
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Cache;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * A7 / A8 / A9 — four more reads that ran on every paint and cached nothing.
 *
 * The invalidation is the whole test surface here, because each of these caches has a
 * writer that is easy to miss:
 *
 *   A7  A member's INTERESTS are profile field values. Onboarding is not their only
 *       writer — ProfileService writes the same rows whenever a member edits their
 *       profile — so busting only in the onboarding save would leave a member who
 *       changed their interests on the profile screen with the OLD ones still choosing
 *       what their feed shows them.
 *
 *   A9  A pinned post that stays pinned after being UNPINNED is content an owner has
 *       explicitly tried to take off the top of their space and failed to.
 *
 *   A9  Sub-spaces are visibility-scoped exactly like the directory: a secret child is
 *       dropped unless the viewer owns it, belongs to it, or is an admin. Same key rule,
 *       same leak if it is got wrong.
 *
 * @covers \BuddyNext\Onboarding\OnboardingService::get_interest_ids
 * @covers \BuddyNext\Feed\FeedService::space_pinned_posts
 * @covers \BuddyNext\Spaces\SpaceService::get_subspaces
 */
class BucketARemainderTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * Make a space.
	 *
	 * @param int    $owner Owner.
	 * @param string $name  Name.
	 * @param string $type  Space type.
	 * @param int    $parent Parent space (0 = root).
	 * @return int
	 */
	private function make_space( int $owner, string $name, string $type = 'open', int $parent = 0 ): int {
		$data = array(
			'name' => $name,
			'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 9999 ),
			'type' => $type,
		);

		if ( $parent > 0 ) {
			$data['parent_id'] = $parent;
		}

		$id = ( new SpaceService() )->create( $owner, $data );

		$this->assertIsInt( $id, 'Could not create space: ' . ( is_wp_error( $id ) ? $id->get_error_message() : '' ) );

		return (int) $id;
	}

	/**
	 * A9 — unpinning a post takes it off the space's pinned strip at once.
	 *
	 * @return void
	 */
	public function test_unpinning_a_post_clears_the_pinned_strip(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );

		$space_id = $this->make_space( $owner, 'Pin Space' );

		$posts   = new PostService();
		$post_id = $posts->create( $owner, array( 'content' => 'Pin me', 'space_id' => $space_id ) );
		$this->assertIsInt( $post_id );

		$posts->pin( (int) $post_id, $owner );

		$feed = buddynext_service( 'feed' );

		// Warm the strip WITH the pinned post.
		$this->assertCount( 1, $feed->space_pinned_posts( $space_id ), 'Precondition: the post should be pinned.' );

		$posts->unpin( (int) $post_id, $owner );

		$this->assertCount(
			0,
			$feed->space_pinned_posts( $space_id ),
			'An UNPINNED post is still on the space pinned strip. The owner explicitly took it off the top of their space and the cache put it back.'
		);
	}

	/**
	 * A9 — PINNING a post puts it on the strip immediately.
	 *
	 * This is the direction that genuinely needs the bust, and it is worth being precise
	 * about why, because the unpin test above does NOT prove it: only the ids are cached,
	 * and each is re-read through the per-post cache, so an UNPINNED post filters itself
	 * out of a stale list on its own. A NEWLY PINNED one cannot — its id is simply not in
	 * the list, and nothing will ever look at it. Removing the bust breaks this test and
	 * only this test.
	 *
	 * @return void
	 */
	public function test_pinning_a_post_puts_it_on_the_strip_immediately(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );

		$space_id = $this->make_space( $owner, 'Fresh Pin Space' );

		$posts   = new PostService();
		$post_id = (int) $posts->create( $owner, array( 'content' => 'Not pinned yet', 'space_id' => $space_id ) );

		$feed = buddynext_service( 'feed' );

		// Warm the strip while it is EMPTY. This is the state a stale cache would keep.
		$this->assertCount( 0, $feed->space_pinned_posts( $space_id ) );

		$posts->pin( $post_id, $owner );

		$this->assertCount(
			1,
			$feed->space_pinned_posts( $space_id ),
			'A newly PINNED post never reached the strip. A stale id list cannot heal from this one - the id is simply not in it, so nothing re-reads the post and discovers it is now pinned. Pinning something and not seeing it pinned is the entire feature failing.'
		);
	}

	/**
	 * A9 — a deleted pinned post drops off the strip even without a pin-specific bust.
	 *
	 * Only ids are cached; the row is re-read through the per-post cache, which every post
	 * write busts. That is what makes the strip self-healing.
	 *
	 * @return void
	 */
	public function test_a_deleted_pinned_post_drops_off_the_strip(): void {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );

		$space_id = $this->make_space( $owner, 'Delete Pin Space' );

		$posts   = new PostService();
		$post_id = (int) $posts->create( $owner, array( 'content' => 'Doomed pin', 'space_id' => $space_id ) );
		$posts->pin( $post_id, $owner );

		$feed = buddynext_service( 'feed' );
		$this->assertCount( 1, $feed->space_pinned_posts( $space_id ) );

		$posts->delete( $post_id, $owner );

		$this->assertCount(
			0,
			$feed->space_pinned_posts( $space_id ),
			'A DELETED post is still on the pinned strip. The cached id list must be re-validated against the post itself, not trusted.'
		);
	}

	/**
	 * A9 — a secret sub-space cached for its owner is not served to a stranger.
	 *
	 * @return void
	 */
	public function test_a_secret_subspace_is_not_served_to_a_stranger(): void {
		$owner    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$stranger = self::factory()->user->create();

		$parent = $this->make_space( $owner, 'Parent Space' );
		$this->make_space( $owner, 'Secret Child', 'secret', $parent );

		$spaces = new SpaceService();

		// Owner warms the cache with a list that CONTAINS the secret child.
		$owner_sees = $spaces->get_subspaces( $parent, 24, 0, $owner, false );
		$this->assertNotEmpty( $owner_sees, 'The owner cannot see their own secret sub-space.' );

		$stranger_sees = $spaces->get_subspaces( $parent, 24, 0, $stranger, false );

		$this->assertCount(
			0,
			$stranger_sees,
			'A SECRET SUB-SPACE LEAKED. The list was cached for the owner and served to a stranger — the sub-space rail is visibility-scoped exactly like the directory.'
		);
	}

	/**
	 * A8 — resharing shows up on the member's reshare tab at once, at any page size.
	 *
	 * @return void
	 */
	public function test_a_reshare_appears_on_the_members_tab_immediately(): void {
		$author  = self::factory()->user->create();
		$sharer  = self::factory()->user->create();

		$post_id = (int) ( new PostService() )->create( $author, array( 'content' => 'Shareable' ) );

		$shares = new ShareService();

		// Warm the tab while the member has reshared nothing — at two page sizes, because
		// the list is cached per page size.
		$this->assertSame( 0, (int) $shares->user_shares_paginated( $sharer, 20, 1 )['total'] );
		$this->assertSame( 0, (int) $shares->user_shares_paginated( $sharer, 5, 1 )['total'] );

		$shares->share( $sharer, $post_id, 'Worth reading' );

		foreach ( array( 20, 5 ) as $per_page ) {
			$this->assertSame(
				1,
				(int) $shares->user_shares_paginated( $sharer, $per_page, 1 )['total'],
				"A new reshare is missing from the member's tab at page size {$per_page}. The list is cached per page size, so the bust must clear all of them."
			);
		}
	}
}
