<?php
/**
 * Tests for SpaceMemberService.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService
 */
class SpaceMemberServiceTest extends \WP_UnitTestCase {

	private SpaceMemberService $service;
	private SpaceService $spaces;
	private int $owner_id;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces   = new SpaceService();
		$this->service  = new SpaceMemberService();
		$this->owner_id = self::factory()->user->create();
		$this->space_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Test Space',
				'slug' => 'test-space-members',
				'type' => 'open',
			)
		);
	}

	public function test_join_open_space(): void {
		$user_id = self::factory()->user->create();

		$result = $this->service->join( $this->space_id, $user_id );

		$this->assertTrue( $result );
		$this->assertTrue( $this->service->is_member( $this->space_id, $user_id ) );
	}

	public function test_duplicate_join_is_safe(): void {
		$user_id = self::factory()->user->create();

		$this->service->join( $this->space_id, $user_id );
		$result = $this->service->join( $this->space_id, $user_id );

		$this->assertTrue( $result );
		$this->assertSame( 1, $this->service->member_count( $this->space_id ) - 1 ); // -1 for owner
	}

	public function test_leave_removes_member(): void {
		$user_id = self::factory()->user->create();

		$this->service->join( $this->space_id, $user_id );
		$this->service->leave( $this->space_id, $user_id );

		$this->assertFalse( $this->service->is_member( $this->space_id, $user_id ) );
	}

	public function test_owner_cannot_leave(): void {
		$result = $this->service->leave( $this->space_id, $this->owner_id );

		$this->assertWPError( $result );
		$this->assertSame( 'owner_cannot_leave', $result->get_error_code() );
	}

	public function test_get_role_returns_correct_value(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$role = $this->service->get_role( $this->space_id, $user_id );

		$this->assertSame( 'member', $role );
	}

	public function test_get_role_returns_owner_for_creator(): void {
		$role = $this->service->get_role( $this->space_id, $this->owner_id );

		$this->assertSame( 'owner', $role );
	}

	public function test_change_role_by_owner(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$result = $this->service->change_role( $this->space_id, $user_id, 'moderator', $this->owner_id );

		$this->assertTrue( $result );
		$this->assertSame( 'moderator', $this->service->get_role( $this->space_id, $user_id ) );
	}

	public function test_change_role_by_non_owner_returns_error(): void {
		$user_id    = self::factory()->user->create();
		$other_user = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );
		$this->service->join( $this->space_id, $other_user );

		$result = $this->service->change_role( $this->space_id, $user_id, 'moderator', $other_user );

		$this->assertWPError( $result );
	}

	public function test_member_count_increments_on_join(): void {
		$initial = $this->service->member_count( $this->space_id );
		$user_id = self::factory()->user->create();

		$this->service->join( $this->space_id, $user_id );

		$this->assertSame( $initial + 1, $this->service->member_count( $this->space_id ) );
	}

	public function test_member_count_decrements_on_leave(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );
		$after_join = $this->service->member_count( $this->space_id );

		$this->service->leave( $this->space_id, $user_id );

		$this->assertSame( $after_join - 1, $this->service->member_count( $this->space_id ) );
	}

	public function test_get_members_returns_list(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$members = $this->service->get_members( $this->space_id );

		$user_ids = array_column( $members, 'user_id' );
		$this->assertContains( $user_id, $user_ids );
		$this->assertContains( $this->owner_id, $user_ids );
	}

	// ── request_join ────────────────────────────────────────────────────────

	public function test_request_join_creates_pending_status(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Space',
				'slug' => 'private-space-req',
				'type' => 'private',
			)
		);

		$this->service->request_join( $private_id, $user_id );

		$this->assertSame( 'pending', $this->service->get_status( $private_id, $user_id ) );
		$this->assertFalse( $this->service->is_member( $private_id, $user_id ) );
	}

	public function test_request_join_is_idempotent(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Idempotent',
				'slug' => 'private-idempotent',
				'type' => 'private',
			)
		);

		$this->service->request_join( $private_id, $user_id );
		$result = $this->service->request_join( $private_id, $user_id );

		$this->assertTrue( $result );
	}

	// ── invite ──────────────────────────────────────────────────────────────

	public function test_invite_creates_invited_status(): void {
		$user_id    = self::factory()->user->create();
		$secret_id  = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Secret Space',
				'slug' => 'secret-space-invite',
				'type' => 'secret',
			)
		);

		$result = $this->service->invite( $secret_id, $this->owner_id, $user_id );

		$this->assertTrue( $result );
		$this->assertSame( 'invited', $this->service->get_status( $secret_id, $user_id ) );
	}

	public function test_invite_by_non_mod_returns_error(): void {
		$user_a    = self::factory()->user->create();
		$user_b    = self::factory()->user->create();
		$secret_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Secret Perm',
				'slug' => 'secret-perm-invite',
				'type' => 'secret',
			)
		);
		$this->service->join( $secret_id, $user_a ); // force join for test.

		$result = $this->service->invite( $secret_id, $user_a, $user_b );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	// ── approve_request ─────────────────────────────────────────────────────

	public function test_approve_request_makes_member_active(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Approve',
				'slug' => 'private-approve',
				'type' => 'private',
			)
		);
		$this->service->request_join( $private_id, $user_id );

		$result = $this->service->approve_request( $private_id, $this->owner_id, $user_id );

		$this->assertTrue( $result );
		$this->assertTrue( $this->service->is_member( $private_id, $user_id ) );
		$this->assertSame( 'active', $this->service->get_status( $private_id, $user_id ) );
	}

	public function test_approve_request_no_pending_returns_error(): void {
		$user_id = self::factory()->user->create();

		$result = $this->service->approve_request( $this->space_id, $this->owner_id, $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'no_pending_request', $result->get_error_code() );
	}

	// ── ban ─────────────────────────────────────────────────────────────────

	public function test_ban_removes_from_active_members(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$this->service->ban( $this->space_id, $this->owner_id, $user_id );

		$this->assertFalse( $this->service->is_member( $this->space_id, $user_id ) );
		$this->assertSame( 'banned', $this->service->get_status( $this->space_id, $user_id ) );
	}

	public function test_banned_user_cannot_rejoin(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );
		$this->service->ban( $this->space_id, $this->owner_id, $user_id );

		$result = $this->service->join( $this->space_id, $user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'user_banned', $result->get_error_code() );
	}

	public function test_ban_owner_returns_error(): void {
		$result = $this->service->ban( $this->space_id, $this->owner_id, $this->owner_id );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_ban_owner', $result->get_error_code() );
	}

	// ── get_pending_requests ─────────────────────────────────────────────────

	public function test_get_pending_requests_returns_pending_users(): void {
		$user_a     = self::factory()->user->create();
		$user_b     = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Pending',
				'slug' => 'private-pending-list',
				'type' => 'private',
			)
		);
		$this->service->request_join( $private_id, $user_a );
		$this->service->request_join( $private_id, $user_b );

		$requests = $this->service->get_pending_requests( $private_id );

		$user_ids = array_column( $requests, 'user_id' );
		$this->assertContains( $user_a, $user_ids );
		$this->assertContains( $user_b, $user_ids );
	}

	// ── get_status ───────────────────────────────────────────────────────────

	public function test_get_status_returns_null_for_unknown_user(): void {
		$user_id = self::factory()->user->create();

		$status = $this->service->get_status( $this->space_id, $user_id );

		$this->assertNull( $status );
	}

	public function test_get_status_active_after_join(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$status = $this->service->get_status( $this->space_id, $user_id );

		$this->assertSame( 'active', $status );
	}

	// ── hook contract tests ──────────────────────────────────────────────────

	public function test_join_fires_buddynext_space_member_joined(): void {
		$captured = null;
		$user_id  = self::factory()->user->create();
		add_action(
			'buddynext_space_member_joined',
			function ( int $space_id, int $user_id, string $role ) use ( &$captured ): void {
				$captured = array( $space_id, $user_id, $role );
			},
			10,
			3
		);

		$this->service->join( $this->space_id, $user_id );

		$this->assertSame( array( $this->space_id, $user_id, 'member' ), $captured );
	}

	public function test_leave_fires_buddynext_space_member_left(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$captured = null;
		add_action(
			'buddynext_space_member_left',
			function ( int $space_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $space_id, $user_id );
			},
			10,
			2
		);

		$this->service->leave( $this->space_id, $user_id );

		$this->assertSame( array( $this->space_id, $user_id ), $captured );
	}

	public function test_ban_fires_buddynext_space_member_removed(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$captured = null;
		add_action(
			'buddynext_space_member_removed',
			function ( int $space_id, int $user_id, int $by_user_id ) use ( &$captured ): void {
				$captured = array( $space_id, $user_id, $by_user_id );
			},
			10,
			3
		);

		$this->service->ban( $this->space_id, $this->owner_id, $user_id );

		$this->assertSame( array( $this->space_id, $user_id, $this->owner_id ), $captured );
	}

	public function test_request_join_fires_buddynext_space_join_requested(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Hook Test',
				'slug' => 'private-hook-test',
				'type' => 'private',
			)
		);

		$captured = null;
		add_action(
			'buddynext_space_join_requested',
			function ( int $space_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $space_id, $user_id );
			},
			10,
			2
		);

		$this->service->request_join( $private_id, $user_id );

		$this->assertSame( array( $private_id, $user_id ), $captured );
	}

	public function test_approve_request_fires_buddynext_space_join_approved(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Private Approve Hook',
				'slug' => 'private-approve-hook',
				'type' => 'private',
			)
		);
		$this->service->request_join( $private_id, $user_id );

		$captured = null;
		add_action(
			'buddynext_space_join_approved',
			function ( int $space_id, int $user_id, int $by_user_id ) use ( &$captured ): void {
				$captured = array( $space_id, $user_id, $by_user_id );
			},
			10,
			3
		);

		$this->service->approve_request( $private_id, $this->owner_id, $user_id );

		$this->assertSame( array( $private_id, $user_id, $this->owner_id ), $captured );
	}

	/* ── Surface 2 + 3: notification pref + cache invalidation ────── */

	public function test_set_notification_pref_writes_and_invalidates_cache(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		// Warm the role cache.
		$this->service->get_role( $this->space_id, $user_id );

		$result = $this->service->set_notification_pref( $this->space_id, $user_id, 'mentions' );
		$this->assertTrue( $result );

		$this->assertSame( 'mentions', $this->service->get_notification_pref( $this->space_id, $user_id ) );

		$cache_key = "role_{$this->space_id}_{$user_id}";
		$this->assertFalse( wp_cache_get( $cache_key, 'buddynext_space_members' ) );
	}

	public function test_set_notification_pref_rejects_invalid_value(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$result = $this->service->set_notification_pref( $this->space_id, $user_id, 'turbo' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_pref', $result->get_error_code() );
	}

	public function test_set_notification_pref_rejects_non_member(): void {
		$user_id = self::factory()->user->create();
		$result  = $this->service->set_notification_pref( $this->space_id, $user_id, 'all' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_a_member', $result->get_error_code() );
	}

	public function test_cancel_request_removes_pending_row_and_invalidates_cache(): void {
		$user_id    = self::factory()->user->create();
		$private_id = $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Cancel Space',
				'slug' => 'cancel-request-space',
				'type' => 'private',
			)
		);

		$this->service->request_join( $private_id, $user_id );
		$this->assertSame( 'pending', $this->service->get_status( $private_id, $user_id ) );

		$this->service->get_status( $private_id, $user_id );

		$result = $this->service->cancel_request( $private_id, $user_id );
		$this->assertTrue( $result );

		$status_key = "status_{$private_id}_{$user_id}";
		$this->assertFalse( wp_cache_get( $status_key, 'buddynext_space_members' ) );

		$this->assertNull( $this->service->get_status( $private_id, $user_id ) );
	}

	public function test_change_role_invalidates_member_caches(): void {
		$user_id = self::factory()->user->create();
		$this->service->join( $this->space_id, $user_id );

		$this->service->get_role( $this->space_id, $user_id );

		$this->service->change_role( $this->space_id, $user_id, 'moderator', $this->owner_id );

		$role_key = "role_{$this->space_id}_{$user_id}";
		$this->assertFalse( wp_cache_get( $role_key, 'buddynext_space_members' ) );
		$this->assertSame( 'moderator', $this->service->get_role( $this->space_id, $user_id ) );
	}
}
