<?php
/**
 * Tests for FieldType::presentation_for() — the type → About-tab layout classifier.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_UnitTestCase;

/**
 * Covers the type-based presentation-mode contract for the profile About tab.
 *
 * @covers \BuddyNext\Profile\FieldType::presentation_for
 */
class FieldTypePresentationTest extends WP_UnitTestCase {

	/**
	 * Drop any presentation/type filters a test added so cases stay isolated.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_field_presentation' );
		remove_all_filters( 'buddynext_field_types' );
		parent::tear_down();
	}

	/**
	 * A textarea lays out as a full-width block.
	 *
	 * @return void
	 */
	public function test_textarea_is_block(): void {
		$this->assertSame( 'block', FieldType::presentation_for( 'textarea' ) );
	}

	/**
	 * A url renders as a standalone link affordance.
	 *
	 * @return void
	 */
	public function test_url_is_link(): void {
		$this->assertSame( 'link', FieldType::presentation_for( 'url' ) );
	}

	/**
	 * Every multi-value type renders as a row of chips.
	 *
	 * @return void
	 */
	public function test_multi_value_types_are_chips(): void {
		$this->assertSame( 'chips', FieldType::presentation_for( 'multiselect' ) );
		$this->assertSame( 'chips', FieldType::presentation_for( 'category_multiselect' ) );
		$this->assertSame( 'chips', FieldType::presentation_for( 'member_type_multiselect' ) );
	}

	/**
	 * Scalar and boolean types lay out inline (label + value on one line).
	 *
	 * @dataProvider inline_types
	 *
	 * @param string $type Field type slug expected to resolve to the inline mode.
	 * @return void
	 */
	public function test_scalar_and_bool_types_are_inline( string $type ): void {
		$this->assertSame( 'inline', FieldType::presentation_for( $type ) );
	}

	/**
	 * The scalar/bool types that must all classify as inline.
	 *
	 * @return array<int,array{0:string}>
	 */
	public function inline_types(): array {
		return array(
			array( 'text' ),
			array( 'number' ),
			array( 'date' ),
			array( 'email' ),
			array( 'phone' ),
			array( 'select' ),
			array( 'radio' ),
			array( 'boolean' ),
			array( 'color' ),
		);
	}

	/**
	 * An unknown type degrades safely to inline rather than erroring.
	 *
	 * @return void
	 */
	public function test_unknown_type_degrades_to_inline(): void {
		$this->assertSame( 'inline', FieldType::presentation_for( 'no_such_type_xyz' ) );
	}

	/**
	 * The buddynext_field_presentation filter can override a type's mode.
	 *
	 * @return void
	 */
	public function test_filter_can_override_mode(): void {
		add_filter(
			'buddynext_field_presentation',
			static function ( string $mode, string $type ): string {
				return 'text' === $type ? 'block' : $mode;
			},
			10,
			2
		);
		$this->assertSame( 'block', FieldType::presentation_for( 'text' ) );
	}

	/**
	 * A filter returning an unknown mode falls back to inline.
	 *
	 * @return void
	 */
	public function test_filter_returning_garbage_falls_back_to_inline(): void {
		add_filter( 'buddynext_field_presentation', static fn(): string => 'nonsense' );
		$this->assertSame( 'inline', FieldType::presentation_for( 'text' ) );
	}
}
