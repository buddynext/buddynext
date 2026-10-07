<?php
/**
 * A fresh install and "Restore defaults" must land on the same values.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Admin\Settings;
use BuddyNext\Core\RecommendedDefaults;
use WP_UnitTestCase;

/**
 * Activation seeds RecommendedDefaults; "Restore defaults" writes each field's
 * declared default. Where both name the same option they must agree, or an owner
 * runs one number while the screen calls a different one the default (strikes:
 * seeded suspend at 4 and permanent ban at 6, fields said 5 and off).
 */
class RecommendedDefaultsMatchFieldsTest extends WP_UnitTestCase {

	/**
	 * Every settings field that the fresh-install seed also covers declares the
	 * seeded value as its default.
	 *
	 * @return void
	 */
	public function test_field_defaults_equal_the_seeded_values(): void {
		$seeded  = RecommendedDefaults::map();
		$checked = 0;

		foreach ( ( new Settings() )->settings_fields() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( ! array_key_exists( $field->key, $seeded ) ) {
					continue;
				}
				++$checked;
				// Loose on purpose: a toggle seeds true and stores '1'.
				$this->assertEquals( $seeded[ $field->key ], $field->default, $field->key );
			}
		}

		$this->assertGreaterThan( 4, $checked, 'The strike and queue thresholds are covered.' );
	}

	/**
	 * No automatic permanent ban out of the box, and suspension at 5 strikes.
	 *
	 * @return void
	 */
	public function test_no_automatic_permanent_ban_by_default(): void {
		$this->assertSame( 5, RecommendedDefaults::value( 'buddynext_strike_suspend_threshold' ) );
		$this->assertSame( 0, RecommendedDefaults::value( 'buddynext_strike_perma_ban_threshold' ) );
	}
}
