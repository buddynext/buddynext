<?php
/**
 * A suspended member is denied every write capability at the single permission
 * seam (buddynext_can -> PermissionService::can), so the UI (which reads the same
 * seam to decide whether to render a control) and the services (which read it to
 * allow the action) cannot disagree. Reads and admins are unaffected.
 *
 * This is the class-preventing gate for card 10264293681: it fails if any future
 * change lets a suspended member keep a write ability, or traps a suspended
 * admin, or blocks reads. Every write-affordance surface that flows through
 * buddynext_can is covered here in one place.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @covers \BuddyNext\Core\PermissionService::can
 */
class PermissionSuspensionTest extends \WP_UnitTestCase {

	/**
	 * Representative write capabilities a member holds by default (each mapped to
	 * the 'member' role in PermissionService::ROLE_MAP). A suspended member must
	 * lose all of them.
	 *
	 * @var string[]
	 */
	private const WRITE_CAPS = array(
		'buddynext-feed/create-post',
		'buddynext-comments/create',
		'buddynext-connections/follow',
		'buddynext-connections/connect',
		'buddynext-spaces/create',
		'buddynext-spaces/join',
		'buddynext-moderation/report',
		'buddynext-profile/edit-own',
	);

	/**
	 * Suspend a user through the moderation service (the data-access owner).
	 *
	 * @param int $user_id Member.
	 * @param int $actor   Actor performing the suspension.
	 * @return void
	 */
	private function suspend( int $user_id, int $actor ): void {
		buddynext_service( 'moderation' )->suspend_user( $user_id, $actor, 'phpunit' );
	}

	/**
	 * A member holds the write caps; suspension withdraws every one; lifting the
	 * suspension restores them.
	 *
	 * @return void
	 */
	public function test_suspension_withdraws_then_restores_write_caps(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( self::WRITE_CAPS as $cap ) {
			$this->assertTrue( buddynext_can( $member, $cap ), "member should hold {$cap} before suspension" );
		}

		$this->suspend( $member, 1 );

		foreach ( self::WRITE_CAPS as $cap ) {
			$this->assertFalse( buddynext_can( $member, $cap ), "suspended member must not hold {$cap}" );
		}

		buddynext_service( 'moderation' )->unsuspend_user( $member, 1 );

		foreach ( self::WRITE_CAPS as $cap ) {
			$this->assertTrue( buddynext_can( $member, $cap ), "member should regain {$cap} after unsuspend" );
		}
	}

	/**
	 * A suspended administrator is never trapped out of their own site.
	 *
	 * @return void
	 */
	public function test_suspension_never_traps_an_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->suspend( $admin, 1 );

		foreach ( self::WRITE_CAPS as $cap ) {
			$this->assertTrue( buddynext_can( $admin, $cap ), "admin must keep {$cap} even while suspended" );
		}
	}

	/**
	 * Suspension withdraws writes but not read access: the profile-view read is
	 * not made more restrictive by suspension (it resolves the same either way).
	 *
	 * @return void
	 */
	public function test_suspension_does_not_change_reads(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$before = buddynext_can( $member, 'buddynext-profile/view' );
		$this->suspend( $member, 1 );
		$after = buddynext_can( $member, 'buddynext-profile/view' );

		$this->assertSame( $before, $after, 'a read capability must not change under suspension' );
	}
}
