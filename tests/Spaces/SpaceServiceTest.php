<?php
/**
 * Tests for SpaceService.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Spaces\SpaceService
 */
class SpaceServiceTest extends \WP_UnitTestCase {

	private SpaceService $service;
	private int $owner_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service  = new SpaceService();
		$this->owner_id = self::factory()->user->create();
	}

	public function test_create_space_returns_id(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Test Space',
				'slug' => 'test-space',
				'type' => 'open',
			)
		);

		$this->assertIsInt( $space_id );
		$this->assertGreaterThan( 0, $space_id );
	}

	public function test_create_space_is_not_wp_error(): void {
		$result = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'My Space',
				'slug' => 'my-space',
				'type' => 'open',
			)
		);

		$this->assertNotWPError( $result );
	}

	public function test_create_duplicate_slug_returns_error(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Space A',
				'slug' => 'duplicate-slug',
				'type' => 'open',
			)
		);

		$result = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Space B',
				'slug' => 'duplicate-slug',
				'type' => 'open',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'slug_taken', $result->get_error_code() );
	}

	public function test_get_returns_space(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Get Space',
				'slug' => 'get-space',
				'type' => 'open',
			)
		);

		$space = $this->service->get( $space_id );

		$this->assertNotNull( $space );
		$this->assertSame( $space_id, $space['id'] );
		$this->assertSame( 'Get Space', $space['name'] );
		$this->assertSame( 'get-space', $space['slug'] );
	}

	public function test_get_returns_null_for_missing_space(): void {
		$space = $this->service->get( 999999 );

		$this->assertNull( $space );
	}

	public function test_update_changes_name(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Old Name',
				'slug' => 'old-name-space',
				'type' => 'open',
			)
		);

		$result = $this->service->update( $space_id, $this->owner_id, array( 'name' => 'New Name' ) );

		$this->assertTrue( $result );

		$space = $this->service->get( $space_id );
		$this->assertSame( 'New Name', $space['name'] );
	}

	public function test_update_by_non_owner_returns_error(): void {
		$space_id  = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Owner Space',
				'slug' => 'owner-space',
				'type' => 'open',
			)
		);
		$other_user = self::factory()->user->create();

		$result = $this->service->update( $space_id, $other_user, array( 'name' => 'Hacked' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_delete_removes_space(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Delete Me',
				'slug' => 'delete-me',
				'type' => 'open',
			)
		);

		$this->service->delete( $space_id, $this->owner_id );

		$this->assertNull( $this->service->get( $space_id ) );
	}

	public function test_delete_by_non_owner_returns_error(): void {
		$space_id  = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Protected Space',
				'slug' => 'protected-space',
				'type' => 'open',
			)
		);
		$other_user = self::factory()->user->create();

		$result = $this->service->delete( $space_id, $other_user );

		$this->assertWPError( $result );
	}

	public function test_create_fires_action(): void {
		$fired = false;
		add_action(
			'buddynext_space_created',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Action Space',
				'slug' => 'action-space',
				'type' => 'open',
			)
		);

		$this->assertTrue( $fired );
	}

	public function test_create_auto_adds_owner_as_member(): void {
		global $wpdb;

		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Member Space',
				'slug' => 'member-space',
				'type' => 'open',
			)
		);

		$role = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$space_id,
				$this->owner_id
			)
		);

		$this->assertSame( 'owner', $role );
	}
}
