<?php
/**
 * WB Gamification moments in the BuddyNext inbox.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridgeListener;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\GamificationBridgeListener
 */
class GamificationBridgeListenerTest extends \WP_UnitTestCase {

	private GamificationBridgeListener $listener;
	private int $member = 0;
	private int $other  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->listener = new GamificationBridgeListener();
		$this->member   = self::factory()->user->create();
		$this->other    = self::factory()->user->create();
	}

	/**
	 * Rows a member holds, as type => count.
	 *
	 * @param int $user_id Recipient.
	 * @return array<string,int>
	 */
	private function rows( int $user_id ): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, object_type FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d", $user_id ), ARRAY_A ) as $row ) {
			$out[ $row['type'] ] = ( $out[ $row['type'] ] ?? 0 ) + 1;
		}
		return $out;
	}

	public function test_only_a_level_climb_notifies(): void {
		$member   = array( 'id' => 2, 'name' => 'Member', 'min_points' => 100 );
		$newcomer = array( 'id' => 1, 'name' => 'Newcomer', 'min_points' => 0 );

		$this->listener->on_level_changed( $this->member, $newcomer, $member );
		$this->assertArrayNotHasKey( 'bn.level_up', $this->rows( $this->member ), 'a drop is not announced' );

		$this->listener->on_level_changed( $this->member, $member, $newcomer );
		$this->assertSame( 1, $this->rows( $this->member )['bn.level_up'] ?? 0, 'a climb is announced once' );
	}

	public function test_kudos_reaches_the_receiver_and_leaves_on_revoke(): void {
		global $wpdb;
		$this->listener->on_kudos_given( $this->other, $this->member, 'Thanks!', 41 );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT sender_id, object_type, object_id FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.kudos_received'", $this->member ) );
		$this->assertNotNull( $row );
		$this->assertSame( $this->other, (int) $row->sender_id );
		$this->assertSame( 'wbgam_kudos', $row->object_type );
		$this->assertArrayNotHasKey( 'bn.kudos_received', $this->rows( $this->other ), 'the giver gets nothing' );

		$this->listener->on_kudos_revoked( 41 );
		$this->assertArrayNotHasKey( 'bn.kudos_received', $this->rows( $this->member ), 'a revoked kudos removes its notification' );
	}

	public function test_kudos_from_a_blocked_member_is_dropped(): void {
		buddynext_service( 'blocks' )->block( $this->member, $this->other );
		$this->listener->on_kudos_given( $this->other, $this->member, '', 42 );
		$this->assertArrayNotHasKey( 'bn.kudos_received', $this->rows( $this->member ) );
	}

	public function test_each_moment_creates_one_row_for_its_member(): void {
		$this->listener->on_challenge_completed( $this->member, array( 'id' => 5, 'title' => 'Welcome week' ) );
		$this->listener->on_redemption_fulfilled( 9, $this->member );
		$this->listener->on_credential_expired( $this->member, 'first-aid' );
		$this->listener->on_personal_record( $this->member, 'week', 120, 80, 'Your best week yet: 120 Points.' );
		$this->listener->on_streak_milestone( $this->member, 30 );

		$rows = $this->rows( $this->member );
		foreach ( array( 'bn.challenge_completed', 'bn.reward_fulfilled', 'bn.credential_expired', 'bn.personal_record', 'bn.streak_milestone' ) as $type ) {
			$this->assertSame( 1, $rows[ $type ] ?? 0, $type );
		}
		$this->assertSame( array(), $this->rows( $this->other ), 'no row leaks to another member' );
	}
}
