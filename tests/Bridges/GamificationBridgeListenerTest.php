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

	public function test_deleted_badge_leaves_its_holders_inboxes(): void {
		$this->listener->on_badge_awarded( $this->member, array( 'name' => 'Veteran' ), 'veteran' );
		$this->listener->on_badge_awarded( $this->other, array( 'name' => 'Helper' ), 'helper' );

		$this->listener->on_badge_deleted( 'veteran', array( $this->member, $this->other ) );

		$this->assertArrayNotHasKey( 'bn.badge_awarded', $this->rows( $this->member ), 'the deleted badge leaves the inbox' );
		$this->assertSame( 1, $this->rows( $this->other )['bn.badge_awarded'] ?? 0, 'another badge is untouched' );
	}

	public function test_personal_records_alert_once_per_period_and_stay_current(): void {
		global $wpdb;
		$alerts = 0;
		$count  = static function () use ( &$alerts ) {
			++$alerts;
		};
		add_action( 'buddynext_notification_created', $count );

		// Five awards in a row, each beating day, week and month.
		for ( $i = 1; $i <= 5; $i++ ) {
			foreach ( array( 'day', 'week', 'month' ) as $period ) {
				$this->listener->on_personal_record( $this->member, $period, 40 + $i, 30, "Best {$period}: " . ( 40 + $i ) );
			}
		}
		remove_action( 'buddynext_notification_created', $count );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.personal_record' ORDER BY id", $this->member ), ARRAY_A );
		$this->assertCount( 2, $rows, 'one row each for week and month; daily bests are not announced by default' );
		$this->assertSame( 2, $alerts, 'each period alerts once' );
		foreach ( $rows as $row ) {
			$this->assertSame( 45, (int) json_decode( $row['data'], true )['current'], 'the row shows the latest value' );
		}
	}

	public function test_trivial_first_records_and_a_site_opt_out_send_nothing(): void {
		$this->listener->on_personal_record( $this->member, 'week', 8, 0, 'First week' );
		add_filter( 'buddynext_personal_record_notify', '__return_false' );
		$this->listener->on_personal_record( $this->member, 'month', 500, 300, 'Big month' );
		remove_filter( 'buddynext_personal_record_notify', '__return_false' );
		$this->assertArrayNotHasKey( 'bn.personal_record', $this->rows( $this->member ) );
	}
}
