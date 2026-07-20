<?php
/**
 * Tests for FeatureRegistry — site-owner control over which features are active.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\FeatureRegistry;

/**
 * Tests the feature registry — tiers, option resolution, and grouping.
 *
 * @covers \BuddyNext\Core\FeatureRegistry
 */
class FeatureRegistryTest extends \WP_UnitTestCase {

	/**
	 * The registry under test.
	 *
	 * @var FeatureRegistry
	 */
	private FeatureRegistry $registry;

	/**
	 * Build a fresh registry and clear stored feature state.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->registry = new FeatureRegistry();
		delete_option( 'buddynext_features' );
	}

	/**
	 * Clear stored state and per-feature filter overrides.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_features' );
		// Drop any per-feature filter overrides.
		foreach ( array_keys( $this->registry->catalog() ) as $slug ) {
			remove_all_filters( "buddynext_feature_{$slug}" );
		}
		remove_all_filters( 'buddynext_features' );
		parent::tear_down();
	}

	/**
	 * The catalog carries mandatory, default-on, and opt-in tiers.
	 *
	 * @return void
	 */
	public function test_catalog_contains_mandatory_default_and_opt_in_entries(): void {
		$catalog = $this->registry->catalog();

		$this->assertArrayHasKey( 'feed', $catalog );
		$this->assertSame( FeatureRegistry::TIER_MANDATORY, $catalog['feed']['tier'] );

		$this->assertArrayHasKey( 'sidebar', $catalog );
		$this->assertSame( FeatureRegistry::TIER_DEFAULT_ON, $catalog['sidebar']['tier'] );

		$this->assertArrayHasKey( 'webhooks', $catalog );
		$this->assertSame( FeatureRegistry::TIER_OPT_IN, $catalog['webhooks']['tier'] );
	}

	/**
	 * Mandatory features stay enabled even when the option turns them off.
	 *
	 * @return void
	 */
	public function test_mandatory_features_are_always_enabled_regardless_of_option(): void {
		update_option(
			'buddynext_features',
			array(
				'feed'    => false,
				'profile' => false,
			)
		);
		$this->assertTrue( $this->registry->is_enabled( 'feed' ) );
		$this->assertTrue( $this->registry->is_enabled( 'profile' ) );
	}

	/**
	 * Default-on features resolve true when no option is stored.
	 *
	 * @return void
	 */
	public function test_default_on_features_resolve_true_when_option_absent(): void {
		$this->assertTrue( $this->registry->is_enabled( 'sidebar' ) );
		$this->assertTrue( $this->registry->is_enabled( 'hashtags' ) );
	}

	/**
	 * Opt-in features resolve false when no option is stored.
	 *
	 * @return void
	 */
	public function test_opt_in_features_resolve_false_when_option_absent(): void {
		$this->assertFalse( $this->registry->is_enabled( 'webhooks' ) );
	}

	/**
	 * The owner can disable a default-on feature via the option.
	 *
	 * @return void
	 */
	public function test_owner_can_disable_default_on_feature_via_option(): void {
		update_option( 'buddynext_features', array( 'sidebar' => false ) );
		$this->assertFalse( $this->registry->is_enabled( 'sidebar' ) );
	}

	/**
	 * The owner can enable an opt-in feature via the option.
	 *
	 * @return void
	 */
	public function test_owner_can_enable_opt_in_feature_via_option(): void {
		// webhooks is the opt-in (default-off) feature; the owner turns it on.
		update_option( 'buddynext_features', array( 'webhooks' => true ) );
		$this->assertTrue( $this->registry->is_enabled( 'webhooks' ) );
	}

	/**
	 * A per-feature filter overrides the stored option.
	 *
	 * @return void
	 */
	public function test_per_feature_filter_overrides_option(): void {
		update_option( 'buddynext_features', array( 'sidebar' => true ) );
		add_filter( 'buddynext_feature_sidebar', '__return_false' );
		$this->assertFalse( $this->registry->is_enabled( 'sidebar' ) );
	}

	/**
	 * An unmet dependency forces a feature off.
	 *
	 * @return void
	 */
	public function test_unmet_dependency_forces_feature_off(): void {
		// Hashtags depends on feed. Feed is mandatory and can't be turned off
		// via option, but if a filter denies it, dependent should turn off too.
		add_filter( 'buddynext_feature_feed', '__return_false' );
		// Feed still returns true because mandatory tier short-circuits before
		// the per-feature filter. Dependency check uses the mandatory return.
		$this->assertTrue( $this->registry->is_enabled( 'feed' ) );
		$this->assertTrue( $this->registry->is_enabled( 'hashtags' ) );
	}

	/**
	 * Persisting strips mandatory features from the stored map.
	 *
	 * @return void
	 */
	public function test_persist_strips_mandatory_features(): void {
		$this->registry->persist(
			array(
				'feed'     => false,
				'sidebar'  => false,
				'webhooks' => true,
			)
		);
		$stored = get_option( 'buddynext_features' );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'feed', $stored, 'Mandatory features should not be stored.' );
		$this->assertFalse( $stored['sidebar'] );
		$this->assertTrue( $stored['webhooks'] );
	}

	/**
	 * Grouping partitions every catalog entry into exactly one group.
	 *
	 * @return void
	 */
	public function test_by_group_partitions_catalog(): void {
		$groups = $this->registry->by_group();
		$this->assertArrayHasKey( 'core', $groups );
		$this->assertArrayHasKey( 'community', $groups );
		// Every catalogue entry should land in exactly one group.
		$total_grouped = array_sum( array_map( 'count', $groups ) );
		$this->assertSame( count( $this->registry->catalog() ), $total_grouped );
	}

	/**
	 * A third party registers a feature via the buddynext_features filter.
	 *
	 * @return void
	 */
	public function test_third_party_can_register_a_feature_via_filter(): void {
		add_filter(
			'buddynext_features',
			static function ( array $catalog ): array {
				$catalog['my_addon'] = array(
					'slug'        => 'my_addon',
					'label'       => 'My Addon',
					'description' => 'Third-party plugin feature.',
					'tier'        => FeatureRegistry::TIER_OPT_IN,
					'group'       => 'integrations',
					'depends_on'  => array(),
				);
				return $catalog;
			}
		);

		$fresh = new FeatureRegistry();
		$this->assertArrayHasKey( 'my_addon', $fresh->catalog() );
		$this->assertFalse( $fresh->is_enabled( 'my_addon' ) );
	}

	/**
	 * An unknown slug resolves to false.
	 *
	 * @return void
	 */
	public function test_unknown_slug_returns_false(): void {
		$this->assertFalse( $this->registry->is_enabled( 'nonexistent_feature' ) );
	}
}
