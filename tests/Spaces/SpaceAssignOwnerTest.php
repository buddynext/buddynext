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
 * Covers the single ownership-transfer primitive.
 *
 * @covers \BuddyNext\Spaces\SpaceService::assign_owner
 */
class SpaceAssignOwnerTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SpaceService
	 */
	private SpaceService $spaces;

	/**
	 * Membership service, used to seed and assert member rows.
	 *
	 * @var SpaceMemberService
	 */
	private SpaceMemberService $members;

	/**
	 * The space's original owner.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * The space under test.
	 *
	 * @var int
	 */
	private int $space_id;

	/**
	 * Create a space owned by a fresh user.
	 *
	 * @return void
	 */
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

	/**
	 * An unknown user ID is rejected before any write.
	 *
	 * @return void
	 */
	public function test_rejects_nonexistent_user(): void {
		$this->assertWPError( $this->spaces->assign_owner( $this->space_id, 999999 ) );
	}

	/**
	 * Re-assigning to the incumbent is a successful no-op.
	 *
	 * @return void
	 */
	public function test_noop_when_already_owner(): void {
		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $this->owner_id ) );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	/**
	 * A Space whose pointer says "B owns this" while B holds NO owner row is
	 * divergent — exactly the wreckage the old transfer_ownership() produced.
	 * assign_owner() must REPAIR it, not certify it as healthy.
	 *
	 * @return void
	 */
	public function test_repairs_missing_owner_row_for_the_current_pointer(): void {
		global $wpdb;

		// Break the membership side only: the pointer still says $this->owner_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_space_members SET role = 'member' WHERE space_id = %d AND user_id = %d",
				$this->space_id,
				$this->owner_id
			)
		);
		$this->members->flush_user_caches( $this->space_id, array( $this->owner_id ) );
		$this->assertSame( 'member', $this->members->get_role( $this->space_id, $this->owner_id ), 'precondition: the owner row is gone' );

		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $this->owner_id, null ) );

		$this->assertSame( 'owner', $this->members->get_role( $this->space_id, $this->owner_id ), 'the missing owner row must be repaired' );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'] );
		$this->assertSame( 1, $this->count_owner_rows(), 'exactly one owner row' );
	}

	/**
	 * A Space carrying TWO role='owner' rows must collapse to exactly one when
	 * assign_owner() names one of them — the primitive is self-healing.
	 *
	 * @return void
	 */
	public function test_collapses_duplicate_owner_rows(): void {
		global $wpdb;

		$stray = self::factory()->user->create();
		$this->members->join( $this->space_id, $stray );

		// A second owner row while the pointer still names $this->owner_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_space_members SET role = 'owner' WHERE space_id = %d AND user_id = %d",
				$this->space_id,
				$stray
			)
		);
		$this->members->flush_user_caches( $this->space_id, array( $stray ) );
		$this->assertSame( 2, $this->count_owner_rows(), 'precondition: two owner rows' );

		$this->assertTrue( $this->spaces->assign_owner( $this->space_id, $this->owner_id, null ) );

		$this->assertSame( 1, $this->count_owner_rows(), 'the stray owner row must be demoted' );
		$this->assertSame( 'owner', $this->members->get_role( $this->space_id, $this->owner_id ) );
		$this->assertSame( 'member', $this->members->get_role( $this->space_id, $stray ) );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'] );
	}

	/**
	 * Count the space's `role = 'owner'` membership rows.
	 *
	 * @return int Number of owner rows for the space under test.
	 */
	private function count_owner_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND role = 'owner'",
				$this->space_id
			)
		);
	}

	/**
	 * A soft-banned member (bn_space_members.status = 'banned') must never be
	 * promoted to owner: the upsert would silently flip them back to 'active'.
	 */
	public function test_soft_banned_member_cannot_be_made_owner(): void {
		$heir = self::factory()->user->create();
		$this->members->join( $this->space_id, $heir );

		// ban() writes BOTH surfaces; drop the hard-ban row so this test isolates
		// the soft ban (status='banned' on the membership row).
		$this->assertTrue( $this->members->ban( $this->space_id, $this->owner_id, $heir ) );
		$this->assertTrue( $this->members->unban_from_space( $this->space_id, $heir ) );
		$this->assertSame( 'banned', $this->members->get_status( $this->space_id, $heir ) );

		$result = $this->spaces->assign_owner( $this->space_id, $heir );

		$this->assertWPError( $result );
		$this->assertSame( 'heir_banned', $result->get_error_code() );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'], 'owner_id must be untouched' );
		$this->assertSame( 'banned', $this->members->get_status( $this->space_id, $heir ), 'a banned member must not be resurrected as active' );
	}

	/**
	 * A hard-banned user (bn_space_bans row, no membership row) must never be
	 * promoted: PermissionService::is_space_banned() would keep hard-denying
	 * every capability while they are nominally the owner.
	 */
	public function test_hard_banned_user_cannot_be_made_owner(): void {
		global $wpdb;
		$heir = self::factory()->user->create();

		// System ban: inserts bn_space_bans and removes any membership row.
		$this->assertTrue( $this->members->ban_from_space( $this->space_id, $heir, 0, 'spam' ) );

		$result = $this->spaces->assign_owner( $this->space_id, $heir, null );

		$this->assertWPError( $result );
		$this->assertSame( 'heir_banned', $result->get_error_code() );
		$this->assertSame( $this->owner_id, (int) $this->spaces->get( $this->space_id )['owner_id'], 'owner_id must be untouched' );

		$rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$this->space_id,
				$heir
			)
		);
		$this->assertSame( 0, $rows, 'a hard-banned user must not be inserted as a member at all' );
	}

	/**
	 * The ownership-transferred event fires exactly once per move.
	 *
	 * @return void
	 */
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
