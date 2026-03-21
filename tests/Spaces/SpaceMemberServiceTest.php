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
}
