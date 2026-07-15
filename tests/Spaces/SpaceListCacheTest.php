<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The spaces directory is cached — and must not leak a secret space to anyone.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * A2 — the spaces directory ran 5-9 queries per render and cached nothing.
 *
 * The danger in fixing it is not staleness, it is DISCLOSURE. The directory is
 * visibility-scoped: which secret ("unlisted") spaces a viewer may see depends on who
 * they are and whether they are an admin, and that logic ends up inside the WHERE clause.
 * A cache key that misses any input serves one member the rows built for another — and
 * the rows they would be served are precisely the ones they were not allowed to see.
 *
 * So the key is derived from the RESOLVED QUERY SCOPE rather than from a hand-listed set
 * of args: the key IS the query, and two callers share an entry only when they would run
 * the same SQL with the same bound values. A hand-built key is one somebody forgets to
 * extend when a new filter lands, and the failure mode is a leak, not a stale number.
 *
 * @covers \BuddyNext\Spaces\SpaceService::list_spaces
 * @covers \BuddyNext\Spaces\SpaceService::list_spaces_with_total
 */
class SpaceListCacheTest extends WP_UnitTestCase {

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
	 * Make a space and return its id.
	 *
	 * @param int    $owner   Owner user id.
	 * @param string $name    Space name.
	 * @param string $privacy Space type/privacy.
	 * @return int
	 */
	private function make_space( int $owner, string $name, string $type = 'open' ): int {
		$service = new SpaceService();

		// The field is `type`, NOT `privacy` — create() reads $data['type'] and falls back
		// to the site default. Passing 'privacy' silently creates an OPEN space, which
		// would make the secret-space leak tests below pass for entirely the wrong reason.
		$id = $service->create(
			$owner,
			array(
				'name' => $name,
				'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 9999 ),
				'type' => $type,
			)
		);

		$this->assertIsInt( $id, 'Could not create the space: ' . ( is_wp_error( $id ) ? $id->get_error_message() : '' ) );

		return (int) $id;
	}

	/**
	 * Names in a listing.
	 *
	 * @param array<int, mixed> $rows Rows from list_spaces().
	 * @return array<int, string>
	 */
	private function names( array $rows ): array {
		return array_map(
			static fn( $s ): string => (string) ( is_array( $s ) ? ( $s['name'] ?? '' ) : ( $s->name ?? '' ) ),
			$rows
		);
	}

	/**
	 * A newly created space shows in the directory immediately.
	 *
	 * Create is the one write with no space row to invalidate, so it is the one that has
	 * to bust the listings explicitly — and the one an owner notices instantly.
	 *
	 * @return void
	 */
	public function test_a_new_space_appears_in_the_directory_immediately(): void {
		$owner   = self::factory()->user->create();
		$service = new SpaceService();

		// Warm the cache with the directory as it is now.
		$before = $this->names( $service->list_spaces( array( 'viewer' => $owner ) ) );
		$this->assertNotContains( 'Brand New Space', $before );

		$this->make_space( $owner, 'Brand New Space' );

		$this->assertContains(
			'Brand New Space',
			$this->names( $service->list_spaces( array( 'viewer' => $owner ) ) ),
			'A newly created space is missing from the directory. Create has no space row to bust, so it must invalidate the listings itself.'
		);
	}

	/**
	 * THE ONE THAT MATTERS: a secret space cached for its owner is not served to a
	 * stranger.
	 *
	 * @return void
	 */
	public function test_a_secret_space_cached_for_its_owner_is_not_served_to_a_stranger(): void {
		$owner    = self::factory()->user->create();
		$stranger = self::factory()->user->create();
		$service  = new SpaceService();

		$this->make_space( $owner, 'Owners Secret Space', 'secret' );

		// The OWNER reads the directory first, warming the cache with a row set that
		// contains the secret space.
		$owner_sees = $this->names( $service->list_spaces( array( 'viewer' => $owner ) ) );
		$this->assertContains( 'Owners Secret Space', $owner_sees, 'The owner cannot see their own secret space.' );

		// Now a stranger reads the same directory.
		$stranger_sees = $this->names( $service->list_spaces( array( 'viewer' => $stranger ) ) );

		$this->assertNotContains(
			'Owners Secret Space',
			$stranger_sees,
			'A SECRET SPACE LEAKED. The directory was cached for the owner and served to a stranger, so the cache key does not capture the viewer. This is disclosure, not staleness.'
		);
	}

	/**
	 * And an admin's view (which sees everything) is not served to a normal member.
	 *
	 * @return void
	 */
	public function test_an_admins_view_is_not_served_to_a_normal_member(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner   = self::factory()->user->create();
		$member  = self::factory()->user->create();
		$service = new SpaceService();

		$this->make_space( $owner, 'Someone Elses Secret', 'secret' );

		// Admin warms the cache — admins see every space, secret ones included.
		$admin_sees = $this->names(
			$service->list_spaces(
				array(
					'viewer'   => $admin,
					'is_admin' => true,
				)
			)
		);
		$this->assertContains( 'Someone Elses Secret', $admin_sees, 'Precondition: an admin should see secret spaces.' );

		$member_sees = $this->names( $service->list_spaces( array( 'viewer' => $member ) ) );

		$this->assertNotContains(
			'Someone Elses Secret',
			$member_sees,
			"The ADMIN's row set was served to an ordinary member. is_admin is part of the visibility scope and must be part of the cache key."
		);
	}

	/**
	 * Renaming a space is visible in the directory at once.
	 *
	 * @return void
	 */
	public function test_renaming_a_space_updates_the_directory(): void {
		$owner   = self::factory()->user->create();
		$service = new SpaceService();

		$id = $this->make_space( $owner, 'Old Name' );

		$this->assertContains( 'Old Name', $this->names( $service->list_spaces( array( 'viewer' => $owner ) ) ) );

		$service->update( $id, $owner, array( 'name' => 'New Name' ) );

		$names = $this->names( $service->list_spaces( array( 'viewer' => $owner ) ) );

		$this->assertContains( 'New Name', $names, 'A renamed space still shows its old name in the directory.' );
		$this->assertNotContains( 'Old Name', $names, 'The old name is still in the cached directory listing.' );
	}

	/**
	 * A deleted space leaves the directory at once.
	 *
	 * @return void
	 */
	public function test_a_deleted_space_leaves_the_directory(): void {
		$owner   = self::factory()->user->create();
		$service = new SpaceService();

		$id = $this->make_space( $owner, 'Doomed Space' );
		$this->assertContains( 'Doomed Space', $this->names( $service->list_spaces( array( 'viewer' => $owner ) ) ) );

		$service->delete( $id, $owner );

		$this->assertNotContains(
			'Doomed Space',
			$this->names( $service->list_spaces( array( 'viewer' => $owner ) ) ),
			'A DELETED space is still listed in the directory.'
		);
	}

	/**
	 * The total travels with the rows — a new space moves the count, not just the grid.
	 *
	 * @return void
	 */
	public function test_the_total_is_busted_along_with_the_rows(): void {
		$owner   = self::factory()->user->create();
		$service = new SpaceService();

		$this->make_space( $owner, 'Counted One' );

		$before = (int) $service->list_spaces_with_total( array( 'viewer' => $owner ) )['total'];

		$this->make_space( $owner, 'Counted Two' );

		$after = (int) $service->list_spaces_with_total( array( 'viewer' => $owner ) )['total'];

		$this->assertSame(
			$before + 1,
			$after,
			'The directory TOTAL did not move when a space was added. The count is cached separately from the rows, so it needs the same invalidation - otherwise the grid and its pagination disagree.'
		);
	}
}
