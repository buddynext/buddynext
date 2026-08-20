<?php
/**
 * Paid content must not be searchable by the whole internet.
 *
 * The search index caps each row's visibility at what its space allows, and the
 * cap was computed from the space's TYPE alone. An OPEN space carrying
 * `required_ability` is open by type and paid-for in fact, so its content was
 * indexed `visibility = 'public'` — and the guest query is literally
 * `si.visibility = 'public'`.
 *
 * Reproduced on the site before the fix: six rows in an open space gated behind
 * `tier:vip` sat in the index as public.
 *
 * The cap is enforced at the single write door (`SearchService::index()`), not at
 * the call sites, so these tests ask for `public` explicitly and assert the door
 * refuses. A test that simply omitted the argument would pass against a broken
 * door.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;
use BuddyNext\Spaces\SpaceService;

/**
 * The space ceiling applied to every indexed row.
 *
 * @covers \BuddyNext\Search\SearchService::index
 */
class GatedSpaceIndexCeilingTest extends \WP_UnitTestCase {

	/**
	 * Indexer.
	 *
	 * @var SearchService
	 */
	private SearchService $search;

	/**
	 * Spaces.
	 *
	 * @var SpaceService
	 */
	private SpaceService $spaces;

	/**
	 * Owner for the fixture spaces.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * Fresh services and an owner.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->search   = new SearchService();
		$this->spaces   = new SpaceService();
		$this->owner_id = self::factory()->user->create();
	}

	/**
	 * Create a space, optionally behind a plan gate.
	 *
	 * The gate is written straight to the column: the validated write path runs
	 * through `buddynext_sanitize_space_required_ability`, which only Pro answers,
	 * so on a Free-only test run every gate would be silently discarded and each
	 * test below would pass while proving nothing.
	 *
	 * @param string $type    Space type.
	 * @param string $ability required_ability, or '' for none.
	 * @return int Space id.
	 */
	private function space( string $type, string $ability = '' ): int {
		global $wpdb;

		$slug = 'ceiling-' . strtolower( wp_generate_password( 8, false ) );

		$id = (int) $this->spaces->create(
			$this->owner_id,
			array(
				'name' => 'Ceiling ' . $slug,
				'slug' => $slug,
				'type' => $type,
			)
		);

		if ( '' !== $ability ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => $ability ), array( 'id' => $id ) );
			wp_cache_flush();
		}

		SearchService::flush_space_ceiling();

		return $id;
	}

	/**
	 * Index one row, ASKING for public, and report what was stored.
	 *
	 * @param int $space_id Space the content lives in.
	 * @return string Stored visibility.
	 */
	private function index_asking_for_public( int $space_id ): string {
		global $wpdb;

		$object_id = wp_rand( 100000, 999999 );

		$this->search->index( 'post', $object_id, 'Ceiling probe', 'body', $this->owner_id, 'public', $space_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d",
				$object_id
			)
		);
	}

	/**
	 * The bug: an open space behind a plan gate indexed its content publicly.
	 *
	 * @return void
	 */
	public function test_a_gated_open_space_cannot_index_public_content(): void {
		$space_id = $this->space( 'open', 'tier:vip' );

		$this->assertSame(
			'private',
			$this->index_asking_for_public( $space_id ),
			'Content behind a plan gate was searchable by logged-out visitors, because the guest query is si.visibility = public.'
		);
	}

	/**
	 * An ungated open space is untouched — the fix must not break normal search.
	 *
	 * @return void
	 */
	public function test_an_ungated_open_space_still_indexes_publicly(): void {
		$space_id = $this->space( 'open' );

		$this->assertSame( 'public', $this->index_asking_for_public( $space_id ) );
	}

	/**
	 * A private space was already capped, and stays capped.
	 *
	 * @return void
	 */
	public function test_a_private_space_is_still_capped(): void {
		$space_id = $this->space( 'private' );

		$this->assertSame( 'private', $this->index_asking_for_public( $space_id ) );
	}

	/**
	 * Content with no space at all is unaffected.
	 *
	 * @return void
	 */
	public function test_content_outside_any_space_is_unaffected(): void {
		$this->assertSame( 'public', $this->index_asking_for_public( 0 ) );
	}

	/**
	 * Adding a gate to an existing space changes the ceiling for new writes.
	 *
	 * The memo is per-request and keyed by space id, so without a flush an owner
	 * gating a space mid-request would keep publishing its content publicly.
	 *
	 * @return void
	 */
	public function test_gating_a_space_changes_the_ceiling(): void {
		global $wpdb;

		$space_id = $this->space( 'open' );

		$this->assertSame( 'public', $this->index_asking_for_public( $space_id ), 'Precondition: ungated.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'required_ability' => 'tier:vip' ), array( 'id' => $space_id ) );
		wp_cache_flush();
		SearchService::flush_space_ceiling();

		$this->assertSame( 'private', $this->index_asking_for_public( $space_id ) );
	}
}
