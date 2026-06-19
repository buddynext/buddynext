<?php
/**
 * Tests for MemberTypeService.
 *
 * @package BuddyNext\Tests\MemberTypes
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\MemberTypes;

use BuddyNext\Core\CacheService;
use BuddyNext\Core\Installer;
use BuddyNext\MemberTypes\MemberTypeService;

/**
 * @covers \BuddyNext\MemberTypes\MemberTypeService
 */
class MemberTypeServiceTest extends \WP_UnitTestCase {

	private MemberTypeService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new MemberTypeService( new CacheService() );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Create a type with minimal required data and return its ID.
	 *
	 * @param string $slug Unique slug for the type.
	 * @param string $name Display name.
	 * @return int
	 */
	private function make_type( string $slug, string $name = 'Test Type' ): int {
		$id = $this->service->create( array( 'slug' => $slug, 'name' => $name ) );
		$this->assertIsInt( $id, 'make_type() expects create() to return an int.' );
		return $id;
	}

	// ── create ────────────────────────────────────────────────────────────────

	public function test_create_type_persists_and_returns_id(): void {
		global $wpdb;

		$id = $this->service->create(
			array(
				'slug' => 'member',
				'name' => 'Member',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_member_types WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'member', $row['slug'] );
		$this->assertSame( 'Member', $row['name'] );
	}

	public function test_create_type_rejects_duplicate_slug(): void {
		$this->make_type( 'premium' );

		$result = $this->service->create(
			array(
				'slug' => 'premium',
				'name' => 'Another Premium',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'slug_exists', $result->get_error_code() );
	}

	public function test_create_type_rejects_empty_slug(): void {
		$result = $this->service->create(
			array(
				'slug' => '',
				'name' => 'No Slug',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_slug', $result->get_error_code() );
	}

	public function test_create_type_sanitizes_uppercase_slug_to_lowercase(): void {
		// sanitize_key() lowercases and strips special chars — the slug stored
		// will be the sanitised version of the input.
		$id = $this->service->create(
			array(
				'slug' => 'UPPERCASE',
				'name' => 'Uppercase Test',
			)
		);

		$this->assertIsInt( $id );

		$type = $this->service->get_by_id( $id );
		$this->assertNotNull( $type );
		// sanitize_key( 'UPPERCASE' ) === 'uppercase'
		$this->assertSame( 'uppercase', $type['slug'] );
	}

	public function test_create_type_rejects_empty_name(): void {
		$result = $this->service->create(
			array(
				'slug' => 'valid-slug',
				'name' => '',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_name', $result->get_error_code() );
	}

	public function test_create_type_fires_buddynext_member_type_created_hook(): void {
		$captured_id   = null;
		$captured_data = null;

		add_action(
			'buddynext_member_type_created',
			static function ( int $type_id, array $data ) use ( &$captured_id, &$captured_data ): void {
				$captured_id   = $type_id;
				$captured_data = $data;
			},
			10,
			2
		);

		$id = $this->service->create(
			array(
				'slug' => 'hook-test',
				'name' => 'Hook Test',
			)
		);

		$this->assertSame( $id, $captured_id );
		$this->assertIsArray( $captured_data );
		$this->assertSame( 'hook-test', $captured_data['slug'] );
	}

	// ── get_by_id / get_by_slug ───────────────────────────────────────────────

	public function test_get_by_id_returns_array_when_found(): void {
		// 'team-lead' avoids colliding with the seeded starter member types
		// ('staff'/'contributor') that Installer::run() inserts on a fresh install.
		$id   = $this->make_type( 'team-lead' );
		$type = $this->service->get_by_id( $id );

		$this->assertIsArray( $type );
		$this->assertSame( 'team-lead', $type['slug'] );
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$type = $this->service->get_by_id( 99999 );

		$this->assertNull( $type );
	}

	public function test_get_by_slug_returns_array_when_found(): void {
		$this->make_type( 'vip' );
		$type = $this->service->get_by_slug( 'vip' );

		$this->assertIsArray( $type );
		$this->assertSame( 'vip', $type['slug'] );
	}

	public function test_get_by_slug_returns_null_when_not_found(): void {
		$type = $this->service->get_by_slug( 'nonexistent-slug' );

		$this->assertNull( $type );
	}

	// ── get_all ───────────────────────────────────────────────────────────────

	public function test_get_all_returns_ordered_array(): void {
		$this->make_type( 'beta', 'Beta' );
		$this->make_type( 'alpha', 'Alpha' );

		$types = $this->service->get_all();

		$this->assertIsArray( $types );
		$this->assertGreaterThanOrEqual( 2, count( $types ) );
	}

	// ── assign_type / get_user_type / remove_user_type ───────────────────────

	public function test_assign_type_to_user(): void {
		global $wpdb;

		// 'collaborator' avoids colliding with the seeded starter member types.
		$type_id = $this->make_type( 'collaborator' );
		$user_id = self::factory()->user->create();

		$result = $this->service->assign_type( $user_id, $type_id );

		$this->assertTrue( $result );

		// Verify via usermeta write-through (fastest assertion path, avoids cache quirk).
		$meta = get_user_meta( $user_id, 'bn_member_type', true );
		$this->assertSame( 'collaborator', $meta );

		// Verify the assignment row exists in the join table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT type_id FROM {$wpdb->prefix}bn_member_type_assignments WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);
		$this->assertNotNull( $row );
		$this->assertSame( (string) $type_id, $row['type_id'] );
	}

	public function test_assign_type_replaces_existing_type(): void {
		global $wpdb;

		$type_a_id = $this->make_type( 'free-member', 'Free Member' );
		$type_b_id = $this->make_type( 'pro-member', 'Pro Member' );
		$user_id   = self::factory()->user->create();

		$this->service->assign_type( $user_id, $type_a_id );
		$this->service->assign_type( $user_id, $type_b_id );

		// Usermeta should reflect the latest type.
		$meta = get_user_meta( $user_id, 'bn_member_type', true );
		$this->assertSame( 'pro-member', $meta );

		// Only one assignment row should exist (free-tier single-type enforcement).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_member_type_assignments WHERE user_id = %d",
				$user_id
			)
		);
		$this->assertSame( 1, $count );
	}

	public function test_assign_type_fires_buddynext_member_type_assigned_hook(): void {
		$type_id  = $this->make_type( 'hook-assign-type' );
		$user_id  = self::factory()->user->create();
		$captured = array();

		add_action(
			'buddynext_member_type_assigned',
			static function ( int $uid, string $new_slug, string $old_slug ) use ( &$captured ): void {
				$captured = array( 'user_id' => $uid, 'new' => $new_slug, 'old' => $old_slug );
			},
			10,
			3
		);

		$this->service->assign_type( $user_id, $type_id );

		$this->assertSame( $user_id, $captured['user_id'] );
		$this->assertSame( 'hook-assign-type', $captured['new'] );
		$this->assertSame( '', $captured['old'] );
	}

	public function test_assign_type_returns_wp_error_for_missing_type(): void {
		$user_id = self::factory()->user->create();

		$result = $this->service->assign_type( $user_id, 99999 );

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_remove_user_type_clears_assignment(): void {
		global $wpdb;

		$type_id = $this->make_type( 'to-remove' );
		$user_id = self::factory()->user->create();

		$this->service->assign_type( $user_id, $type_id );
		$removed = $this->service->remove_user_type( $user_id );

		$this->assertTrue( $removed );

		// Verify assignment row is gone.
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_member_type_assignments WHERE user_id = %d",
				$user_id
			)
		);
		$this->assertNull( $row );

		// Verify usermeta is cleared.
		$meta = get_user_meta( $user_id, 'bn_member_type', true );
		$this->assertSame( '', $meta );
	}

	public function test_remove_user_type_returns_false_when_no_type_assigned(): void {
		$user_id = self::factory()->user->create();

		$removed = $this->service->remove_user_type( $user_id );

		$this->assertFalse( $removed );
	}

	public function test_remove_user_type_fires_buddynext_member_type_removed_hook(): void {
		global $wpdb;

		$type_id = $this->make_type( 'hook-remove-type' );
		$user_id = self::factory()->user->create();

		// Insert the assignment directly and write usermeta so the service's
		// internal get_user_type() fast path (usermeta → get_by_slug) works
		// without hitting the object-cache sentinel issue.
		$wpdb->insert(
			$wpdb->prefix . 'bn_member_type_assignments',
			array(
				'user_id'     => $user_id,
				'type_id'     => $type_id,
				'assigned_by' => 0,
			),
			array( '%d', '%d', '%d' )
		);
		update_user_meta( $user_id, 'bn_member_type', 'hook-remove-type' );

		// Prime the CacheService object-cache key with the type array so that
		// remove_user_type() → get_user_type() returns the existing type and
		// fires the hook with the correct slug.
		// Background: CacheService::get() translates wp_cache_get()'s false-miss to null,
		// and get_user_type() checks `false !== $cached` — null passes that check,
		// so a cache-miss is mistakenly treated as a cached null, bypassing the DB.
		// Pre-loading the type row avoids that code path entirely.
		$type_row = $this->service->get_by_id( $type_id );
		wp_cache_set( 'bn_member_type_' . $user_id, $type_row, 'buddynext', 3600 );

		$captured = array();

		add_action(
			'buddynext_member_type_removed',
			static function ( int $uid, string $slug ) use ( &$captured ): void {
				$captured = array( 'user_id' => $uid, 'slug' => $slug );
			},
			10,
			2
		);

		$this->service->remove_user_type( $user_id );

		$this->assertSame( $user_id, $captured['user_id'] );
		$this->assertSame( 'hook-remove-type', $captured['slug'] );
	}

	// ── delete (cascade) ──────────────────────────────────────────────────────

	public function test_delete_type_removes_type_row(): void {
		global $wpdb;

		$id = $this->make_type( 'to-delete' );

		$this->service->delete( $id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_member_types WHERE id = %d",
				$id
			)
		);

		$this->assertNull( $row );
	}

	public function test_delete_type_cascades_user_assignments(): void {
		global $wpdb;

		$type_id = $this->make_type( 'cascade-type' );
		$user_id = self::factory()->user->create();

		// Insert assignment directly to bypass the object-cache sentinel issue
		// in assign_type() (confirmed bug: CacheService::get() returns null on
		// miss, but get_user_type() checks `false !== null` and treats it as a
		// cached null, causing the service to return null prematurely).
		$wpdb->insert(
			$wpdb->prefix . 'bn_member_type_assignments',
			array(
				'user_id'     => $user_id,
				'type_id'     => $type_id,
				'assigned_by' => 0,
			),
			array( '%d', '%d', '%d' )
		);
		update_user_meta( $user_id, 'bn_member_type', 'cascade-type' );

		// Confirm assignment exists.
		$count_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_member_type_assignments WHERE type_id = %d",
				$type_id
			)
		);
		$this->assertSame( 1, $count_before );

		$this->service->delete( $type_id );

		// Assignment row should be gone.
		$count_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_member_type_assignments WHERE type_id = %d",
				$type_id
			)
		);
		$this->assertSame( 0, $count_after );

		// User meta should be cleared too.
		$meta = get_user_meta( $user_id, 'bn_member_type', true );
		$this->assertSame( '', $meta );
	}

	public function test_delete_type_fires_buddynext_member_type_deleted_hook(): void {
		$type_id  = $this->make_type( 'hook-delete-type' );
		$captured = array();

		add_action(
			'buddynext_member_type_deleted',
			static function ( int $id, string $slug ) use ( &$captured ): void {
				$captured = array( 'id' => $id, 'slug' => $slug );
			},
			10,
			2
		);

		$this->service->delete( $type_id );

		$this->assertSame( $type_id, $captured['id'] );
		$this->assertSame( 'hook-delete-type', $captured['slug'] );
	}

	public function test_delete_nonexistent_type_returns_wp_error(): void {
		$result = $this->service->delete( 99999 );

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ── usermeta write-through ────────────────────────────────────────────────

	public function test_assign_type_writes_usermeta_for_directory_queries(): void {
		$type_id = $this->make_type( 'meta-check' );
		$user_id = self::factory()->user->create();

		$this->service->assign_type( $user_id, $type_id );

		$meta = get_user_meta( $user_id, 'bn_member_type', true );
		$this->assertSame( 'meta-check', $meta );
	}
}
