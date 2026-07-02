<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Interest-overlap follow-suggestion ranking tests.
 *
 * Covers the Phase-2 suggestion engine: interest-overlap candidates, the
 * merge weights (friends-of-friends 3 / shared interest 2), the selectivity
 * ceiling, the blank-interests fallback contract, and the cache bust on
 * interest edits.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\FollowService;

/**
 * Tests for the interest-overlap branch of FollowService::suggestions().
 */
class FollowSuggestionInterestTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var FollowService
	 */
	private FollowService $service;

	/**
	 * Set up a fresh service per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		// Seed the profile fields (including the system interests field) —
		// the bootstrap installs schema only.
		\BuddyNext\Core\Installer::run();
		$this->service = new FollowService();
	}

	/**
	 * Resolve (or create) a space category by name.
	 *
	 * @param string $name Category name.
	 * @return int Category ID.
	 */
	private function create_category( string $name ): int {
		$id = ( new \BuddyNext\Spaces\SpaceCategoryService() )->create( array( 'name' => $name ) );
		if ( ! is_wp_error( $id ) ) {
			return (int) $id;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_space_categories WHERE slug = %s", sanitize_title( $name ) )
		);
	}

	/**
	 * Store interest picks through the canonical path (fires the update action).
	 *
	 * @param int   $user_id User ID.
	 * @param int[] $cat_ids Category IDs.
	 * @return void
	 */
	private function set_interests( int $user_id, array $cat_ids ): void {
		( new \BuddyNext\Onboarding\OnboardingService() )->save_interest_ids( $user_id, $cat_ids );
	}

	/**
	 * Fast-path picks for bulk fixture users: direct bn_profile_values rows
	 * mirroring the canonical field shape (one row per pick).
	 *
	 * @param int   $user_id User ID.
	 * @param int[] $cat_ids Category IDs.
	 * @return void
	 */
	private function insert_picks_directly( int $user_id, array $cat_ids ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$field_id = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'interests' AND type = 'category_multiselect' LIMIT 1"
		);
		$this->assertGreaterThan( 0, $field_id, 'The system interests field must be seeded.' );
		foreach ( array_values( $cat_ids ) as $index => $cat_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_values',
				array(
					'user_id'     => $user_id,
					'field_id'    => $field_id,
					'entry_index' => $index,
					'value'       => (string) $cat_id,
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Cold start solved: a viewer with picks and ZERO follows gets
	 * interest-matched members ranked by shared-category count — no longer [].
	 */
	public function test_interest_overlap_ranks_sharers_and_solves_cold_start(): void {
		$cat_a = $this->create_category( 'Overlap Cat A' );
		$cat_b = $this->create_category( 'Overlap Cat B' );

		$viewer   = self::factory()->user->create();
		$both     = self::factory()->user->create(); // Shares 2 categories → score 4.
		$one      = self::factory()->user->create(); // Shares 1 category → score 2.
		$stranger = self::factory()->user->create(); // No picks → absent.

		$this->set_interests( $viewer, array( $cat_a, $cat_b ) );
		$this->set_interests( $both, array( $cat_a, $cat_b ) );
		$this->set_interests( $one, array( $cat_a ) );

		$suggestions = $this->service->suggestions( $viewer );

		$this->assertSame( array( $both, $one ), $suggestions );
		$this->assertNotContains( $stranger, $suggestions );
		$this->assertNotContains( $viewer, $suggestions );
	}

	/**
	 * Merge weights: a friend-of-friend hit (3) plus shared interests
	 * outranks interest-only, and two shared interests (4) outrank a
	 * friend-of-friend hit alone (3) — social proof is king per hit but the
	 * signals stack.
	 */
	public function test_merged_ranking_weights_fof_and_interests(): void {
		$cat_a = $this->create_category( 'Merge Cat A' );
		$cat_b = $this->create_category( 'Merge Cat B' );

		$viewer = self::factory()->user->create();
		$friend = self::factory()->user->create();
		$fof    = self::factory()->user->create(); // FoF only → 3.
		$shared = self::factory()->user->create(); // 2 shared interests → 4.

		$this->service->follow( $viewer, $friend );
		$this->service->follow( $friend, $fof );

		$this->set_interests( $viewer, array( $cat_a, $cat_b ) );
		$this->set_interests( $shared, array( $cat_a, $cat_b ) );

		$suggestions = $this->service->suggestions( $viewer );

		$this->assertSame( array( $shared, $fof ), $suggestions );
	}

	/**
	 * Selectivity ceiling: a category picked by more members than the ceiling
	 * (max of the 20-member floor and ~10% of members) is excluded from
	 * matching, while rare categories keep working.
	 */
	public function test_selectivity_ceiling_excludes_mega_category(): void {
		$mega = $this->create_category( 'Mega Cat' );
		$rare = $this->create_category( 'Rare Cat' );

		$viewer    = self::factory()->user->create();
		$rare_pal  = self::factory()->user->create(); // Shares rare + mega.
		$mega_only = self::factory()->user->create(); // Shares ONLY the mega category.

		$this->set_interests( $viewer, array( $mega, $rare ) );
		$this->set_interests( $rare_pal, array( $rare, $mega ) );
		$this->set_interests( $mega_only, array( $mega ) );

		// Push the mega category past the 20-member ceiling floor.
		$bulk = self::factory()->user->create_many( 21 );
		foreach ( $bulk as $bulk_id ) {
			$this->insert_picks_directly( (int) $bulk_id, array( $mega ) );
		}

		$suggestions = $this->service->suggestions( $viewer );

		$this->assertContains( $rare_pal, $suggestions, 'Rare-category sharer must still match.' );
		$this->assertNotContains( $mega_only, $suggestions, 'Mega-category-only sharer must be excluded by the ceiling.' );
		foreach ( $bulk as $bulk_id ) {
			$this->assertNotContains( (int) $bulk_id, $suggestions );
		}
	}

	/**
	 * Blank-interests contract: with no picks anywhere the engine is exactly
	 * the friends-of-friends graph (additive signal), and a blank cold-start
	 * viewer still gets [].
	 */
	public function test_blank_interests_falls_back_to_fof_only(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();
		$carol = self::factory()->user->create();

		$this->service->follow( $alice, $bob );
		$this->service->follow( $bob, $carol );

		$this->assertSame( array( $carol ), $this->service->suggestions( $alice ) );

		$cold_blank = self::factory()->user->create();
		$this->assertSame( array(), $this->service->suggestions( $cold_blank ) );
	}

	/**
	 * Interest edits bust the per-viewer suggestion cache: the ranked list
	 * shifts on the next fetch instead of serving the stale cached set.
	 */
	public function test_interest_change_busts_suggestion_cache(): void {
		$cat_a = $this->create_category( 'Bust Cat A' );
		$cat_b = $this->create_category( 'Bust Cat B' );

		$viewer   = self::factory()->user->create();
		$sharer_a = self::factory()->user->create();
		$sharer_b = self::factory()->user->create();

		$this->set_interests( $sharer_a, array( $cat_a ) );
		$this->set_interests( $sharer_b, array( $cat_b ) );

		$this->set_interests( $viewer, array( $cat_a ) );
		$this->assertSame( array( $sharer_a ), $this->service->suggestions( $viewer ) );

		// Edit: switch the viewer's picks. Without the InterestListener bust
		// the cached [$sharer_a] list would still be served.
		$this->set_interests( $viewer, array( $cat_b ) );
		$this->assertSame( array( $sharer_b ), $this->service->suggestions( $viewer ) );
	}
}
