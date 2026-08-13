<?php
/**
 * Shared conformance harness for the profile field-type engine.
 *
 * Every registered field type must render a human-readable value on EVERY engine
 * output, not just the ones its author happened to wire. This trait holds the
 * assertions; free runs them over its built-in registry, Pro requires this file
 * and runs the same assertions with its own types loaded.
 *
 * Why a shared trait rather than two copies: the whole point of the contract is
 * that it is identical on both sides of the free/pro seam. Two copies drift, and
 * the drift is exactly the bug this guards against.
 *
 * @package BuddyNext\Tests\Harness
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Harness;

use BuddyNext\Profile\FieldType;

/**
 * Assertions that hold for every field type, built-in or registered by an add-on.
 */
trait FieldTypeConformance {

	/**
	 * Representative stored values for free's built-in types.
	 *
	 * Extension types (anything added through `buddynext_field_types`) do NOT get
	 * an entry here on purpose — they must declare their own `sample_value` in the
	 * registry. See assert_registry_conforms() for why.
	 *
	 * @return array<string, array{value:mixed, options?:array<string,string>}>
	 */
	protected function builtin_samples(): array {
		return array(
			'text'                    => array( 'value' => 'Plain text' ),
			'textarea'                => array( 'value' => "Line one\nLine two" ),
			'url'                     => array( 'value' => 'https://example.com' ),
			'email'                   => array( 'value' => 'member@example.com' ),
			'phone'                   => array( 'value' => '+1 555 0100' ),
			'color'                   => array( 'value' => '#ff0000' ),
			'number'                  => array( 'value' => '42' ),
			'date'                    => array( 'value' => '1990-01-01' ),
			'boolean'                 => array( 'value' => '1' ),
			'select'                  => array(
				'value'   => 'option-one',
				'options' => array( 'option-one' => 'Option One' ),
			),
			'radio'                   => array(
				'value'   => 'option-one',
				'options' => array( 'option-one' => 'Option One' ),
			),
			'multiselect'             => array(
				'value'   => 'option-one,option-two',
				'options' => array(
					'option-one' => 'Option One',
					'option-two' => 'Option Two',
				),
			),
			// Live-optioned types resolve their own choices from the site's taxonomy /
			// member types, so the harness seeds those in the test's set_up() rather
			// than declaring options here.
			'category_multiselect'    => array( 'value' => '' ),
			'member_type_multiselect' => array( 'value' => '' ),
			'member_type'             => array( 'value' => '' ),
		);
	}

	/**
	 * Types whose stored value legitimately IS their display value.
	 *
	 * For these, display_text() returning the input unchanged is correct rather
	 * than a passthrough bug.
	 *
	 * @return string[]
	 */
	protected function plain_text_types(): array {
		return array( 'text', 'textarea', 'url', 'email', 'phone', 'color', 'number' );
	}

	/**
	 * Run the full contract over every registered type.
	 *
	 * A type registered through the `buddynext_field_types` extension point must
	 * declare a `sample_value` (and, when it is a choice type, `sample_options`).
	 * That requirement is the forcing function: a new Pro or third-party type
	 * cannot pass this test by simply not being listed anywhere, which is how the
	 * location/multi_select_advanced/conditional gaps survived unnoticed.
	 *
	 * @return void
	 */
	protected function assert_registry_conforms(): void {
		$samples  = $this->builtin_samples();
		$registry = FieldType::types();

		$this->assertNotEmpty( $registry, 'The field-type registry is empty.' );

		foreach ( $registry as $slug => $def ) {
			$slug = (string) $slug;

			if ( isset( $samples[ $slug ] ) ) {
				$value   = $samples[ $slug ]['value'];
				$options = $samples[ $slug ]['options'] ?? null;
			} else {
				$this->assertArrayHasKey(
					'sample_value',
					$def,
					sprintf(
						'Field type "%s" is registered but declares no `sample_value`. '
						. 'Every extension type must declare a representative stored value so the '
						. 'conformance contract can be checked against it. Add `sample_value` '
						. '(and `sample_options` for a choice type) to its registry entry.',
						$slug
					)
				);
				$value   = $def['sample_value'];
				$options = $def['sample_options'] ?? null;
			}

			// A type may legitimately have nothing meaningful to sample (live-optioned
			// types before their taxonomy exists). Skip the value assertions, never the
			// registration one above.
			if ( '' === (string) $value ) {
				continue;
			}

			$field = array(
				'type'      => $slug,
				'field_key' => 'conformance_' . $slug,
				'label'     => 'Conformance probe',
				'options'   => $options,
			);

			$this->assert_display_text_is_human_readable( $slug, $field, $value );
			$this->assert_rest_value_matches_kind( $slug, $def, $field, $value );
			$this->assert_render_display_hides_raw_storage( $slug, $field, $value );
		}
	}

	/**
	 * display_text() must return something a person can read — never raw storage.
	 *
	 * @param string              $slug  Type slug.
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Representative stored value.
	 * @return void
	 */
	protected function assert_display_text_is_human_readable( string $slug, array $field, $value ): void {
		$out = FieldType::display_text( $field, $value );

		$this->assertIsString( $out, sprintf( '%s: display_text() must return a string.', $slug ) );
		$this->assertNotSame(
			'',
			trim( $out ),
			sprintf( '%s: display_text() returned empty for a non-empty stored value.', $slug )
		);

		// A structured payload reaching a plain-text surface is the whole bug class:
		// the profile hero, notifications and exports all print this verbatim.
		$decoded = json_decode( (string) $out, true );
		$this->assertFalse(
			is_array( $decoded ),
			sprintf(
				'%s: display_text() returned decodable JSON ("%s"). A structured value must be '
				. 'rendered, not handed over raw — register on `buddynext_field_display_text`.',
				$slug,
				mb_substr( (string) $out, 0, 80 )
			)
		);

		// A choice type must map its stored slug(s) to labels. Returning the raw slug
		// is how multi_select_advanced shipped: correct-looking, and wrong on every
		// surface that is not the About panel.
		if ( ! empty( $field['options'] ) && ! in_array( $slug, $this->plain_text_types(), true ) ) {
			$this->assertNotSame(
				(string) $value,
				(string) $out,
				sprintf(
					'%s: display_text() returned the stored slug(s) verbatim instead of the '
					. 'configured option label(s).',
					$slug
				)
			);
		}
	}

	/**
	 * rest_value() must honour the type's declared value_kind.
	 *
	 * An app client should never have to re-parse a comma string or a JSON blob out
	 * of a field the registry already described as multi or scalar.
	 *
	 * @param string              $slug  Type slug.
	 * @param array<string,mixed> $def   Registry entry.
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Representative stored value.
	 * @return void
	 */
	protected function assert_rest_value_matches_kind( string $slug, array $def, array $field, $value ): void {
		$kind = (string) ( $def['value_kind'] ?? 'scalar' );
		$out  = FieldType::rest_value( $field, $value );

		switch ( $kind ) {
			case 'bool':
				$this->assertIsBool(
					$out,
					sprintf( '%s: value_kind is bool, so rest_value() must return a real boolean.', $slug )
				);
				break;

			case 'multi':
				$this->assertIsArray(
					$out,
					sprintf(
						'%s: value_kind is multi, so rest_value() must return an array — not a '
						. 'comma string the client has to split.',
						$slug
					)
				);
				break;

			case 'scalar':
			default:
				$this->assertTrue(
					is_string( $out ) || is_int( $out ) || is_float( $out ),
					sprintf( '%s: value_kind is scalar, so rest_value() must return a scalar.', $slug )
				);

				if ( is_string( $out ) ) {
					$decoded = json_decode( $out, true );
					$this->assertFalse(
						is_array( $decoded ),
						sprintf(
							'%s: rest_value() returned raw JSON. `value` is the display channel; '
							. 'structure belongs in the owner-only `value_raw`.',
							$slug
						)
					);
				}
				break;
		}
	}

	/**
	 * render_display() must not echo raw structured storage into the About panel.
	 *
	 * @param string              $slug  Type slug.
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Representative stored value.
	 * @return void
	 */
	protected function assert_render_display_hides_raw_storage( string $slug, array $field, $value ): void {
		$raw = (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );

		// Only meaningful for structured storage: a text field's HTML legitimately
		// contains its own value.
		if ( ! is_array( json_decode( $raw, true ) ) ) {
			return;
		}

		$html  = FieldType::render_display( $field, $value );
		$plain = trim( wp_strip_all_tags( $html ) );

		$this->assertStringNotContainsString(
			'"lat"',
			$plain,
			sprintf( '%s: render_display() leaked raw JSON keys into the rendered output.', $slug )
		);
		$this->assertNotSame(
			$raw,
			$plain,
			sprintf( '%s: render_display() echoed the stored payload verbatim.', $slug )
		);
	}
}
