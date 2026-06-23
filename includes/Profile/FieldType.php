<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext profile field-type engine.
 *
 * The single source of truth for how every profile field type behaves across
 * all six layers: input → validate → store → display → search → privacy.
 *
 * Every consumer (ProfileService, the directory search, the edit/view
 * templates, the admin field config) delegates here so that adding a niche
 * field type is pure data (admin UI) and never code. The class is pure and
 * dependency-free: no DB access, no globals, no side effects beyond reading
 * the field definition array it is handed.
 *
 * Field definition array shape (as produced by ProfileService::get_fields):
 *
 *   [
 *     'field_key'     => 'favourite_opening',
 *     'label'         => 'Favourite Opening',
 *     'type'          => 'select',
 *     'options'       => ['Sicilian', 'Caro-Kann', ...] | null,   // JSON-decoded
 *     'is_searchable' => true|false,
 *     ...
 *   ]
 *
 * Options-bearing types (select / radio / multiselect) read their choices from
 * $field['options'] — a flat list of human-readable strings. Each option's
 * machine slug is sanitize_title() of the string; its label is the string
 * itself. multi (multiselect) values are stored as a comma-joined list of
 * option SLUGS.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use WP_Error;

/**
 * Stateless field-type engine. All members are static.
 */
class FieldType {

	/**
	 * Built-in type registry.
	 *
	 * Keyed by type slug; each entry is:
	 *   'label'                 => string  Human label for the admin type picker.
	 *   'value_kind'            => string  scalar | multi | bool.
	 *   'is_choice'             => bool    Reads choices from $field['options'].
	 *   'is_searchable_capable' => bool    May expose a free-text search mirror.
	 *
	 * @var array<string,array{label:string,value_kind:string,is_choice:bool,is_searchable_capable:bool}>
	 */
	private static function builtin_types(): array {
		return array(
			'text'        => array(
				'label'                 => __( 'Text', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => true,
			),
			'textarea'    => array(
				'label'                 => __( 'Paragraph', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => true,
			),
			'url'         => array(
				'label'                 => __( 'URL', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => true,
			),
			'email'       => array(
				'label'                 => __( 'Email', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => true,
			),
			'phone'       => array(
				'label'                 => __( 'Phone', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => true,
			),
			'number'      => array(
				'label'                 => __( 'Number', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => false,
			),
			'date'        => array(
				'label'                 => __( 'Date', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => false,
			),
			'boolean'     => array(
				'label'                 => __( 'Yes / No', 'buddynext' ),
				'value_kind'            => 'bool',
				'is_choice'             => false,
				'is_searchable_capable' => false,
			),
			'select'      => array(
				'label'                 => __( 'Dropdown', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => true,
				'is_searchable_capable' => true,
			),
			'radio'       => array(
				'label'                 => __( 'Radio', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => true,
				'is_searchable_capable' => true,
			),
			'multiselect' => array(
				'label'                 => __( 'Multi-select', 'buddynext' ),
				'value_kind'            => 'multi',
				'is_choice'             => true,
				'is_searchable_capable' => true,
			),
			'color'       => array(
				'label'                 => __( 'Colour', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => false,
			),
			'file'        => array(
				'label'                 => __( 'File', 'buddynext' ),
				'value_kind'            => 'scalar',
				'is_choice'             => false,
				'is_searchable_capable' => false,
			),
		);
	}

	/**
	 * Return the full type registry, filterable by add-ons.
	 *
	 * Add-ons register new types by hooking `buddynext_field_types` and adding
	 * a slug => descriptor entry. Each descriptor MUST provide the four keys
	 * (label, value_kind, is_choice, is_searchable_capable); malformed entries
	 * are normalised so consumers can rely on the shape.
	 *
	 * @return array<string,array{label:string,value_kind:string,is_choice:bool,is_searchable_capable:bool}>
	 */
	public static function types(): array {
		/**
		 * Filter the registered profile field types.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array> $types slug => descriptor map.
		 */
		$types = (array) apply_filters( 'buddynext_field_types', self::builtin_types() );

		$normalised = array();
		foreach ( $types as $slug => $descriptor ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$descriptor          = is_array( $descriptor ) ? $descriptor : array();
			$normalised[ $slug ] = array(
				'label'                 => isset( $descriptor['label'] ) ? (string) $descriptor['label'] : ucfirst( $slug ),
				'value_kind'            => in_array( $descriptor['value_kind'] ?? '', array( 'scalar', 'multi', 'bool' ), true ) ? (string) $descriptor['value_kind'] : 'scalar',
				'is_choice'             => ! empty( $descriptor['is_choice'] ),
				'is_searchable_capable' => ! empty( $descriptor['is_searchable_capable'] ),
			);
		}

		return $normalised;
	}

	/**
	 * Resolve a raw type slug to a known type, degrading to `text`.
	 *
	 * @param string $type Requested type slug.
	 * @return string A slug guaranteed to exist in self::types().
	 */
	private static function resolve_type( string $type ): string {
		$types = self::types();
		if ( isset( $types[ $type ] ) ) {
			return $type;
		}

		return isset( $types['text'] ) ? 'text' : (string) array_key_first( $types );
	}

	/**
	 * Whether a type exposes a free-text search mirror.
	 *
	 * Text / textarea / url / email / phone / select / radio / multiselect are
	 * searchable; number / date / boolean / color / file are not free-text.
	 *
	 * @param string $type Field type slug.
	 * @return bool True when the type may back a searchable mirror.
	 */
	public static function is_text_searchable( string $type ): bool {
		$types = self::types();
		$type  = self::resolve_type( $type );

		return ! empty( $types[ $type ]['is_searchable_capable'] );
	}

	/**
	 * Normalise a field definition's options into slug => label pairs.
	 *
	 * Options are stored as a flat list of human-readable strings. Each option's
	 * machine slug is sanitize_title() of the string; the label is the string.
	 * Already-associative (slug => label) arrays are respected as-is.
	 *
	 * @param array $field Field definition.
	 * @return array<string,string> slug => label.
	 */
	private static function options( array $field ): array {
		$raw = $field['options'] ?? null;
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$pairs = array();
		foreach ( $raw as $key => $value ) {
			if ( is_int( $key ) ) {
				$label = (string) $value;
				$slug  = sanitize_title( $label );
			} else {
				$slug  = sanitize_title( (string) $key );
				$label = (string) $value;
			}
			if ( '' === $slug ) {
				continue;
			}
			$pairs[ $slug ] = $label;
		}

		return $pairs;
	}

	/**
	 * Split a stored multi value (comma-joined slugs) into a slug list.
	 *
	 * @param mixed $value Stored value (string of comma-joined slugs, or array).
	 * @return string[] Option slugs.
	 */
	private static function multi_values( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = explode( ',', (string) $value );
		}

		$slugs = array();
		foreach ( $parts as $part ) {
			$slug = sanitize_title( (string) $part );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Render the edit-form input control for a field. Always escaped.
	 *
	 * @param array  $field Field definition.
	 * @param mixed  $value Current stored value.
	 * @param string $name  Form field `name` attribute.
	 * @return string Escaped HTML control.
	 */
	public static function render_input( array $field, $value, string $name ): string {
		// Add-on hook: lets Pro / extensions render their own field types (e.g.
		// location, conditional) registered via buddynext_field_types. Return an
		// escaped HTML string to take over; null falls through to the core types
		// (and unknown-without-handler degrades to a text input below).
		$custom = apply_filters( 'buddynext_field_render_input', null, $field, $value, $name );
		if ( is_string( $custom ) ) {
			return $custom;
		}

		$type     = self::resolve_type( isset( $field['type'] ) ? (string) $field['type'] : 'text' );
		$id       = 'bn-field-' . sanitize_html_class( $name );
		$required = ! empty( $field['is_required'] ) ? ' required' : '';

		switch ( $type ) {
			case 'textarea':
				return sprintf(
					'<textarea class="bn-input bn-field-textarea" id="%1$s" name="%2$s" rows="4"%4$s>%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value ),
					$required
				);

			case 'select':
				return self::render_select_input( $field, $value, $name, $id, $required );

			case 'radio':
				return self::render_radio_input( $field, $value, $name, $id );

			case 'multiselect':
				return self::render_multiselect_input( $field, $value, $name, $id );

			case 'boolean':
				return sprintf(
					'<label class="bn-field-checkbox"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> <span>%4$s</span></label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( self::truthy( $value ), true, false ),
					esc_html( isset( $field['label'] ) ? (string) $field['label'] : '' )
				);

			case 'date':
				return self::render_simple_input( 'date', $field, (string) $value, $name, $id, $required );

			case 'number':
				return self::render_simple_input( 'number', $field, (string) $value, $name, $id, $required );

			case 'url':
				return self::render_simple_input( 'url', $field, (string) $value, $name, $id, $required );

			case 'email':
				return self::render_simple_input( 'email', $field, (string) $value, $name, $id, $required );

			case 'phone':
				return self::render_simple_input( 'tel', $field, (string) $value, $name, $id, $required );

			case 'color':
				$color = '' !== (string) $value ? (string) $value : '#000000';
				return sprintf(
					'<input type="color" class="bn-input bn-field-color" id="%1$s" name="%2$s" value="%3$s"%4$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $color ),
					$required
				);

			case 'file':
				$current = '';
				if ( '' !== (string) $value ) {
					$current = sprintf(
						'<a class="bn-field-file-current" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( (string) $value ),
						esc_html__( 'Current file', 'buddynext' )
					);
				}
				return sprintf(
					'<input type="url" class="bn-input bn-field-file" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s"%5$s /> %6$s',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr__( 'https://example.com/file.pdf', 'buddynext' ),
					$required,
					$current
				);

			case 'text':
			default:
				return self::render_simple_input( 'text', $field, (string) $value, $name, $id, $required );
		}
	}

	/**
	 * Render a plain <input> of the given HTML type.
	 *
	 * @param string $html_type HTML input type attribute.
	 * @param array  $field     Field definition.
	 * @param string $value     Current value.
	 * @param string $name      Form name.
	 * @param string $id        Element id.
	 * @param string $required  Pre-built ` required` attribute or ''.
	 * @return string Escaped HTML.
	 */
	private static function render_simple_input( string $html_type, array $field, string $value, string $name, string $id, string $required ): string {
		$placeholder = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';

		return sprintf(
			'<input type="%1$s" class="bn-input bn-field-%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s />',
			esc_attr( $html_type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			'' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '',
			$required
		);
	}

	/**
	 * Render a <select> control for select-type fields.
	 *
	 * @param array  $field    Field definition.
	 * @param mixed  $value    Current value (slug).
	 * @param string $name     Form name.
	 * @param string $id       Element id.
	 * @param string $required Pre-built ` required` attribute or ''.
	 * @return string Escaped HTML.
	 */
	private static function render_select_input( array $field, $value, string $name, string $id, string $required ): string {
		$options  = self::options( $field );
		$selected = sanitize_title( (string) $value );

		$html  = sprintf(
			'<select class="bn-input bn-field-select" id="%1$s" name="%2$s"%3$s>',
			esc_attr( $id ),
			esc_attr( $name ),
			$required
		);
		$html .= '<option value="">' . esc_html__( 'Select…', 'buddynext' ) . '</option>';

		foreach ( $options as $slug => $label ) {
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $slug, $selected, false ),
				esc_html( $label )
			);
		}

		$html .= '</select>';

		return $html;
	}

	/**
	 * Render a radio-button group.
	 *
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value (slug).
	 * @param string $name  Form name.
	 * @param string $id    Element id base.
	 * @return string Escaped HTML.
	 */
	private static function render_radio_input( array $field, $value, string $name, string $id ): string {
		$options  = self::options( $field );
		$selected = sanitize_title( (string) $value );

		$html = '<fieldset class="bn-field-radio-group">';
		$i    = 0;
		foreach ( $options as $slug => $label ) {
			$opt_id = $id . '-' . (string) $i;
			$html  .= sprintf(
				'<label class="bn-field-radio" for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s /> <span>%5$s</span></label>',
				esc_attr( $opt_id ),
				esc_attr( $name ),
				esc_attr( $slug ),
				checked( $slug, $selected, false ),
				esc_html( $label )
			);
			++$i;
		}
		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Render a checkbox group for multiselect fields.
	 *
	 * Submits as `name[]` so the consumer receives an array; sanitize() joins
	 * the chosen option slugs with commas for storage.
	 *
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value (comma-joined slugs or array).
	 * @param string $name  Form name (the `[]` suffix is appended).
	 * @param string $id    Element id base.
	 * @return string Escaped HTML.
	 */
	private static function render_multiselect_input( array $field, $value, string $name, string $id ): string {
		$options  = self::options( $field );
		$selected = self::multi_values( $value );

		$html = '<fieldset class="bn-field-checkbox-group">';
		$i    = 0;
		foreach ( $options as $slug => $label ) {
			$opt_id = $id . '-' . (string) $i;
			$html  .= sprintf(
				'<label class="bn-field-checkbox" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s /> <span>%5$s</span></label>',
				esc_attr( $opt_id ),
				esc_attr( $name ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
			++$i;
		}
		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Render the profile-view display for a field. Always escaped.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored value.
	 * @return string Escaped HTML (empty string when there is nothing to show).
	 */
	public static function render_display( array $field, $value ): string {
		// Add-on hook: extensions render their own types' display output. Return
		// an escaped string to take over; null falls through to core types.
		$custom = apply_filters( 'buddynext_field_render_display', null, $field, $value );
		if ( is_string( $custom ) ) {
			return $custom;
		}

		$type = self::resolve_type( isset( $field['type'] ) ? (string) $field['type'] : 'text' );

		// Boolean is special: false/empty hides the row entirely.
		if ( 'boolean' === $type ) {
			if ( ! self::truthy( $value ) ) {
				return '';
			}
			return '<span class="bn-field-value bn-field-bool">' . esc_html__( 'Yes', 'buddynext' ) . '</span>';
		}

		if ( 'multiselect' === $type ) {
			return self::render_chips( $field, $value );
		}

		// Everything else: nothing to show for an empty value.
		if ( '' === trim( (string) $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'textarea':
				return '<div class="bn-field-value bn-field-paragraphs">' . wp_kses_post( wpautop( esc_html( (string) $value ) ) ) . '</div>';

			case 'url':
				$url = esc_url( (string) $value );
				if ( '' === $url ) {
					return '';
				}
				return sprintf(
					'<a class="bn-field-value bn-field-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					$url,
					esc_html( self::display_host( (string) $value ) )
				);

			case 'email':
				$email = sanitize_email( (string) $value );
				if ( '' === $email || ! is_email( $email ) ) {
					return '';
				}
				return sprintf(
					'<a class="bn-field-value bn-field-mailto" href="%1$s">%2$s</a>',
					esc_url( 'mailto:' . $email ),
					esc_html( $email )
				);

			case 'phone':
				$tel = preg_replace( '/[^0-9+]/', '', (string) $value );
				if ( null === $tel || '' === $tel ) {
					return esc_html( (string) $value );
				}
				return sprintf(
					'<a class="bn-field-value bn-field-tel" href="%1$s">%2$s</a>',
					esc_url( 'tel:' . $tel ),
					esc_html( (string) $value )
				);

			case 'date':
				$ts = strtotime( (string) $value );
				if ( false === $ts ) {
					return esc_html( (string) $value );
				}
				return '<span class="bn-field-value bn-field-date">' . esc_html( date_i18n( (string) get_option( 'date_format', 'F j, Y' ), $ts ) ) . '</span>';

			case 'number':
				return '<span class="bn-field-value bn-field-number">' . esc_html( (string) $value ) . '</span>';

			case 'color':
				$color = self::valid_color( (string) $value );
				if ( '' === $color ) {
					return '';
				}
				return sprintf(
					'<span class="bn-field-value bn-field-color"><span class="bn-color-swatch" style="background-color:%1$s"></span> <code>%2$s</code></span>',
					esc_attr( $color ),
					esc_html( $color )
				);

			case 'file':
				$url = esc_url( (string) $value );
				if ( '' === $url ) {
					return '';
				}
				$is_image = (bool) preg_match( '/\.(jpe?g|png|gif|webp|svg|avif)$/i', (string) $value );
				if ( $is_image ) {
					return sprintf(
						'<a class="bn-field-value bn-field-file bn-field-file-image" href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="%2$s" loading="lazy" /></a>',
						$url,
						esc_attr( isset( $field['label'] ) ? (string) $field['label'] : '' )
					);
				}
				return sprintf(
					'<a class="bn-field-value bn-field-file" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s %3$s</a>',
					$url,
					buddynext_get_icon( 'file', 'bn-field-file-icon' ),
					esc_html( wp_basename( (string) $value ) )
				);

			case 'select':
			case 'radio':
				$options = self::options( $field );
				$slug    = sanitize_title( (string) $value );
				$label   = $options[ $slug ] ?? (string) $value;
				return '<span class="bn-field-value bn-field-option">' . esc_html( $label ) . '</span>';

			case 'text':
			default:
				return '<span class="bn-field-value bn-field-text">' . esc_html( (string) $value ) . '</span>';
		}
	}

	/**
	 * Render multiselect values as chips.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored value (comma-joined slugs or array).
	 * @return string Escaped HTML chip list (empty string when no values).
	 */
	private static function render_chips( array $field, $value ): string {
		$options  = self::options( $field );
		$selected = self::multi_values( $value );
		if ( empty( $selected ) ) {
			return '';
		}

		$chips = '';
		foreach ( $selected as $slug ) {
			$label  = $options[ $slug ] ?? $slug;
			$chips .= '<span class="bn-chip bn-field-chip">' . esc_html( $label ) . '</span>';
		}

		return '<span class="bn-field-value bn-field-chips">' . $chips . '</span>';
	}

	/**
	 * Validate + sanitize a raw submitted value into a storable scalar.
	 *
	 * Returns a storable scalar string (multi → comma-joined option slugs) or a
	 * WP_Error when validation fails.
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Raw submitted value.
	 * @return string|WP_Error Storable scalar, or WP_Error on validation failure.
	 */
	public static function sanitize( array $field, $raw ) {
		// Add-on hook: extensions validate/sanitize their own types. Return a
		// scalar string (or WP_Error) to take over; null falls through to core.
		$custom = apply_filters( 'buddynext_field_sanitize', null, $field, $raw );
		if ( null !== $custom ) {
			return $custom;
		}

		$type  = self::resolve_type( isset( $field['type'] ) ? (string) $field['type'] : 'text' );
		$label = isset( $field['label'] ) ? (string) $field['label'] : ( isset( $field['field_key'] ) ? (string) $field['field_key'] : '' );

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( (string) ( is_array( $raw ) ? '' : $raw ) );

			case 'url':
			case 'file':
				$url = esc_url_raw( trim( (string) $raw ) );
				if ( '' !== trim( (string) $raw ) && '' === $url ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_url', sprintf( __( '%s must be a valid URL.', 'buddynext' ), $label ) );
				}
				return $url;

			case 'email':
				$value = trim( (string) $raw );
				if ( '' === $value ) {
					return '';
				}
				$email = sanitize_email( $value );
				if ( '' === $email || ! is_email( $email ) ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_email', sprintf( __( '%s must be a valid email address.', 'buddynext' ), $label ) );
				}
				return $email;

			case 'phone':
				$value = trim( (string) $raw );
				if ( '' === $value ) {
					return '';
				}
				if ( ! preg_match( '/^[0-9+()\-.\s]{3,32}$/', $value ) ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_phone', sprintf( __( '%s must be a valid phone number.', 'buddynext' ), $label ) );
				}
				return sanitize_text_field( $value );

			case 'number':
				$value = trim( (string) $raw );
				if ( '' === $value ) {
					return '';
				}
				if ( ! is_numeric( $value ) ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_number', sprintf( __( '%s must be a number.', 'buddynext' ), $label ) );
				}
				// Preserve integers as-is, normalise floats.
				return (string) ( $value + 0 );

			case 'date':
				$value = trim( (string) $raw );
				if ( '' === $value ) {
					return '';
				}
				$ts = strtotime( $value );
				if ( false === $ts ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_date', sprintf( __( '%s must be a valid date.', 'buddynext' ), $label ) );
				}
				return gmdate( 'Y-m-d', $ts );

			case 'boolean':
				return self::truthy( $raw ) ? '1' : '0';

			case 'color':
				$value = trim( (string) $raw );
				if ( '' === $value ) {
					return '';
				}
				$color = self::valid_color( $value );
				if ( '' === $color ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_color', sprintf( __( '%s must be a valid colour.', 'buddynext' ), $label ) );
				}
				return $color;

			case 'select':
			case 'radio':
				$value = trim( (string) ( is_array( $raw ) ? '' : $raw ) );
				if ( '' === $value ) {
					return '';
				}
				$options = self::options( $field );
				$slug    = sanitize_title( $value );
				if ( ! isset( $options[ $slug ] ) ) {
					/* translators: %s: field label. */
					return new WP_Error( 'bn_invalid_option', sprintf( __( 'Please choose a valid option for %s.', 'buddynext' ), $label ) );
				}
				return $slug;

			case 'multiselect':
				$options  = self::options( $field );
				$incoming = self::multi_values( $raw );
				$valid    = array();
				foreach ( $incoming as $slug ) {
					if ( isset( $options[ $slug ] ) ) {
						$valid[] = $slug;
					}
				}
				return implode( ',', $valid );

			case 'text':
			default:
				return sanitize_text_field( (string) ( is_array( $raw ) ? '' : $raw ) );
		}
	}

	/**
	 * Produce the human-readable, searchable mirror text for a value.
	 *
	 * Scalar types mirror the sanitized value; choice types mirror option
	 * LABELS (not slugs) so directory search matches what humans typed; multi
	 * mirrors the comma-joined labels. Non-searchable types return ''.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored value.
	 * @return string Mirror text (empty when the type is not searchable).
	 */
	public static function searchable_text( array $field, $value ): string {
		$type = self::resolve_type( isset( $field['type'] ) ? (string) $field['type'] : 'text' );
		if ( ! self::is_text_searchable( $type ) ) {
			return '';
		}

		if ( 'multiselect' === $type ) {
			$options = self::options( $field );
			$labels  = array();
			foreach ( self::multi_values( $value ) as $slug ) {
				$labels[] = $options[ $slug ] ?? $slug;
			}
			return implode( ', ', $labels );
		}

		if ( 'select' === $type || 'radio' === $type ) {
			$options = self::options( $field );
			$slug    = sanitize_title( (string) $value );
			return $options[ $slug ] ?? (string) $value;
		}

		return (string) $value;
	}

	/**
	 * Loosely coerce a value to bool for checkbox / boolean handling.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Validate a colour string (#rgb or #rrggbb), returning a normalised value.
	 *
	 * @param string $value Raw colour.
	 * @return string Normalised lowercase hex colour, or '' when invalid.
	 */
	private static function valid_color( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
			return strtolower( $value );
		}

		return '';
	}

	/**
	 * Reduce a URL to its host for compact link display, falling back to the URL.
	 *
	 * @param string $url Full URL.
	 * @return string Host or the original URL.
	 */
	private static function display_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $host : $url;
	}
}
