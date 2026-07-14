<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The "My spaces" rail is cached — and must not keep showing a space you left.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * A6 — the rail flyout ran two queries on EVERY hub page.
 *
 * membership_rows() and count_memberships() back the "My spaces" flyout, which renders on
 * every hub page a logged-in member loads. Two queries per page for a list that only
 * changes when the member joins or leaves something.
 *
 * The invalidation is a per-member version bump rather than a set of keyed deletes,
 * because membership_rows() takes an arbitrary limit (1-50) and is therefore cached under
 * an arbitrary number of keys. Deleting a fixed list of them would quietly miss whichever
 * limit a caller picked next, and the rail would go on advertising a space the member had
 * already left.
 *
 * @covers \BuddyNext\Spaces\SpaceMemberService::membership_rows
 * @covers \BuddyNext\Spaces\SpaceMemberService::count_memberships
 */
class RailMembershipCacheTest extends WP_UnitTestCase {

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
	 * Joining a space shows up in the rail at once.
	 *
	 * @return void
	 */
	public function test_joining_a_space_updates_the_rail(): void {
		$owner  = self::factory()->user->create();
		$member = self::factory()->user->create();

		$spaces  = new SpaceService();
		$members = buddynext_service( 'space_members' );

		$space_id = $spaces->create(
			$owner,
			array(
				'name' => 'Rail Space',
				'slug' => 'rail-space-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id );

		// Warm the rail while the member belongs to nothing.
		$this->assertSame( 0, $members->count_memberships( $member ) );
		$this->assertSame( array(), $members->membership_rows( $member, 5 ) );

		$members->join( (int) $space_id, $member );

		$this->assertSame(
			1,
			$members->count_memberships( $member ),
			'The rail count did not move after the member joined a space.'
		);
		$this->assertCount(
			1,
			$members->membership_rows( $member, 5 ),
			'The joined space is missing from the rail flyout.'
		);
	}

	/**
	 * Leaving a space takes it off the rail at once — at ANY limit.
	 *
	 * The limit is part of the cache key, so a bust that only cleared the default limit
	 * would leave every other one serving a space the member had left.
	 *
	 * @return void
	 */
	public function test_leaving_a_space_clears_the_rail_at_every_limit(): void {
		$owner  = self::factory()->user->create();
		$member = self::factory()->user->create();

		$spaces  = new SpaceService();
		$members = buddynext_service( 'space_members' );

		$space_id = (int) $spaces->create(
			$owner,
			array(
				'name' => 'Leavable Space',
				'slug' => 'leavable-space-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);

		$members->join( $space_id, $member );

		// Warm the cache at SEVERAL limits — the rail uses 5, other callers use others.
		foreach ( array( 3, 5, 10 ) as $limit ) {
			$this->assertCount( 1, $members->membership_rows( $member, $limit ) );
		}
		$this->assertSame( 1, $members->count_memberships( $member ) );

		$members->leave( $space_id, $member );

		foreach ( array( 3, 5, 10 ) as $limit ) {
			$this->assertCount(
				0,
				$members->membership_rows( $member, $limit ),
				"The rail still lists a space the member LEFT, at limit {$limit}. The bust missed this key."
			);
		}

		$this->assertSame(
			0,
			$members->count_memberships( $member ),
			'The rail count still includes a space the member left.'
		);
	}

	/**
	 * One member's rail is not served to another.
	 *
	 * @return void
	 */
	public function test_one_members_rail_is_not_served_to_another(): void {
		$owner = self::factory()->user->create();
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$spaces  = new SpaceService();
		$members = buddynext_service( 'space_members' );

		$space_id = (int) $spaces->create(
			$owner,
			array(
				'name' => 'Alices Space',
				'slug' => 'alices-space-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);

		$members->join( $space_id, $alice );

		$this->assertSame( 1, $members->count_memberships( $alice ) );

		$this->assertSame(
			0,
			$members->count_memberships( $bob ),
			"Alice's membership count was served to Bob. The rail cache is not keyed per member."
		);
	}
}
