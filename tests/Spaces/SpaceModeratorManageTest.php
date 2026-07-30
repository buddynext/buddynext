<?php
/**
 * A space moderator manages the space, but does not own it.
 *
 * Owner decision, 2026-07-30: "moderators have manage space, they just cannot remove
 * the owner or delete the space."
 *
 * Before this, `can_manage_space()` was `'owner' === $role`, so a moderator was
 * refused — while the space navigation computed owner-or-moderator and showed them
 * the manage panel anyway. Two gates answering the same question differently for
 * exactly one role, which surfaced as "the moderator role does not work properly"
 * rather than as a permissions bug.
 *
 * Widening the capability alone would have been unsafe: `delete()`,
 * `transfer_ownership()` and `assign_owner()` all checked `buddynext-manage-space`,
 * so moderators would have gained space deletion and ownership transfer as a side
 * effect. Those moved to `buddynext-own-space` first.
 *
 * So these tests are written in pairs — what a moderator GAINS, and what they must
 * still be refused. The second half is the one that matters: a widening is only
 * correct if the things it was not supposed to widen still hold.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Core\PermissionService::can_manage_space
 * @covers \BuddyNext\Core\PermissionService::can_own_space
 */
class SpaceModeratorManageTest extends \WP_UnitTestCase {

	private SpaceService $spaces;
	private SpaceMemberService $members;
	private int $owner_id;
	private int $moderator_id;
	private int $member_id;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->spaces  = new SpaceService();
		$this->members = new SpaceMemberService();

		$this->owner_id     = self::factory()->user->create();
		$this->moderator_id = self::factory()->user->create();
		$this->member_id    = self::factory()->user->create();

		$this->space_id = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Moderator Manage Space',
				'slug' => 'moderator-manage-space',
				'type' => 'open',
			)
		);

		$this->members->join( $this->space_id, $this->moderator_id );
		$this->members->join( $this->space_id, $this->member_id );
		$this->promote_to_moderator( $this->moderator_id );
	}

	/**
	 * Ask the capability layer, the same way every caller does.
	 *
	 * @param int    $user_id    Actor.
	 * @param string $capability Capability slug.
	 * @return bool
	 */
	private function can( int $user_id, string $capability ): bool {
		return buddynext_service( 'permissions' )->can(
			$user_id,
			$capability,
			array( 'space_id' => $this->space_id )
		);
	}

	/**
	 * A moderator holds manage-space. This is the reported bug.
	 */
	public function test_moderator_can_manage_space(): void {
		$this->assertTrue( $this->can( $this->moderator_id, 'buddynext-manage-space' ) );
		$this->assertTrue( $this->can( $this->owner_id, 'buddynext-manage-space' ) );
	}

	/**
	 * A plain member does not — widening to moderators must not widen to everyone.
	 */
	public function test_plain_member_cannot_manage_space(): void {
		$this->assertFalse( $this->can( $this->member_id, 'buddynext-manage-space' ) );
		$this->assertFalse( $this->can( self::factory()->user->create(), 'buddynext-manage-space' ), 'a non-member cannot manage' );
	}

	/**
	 * A moderator does NOT hold owner authority.
	 */
	public function test_moderator_does_not_hold_owner_authority(): void {
		$this->assertFalse( $this->can( $this->moderator_id, 'buddynext-own-space' ) );
		$this->assertTrue( $this->can( $this->owner_id, 'buddynext-own-space' ) );
	}

	/**
	 * A moderator cannot delete the space, and the space survives the attempt.
	 *
	 * Asserting the row still exists as well as the error: a gate that returns an
	 * error after the delete has already run is not a gate.
	 */
	public function test_moderator_cannot_delete_the_space(): void {
		global $wpdb;

		$result = $this->spaces->delete( $this->space_id, $this->moderator_id );

		$this->assertWPError( $result, 'a moderator must not be able to delete the space' );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $this->space_id )
		);
		$this->assertSame( 1, $still_there, 'the space must still exist after a refused delete' );
	}

	/**
	 * The owner still can delete it — the guard must not have locked everybody out.
	 */
	public function test_owner_can_still_delete_the_space(): void {
		global $wpdb;

		$result = $this->spaces->delete( $this->space_id, $this->owner_id );

		$this->assertNotWPError( $result );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $this->space_id ) )
		);
	}

	/**
	 * A moderator cannot transfer ownership, and ownership does not move.
	 */
	public function test_moderator_cannot_transfer_ownership(): void {
		global $wpdb;

		$result = $this->spaces->assign_owner( $this->space_id, $this->member_id, $this->moderator_id );

		$this->assertWPError( $result, 'a moderator must not be able to transfer ownership' );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		$owner_now = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT owner_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $this->space_id )
		);
		$this->assertSame( $this->owner_id, $owner_now, 'ownership must not have moved' );
	}

	/**
	 * Promote a member to moderator.
	 *
	 * Written straight to the column because Free exposes no public promotion API —
	 * role writes in SpaceService only ever set 'owner' or demote to 'member'. The
	 * cache is busted the way a membership write would, so `get_role()` — which every
	 * gate reads through — sees the new role rather than a stale cached one.
	 *
	 * @param int $user_id Member to promote.
	 * @return void
	 */
	private function promote_to_moderator( int $user_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => 'moderator' ),
			array(
				'space_id' => $this->space_id,
				'user_id'  => $user_id,
			)
		);
		wp_cache_flush();

		$this->assertSame(
			'moderator',
			$this->members->get_role( $this->space_id, $user_id ),
			'fixture did not produce a moderator, so every assertion below would be meaningless'
		);
	}
}
