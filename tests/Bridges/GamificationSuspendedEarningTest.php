<?php
/**
 * A suspended member earns no gamification points while suspended.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;

/**
 * GamificationBridge::no_earning_while_suspended().
 *
 * @covers \BuddyNext\Bridges\GamificationBridge::no_earning_while_suspended
 */
class GamificationSuspendedEarningTest extends \WP_UnitTestCase {

	/**
	 * A member in good standing may earn; once suspended they may not; lifting it restores earning.
	 *
	 * @return void
	 */
	public function test_a_suspended_member_cannot_earn(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$member = self::factory()->user->create();
		$bridge = new GamificationBridge();

		$this->assertTrue( $bridge->no_earning_while_suspended( true, $member ), 'A member in good standing lost earning.' );

		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $member, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ) );
		$this->assertFalse( $bridge->no_earning_while_suspended( true, $member ), 'A suspended member can still earn points.' );

		// Never turns earning ON for someone wb-gamification already excluded.
		$this->assertFalse( $bridge->no_earning_while_suspended( false, self::factory()->user->create() ) );
	}

	/**
	 * The bridge answers wb-gamification's earning filter.
	 *
	 * @return void
	 */
	public function test_the_filter_is_hooked(): void {
		$bridge = new GamificationBridge();
		$bridge->init();
		$this->assertNotFalse( has_filter( 'wb_gam_user_can_earn', array( $bridge, 'no_earning_while_suspended' ) ) );
	}

	/**
	 * The leaderboard exclusion fragment drops a suspended member and keeps everyone else.
	 *
	 * @return void
	 */
	public function test_the_leaderboard_fragment_skips_a_suspended_member(): void {
		global $wpdb;

		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$suspended = self::factory()->user->create();
		$fine      = self::factory()->user->create();
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $suspended, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ) );

		[ $sql, $values ] = ( new GamificationBridge() )->leaderboard_skips_suspended( array( ' AND 1 = 1', array() ), 'lb' );
		$this->assertSame( array(), $values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT lb.user_id FROM ( SELECT ID AS user_id FROM {$wpdb->users} WHERE ID IN ( {$suspended}, {$fine} ) ) lb WHERE 1 = 1 {$sql}" ) );

		$this->assertSame( array( $fine ), $ids, 'The suspended member is still on the board, or a member in good standing was dropped.' );
	}
}
