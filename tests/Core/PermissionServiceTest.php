<?php
/**
 * Tests for the 4-layer permission model.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PermissionService;

/**
 * @covers \BuddyNext\Core\PermissionService
 */
class PermissionServiceTest extends \WP_UnitTestCase {

	private PermissionService $service;
	private int $admin_id;
	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service   = new PermissionService();
		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		update_user_meta( $this->admin_id,  'bn_community_role', 'admin' );
		update_user_meta( $this->member_id, 'bn_community_role', 'member' );
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_user_can' );
		parent::tear_down();
	}

	// Layer 1 — WP site admin bypass.

	public function test_wp_admin_passes_any_check(): void {
		$this->assertTrue( $this->service->can( $this->admin_id, 'buddynext-moderation/suspend-user' ) );
	}

	// Layer 2 — Community role.

	public function test_member_can_create_post(): void {
		$this->assertTrue( $this->service->can( $this->member_id, 'buddynext-feed/create-post' ) );
	}

	public function test_member_cannot_suspend_user(): void {
		$this->assertFalse( $this->service->can( $this->member_id, 'buddynext-moderation/suspend-user' ) );
	}

	public function test_member_cannot_review_moderation_queue(): void {
		$this->assertFalse( $this->service->can( $this->member_id, 'buddynext-moderation/review-queue' ) );
	}

	public function test_null_default_capability_denied_without_grant(): void {
		$this->assertFalse( $this->service->can( $this->member_id, 'buddynext-spaces/join-gated' ) );
	}

	// Layer 3 — Explicit ability grant.

	public function test_granted_ability_unlocks_gated_space_join(): void {
		update_user_meta(
			$this->member_id,
			\BuddyNext\Core\PermissionService::ability_meta_key( 'buddynext-spaces/join-gated' ),
			0 // 0 = never expires
		);

		$this->assertTrue( $this->service->can( $this->member_id, 'buddynext-spaces/join-gated' ) );
	}

	public function test_expired_ability_is_denied(): void {
		update_user_meta(
			$this->member_id,
			\BuddyNext\Core\PermissionService::ability_meta_key( 'buddynext-spaces/join-gated' ),
			(int) strtotime( '2020-01-01 00:00:00' )
		);

		$this->assertFalse( $this->service->can( $this->member_id, 'buddynext-spaces/join-gated' ) );
	}

	// Layer 4 — Developer filter.

	public function test_filter_can_deny_any_capability(): void {
		add_filter( 'buddynext_user_can', '__return_false', 10, 4 );
		$this->assertFalse( $this->service->can( $this->member_id, 'buddynext-feed/create-post' ) );
	}

	public function test_filter_can_grant_any_capability(): void {
		add_filter( 'buddynext_user_can', '__return_true', 10, 4 );
		$this->assertTrue( $this->service->can( $this->member_id, 'buddynext-moderation/suspend-user' ) );
	}
}
