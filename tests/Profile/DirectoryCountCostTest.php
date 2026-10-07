<?php
/**
 * The members directory counts the whole user table at most once per page.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\MemberDirectoryService;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryService
 */
class DirectoryCountCostTest extends \WP_UnitTestCase {

	/**
	 * with_total => false skips the COUNT and returns a null total.
	 *
	 * @return void
	 */
	public function test_widgets_can_skip_the_total(): void {
		self::factory()->user->create_many( 4 );
		$dir    = new MemberDirectoryService();
		$viewer = self::factory()->user->create();
		global $wpdb;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$full   = $dir->list_members( $viewer, null, 5, array( 'sort' => 'newest' ) );
		$cost   = $wpdb->num_queries - $before;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$lite   = $dir->list_members( $viewer, null, 5, array( 'sort' => 'newest', 'with_total' => false ) );

		$this->assertNull( $lite['total'] );
		$this->assertIsInt( $full['total'] );
		$this->assertSame( wp_list_pluck( $full['items'], 'user_id' ), wp_list_pluck( $lite['items'], 'user_id' ), 'Same members either way.' );
		$this->assertLessThan( $cost, $wpdb->num_queries - $before, 'One query fewer without the total.' );
	}

	/**
	 * The community total (viewer included) equals the old single-query count.
	 *
	 * @return void
	 */
	public function test_community_total_matches_the_direct_count(): void {
		$members = self::factory()->user->create_many( 5 );
		$dir     = new MemberDirectoryService();
		foreach ( array( $members[0], 0 ) as $viewer ) {
			foreach ( array( array(), array( 'include' => array( $members[0], $members[1] ) ), array( 'include' => array( $members[2] ) ) ) as $filters ) {
				wp_cache_flush();
				$derived = $dir->directory_total( $viewer, $filters + array( 'count_viewer' => true ) );
				wp_cache_flush();
				$direct = $dir->directory_total( $viewer, $filters + array( 'count_viewer' => true, 'viewer_row_only' => true ) );
				$this->assertSame( $direct, $derived, 'viewer ' . $viewer . ' filters ' . wp_json_encode( $filters ) );
			}
		}
	}
}
