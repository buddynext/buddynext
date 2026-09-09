<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The move-under-parent picker must not re-query per root space.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * SpaceService::eligible_parents() used to load EVERY root space and run
 * permissions->can() (and count_subspaces()) per row, so the space-settings page
 * issued hundreds to tens of thousands of queries and would not open at scale.
 * The rewrite filters permission IN SQL and bounds the result with LIMIT.
 *
 * @covers \BuddyNext\Spaces\SpaceService::eligible_parents
 */
class EligibleParentsScaleTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Insert a root space row directly and return its id.
	 *
	 * @param int    $owner_id Owner user id.
	 * @param string $name     Space name.
	 * @return int
	 */
	private function make_root( int $owner_id, string $name ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ) . '-' . wp_generate_password( 6, false ),
				'owner_id'    => $owner_id,
				'parent_id'   => null,
				'is_archived' => 0,
				'type'        => 'public',
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * The query count is bounded and does NOT scale with the number of root spaces.
	 *
	 * @return void
	 */
	public function test_eligible_parents_query_count_is_bounded(): void {
		global $wpdb;

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// One space to move (no children -> eligible), plus 40 root spaces.
		$mover = $this->make_root( $admin, 'Mover' );
		for ( $i = 0; $i < 40; $i++ ) {
			$this->make_root( $admin, 'Root ' . $i );
		}

		$service = new SpaceService();

		// Warm any per-request option/cap caches so we measure the read itself.
		$service->eligible_parents( $mover, $admin, '', 20 );

		$before = $wpdb->num_queries;
		$result = $service->eligible_parents( $mover, $admin, '', 20 );
		$used   = $wpdb->num_queries - $before;

		// Bounded by LIMIT, not by the 40 roots. The old per-row permission +
		// count N+1 would have been ~40+ here; a handful is all the SQL-filtered,
		// LIMIT-bounded read needs (cap off => no per-row count_subspaces).
		$this->assertLessThanOrEqual(
			5,
			$used,
			"eligible_parents() used {$used} queries for 40 root spaces — it must not query per root space."
		);

		// LIMIT is honoured.
		$this->assertCount( 20, $result, 'The picker must return at most the LIMIT (20), not every root space.' );
	}

	/**
	 * Insert a sub-space under a parent and return its id.
	 *
	 * @param int    $owner_id  Owner user id.
	 * @param int    $parent_id Parent root id.
	 * @param string $name      Space name.
	 * @return int
	 */
	private function make_child( int $owner_id, int $parent_id, string $name ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ) . '-' . wp_generate_password( 6, false ),
				'owner_id'    => $owner_id,
				'parent_id'   => $parent_id,
				'is_archived' => 0,
				'type'        => 'public',
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * With a per-parent sub-space cap set, a root already at the cap is filtered
	 * out IN SQL — before LIMIT — so it neither appears as a candidate nor eats a
	 * result slot, and the read stays bounded (no per-row count_subspaces() N+1).
	 *
	 * Guards the RFT edge: the cap used to be a per-row PHP filter applied AFTER
	 * LIMIT, so capped-out roots sorting first could push valid parents past the
	 * page with no way to reach them.
	 *
	 * @return void
	 */
	public function test_sub_space_cap_is_filtered_in_sql_before_limit(): void {
		global $wpdb;

		update_option( 'buddynext_space_allow_sub', '1' );
		update_option( 'buddynext_space_max_sub_spaces', 2 );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mover = $this->make_root( $admin, 'Mover' );

		// Two capped-out roots sorting FIRST (AAA…), each already holding 2 children.
		$full1 = $this->make_root( $admin, 'AAA Full 1' );
		$full2 = $this->make_root( $admin, 'AAA Full 2' );
		foreach ( array( $full1, $full2 ) as $full ) {
			$this->make_child( $admin, $full, 'child a' );
			$this->make_child( $admin, $full, 'child b' );
		}

		// Two under-cap roots sorting LAST (ZZZ…).
		$open1 = $this->make_root( $admin, 'ZZZ Open 1' );
		$open2 = $this->make_root( $admin, 'ZZZ Open 2' );

		$service = new SpaceService();

		// Small LIMIT: the capped roots sort first, so a post-LIMIT PHP filter would
		// have dropped them and returned fewer than the opens. SQL filtering keeps
		// the opens in.
		$ids = array_map(
			static fn( array $row ): int => (int) $row['id'],
			$service->eligible_parents( $mover, $admin, '', 3 )
		);

		$this->assertContains( $open1, $ids, 'An under-cap root must be offered.' );
		$this->assertContains( $open2, $ids, 'An under-cap root must be offered.' );
		$this->assertNotContains( $full1, $ids, 'A capped-out root must be filtered in SQL.' );
		$this->assertNotContains( $full2, $ids, 'A capped-out root must be filtered in SQL.' );

		// The cap join adds a single grouped scan, not a per-candidate query.
		$service->eligible_parents( $mover, $admin, '', 20 );
		$before = $wpdb->num_queries;
		$service->eligible_parents( $mover, $admin, '', 20 );
		$used = $wpdb->num_queries - $before;
		$this->assertLessThanOrEqual( 3, $used, "cap-on read used {$used} queries — the cap must not re-query per candidate." );

		delete_option( 'buddynext_space_max_sub_spaces' );
	}

	/**
	 * The SQL cap must count only what the enforcement path counts. count_subspaces()
	 * — which validate_parent_move() caps against — excludes archived children, so the
	 * picker's cap must too. Otherwise a root at cap whose children are all archived
	 * is withheld from the picker while a PATCH to it would succeed (card 10264295263
	 * round-3: the mirror-exactly promise, inverted).
	 *
	 * @return void
	 */
	public function test_capped_root_with_archived_children_is_offered(): void {
		global $wpdb;

		update_option( 'buddynext_space_allow_sub', '1' );
		update_option( 'buddynext_space_max_sub_spaces', 2 );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mover = $this->make_root( $admin, 'Mover' );
		$root  = $this->make_root( $admin, 'Root With Archived Kids' );

		// Two children, both archived — the enforcement count_subspaces() ignores them.
		foreach ( array( 'arch a', 'arch b' ) as $name ) {
			$child = $this->make_child( $admin, $root, $name );
			$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'is_archived' => 1 ), array( 'id' => $child ), array( '%d' ), array( '%d' ) );
		}

		$service = new SpaceService();

		$this->assertSame( 0, $service->count_subspaces( $root ), 'Enforcement count excludes archived children.' );

		$ids = array_map(
			static fn( array $row ): int => (int) $row['id'],
			$service->eligible_parents( $mover, $admin, '', 20 )
		);

		$this->assertContains( $root, $ids, 'A root whose only children are archived is under cap, so the picker must offer it.' );

		delete_option( 'buddynext_space_max_sub_spaces' );
	}

	/**
	 * Permission is filtered in SQL: a member (not owner/moderator) sees nothing.
	 *
	 * @return void
	 */
	public function test_permission_is_filtered_in_sql(): void {
		global $wpdb;

		$owner  = self::factory()->user->create();
		$member = self::factory()->user->create();

		$mover = $this->make_root( $member, 'Mover' );
		$root  = $this->make_root( $owner, 'A Root' );

		$service = new SpaceService();

		// The member manages nothing -> no candidates.
		$this->assertCount( 0, $service->eligible_parents( $mover, $member, '', 20 ) );

		// Make them a moderator of the root -> it becomes a candidate.
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $root,
				'user_id'  => $member,
				'role'     => 'moderator',
				'status'   => 'active',
			),
			array( '%d', '%d', '%s', '%s' )
		);
		wp_cache_flush();

		$candidates = $service->eligible_parents( $mover, $member, '', 20 );
		$this->assertCount( 1, $candidates );
		$this->assertSame( $root, (int) $candidates[0]['id'] );
	}
}
