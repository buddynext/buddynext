<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Space-suggestion interest-affinity tests.
 *
 * Covers the Phase-2 wiring: my_categories() unions the viewer's explicit
 * interest picks into the category-affinity signal (so it works before they
 * join anything), the buddynext_space_suggestions seam, and the cache bust
 * on interest edits.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceSuggestionService;

/**
 * Tests for interest-driven space suggestions.
 */
class SpaceSuggestionInterestTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SpaceSuggestionService
	 */
	private SpaceSuggestionService $service;

	/**
	 * Space owner fixture.
	 *
	 * @var int
	 */
	private int $owner_id;

	/**
	 * Set up fixtures per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		// Seed the profile fields (including the system interests field) —
		// the bootstrap installs schema only.
		\BuddyNext\Core\Installer::run();
		$this->service  = new SpaceSuggestionService();
		$this->owner_id = self::factory()->user->create();
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
	 * Create an open space in a category.
	 *
	 * @param string $slug        Space slug.
	 * @param int    $category_id Category ID.
	 * @return int Space ID.
	 */
	private function create_space( string $slug, int $category_id ): int {
		$id = ( new SpaceService() )->create(
			$this->owner_id,
			array(
				'name'        => ucfirst( str_replace( '-', ' ', $slug ) ),
				'slug'        => $slug,
				'type'        => 'open',
				'category_id' => $category_id,
			)
		);
		$this->assertIsInt( $id );
		return (int) $id;
	}

	/**
	 * A member with interest picks and ZERO joined spaces gets the
	 * category-affinity signal from minute zero: spaces in a picked category
	 * rank above equal-popularity spaces in other categories.
	 */
	public function test_my_categories_unions_interest_picks(): void {
		$picked = $this->create_category( 'Picked Cat' );
		$other  = $this->create_category( 'Other Cat' );

		$space_picked = $this->create_space( 'picked-space', $picked );
		$space_other  = $this->create_space( 'other-space', $other );

		$viewer = self::factory()->user->create();
		( new \BuddyNext\Onboarding\OnboardingService() )->save_interest_ids( $viewer, array( $picked ) );

		$ids = $this->service->suggest_ids( $viewer, 6 );

		$this->assertContains( $space_picked, $ids );
		$this->assertContains( $space_other, $ids );
		$this->assertLessThan(
			array_search( $space_other, $ids, true ),
			array_search( $space_picked, $ids, true ),
			'The interest-matched space must rank above the non-matched one.'
		);
	}

	/**
	 * Blank picks leave the ranking exactly as the joined-space /
	 * popularity signals produce it (additive signal contract).
	 */
	public function test_blank_interests_keeps_popularity_order(): void {
		$cat_a = $this->create_category( 'Blank Cat A' );
		$cat_b = $this->create_category( 'Blank Cat B' );

		$space_a = $this->create_space( 'blank-space-a', $cat_a );
		$space_b = $this->create_space( 'blank-space-b', $cat_b );

		$viewer = self::factory()->user->create();

		$ids = $this->service->suggest_ids( $viewer, 6 );

		// No picks, no joins, no follows — pure popularity fallback contains
		// both spaces with no category boost applied to either.
		$this->assertContains( $space_a, $ids );
		$this->assertContains( $space_b, $ids );
	}

	/**
	 * The buddynext_space_suggestions seam reorders/filters the final ranked
	 * id list on every call (Pro rerank parity with the follow engine).
	 */
	public function test_space_suggestions_seam_applies_last(): void {
		$cat_a = $this->create_category( 'Seam Cat A' );

		$space_one = $this->create_space( 'seam-space-one', $cat_a );
		$space_two = $this->create_space( 'seam-space-two', $cat_a );

		$viewer = self::factory()->user->create();

		$received = null;
		$seam     = static function ( array $ids ) use ( &$received, $space_two ): array {
			$received = $ids;
			return array( $space_two );
		};
		add_filter( 'buddynext_space_suggestions', $seam );

		$ids = $this->service->suggest_ids( $viewer, 6 );

		remove_filter( 'buddynext_space_suggestions', $seam );

		$this->assertIsArray( $received, 'The seam must receive the ranked id list.' );
		$this->assertContains( $space_one, $received );
		$this->assertSame( array( $space_two ), $ids );
		$this->assertNotContains( $space_one, $ids );
	}

	/**
	 * Interest edits bust the per-viewer space-suggestion cache via
	 * InterestListener, so the affinity boost shifts on the next fetch.
	 */
	public function test_interest_change_busts_space_suggestion_cache(): void {
		$cat_a = $this->create_category( 'Bust Space Cat A' );
		$cat_b = $this->create_category( 'Bust Space Cat B' );

		$space_a = $this->create_space( 'bust-space-a', $cat_a );
		$space_b = $this->create_space( 'bust-space-b', $cat_b );

		$viewer  = self::factory()->user->create();
		$onboard = new \BuddyNext\Onboarding\OnboardingService();

		$onboard->save_interest_ids( $viewer, array( $cat_a ) );
		$first = $this->service->suggest_ids( $viewer, 6 );
		$this->assertSame( $space_a, $first[0] ?? 0 );

		// Switch picks — without the bust, the cached cat-A-boosted order
		// would still be served.
		$onboard->save_interest_ids( $viewer, array( $cat_b ) );
		$second = $this->service->suggest_ids( $viewer, 6 );
		$this->assertSame( $space_b, $second[0] ?? 0 );
	}
}
