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
 * @covers \BuddyNext\Spaces\SpaceMemberService::ban_from_space
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

	/**
	 * Card 10435625577: a repeat ban (a double-click, or two racing requests) is
	 * one ban with one log row and one hook, and a ban on nobody is refused.
	 */
	public function test_repeat_ban_logs_and_fires_once(): void {
		global $wpdb;

		$space_id = 3;
		$user_id  = self::factory()->user->create();
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$fired    = 0;
		$listener = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'buddynext_space_user_banned', $listener );

		$this->assertTrue( $this->service->ban_from_space( $space_id, $user_id, $admin ) );
		$this->assertTrue( $this->service->ban_from_space( $space_id, $user_id, $admin ) );
		$this->assertTrue( $this->service->ban_from_space( $space_id, $user_id, $admin ) );
		remove_action( 'buddynext_space_user_banned', $listener );

		$logged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE action = 'space_ban' AND actor_id = %d",
				$admin
			)
		);
		$this->assertSame( 1, $logged, 'One ban, one log row.' );
		$this->assertSame( 1, $fired, 'One ban, one banned hook.' );
		$this->assertCount( 1, $this->service->get_space_bans( $space_id ) );
	}

	public function test_ban_of_missing_user_is_refused(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result = $this->service->ban_from_space( 4, 9999999, $admin );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
		$this->assertSame( array(), $this->service->get_space_bans( 4 ) );
	}

	public function test_ban_removes_membership_and_fires_removed(): void {
		$owner    = self::factory()->user->create();
		$space_id = (int) ( new \BuddyNext\Spaces\SpaceService() )->create( $owner, array( 'name' => 'Ban room', 'slug' => 'ban-room', 'type' => 'open' ) );
		$user_id  = self::factory()->user->create();
		$this->service->join( $space_id, $user_id );

		$removed  = 0;
		$listener = static function () use ( &$removed ): void {
			++$removed;
		};
		add_action( 'buddynext_space_member_removed', $listener );
		$this->assertTrue( $this->service->ban_from_space( $space_id, $user_id, $owner ) );
		remove_action( 'buddynext_space_member_removed', $listener );

		$this->assertSame( 1, $removed, 'Listeners that revoke membership grants (forum access) must hear the ban.' );
		$this->assertFalse( $this->service->is_member( $space_id, $user_id ) );
	}
}
