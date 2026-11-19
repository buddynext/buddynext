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
		$space_id   = $this->service->create(
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

	public function test_delete_cleans_up_per_space_options(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Optionful',
				'slug' => 'optionful',
				'type' => 'open',
			)
		);

		// Core keeps space settings in bn_space_meta now; an integration that still
		// stores its own per-space option registers its suffix via this filter so
		// delete() clears it. Verify that cleanup path leaves nothing orphaned.
		add_filter(
			'buddynext_space_option_suffixes',
			static fn( array $s ): array => array_merge( $s, array( 'who_can_post', 'require_join_approval' ) )
		);
		update_option( "bn_space_{$space_id}_who_can_post", 'members' );
		update_option( "bn_space_{$space_id}_require_join_approval", '1' );

		$this->service->delete( $space_id, $this->owner_id );

		$this->assertFalse( get_option( "bn_space_{$space_id}_who_can_post" ) );
		$this->assertFalse( get_option( "bn_space_{$space_id}_require_join_approval" ) );
	}

	public function test_delete_clears_the_space_meta_cache(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Cached',
				'slug' => 'cached',
				'type' => 'open',
			)
		);
		update_metadata( 'bn_space', $space_id, 'bn_test_key', 'kept' );
		get_metadata( 'bn_space', $space_id ); // Prime the meta cache.

		$this->service->delete( $space_id, $this->owner_id );

		$this->assertFalse( wp_cache_get( $space_id, 'bn_space_meta' ), 'A deleted space leaves no cached meta behind.' );
	}

	public function test_delete_by_non_owner_returns_error(): void {
		$space_id   = $this->service->create(
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

	/* ── type_label() ───────────────────────────────────────────────── */

	public function test_type_label_open_returns_open_string(): void {
		$this->assertSame( 'Open', SpaceService::type_label( 'open' ) );
	}

	public function test_type_label_private_returns_private_string(): void {
		$this->assertSame( 'Private', SpaceService::type_label( 'private' ) );
	}

	public function test_type_label_secret_returns_secret_string(): void {
		$this->assertSame( 'Secret', SpaceService::type_label( 'secret' ) );
	}

	public function test_type_label_unknown_falls_back_to_open(): void {
		$this->assertSame( 'Open', SpaceService::type_label( 'bogus' ) );
		$this->assertSame( 'Open', SpaceService::type_label( '' ) );
	}

	public function test_type_label_never_returns_public_legacy_string(): void {
		// Wave-3 sweep: 'Public' was the legacy label. Ensure no caller can
		// re-introduce it through type_label().
		foreach ( array( 'open', 'private', 'secret', 'bogus', '' ) as $type ) {
			$this->assertNotSame( 'Public', SpaceService::type_label( $type ) );
		}
	}

	public function test_type_constants_match_db_enum_values(): void {
		$this->assertSame( 'open', SpaceService::TYPE_OPEN );
		$this->assertSame( 'private', SpaceService::TYPE_PRIVATE );
		$this->assertSame( 'secret', SpaceService::TYPE_SECRET );
	}

	/* ── get_by_slug() ──────────────────────────────────────────────── */

	public function test_get_by_slug_returns_space_when_found(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Slug Lookup Space',
				'slug' => 'slug-lookup-space',
				'type' => 'open',
			)
		);

		$result = $this->service->get_by_slug( 'slug-lookup-space' );
		$this->assertIsArray( $result );
		$this->assertSame( $space_id, $result['id'] );
		$this->assertSame( 'Slug Lookup Space', $result['name'] );
		$this->assertSame( 'open', $result['type'] );
	}

	public function test_get_by_slug_returns_null_when_missing(): void {
		$this->assertNull( $this->service->get_by_slug( 'does-not-exist' ) );
	}

	public function test_get_by_slug_returns_null_for_empty_slug(): void {
		$this->assertNull( $this->service->get_by_slug( '' ) );
		$this->assertNull( $this->service->get_by_slug( '   ' ) );
	}

	public function test_get_by_slug_sanitises_input(): void {
		$this->service->create(
			$this->owner_id,
			array(
				'name' => 'Sanitised',
				'slug' => 'sanitised-space',
				'type' => 'open',
			)
		);

		// "Sanitised Space" → sanitize_title → "sanitised-space".
		$result = $this->service->get_by_slug( 'Sanitised Space' );
		$this->assertIsArray( $result );
		$this->assertSame( 'sanitised-space', $result['slug'] );
	}

	public function test_get_by_slug_after_update_reflects_new_data(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Original',
				'slug' => 'original-name',
				'type' => 'open',
			)
		);

		$this->service->update(
			$space_id,
			$this->owner_id,
			array( 'name' => 'Renamed' )
		);

		$result = $this->service->get_by_slug( 'original-name' );
		$this->assertIsArray( $result );
		$this->assertSame( 'Renamed', $result['name'] );
	}

	public function test_get_by_slug_returns_null_after_delete(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Doomed',
				'slug' => 'doomed-space',
				'type' => 'open',
			)
		);

		$this->service->delete( $space_id, $this->owner_id );

		$this->assertNull( $this->service->get_by_slug( 'doomed-space' ) );
	}

	public function test_update_persists_rules_column(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Rules Space',
				'slug' => 'rules-space',
				'type' => 'open',
			)
		);

		$rules = "Be kind\nNo spam\nStay on topic";
		$this->service->update( $space_id, $this->owner_id, array( 'rules' => $rules ) );

		$result = $this->service->get( $space_id );
		$this->assertIsArray( $result );
		$this->assertSame( $rules, $result['rules'] );
	}

	/**
	 * Insert plain posts straight into a space, past the posting rate limit.
	 *
	 * @param int $space_id Space.
	 * @param int $count    How many.
	 * @return void
	 */
	private function seed_posts( int $space_id, int $count ): void {
		global $wpdb;
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id'    => $this->owner_id,
					'space_id'   => $space_id,
					'type'       => 'text',
					'content'    => 'post ' . $i,
					'status'     => 'published',
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
	}

	private function posts_in( int $space_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d", $space_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A space that fits in one chunk is deleted right away, with no job queued.
	 */
	public function test_small_space_deletes_in_one_request(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Small',
				'slug' => 'small-del',
				'type' => 'open',
			)
		);
		$this->seed_posts( $space_id, 3 );

		$this->service->delete( $space_id, $this->owner_id );

		$this->assertNull( $this->service->get( $space_id ) );
		$this->assertSame( 0, $this->posts_in( $space_id ) );
		$this->assertFalse( SpaceService::is_deleting( $space_id ) );
	}

	/**
	 * A big space disappears at once, keeps its rows until the chain finishes, and
	 * the chain removes it a chunk at a time - even if a job in the middle is lost.
	 */
	public function test_big_space_is_hidden_then_removed_in_chunks(): void {
		$size = static fn() => 2;
		add_filter( 'buddynext_space_delete_chunk_size', $size );

		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Big',
				'slug' => 'big-del',
				'type' => 'open',
			)
		);
		$this->seed_posts( $space_id, 5 );

		$this->service->delete( $space_id, $this->owner_id );

		// Hidden and queued, nothing removed yet.
		$this->assertTrue( SpaceService::is_deleting( $space_id ) );
		$this->assertTrue( $this->service->is_archived( $space_id ) );
		$this->assertFalse( \BuddyNext\Spaces\SpaceVisibility::can_view_space( $this->service->get( $space_id ), $this->owner_id ) );
		$this->assertSame( 5, $this->posts_in( $space_id ) );
		$this->assertTrue( as_has_scheduled_action( SpaceService::DELETE_HOOK, array( $space_id, $this->owner_id, 0 ), 'buddynext' ) );

		// Half-deleted, it can only be finished: never brought back.
		$restored = $this->service->unarchive( $space_id, $this->owner_id );
		$this->assertWPError( $restored );
		$this->assertSame( 'space_deleting', $restored->get_error_code() );
		$this->assertTrue( SpaceService::is_deleting( $space_id ) );

		// First job removes one chunk and has already queued the next link.
		$this->service->run_delete_chunk( $space_id, $this->owner_id, 0 );
		$this->assertSame( 3, $this->posts_in( $space_id ) );
		$this->assertTrue( as_has_scheduled_action( SpaceService::DELETE_HOOK, array( $space_id, $this->owner_id, 1 ), 'buddynext' ) );

		// Link 1 is lost (killed process). Link 2, queued by link 1 before it died, still finishes the job.
		$this->service->run_delete_chunk( $space_id, $this->owner_id, 1 );
		$this->assertSame( 1, $this->posts_in( $space_id ) );
		$this->service->run_delete_chunk( $space_id, $this->owner_id, 2 );

		$this->assertNull( $this->service->get( $space_id ) );
		$this->assertSame( 0, $this->posts_in( $space_id ) );

		remove_filter( 'buddynext_space_delete_chunk_size', $size );
	}

	/**
	 * A job for a space nobody asked to delete removes nothing.
	 */
	public function test_chunk_job_ignores_a_space_not_being_deleted(): void {
		$space_id = $this->service->create(
			$this->owner_id,
			array(
				'name' => 'Keep',
				'slug' => 'keep-del',
				'type' => 'open',
			)
		);
		$this->seed_posts( $space_id, 3 );

		$this->service->run_delete_chunk( $space_id, $this->owner_id, 0 );

		$this->assertNotNull( $this->service->get( $space_id ) );
		$this->assertSame( 3, $this->posts_in( $space_id ) );
	}
}
