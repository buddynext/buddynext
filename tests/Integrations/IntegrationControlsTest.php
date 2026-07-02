<?php
/**
 * Tests for the per-integration owner controls foundation: the registry + the
 * buddynext_integration_enabled() gate.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Integrations\IntegrationRegistry;

/**
 * Registry collection + the enabled() gate semantics (defaults, sub-tab nesting).
 *
 * @covers \BuddyNext\Integrations\IntegrationRegistry
 */
class IntegrationControlsTest extends \WP_UnitTestCase {

	/**
	 * Reset the registry + remove option/filter state before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		IntegrationRegistry::instance()->reset();
		remove_all_filters( 'buddynext_integrations' );
		remove_all_filters( 'buddynext_integration_enabled' );
		foreach ( array( 'cb_nav', 'cb_feed', 'cb_subtab_jobs' ) as $opt ) {
			delete_option( 'buddynext_integration_' . $opt );
		}
	}

	/**
	 * An absent option means ENABLED — a fresh install / new integration is on.
	 */
	public function test_default_is_enabled(): void {
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'nav' ) );
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'feed' ) );
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'nav', 'jobs' ) );
	}

	/**
	 * The nav + feed options gate their own aspect independently.
	 */
	public function test_nav_and_feed_gate_independently(): void {
		update_option( 'buddynext_integration_careerboard_nav', '0' );
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'nav' ) );
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'feed' ), 'feed unaffected by nav toggle' );

		update_option( 'buddynext_integration_careerboard_nav', '1' );
		update_option( 'buddynext_integration_careerboard_feed', '0' );
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'nav' ) );
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'feed' ) );
	}

	/**
	 * A sub-tab is on only when BOTH it and its parent integration nav are on.
	 */
	public function test_subtab_requires_parent_and_self(): void {
		// Parent on, sub off → sub hidden.
		update_option( 'buddynext_integration_careerboard_subtab_jobs', '0' );
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'nav', 'jobs' ) );
		$this->assertTrue( buddynext_integration_enabled( 'careerboard', 'nav', 'resume' ), 'other sub-tab still on' );

		// Parent off → every sub hidden regardless of its own toggle.
		delete_option( 'buddynext_integration_careerboard_subtab_jobs' );
		update_option( 'buddynext_integration_careerboard_nav', '0' );
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'nav', 'jobs' ) );
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'nav', 'resume' ) );
	}

	/**
	 * The read-time filter can override the resolved state without an option.
	 */
	public function test_filter_override(): void {
		add_filter(
			'buddynext_integration_enabled',
			static function ( bool $on, string $key ): bool {
				return 'careerboard' === $key ? false : $on;
			},
			10,
			2
		);
		$this->assertFalse( buddynext_integration_enabled( 'careerboard', 'nav' ) );
		$this->assertTrue( buddynext_integration_enabled( 'jetonomy', 'nav' ) );
	}

	/**
	 * The registry collects + normalizes entries from the public filter, validating
	 * keys, defaulting labels, and de-duplicating.
	 */
	public function test_registry_collects_and_normalizes(): void {
		add_filter(
			'buddynext_integrations',
			static function ( array $items ): array {
				$items['careerboard']  = array(
					'label'    => 'Career Board',
					'has_nav'  => true,
					'has_feed' => true,
					'subtabs'  => array(
						'jobs'   => 'Jobs',
						'resume' => 'Resume',
					),
				);
				$items['gamification'] = array( 'has_nav' => true ); // No label → default.
				$items['']             = array( 'label' => 'Dropped' ); // Bad key → dropped.
				return $items;
			}
		);

		$all = buddynext_integrations();
		$this->assertArrayHasKey( 'careerboard', $all );
		$this->assertArrayHasKey( 'gamification', $all );
		$this->assertArrayNotHasKey( '', $all );
		$this->assertSame( 'Career Board', $all['careerboard']['label'] );
		$this->assertSame( 'Gamification', $all['gamification']['label'], 'label defaults from the key' );
		$this->assertSame(
			array(
				'jobs'   => 'Jobs',
				'resume' => 'Resume',
			),
			$all['careerboard']['subtabs']
		);
		$this->assertFalse( $all['gamification']['has_feed'] );
	}
}
