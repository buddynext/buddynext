<?php
/**
 * Space-scoped permission tests for the cached role/ban delegation.
 *
 * PermissionService routes its in-space role + soft-ban lookups through
 * SpaceMemberService's object cache (so the many permission checks on a page
 * collapse onto cached reads), while the hard-ban (bn_space_bans) stays a direct
 * query. These tests verify the security-relevant outcomes are unchanged AND
 * that a membership write busts the cache so no stale role survives.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PermissionService;
use WP_UnitTestCase;

/**
 * Verifies the cached space-role / ban delegation and its invalidation.
 *
 * @covers \BuddyNext\Core\PermissionService
 */
class PermissionServiceCacheTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var PermissionService
	 */
	private PermissionService $service;

	/**
	 * Space ID used across the cases.
	 *
	 * @var int
	 */
	private int $space_id = 4242;

	/**
	 * Set up a clean service and clear the seeded rows + caches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new PermissionService();
		$this->clear_rows();
		wp_cache_flush();
	}

	/**
	 * Remove seeded membership / ban rows after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clear_rows();
		parent::tearDown();
	}

	/**
	 * A moderator (resolved via the cached delegation) can moderate the space;
	 * a plain member cannot.
	 *
	 * @return void
	 */
	public function test_in_space_role_gates_moderate(): void {
		$moderator = self::factory()->user->create();
		$member    = self::factory()->user->create();
		$this->seed_member( $moderator, 'moderator', 'active' );
		$this->seed_member( $member, 'member', 'active' );

		$ctx = array( 'space_id' => $this->space_id );
		$this->assertTrue( $this->service->can( $moderator, 'buddynext-moderate-space', $ctx ), 'moderator may moderate' );
		$this->assertFalse( $this->service->can( $member, 'buddynext-moderate-space', $ctx ), 'member may not moderate' );
	}

	/**
	 * A hard ban (bn_space_bans) denies any space action, even for an active member.
	 *
	 * @return void
	 */
	public function test_hard_ban_denies_space_action(): void {
		$user = self::factory()->user->create();
		$this->seed_member( $user, 'member', 'active' );
		$this->seed_hard_ban( $user );

		$this->assertFalse(
			$this->service->can( $user, 'buddynext-spaces/post', array( 'space_id' => $this->space_id ) ),
			'hard-banned user is denied a space action'
		);
	}

	/**
	 * A soft ban (member status='banned', read via the cached get_status) denies
	 * a space action.
	 *
	 * @return void
	 */
	public function test_soft_ban_denies_space_action(): void {
		$user = self::factory()->user->create();
		$this->seed_member( $user, 'member', 'banned' );

		$this->assertFalse(
			$this->service->can( $user, 'buddynext-spaces/post', array( 'space_id' => $this->space_id ) ),
			'soft-banned user is denied a space action'
		);
	}

	/**
	 * The role read is cached, and a membership write busts it — so a promotion
	 * is reflected on the next check (no stale role survives).
	 *
	 * @return void
	 */
	public function test_role_change_busts_cache(): void {
		$user = self::factory()->user->create();
		$this->seed_member( $user, 'member', 'active' );

		$ctx = array( 'space_id' => $this->space_id );
		// Prime the cache as a plain member: cannot moderate.
		$this->assertFalse( $this->service->can( $user, 'buddynext-moderate-space', $ctx ) );

		// Promote directly in the DB. Without invalidation the cached 'member'
		// would still be returned (proving the read is genuinely cached).
		$this->update_role( $user, 'moderator' );
		$this->assertFalse(
			$this->service->can( $user, 'buddynext-moderate-space', $ctx ),
			'cached role is still in effect before invalidation'
		);

		// The documented write path busts the per-user cache.
		buddynext_service( 'space_members' )->flush_user_caches( $this->space_id, array( $user ) );
		$this->assertTrue(
			$this->service->can( $user, 'buddynext-moderate-space', $ctx ),
			'after the cache bust the fresh moderator role is honoured'
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────────

	/**
	 * Insert a membership row.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param string $status  Status.
	 * @return void
	 */
	private function seed_member( int $user_id, string $role, string $status ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $this->space_id,
				'user_id'  => $user_id,
				'role'     => $role,
				'status'   => $status,
			),
			array( '%d', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Update a member's role directly (bypassing the cache bust).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    New role.
	 * @return void
	 */
	private function update_role( int $user_id, string $role ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => $role ),
			array(
				'space_id' => $this->space_id,
				'user_id'  => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Insert a hard-ban row.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private function seed_hard_ban( int $user_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id'  => $this->space_id,
				'user_id'   => $user_id,
				'banned_by' => 0,
				'reason'    => '',
			),
			array( '%d', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete the rows seeded for the test space.
	 *
	 * @return void
	 */
	private function clear_rows(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d", $this->space_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bn_space_bans WHERE space_id = %d", $this->space_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
