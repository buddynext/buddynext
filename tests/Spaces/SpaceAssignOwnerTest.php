<?php
/**
 * Tests for SpaceService::assign_owner().
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceService::assign_owner
 */
class SpaceAssignOwnerTest extends \WP_UnitTestCase {

	private SpaceService $spaces;
	private SpaceMemberService $members;
	private int $owner_id;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces   = new SpaceService();
		$this->members  = new SpaceMemberService();
		$this->owner_id = self::factory()->user->create();
		$this->space_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Assign Owner Space',
				'slug' => 'assign-owner-space',
				'type' => 'open',
			)
		);
	}

	/**
	 * Both tables must move together — this is the divergence bug.
	 */
	public function test_assign_owner_updates_both_tables(): void {
		global $wpdb;
		$heir = self::factory()->user->create();
		$this->members->join( $this->space_id, $heir );

		$result = $this->spaces->assign_owner( $this->space_id, $heir );

		$this->assertTrue( $result );

		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $this->space_id )
		);
		$this->assertSame( $heir, $owner_id, 'bn_spaces.owner_id must move' );
		$this->assertSame( 'owner', $this->members->get_role( $this->space_id, $heir ) );
		$this->assertSame( 'member', $this->members->get_role( $this->space_id, $this->owner_id ), 'previous owner must be demoted' );

		$owner_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND role = 'owner'",
				$this->space_id
			)
		);
		$this->assertSame( 1, $owner_rows, 'exactly one owner row — never two' );
	}

	/**
	 * A non-member heir (the site-admin fallback) joins as an active owner and bumps the count.
	 */
	public function test_assign_owner_to_non_member_increments_member_count(): void {
		$before = $this->spaces->get( $this->space_id )['member_count'];
		$heir   = self::factory()->user->create();

		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $heir ) );

		$this->assertSame( 'owner', $this->members->get_role( $this->space_id, $heir ) );
		$this->assertSame( $before + 1, $this->spaces->get( $this->space_id )['member_count'] );
	}

	/**
	 * THE SECURITY BUG: a moderator must not be able to seize the Space.
	 */
	public function test_moderator_cannot_assign_owner(): void {
		$mod = self::factory()->user->create();
		$this->members->join( $this->space_id, $mod );
		$this->members->change_role( $this->space_id, $mod, 'moderator', $this->owner_id );

		$result = $this->spaces->assign_owner( $this->space_id, $mod, $mod );

		$this->assertWPError( $result );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'], 'owner_id must be untouched' );
	}

	/**
	 * System context (actor null) bypasses caps — used by succession and the CLI sweep.
	 */
	public function test_system_context_bypasses_capability_check(): void {
		$heir = self::factory()->user->create();
		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $heir, null ) );
	}

	public function test_rejects_nonexistent_user(): void {
		$this->assertWPError( $this->spaces->assign_owner( $this->space_id, 999999 ) );
	}

	public function test_noop_when_already_owner(): void {
		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $this->owner_id ) );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	public function test_fires_ownership_transferred_action(): void {
		$fired = 0;
		add_action(
			'buddynext_space_ownership_transferred',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$heir = self::factory()->user->create();
		$this->spaces->assign_owner( $this->space_id, $heir );

		$this->assertSame( 1, $fired );
	}
}
