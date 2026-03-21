<?php
/**
 * Tests for MemberDirectoryService.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\MemberDirectoryService;
use BuddyNext\SocialGraph\FollowService;

/**
 * @covers \BuddyNext\Search\MemberDirectoryService
 */
class MemberDirectoryServiceTest extends \WP_UnitTestCase {

	private MemberDirectoryService $service;
	private int $viewer_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service   = new MemberDirectoryService( new FollowService() );
		$this->viewer_id = self::factory()->user->create();
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
}
