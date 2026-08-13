<?php
/**
 * Contract test: every registered field type renders on every engine output.
 *
 * The engine has eight outputs and, before this test, two of them
 * (display_text / rest_value) had no extension point — so a type could be fully
 * wired for the About panel and silently hand back raw storage everywhere else.
 * That is how the Location map type shipped a JSON blob onto the profile hero,
 * and it was never one type's bug: multi_select_advanced, number_advanced,
 * conditional and the built-in member_type all sat on the same hole.
 *
 * This test exists so the next structured type cannot repeat it. It iterates the
 * live registry rather than a hand-written list, so a type added tomorrow is
 * covered the day it lands.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Tests\Harness\FieldTypeConformance;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/harness/field-type-conformance.php';

/**
 * @covers \BuddyNext\Profile\FieldType::display_text
 * @covers \BuddyNext\Profile\FieldType::rest_value
 * @covers \BuddyNext\Profile\FieldType::render_display
 */
class FieldTypeConformanceTest extends WP_UnitTestCase {

	use FieldTypeConformance;

	/**
	 * Install the schema so live-optioned types can resolve their choices.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Leave the registry as we found it — a stray filter would silently change
	 * what every later test in the suite sees.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_field_types' );
		remove_all_filters( 'buddynext_field_display_text' );
		remove_all_filters( 'buddynext_field_rest_value' );
		parent::tear_down();
	}

	/**
	 * Every built-in type honours the display contract on every output.
	 *
	 * @return void
	 */
	public function test_builtin_registry_conforms(): void {
		$this->assert_registry_conforms();
	}

	/**
	 * An extension type that wires its outputs passes.
	 *
	 * This is the shape Pro's types must take: declare a sample, register on the
	 * display seams, and the contract is satisfied.
	 *
	 * @return void
	 */
	public function test_conforming_extension_type_passes(): void {
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['conformance_good'] = array(
					'label'                 => 'Conforming probe',
					'value_kind'            => 'scalar',
					'is_choice'             => false,
					'is_searchable_capable' => false,
					'sample_value'          => '{"address":"Lucknow, India","lat":26.8,"lng":80.9}',
				);
				return $types;
			}
		);

		$decode = static function ( $out, array $field, $value ) {
			if ( 'conformance_good' !== ( $field['type'] ?? '' ) ) {
				return $out;
			}
			$parts = json_decode( (string) $value, true );
			return is_array( $parts ) && isset( $parts['address'] ) ? (string) $parts['address'] : $out;
		};

		add_filter( 'buddynext_field_display_text', $decode, 10, 3 );
		add_filter( 'buddynext_field_rest_value', $decode, 10, 3 );

		$this->assert_registry_conforms();
	}

	/**
	 * An extension type that leaks raw storage FAILS.
	 *
	 * Guards the guard: if this ever passes, the contract has stopped being
	 * enforced and every assertion above is decorative.
	 *
	 * @return void
	 */
	public function test_leaking_extension_type_is_caught(): void {
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['conformance_leaky'] = array(
					'label'                 => 'Leaky probe',
					'value_kind'            => 'scalar',
					'is_choice'             => false,
					'is_searchable_capable' => false,
					'sample_value'          => '{"address":"Lucknow, India","lat":26.8,"lng":80.9}',
				);
				return $types;
			}
		);

		// No display_text / rest_value registration — the value falls through raw.
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->assert_registry_conforms();
	}

	/**
	 * An extension type that declares no sample FAILS.
	 *
	 * Without this, a new type opts out of the contract by staying silent — which
	 * is exactly how the existing gaps went unnoticed.
	 *
	 * @return void
	 */
	public function test_extension_type_without_sample_is_caught(): void {
		add_filter(
			'buddynext_field_types',
			static function ( array $types ): array {
				$types['conformance_undeclared'] = array(
					'label'                 => 'Undeclared probe',
					'value_kind'            => 'scalar',
					'is_choice'             => false,
					'is_searchable_capable' => false,
				);
				return $types;
			}
		);

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->assert_registry_conforms();
	}
}
