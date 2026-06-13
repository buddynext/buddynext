<?php
/**
 * Tests for SpaceMemberService::get_space_bans.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService::get_space_bans
 */
class SpaceBansListTest extends \WP_UnitTestCase {

	private SpaceMemberService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new SpaceMemberService();
	}

	public function test_get_space_bans_returns_banned_users(): void {
		$space_id = 1;
		$user_id  = self::factory()->user->create();
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->service->ban_from_space( $space_id, $user_id, $admin, 'spam' );

		$bans = $this->service->get_space_bans( $space_id );
		$this->assertCount( 1, $bans );
		$this->assertSame( $user_id, (int) $bans[0]['user_id'] );
		$this->assertSame( $space_id, (int) $bans[0]['space_id'] );
	}

	public function test_get_space_bans_empty_for_clean_space(): void {
		$this->assertSame( array(), $this->service->get_space_bans( 999 ) );
	}

	public function test_get_space_bans_caps_results(): void {
		$bans = $this->service->get_space_bans( 1, 50 );
		$this->assertLessThanOrEqual( 50, count( $bans ) );
	}

	public function test_ban_with_no_actor_persists(): void {
		$space_id = 2;
		$user_id  = self::factory()->user->create();

		// System ban (no actor): banned_by defaults to 0. Column is NOT NULL.
		$result = $this->service->ban_from_space( $space_id, $user_id );
		$this->assertTrue( $result );

		$bans = $this->service->get_space_bans( $space_id );
		$this->assertCount( 1, $bans );
		$this->assertSame( 0, (int) $bans[0]['banned_by'] );
	}
}
