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
		'toggle'   => 'rest_sanitize_boolean',
		'text'     => 'sanitize_text_field',
		'textarea' => 'sanitize_textarea_field',
		'number'   => 'absint',
		'select'   => 'sanitize_key',
		'color'    => 'sanitize_hex_color',
		'media'    => 'esc_url_raw',
		'password' => 'sanitize_text_field',
		'secret'   => 'sanitize_text_field',
		'email'    => 'sanitize_email',
		'url'      => 'esc_url_raw',
		'readonly' => 'sanitize_text_field',
	);

	/**
	 * Type => AdminPageBase render helper method name.
	 *
	 * @var array<string, string>
	 */
	private const HELPERS = array(
		'toggle'   => 'render_toggle_row',
		'text'     => 'render_text_row',
		'textarea' => 'render_textarea_row',
		'number'   => 'render_number_row',
		'select'   => 'render_select_row',
		'color'    => 'render_color_row',
		'media'    => 'render_media_row',
		'password' => 'render_password_row',
		'secret'   => 'render_password_row',
		'email'    => 'render_text_row',
		'url'      => 'render_text_row',
		'readonly' => 'render_text_row',
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
	 * Resolve the render-helper method name for a type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	public static function render_helper( string $type ): string {
		return self::HELPERS[ $type ] ?? 'render_text_row';
	}
}
