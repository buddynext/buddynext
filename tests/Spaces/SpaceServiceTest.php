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

	public function test_list_spaces_returns_array(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Open A',
				'slug' => 'open-a-list',
				'type' => 'open',
			)
		);
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Open B',
				'slug' => 'open-b-list',
				'type' => 'open',
			)
		);

		$spaces = $this->service->list_spaces();

		$this->assertIsArray( $spaces );
		$this->assertNotEmpty( $spaces );
	}

	public function test_list_spaces_excludes_secret_by_default(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Secret One',
				'slug' => 'secret-one-list',
				'type' => 'secret',
			)
		);

		$spaces = $this->service->list_spaces();

		$types = array_column( $spaces, 'type' );
		$this->assertNotContains( 'secret', $types );
	}

	public function test_list_spaces_type_filter(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Private Type Filter',
				'slug' => 'private-type-filter',
				'type' => 'private',
			)
		);

		$spaces = $this->service->list_spaces( array( 'type' => 'private' ) );

		$types = array_column( $spaces, 'type' );
		foreach ( $types as $t ) {
			$this->assertSame( 'private', $t );
		}
	}

	public function test_search_finds_by_name(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Photography Open',
				'slug' => 'photography-open-srch',
				'type' => 'open',
			)
		);

		$results = $this->service->search( 'Photography' );

		$names = array_column( $results, 'name' );
		$this->assertContains( 'Photography Open', $names );
	}

	public function test_search_excludes_secret_spaces(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Photography Secret',
				'slug' => 'photography-secret-srch',
				'type' => 'secret',
			)
		);

		$results = $this->service->search( 'Photography Secret' );

		$types = array_column( $results, 'type' );
		$this->assertNotContains( 'secret', $types );
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
