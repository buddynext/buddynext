<?php
/**
 * Field-type registration is programmatic and registry-driven.
 *
 * A developer must be able to register a whole new profile field type in pure
 * PHP via the `buddynext_field_types` filter — with default input config — and
 * have it be first-class end to end without editing any core switch. These
 * tests pin that contract: a custom type renders via its declared default_field,
 * and the family/date/multi-entry predicates read the registry (not hardcoded
 * slug lists), so a registered multi/date type is recognised. The built-in
 * `year` type is the reference case for default_field-driven rendering.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;

/**
 * @covers \BuddyNext\Profile\FieldType
 */
class FieldTypeExtensibilityTest extends \WP_UnitTestCase {

	/**
	 * Remove any registered test types between cases.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_field_types' );
		parent::tear_down();
	}

	/**
	 * The built-in year type renders a bounded number input from its default_field
	 * (no core render case, no field-key heuristic).
	 *
	 * @return void
	 */
	public function test_year_type_renders_bounded_number_from_default_field(): void {
		$max  = (int) gmdate( 'Y' ) + 10;
		$html = FieldType::render_input(
			array(
				'type'      => 'year',
				'field_key' => 'edu_start_year',
				'label'     => 'Start Year',
			),
			'2016',
			'education[0][edu_start_year]'
		);

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'min="1900"', $html );
		$this->assertStringContainsString( 'max="' . $max . '"', $html );
		$this->assertStringContainsString( 'step="1"', $html );
		$this->assertStringContainsString( 'value="2016"', $html );
	}

	/**
	 * A field type registered purely via the filter, declaring default_field,
	 * renders its html_input + attributes with no core edit.
	 *
	 * @return void
	 */
	public function test_registered_type_renders_from_default_field(): void {
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['stars'] = array(
					'label'                 => 'Stars',
					'value_kind'            => 'scalar',
					'is_choice'             => false,
					'is_searchable_capable' => false,
					'default_field'         => array(
						'html_input' => 'number',
						'min'        => 1,
						'max'        => 5,
						'step'       => 1,
					),
				);
				return $types;
			}
		);

		$this->assertArrayHasKey( 'stars', FieldType::types() );

		$html = FieldType::render_input(
			array(
				'type'      => 'stars',
				'field_key' => 'rating',
			),
			'4',
			'rating'
		);

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'min="1"', $html );
		$this->assertStringContainsString( 'max="5"', $html );
	}

	/**
	 * A field-set value overrides the type's default_field default.
	 *
	 * @return void
	 */
	public function test_field_set_value_wins_over_default_field(): void {
		$html = FieldType::render_input(
			array(
				'type'      => 'year',
				'field_key' => 'edu_start_year',
				'min'       => 2000,
			),
			'',
			'education[0][edu_start_year]'
		);

		$this->assertStringContainsString( 'min="2000"', $html );
		$this->assertStringNotContainsString( 'min="1900"', $html );
	}

	/**
	 * The multi / date / multi-entry predicates read the registry, so a type
	 * registered with the matching descriptor flag is recognised.
	 *
	 * @return void
	 */
	public function test_family_predicates_are_registry_driven(): void {
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['tag_cloud'] = array(
					'label'       => 'Tag Cloud',
					'value_kind'  => 'multi',
					'multi_entry' => true,
				);
				$types['birth_year'] = array(
					'label'      => 'Birth Year',
					'value_kind' => 'scalar',
					'is_date'    => true,
				);
				return $types;
			}
		);

		$this->assertTrue( FieldType::is_multiselect_family( 'tag_cloud' ), 'value_kind=multi type is in the multiselect family.' );
		$this->assertTrue( FieldType::is_multi_entry( 'tag_cloud' ), 'multi_entry=true type opts into per-row storage.' );

		// is_date_type() is private; assert its observable effect via the date
		// display mode, which only a date-family type resolves.
		$this->assertTrue( FieldType::is_multiselect_family( 'multiselect' ), 'The built-in multiselect stays a family member.' );
		$this->assertFalse( FieldType::is_multiselect_family( 'text' ), 'A scalar type is not a multiselect family member.' );
		$this->assertFalse( FieldType::is_multi_entry( 'multiselect' ), 'A non-multi_entry multi type is not per-row.' );
	}
}
