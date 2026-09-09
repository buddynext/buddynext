<?php
/**
 * Tests for the Field descriptor.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings\Field;

/**
 * Verifies descriptor anchors, sanitizer, and render-helper resolution.
 *
 * @covers \BuddyNext\Admin\Settings\Field
 * @covers \BuddyNext\Admin\Settings\FieldTypes
 */
class FieldTest extends \WP_UnitTestCase {

	/**
	 * The DOM anchor is derived from the option key.
	 *
	 * @return void
	 */
	public function test_anchor_is_derived_from_key(): void {
		$field = new Field(
			array(
				'key'   => 'buddynext_cookie_consent',
				'type'  => 'toggle',
				'label' => 'X',
			)
		);
		$this->assertSame( 'bn-opt-buddynext_cookie_consent', $field->anchor() );
	}

	/**
	 * A toggle resolves the boolean sanitizer and toggle render helper.
	 *
	 * @return void
	 */
	public function test_toggle_resolves_boolean_sanitizer_and_helper(): void {
		$field = new Field(
			array(
				'key'   => 'k',
				'type'  => 'toggle',
				'label' => 'X',
			)
		);
		$this->assertSame( 'rest_sanitize_boolean', $field->sanitizer() );
		$this->assertSame( 'render_toggle_row', $field->render_helper() );
	}

	/**
	 * A per-field sanitize callback overrides the type default.
	 *
	 * @return void
	 */
	public function test_custom_sanitizer_overrides_type_default(): void {
		$cb    = static fn( $v ) => $v;
		$field = new Field(
			array(
				'key'      => 'k',
				'type'     => 'text',
				'label'    => 'X',
				'sanitize' => $cb,
			)
		);
		$this->assertSame( $cb, $field->sanitizer() );
	}

	/**
	 * An unknown type falls back to the text render helper.
	 *
	 * @return void
	 */
	public function test_unknown_type_falls_back_to_text_helper(): void {
		$field = new Field(
			array(
				'key'   => 'k',
				'type'  => 'nope',
				'label' => 'X',
			)
		);
		$this->assertSame( 'render_text_row', $field->render_helper() );
	}

	/**
	 * A number field declaring min/max clamps out-of-range input on save, not just
	 * in the HTML attribute (card 10285238883 — a crafted POST persisted 500).
	 *
	 * @return void
	 */
	public function test_number_field_clamps_to_declared_bounds(): void {
		$field = new Field(
			array(
				'key'  => 'buddynext_members_only_teaser_percent',
				'type' => 'number',
				'min'  => 0,
				'max'  => 95,
			)
		);
		$sanitize = $field->sanitizer();
		$this->assertIsCallable( $sanitize );
		$this->assertSame( 95, $sanitize( 500 ), 'Above-max value must clamp to max.' );
		$this->assertSame( 95, $sanitize( 100 ), '100 must clamp to the 95 cap.' );
		$this->assertSame( 0, $sanitize( -5 ), 'Below-min value must clamp to min.' );
		$this->assertSame( 25, $sanitize( 25 ), 'An in-range value is untouched.' );
	}

	/**
	 * min=0 keeps 0 a valid stored value. Regression guard: the generic clamp
	 * (card 10285238883) must NOT force a "0 = disabled" setting up to 1. The
	 * auto-hide threshold treats 0 as off; forcing it to 1 flips a safety setting
	 * from OFF to "hide after a single report" on the next unrelated save.
	 *
	 * @return void
	 */
	public function test_number_field_min_zero_preserves_disabled_sentinel(): void {
		$field    = new Field(
			array(
				'key'  => 'buddynext_auto_hide_threshold',
				'type' => 'number',
				'min'  => 0,
			)
		);
		$sanitize = $field->sanitizer();
		$this->assertSame( 0, $sanitize( 0 ), '0 (disabled) must survive the clamp.' );
		$this->assertSame( 0, $sanitize( -3 ), 'A negative still clamps up to the 0 floor.' );
		$this->assertSame( 7, $sanitize( 7 ), 'A real threshold is untouched.' );
	}

	/**
	 * min=1 still clamps 0 up to 1 where 0 is unsafe rather than "off" — the strike
	 * thresholds have no zero-guard, so 0 would mean "act on the first strike".
	 *
	 * @return void
	 */
	public function test_number_field_min_one_clamps_zero_up(): void {
		$field    = new Field(
			array(
				'key'  => 'buddynext_strike_suspend_threshold',
				'type' => 'number',
				'min'  => 1,
			)
		);
		$sanitize = $field->sanitizer();
		$this->assertSame( 1, $sanitize( 0 ), '0 must clamp to 1 (no accidental suspend-on-first-strike).' );
	}

	/**
	 * Run a field's resolved sanitizer inside the sanitize_option_{key} filter, so
	 * the optional_limit type resolves the option name from current_filter() and
	 * reads $_POST[{key}_limited] exactly as WordPress drives it on save.
	 *
	 * @param Field $field   The field whose sanitizer to run.
	 * @param mixed $value   Raw posted value.
	 * @param bool  $limited Whether the "_limited" toggle is checked.
	 * @return int
	 */
	private function run_sanitizer( Field $field, $value, bool $limited ): int {
		$key = $field->key;
		$cb  = $field->sanitizer();
		add_filter( "sanitize_option_{$key}", $cb, 10, 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test harness sets the posted toggle directly.
		$_POST = $limited ? array( $key . '_limited' => '1' ) : array();
		$out   = (int) apply_filters( "sanitize_option_{$key}", $value );
		remove_filter( "sanitize_option_{$key}", $cb, 10 );
		$_POST = array();
		return $out;
	}

	/**
	 * The optional_limit type honours the same declared max as number (card
	 * 10287133334): the "limit on" branch was previously bounded only by max(1,…),
	 * so a crafted POST persisted any integer past the declared max.
	 *
	 * @return void
	 */
	public function test_optional_limit_clamps_to_declared_max_when_on(): void {
		$field = new Field(
			array(
				'key' => 'buddynext_data_retention_days',
				'type' => 'optional_limit',
				'min' => 0,
				'max' => 3650,
			)
		);

		$this->assertSame( 3650, $this->run_sanitizer( $field, 999999, true ), 'Above-max clamps to the declared max.' );
		$this->assertSame( 500, $this->run_sanitizer( $field, 500, true ), 'In-range value is kept.' );
	}

	/**
	 * The optional_limit "limit off" branch (toggle unchecked) still stores 0 and
	 * is NEVER clamped up to min — 0 means "no limit", not an out-of-range value.
	 *
	 * @return void
	 */
	public function test_optional_limit_off_stores_zero_and_is_not_clamped(): void {
		$field = new Field(
			array(
				'key' => 'buddynext_reg_rate_limit',
				'type' => 'optional_limit',
				'min' => 0,
				'max' => 100,
			)
		);

		$this->assertSame( 0, $this->run_sanitizer( $field, 999999, false ), 'Toggle off stores 0 (limit disabled), unclamped.' );
		$this->assertSame( 100, $this->run_sanitizer( $field, 5000, true ), 'Toggle on clamps to the declared max.' );
		$this->assertSame( 1, $this->run_sanitizer( $field, 0, true ), 'On + 0 keeps the max(1,…) floor.' );
	}
}
