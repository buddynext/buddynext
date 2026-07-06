<?php
/**
 * Derives Settings-API registration and save-grouping from field descriptors.
 *
 * The single place that turns a page's declared fields into register_setting()
 * calls (under per-tab option groups) and answers "which group saves this
 * option?". Replaces the hand-maintained SETTINGS_MAP + TAB_OPTIONS lists.
 *
 * @package BuddyNext\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Settings;

use BuddyNext\Contracts\ProvidesSettings;

/**
 * Registration + save-group resolution from descriptors.
 */
final class SettingsDriver {

	/**
	 * Register every field of a page with the WordPress Settings API.
	 *
	 * Each field is registered under "{group_prefix}_{tab}" so a save only
	 * touches the active tab's options. `readonly` fields are display-only and
	 * never registered.
	 *
	 * @param ProvidesSettings $page         Page whose fields to register.
	 * @param string           $group_prefix Option-group prefix (e.g. 'buddynext').
	 * @return void
	 */
	public static function register_page( ProvidesSettings $page, string $group_prefix ): void {
		foreach ( $page->settings_fields() as $section ) {
			$group = $group_prefix . '_' . $section->tab;
			foreach ( $section->fields as $field ) {
				// Display-only or bespoke composite controls carry no registered
				// option of their own (their backing option, if any, is bespoke).
				if ( 'readonly' === $field->type || 'custom' === $field->type ) {
					continue;
				}
				$args = array(
					'type'              => self::wp_type( $field->type ),
					'sanitize_callback' => $field->sanitizer(),
				);
				// Register a default ONLY when the field explicitly declared one.
				// A registered default is required for default-ON booleans with no
				// seeded DB row (else saving OFF equals WP's absent-default and the
				// row is never written). Fields with no declared default keep their
				// read-site inline fallback (which may be dynamic) untouched.
				if ( $field->has_default ) {
					$args['default'] = $field->default;
				}
				register_setting( $group, $field->key, $args );
			}
		}
	}

	/**
	 * The option group that saves a given key.
	 *
	 * @param string $key          Option name.
	 * @param string $group_prefix Option-group prefix (e.g. 'buddynext').
	 * @return string
	 */
	public static function save_group_of( string $key, string $group_prefix ): string {
		foreach ( SettingsRegistry::pages() as $page ) {
			foreach ( $page->settings_fields() as $section ) {
				foreach ( $section->fields as $field ) {
					if ( $field->key === $key ) {
						return $group_prefix . '_' . $section->tab;
					}
				}
			}
		}
		return $group_prefix;
	}

	/**
	 * Map a field type to the WordPress register_setting() scalar type.
	 *
	 * @param string $type Field type.
	 * @return string 'boolean'|'integer'|'string'
	 */
	private static function wp_type( string $type ): string {
		if ( 'toggle' === $type ) {
			return 'boolean';
		}
		if ( 'number' === $type ) {
			return 'integer';
		}
		return 'string';
	}
}
