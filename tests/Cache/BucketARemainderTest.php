<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Bucket [A] remainder — interests, reshares, sub-spaces.
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
 *   A9  Sub-spaces are visibility-scoped exactly like the directory: a secret child is
 *       dropped unless the viewer owns it, belongs to it, or is an admin. Same key rule,
 *       same leak if it is got wrong.
 *
 * @covers \BuddyNext\Onboarding\OnboardingService::get_interest_ids
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
