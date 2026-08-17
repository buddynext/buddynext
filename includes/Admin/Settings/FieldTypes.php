<?php
/**
 * Field type maps for the admin settings registry.
 *
 * Single place that resolves an option's declared `type` to (a) its default
 * WordPress sanitizer and (b) the AdminPageBase render helper that draws it.
 * A field names its type once; render, save, and sanitize all derive from here.
 *
 * @package BuddyNext\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Settings;

/**
 * Resolves field types to sanitizers and render helpers.
 */
final class FieldTypes {

	/**
	 * Type => default sanitize callback (overridable per-field via Field::$sanitize).
	 *
	 * @var array<string, callable|string>
	 */
	private const SANITIZERS = array(
		'toggle'         => 'rest_sanitize_boolean',
		'text'           => 'sanitize_text_field',
		'textarea'       => 'sanitize_textarea_field',
		'number'         => 'absint',
		// A limit that can be switched off. Sanitised, not just cast: the
		// companion checkbox decides whether the number counts at all, so the
		// callback has to look wider than the value it is handed.
		'optional_limit' => array( self::class, 'sanitize_optional_limit' ),
		'select'         => 'sanitize_key',
		'color'          => 'sanitize_hex_color',
		'media'          => 'esc_url_raw',
		'password'       => 'sanitize_text_field',
		'secret'         => 'sanitize_text_field',
		'email'          => 'sanitize_email',
		'url'            => 'esc_url_raw',
		'readonly'       => 'sanitize_text_field',
	);

	/**
	 * Type => AdminPageBase render helper method name.
	 *
	 * @var array<string, string>
	 */
	private const HELPERS = array(
		'toggle'         => 'render_toggle_row',
		'text'           => 'render_text_row',
		'textarea'       => 'render_textarea_row',
		'number'         => 'render_number_row',
		'optional_limit' => 'render_optional_limit_row',
		'select'         => 'render_select_row',
		'color'          => 'render_color_row',
		'media'          => 'render_media_row',
		'password'       => 'render_password_row',
		'secret'         => 'render_password_row',
		'email'          => 'render_text_row',
		'url'            => 'render_text_row',
		'readonly'       => 'render_text_row',
	);

	/**
	 * Resolve the default sanitizer for a type.
	 *
	 * @param string $type Field type.
	 * @return callable|string
	 */
	public static function sanitizer( string $type ) {
		return self::SANITIZERS[ $type ] ?? 'sanitize_text_field';
	}

	/**
	 * Sanitize a limit that can be switched off.
	 *
	 * WordPress hands a sanitize_callback only its own field's value, but this
	 * control's meaning lives in the checkbox beside it — which is not a
	 * registered setting, so `options.php` never passes it here. The option name
	 * is recovered from the current filter (`sanitize_option_{$option}`), which
	 * is how one shared callback can tell WHICH field it is sanitising without a
	 * closure per field.
	 *
	 * Not from a second parameter, though `sanitize_option()` documents one:
	 * `register_setting()` attaches the callback with `add_filter( … )` and NO
	 * accepted-args count, so it defaults to 1 and only the value ever arrives.
	 * Trusting the documented signature would have left `$option` empty, dropped
	 * every field into the direct-call fallback below, and saved the number
	 * instead of 0 on every limit an owner switched off — silently, because the
	 * number input still holds a plausible value.
	 *
	 * Unticked stores 0 whatever the number says. That is the point: the number
	 * keeps its value while the limit is off, so reading it alone would put back
	 * a limit the owner had just removed.
	 *
	 * @param mixed $value Raw posted value for the number.
	 * @return int
	 */
	public static function sanitize_optional_limit( $value ): int {
		$option = (string) preg_replace( '/^sanitize_option_/', '', (string) current_filter() );

		// No option name means this was called directly rather than through the
		// Settings API; fall back to the plain number so a direct caller still
		// gets a sane value instead of a silent 0.
		if ( '' === $option || 'sanitize_option_' === $option ) {
			return absint( $value );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce before any sanitize_callback runs.
		if ( empty( $_POST[ $option . '_limited' ] ) ) {
			return 0;
		}

		return max( 1, absint( $value ) );
	}

	/**
	 * Resolve the render-helper method name for a type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	public static function render_helper( string $type ): string {
		return self::HELPERS[ $type ] ?? 'render_text_row';
	}
}
