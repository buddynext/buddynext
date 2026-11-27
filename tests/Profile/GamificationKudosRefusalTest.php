<?php
/**
 * Kudos cannot be sent by a suspended member or across a block, on any route.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\GamificationKudos;

/**
 * @covers \BuddyNext\Profile\GamificationKudos::refusal
 * @covers \BuddyNext\Profile\GamificationKudos::gate_partner_send
 */
class GamificationKudosRefusalTest extends \WP_UnitTestCase {

	/**
	 * Two members in good standing may send kudos.
	 *
	 * @return void
	 */
	public function test_members_in_good_standing_may_send_kudos(): void {
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();

		$this->assertNull( GamificationKudos::refusal( $a, $b ) );
		$this->assertTrue( GamificationKudos::gate_partner_send( true, $a, $b ) );
	}

	/**
	 * A block refuses kudos in both directions, and answers like any invalid receiver.
	 *
	 * @return void
	 */
	public function test_a_block_refuses_kudos_both_ways(): void {
		$blocker = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		$this->assertTrue( true === buddynext_service( 'blocks' )->block( $blocker, $blocked ) || ! is_wp_error( buddynext_service( 'blocks' )->block( $blocker, $blocked ) ) );

		$one = GamificationKudos::refusal( $blocked, $blocker );
		$this->assertWPError( $one, 'A blocked member sent kudos to the member who blocked them.' );
		$this->assertSame( 'invalid_receiver', $one->get_error_code(), 'A block must not tell the sender they were blocked.' );

		$this->assertWPError( GamificationKudos::refusal( $blocker, $blocked ), 'The blocker sent kudos across their own block.' );
		$this->assertWPError( GamificationKudos::gate_partner_send( true, $blocked, $blocker ), 'The partner route let kudos through a block.' );
	}

	/**
	 * A suspended member cannot send kudos; the refusal carries the appeal link.
	 *
	 * @return void
	 */
	public function test_a_suspended_member_cannot_send_kudos(): void {
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$suspended = self::factory()->user->create();
		$other     = self::factory()->user->create();
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $suspended, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ) );

		$refused = GamificationKudos::refusal( $suspended, $other );
		$this->assertWPError( $refused, 'A suspended member sent kudos.' );
		$this->assertSame( 403, $refused->get_error_data()['status'] ?? 0 );
		$this->assertNotEmpty( $refused->get_error_data()['appeal_url'] ?? '' );
		$this->assertWPError( GamificationKudos::gate_partner_send( true, $suspended, $other ), 'The partner route let a suspended member send kudos.' );

		// Another member's earlier refusal is passed on untouched.
		$earlier = new \WP_Error( 'x', 'earlier' );
		$this->assertSame( $earlier, GamificationKudos::gate_partner_send( $earlier, $other, $admin ) );
	}
}
