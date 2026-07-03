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
}
