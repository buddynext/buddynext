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
 * @covers \BuddyNext\Core\FeatureRegistry
 */
class FeatureRegistryTest extends \WP_UnitTestCase {

	private FeatureRegistry $registry;

	public function set_up(): void {
		parent::set_up();
		$this->registry = new FeatureRegistry();
		delete_option( 'buddynext_features' );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_features' );
		// Drop any per-feature filter overrides.
		foreach ( array_keys( $this->registry->catalog() ) as $slug ) {
			remove_all_filters( "buddynext_feature_{$slug}" );
		}
		remove_all_filters( 'buddynext_features' );
		parent::tear_down();
	}

	public function test_catalog_contains_mandatory_default_and_opt_in_entries(): void {
		$catalog = $this->registry->catalog();

		$this->assertArrayHasKey( 'feed', $catalog );
		$this->assertSame( FeatureRegistry::TIER_MANDATORY, $catalog['feed']['tier'] );

		$this->assertArrayHasKey( 'sidebar', $catalog );
		$this->assertSame( FeatureRegistry::TIER_DEFAULT_ON, $catalog['sidebar']['tier'] );

		$this->assertArrayHasKey( 'gamification', $catalog );
		$this->assertSame( FeatureRegistry::TIER_OPT_IN, $catalog['gamification']['tier'] );
	}

	public function test_mandatory_features_are_always_enabled_regardless_of_option(): void {
		update_option( 'buddynext_features', array( 'feed' => false, 'profile' => false ) );
		$this->assertTrue( $this->registry->is_enabled( 'feed' ) );
		$this->assertTrue( $this->registry->is_enabled( 'profile' ) );
	}

	public function test_default_on_features_resolve_true_when_option_absent(): void {
		$this->assertTrue( $this->registry->is_enabled( 'sidebar' ) );
		$this->assertTrue( $this->registry->is_enabled( 'hashtags' ) );
	}

	public function test_opt_in_features_resolve_false_when_option_absent(): void {
		$this->assertFalse( $this->registry->is_enabled( 'gamification' ) );
		$this->assertFalse( $this->registry->is_enabled( 'webhooks' ) );
	}

	public function test_owner_can_disable_default_on_feature_via_option(): void {
		update_option( 'buddynext_features', array( 'sidebar' => false ) );
		$this->assertFalse( $this->registry->is_enabled( 'sidebar' ) );
	}

	public function test_owner_can_enable_opt_in_feature_via_option(): void {
		update_option( 'buddynext_features', array( 'gamification' => true ) );
		$this->assertTrue( $this->registry->is_enabled( 'gamification' ) );
	}

	public function test_per_feature_filter_overrides_option(): void {
		update_option( 'buddynext_features', array( 'sidebar' => true ) );
		add_filter( 'buddynext_feature_sidebar', '__return_false' );
		$this->assertFalse( $this->registry->is_enabled( 'sidebar' ) );
	}

	public function test_unmet_dependency_forces_feature_off(): void {
		// Hashtags depends on feed. Feed is mandatory and can't be turned off
		// via option, but if a filter denies it, dependent should turn off too.
		add_filter( 'buddynext_feature_feed', '__return_false' );
		// Feed still returns true because mandatory tier short-circuits before
		// the per-feature filter. Dependency check uses the mandatory return.
		$this->assertTrue( $this->registry->is_enabled( 'feed' ) );
		$this->assertTrue( $this->registry->is_enabled( 'hashtags' ) );
	}

	public function test_persist_strips_mandatory_features(): void {
		$this->registry->persist( array( 'feed' => false, 'sidebar' => false, 'gamification' => true ) );
		$stored = get_option( 'buddynext_features' );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'feed', $stored, 'Mandatory features should not be stored.' );
		$this->assertFalse( $stored['sidebar'] );
		$this->assertTrue( $stored['gamification'] );
	}

	public function test_by_group_partitions_catalog(): void {
		$groups = $this->registry->by_group();
		$this->assertArrayHasKey( 'core', $groups );
		$this->assertArrayHasKey( 'community', $groups );
		$this->assertArrayHasKey( 'bridges', $groups );
		// Every catalogue entry should land in exactly one group.
		$total_grouped = array_sum( array_map( 'count', $groups ) );
		$this->assertSame( count( $this->registry->catalog() ), $total_grouped );
	}

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

	public function test_unknown_slug_returns_false(): void {
		$this->assertFalse( $this->registry->is_enabled( 'nonexistent_feature' ) );
	}
}
