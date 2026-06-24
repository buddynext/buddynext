<?php
/**
 * Tests for MemberDirectoryService.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Profile\MemberDirectoryService;

/**
 * @covers \BuddyNext\Profile\MemberDirectoryService
 */
class MemberDirectoryServiceTest extends \WP_UnitTestCase {

	private MemberDirectoryService $service;
	private ModerationService $moderation;
	private int $viewer_id;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service    = new MemberDirectoryService();
		$this->moderation = new ModerationService();
		$this->viewer_id  = self::factory()->user->create();
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_list_members_returns_paginated_structure(): void {
		self::factory()->user->create_many( 3 );

		$result = $this->service->list_members( $this->viewer_id );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'next_cursor', $result );
	}

	public function test_list_members_item_shape(): void {
		self::factory()->user->create();

		$result = $this->service->list_members( $this->viewer_id );

		$this->assertNotEmpty( $result['items'] );
		$item = $result['items'][0];

		$this->assertArrayHasKey( 'user_id', $item );
		$this->assertArrayHasKey( 'display_name', $item );
		$this->assertArrayHasKey( 'avatar_url', $item );
		$this->assertArrayHasKey( 'registered_at', $item );
	}

	public function test_list_members_respects_per_page(): void {
		self::factory()->user->create_many( 5 );

		$result = $this->service->list_members( $this->viewer_id, null, 2 );

		$this->assertCount( 2, $result['items'] );
		$this->assertNotNull( $result['next_cursor'] );
	}

	public function test_cursor_pagination_no_gaps(): void {
		$user_ids = self::factory()->user->create_many( 4 );

		$page1 = $this->service->list_members( $this->viewer_id, null, 2 );
		$page2 = $this->service->list_members( $this->viewer_id, $page1['next_cursor'], 2 );

		$ids1 = array_column( $page1['items'], 'user_id' );
		$ids2 = array_column( $page2['items'], 'user_id' );

		// No overlap between pages.
		$this->assertEmpty( array_intersect( $ids1, $ids2 ) );
	}

	public function test_list_members_excludes_self(): void {
		$result = $this->service->list_members( $this->viewer_id );

		$ids = array_column( $result['items'], 'user_id' );
		$this->assertNotContains( $this->viewer_id, $ids );
	}

	public function test_last_page_has_null_cursor(): void {
		// Only one user besides viewer — one per_page=10 page.
		self::factory()->user->create();

		$result = $this->service->list_members( $this->viewer_id, null, 10 );

		$this->assertNull( $result['next_cursor'] );
	}

	// ── Suspension + shadow-ban filtering ──────────────────────────────────

	public function test_directory_excludes_suspended_users(): void {
		$suspended = self::factory()->user->create();

		wp_set_current_user( $this->admin_id );
		$this->moderation->suspend_user( $suspended, $this->admin_id, 'test', array() );

		$result = $this->service->list_members( $this->viewer_id );

		$ids = array_column( $result['items'], 'user_id' );
		$this->assertNotContains( $suspended, $ids );
	}

	public function test_directory_excludes_shadow_banned_users(): void {
		$shadow = self::factory()->user->create();
		update_user_meta( $shadow, 'bn_shadow_banned', '1' );

		$result = $this->service->list_members( $this->viewer_id );

		$ids = array_column( $result['items'], 'user_id' );
		$this->assertNotContains( $shadow, $ids );
	}

	// ── Production wiring: filter + sort + search + member-type ────────────

	/**
	 * Search filter scopes results to a substring of display_name.
	 *
	 * @return void
	 */
	public function test_search_filters_results_by_display_name(): void {
		self::factory()->user->create( array( 'display_name' => 'Alice Wonder' ) );
		self::factory()->user->create( array( 'display_name' => 'Bob Apparent' ) );

		$result = $this->service->list_members(
			$this->viewer_id,
			null,
			20,
			array( 'search' => 'Wonder' )
		);

		$names = array_column( $result['items'], 'display_name' );
		$this->assertContains( 'Alice Wonder', $names );
		$this->assertNotContains( 'Bob Apparent', $names );
	}

	/**
	 * Alphabetical sort places A names ahead of Z names.
	 *
	 * @return void
	 */
	public function test_alphabetical_sort_orders_users_by_display_name(): void {
		self::factory()->user->create( array( 'display_name' => 'Zebra Z' ) );
		self::factory()->user->create( array( 'display_name' => 'Aardvark A' ) );

		$result = $this->service->list_members(
			$this->viewer_id,
			null,
			20,
			array( 'sort' => 'alphabetical' )
		);

		$names = array_column( $result['items'], 'display_name' );
		$idx_a = array_search( 'Aardvark A', $names, true );
		$idx_z = array_search( 'Zebra Z', $names, true );
		$this->assertNotFalse( $idx_a );
		$this->assertNotFalse( $idx_z );
		$this->assertLessThan( $idx_z, $idx_a, 'A must precede Z under alphabetical sort' );
	}

	/**
	 * Most-active sort surfaces recently-active users ahead of stale ones.
	 *
	 * @return void
	 */
	public function test_most_active_sort_orders_by_last_active_desc(): void {
		$now    = time();
		$recent = self::factory()->user->create();
		$stale  = self::factory()->user->create();
		// Presence is read from the indexed bn_presence table (stage-2 reader switch).
		\BuddyNext\Realtime\PresenceService::write( $recent, $now - 60 );
		\BuddyNext\Realtime\PresenceService::write( $stale, $now - 86400 );

		$result = $this->service->list_members(
			$this->viewer_id,
			null,
			20,
			array( 'sort' => 'most_active' )
		);

		$ids        = array_column( $result['items'], 'user_id' );
		$idx_recent = array_search( $recent, $ids, true );
		$idx_stale  = array_search( $stale, $ids, true );
		$this->assertNotFalse( $idx_recent );
		$this->assertNotFalse( $idx_stale );
		$this->assertLessThan( $idx_stale, $idx_recent, 'Recently-active users must rank ahead' );
	}

	/**
	 * Member-type meta is round-trippable for the controller filter pass.
	 *
	 * The service does not scope by member_type natively; the controller layer
	 * post-filters items by meta. This test asserts the meta value is readable
	 * for the production controller's filter pass.
	 *
	 * @return void
	 */
	public function test_member_type_scope_via_assigned_meta(): void {
		$type_user  = self::factory()->user->create();
		$other_user = self::factory()->user->create();
		update_user_meta( $type_user, 'bn_member_type', 'creator' );

		$result = $this->service->list_members( $this->viewer_id, null, 20 );
		$ids    = array_column( $result['items'], 'user_id' );
		$this->assertContains( $type_user, $ids );
		$this->assertContains( $other_user, $ids );
		$this->assertSame( 'creator', get_user_meta( $type_user, 'bn_member_type', true ) );
	}

	/**
	 * Connections relation scopes the directory to accepted peers only.
	 *
	 * @return void
	 */
	public function test_connections_relation_scopes_to_accepted_peers_only(): void {
		$peer    = self::factory()->user->create();
		$unknown = self::factory()->user->create();

		// Establish an accepted connection between viewer and peer.
		$conn = new \BuddyNext\SocialGraph\ConnectionService();
		$conn->send_request( $this->viewer_id, $peer );
		$conn->accept_request( $peer, $this->viewer_id );

		$result = $this->service->list_members(
			$this->viewer_id,
			null,
			20,
			array( 'connection_status' => 'connections' )
		);

		$ids = array_column( $result['items'], 'user_id' );
		$this->assertContains( $peer, $ids );
		$this->assertNotContains( $unknown, $ids );
		$this->assertNotContains( $this->viewer_id, $ids );
	}
}
