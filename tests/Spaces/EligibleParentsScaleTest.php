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
