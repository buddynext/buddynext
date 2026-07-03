<?php
/**
 * Tests for the SettingsRegistry aggregation.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings\Field;
use BuddyNext\Admin\Settings\Section;
use BuddyNext\Admin\Settings\SettingsRegistry;

/**
 * Verifies free+Pro page aggregation and key flattening.
 *
 * @covers \BuddyNext\Admin\Settings\SettingsRegistry
 */
class SettingsRegistryTest extends \WP_UnitTestCase {

	/**
	 * Start each test with an empty registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		SettingsRegistry::reset();
	}

	/**
	 * Leave the registry empty for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		SettingsRegistry::reset();
		parent::tear_down();
	}

	/**
	 * Build a one-toggle page carrying the given option key.
	 *
	 * @param string $key Option key.
	 * @return StubSettingsPage
	 */
	private function page( string $key ): StubSettingsPage {
		return new StubSettingsPage(
			array(
				new Section(
					'privacy',
					'Cookie Consent',
					array(
						new Field(
							array(
								'key'   => $key,
								'type'  => 'toggle',
								'label' => 'X',
							)
						),
					)
				),
			)
		);
	}

	/**
	 * A registered page's keys appear in all_keys().
	 *
	 * @return void
	 */
	public function test_registers_and_flattens_keys(): void {
		SettingsRegistry::register( $this->page( 'buddynext_cookie_consent' ) );
		$this->assertContains( 'buddynext_cookie_consent', SettingsRegistry::all_keys() );
	}

	/**
	 * Multiple pages union their keys (free + Pro).
	 *
	 * @return void
	 */
	public function test_union_across_pages(): void {
		SettingsRegistry::register( $this->page( 'free_opt' ) );
		SettingsRegistry::register( $this->page( 'pro_opt' ) );
		$this->assertEqualsCanonicalizing( array( 'free_opt', 'pro_opt' ), SettingsRegistry::all_keys() );
	}

	/**
	 * An empty registry yields no keys (standalone baseline).
	 *
	 * @return void
	 */
	public function test_empty_registry_has_no_keys(): void {
		$this->assertSame( array(), SettingsRegistry::all_keys() );
	}
}
