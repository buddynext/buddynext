<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cache + invalidation tests for SpaceCategoryService (change-index item C7/C6).
 *
 * The category lists are global, hot reads (spaces directory + explore aside). They
 * are object-cached; every write (create/update/delete) must bust both keys so the
 * directory reflects edits immediately.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceCategoryService;
use WP_UnitTestCase;

/**
 * Category-list caching behaviour.
 */
class SpaceCategoryServiceCacheTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SpaceCategoryService
	 */
	private SpaceCategoryService $service;

	/**
	 * Fresh service + clean cache group per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new SpaceCategoryService();
		wp_cache_delete( 'all', 'buddynext_space_cats' );
		wp_cache_delete( 'all_counts', 'buddynext_space_cats' );
	}

	/**
	 * A create is visible immediately in both cached lists (bust works).
	 *
	 * @return void
	 */
	public function test_create_busts_both_lists(): void {
		$this->service->get_all();             // Prime 'all'.
		$this->service->get_all_with_counts(); // Prime 'all_counts'.

		$id = $this->service->create( array( 'name' => 'Cache Cat' ) );
		$this->assertIsInt( $id );

		$names  = wp_list_pluck( $this->service->get_all(), 'name' );
		$names2 = wp_list_pluck( $this->service->get_all_with_counts(), 'name' );
		$this->assertContains( 'Cache Cat', $names, 'get_all must reflect the new category.' );
		$this->assertContains( 'Cache Cat', $names2, 'get_all_with_counts must reflect the new category.' );
	}

	/**
	 * An update to a name is visible immediately (bust works).
	 *
	 * @return void
	 */
	public function test_update_busts_cache(): void {
		$id = $this->service->create( array( 'name' => 'Before' ) );
		$this->service->get_all(); // Prime with old name.

		$this->service->update( (int) $id, array( 'name' => 'After' ) );

		$names = wp_list_pluck( $this->service->get_all(), 'name' );
		$this->assertContains( 'After', $names );
		$this->assertNotContains( 'Before', $names );
	}

	/**
	 * A delete is visible immediately (bust works).
	 *
	 * @return void
	 */
	public function test_delete_busts_cache(): void {
		$id = $this->service->create( array( 'name' => 'Doomed' ) );
		$this->service->get_all(); // Prime including it.

		$this->service->delete( (int) $id );

		$names = wp_list_pluck( $this->service->get_all(), 'name' );
		$this->assertNotContains( 'Doomed', $names );
	}

	/**
	 * A second read with no write in between is served from cache (same payload).
	 *
	 * @return void
	 */
	public function test_repeat_read_is_cached(): void {
		$this->service->create( array( 'name' => 'Stable' ) );
		$first  = $this->service->get_all();
		$second = $this->service->get_all();
		$this->assertSame( $first, $second );
	}
}
