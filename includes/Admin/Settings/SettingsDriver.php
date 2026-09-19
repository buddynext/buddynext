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
					$args['default'] = $field->resolve_default();
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

	// ── Restore defaults ──────────────────────────────────────────────────────────

	/**
	 * The admin-post action name for a settings-tab reset.
	 */
	public const RESTORE_ACTION = 'bn_settings_restore_defaults';

	/**
	 * Whether the reset handler is already wired.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Wire the "Restore defaults" admin-post handler. Idempotent.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( self::class, 'handle_restore_defaults' ) );
	}

	/**
	 * The resettable, driver-registered fields on a tab, keyed by option name.
	 * These are the only options "Restore defaults" ever touches — readonly/custom
	 * controls and `resettable => false` owner-data are excluded. Works across every
	 * registered page (Free, Pro AND third-party add-ons), so an add-on's own tab
	 * gets the same Restore behaviour for free.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string, Field>
	 */
	public static function resettable_fields_for_tab( string $tab ): array {
		$out = array();
		foreach ( SettingsRegistry::pages() as $page ) {
			foreach ( $page->settings_fields() as $section ) {
				if ( $section->tab !== $tab ) {
					continue;
				}
				foreach ( $section->fields as $field ) {
					if ( in_array( $field->type, array( 'readonly', 'custom' ), true ) || ! $field->resettable ) {
						continue;
					}
					$out[ $field->key ] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * The dialog preview for a tab reset: which settings WOULD change (label,
	 * current, default) and which resettable settings are already at default. Feeds
	 * the confirm dialog so the owner sees exactly what a reset does before agreeing.
	 *
	 * @param string $tab Tab slug.
	 * @return array{changes: array<int, array{key:string,label:string,current:string,default:string}>, unchanged: int}
	 */
	public static function tab_reset_preview( string $tab ): array {
		$changes   = array();
		$unchanged = 0;
		foreach ( self::resettable_fields_for_tab( $tab ) as $key => $field ) {
			$default = $field->resolve_default();
			$current = get_option( $key, $default );
			if ( self::scalarise( $current ) === self::scalarise( $default ) ) {
				++$unchanged;
				continue;
			}
			$changes[] = array(
				'key'     => $key,
				'label'   => $field->label,
				'current' => self::display( $current ),
				'default' => self::display( $default ),
			);
		}
		return array(
			'changes'   => $changes,
			'unchanged' => $unchanged,
		);
	}

	/**
	 * Reset one settings tab's resettable options to their declared defaults.
	 *
	 * Deletes each resettable option so the Settings-API registered default applies
	 * on the next read (never writes a value, so a dynamic default_callback keeps
	 * working). Guards on manage_options + a per-tab nonce, records who reset which
	 * tab, and fires `buddynext_settings_tab_reset` for Pro and add-ons. Owner data
	 * (resettable => false) and every other tab are untouched.
	 *
	 * @return void
	 */
	public static function handle_restore_defaults(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to reset these settings.', 'buddynext' ), 403 );
		}
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : '';
		check_admin_referer( self::RESTORE_ACTION . '_' . $tab );

		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( (string) $_POST['section'] ) ) : '';

		// Reset only the options that actually differ from their default, so the
		// count reported back matches the dialog's "what will change" list and a
		// no-op reset touches nothing. Deleting the row lets the registered default
		// (static or default_callback) apply on the next read.
		$keys = array();
		foreach ( self::resettable_fields_for_tab( $tab ) as $key => $field ) {
			$default = $field->resolve_default();
			if ( self::scalarise( get_option( $key, $default ) ) === self::scalarise( $default ) ) {
				continue;
			}
			delete_option( $key );
			$keys[] = $key;
		}

		$user_id = get_current_user_id();

		/**
		 * Fires after a settings tab is reset to its declared defaults.
		 *
		 * @param string   $tab     The tab slug that was reset.
		 * @param string[] $keys    The option names that were reset (resettable only).
		 * @param int      $user_id The administrator who reset the tab.
		 */
		do_action( 'buddynext_settings_tab_reset', $tab, $keys, $user_id );

		$redirect = '';
		if ( '' !== $section && class_exists( '\BuddyNext\Admin\AdminHub' ) ) {
			$redirect = \BuddyNext\Admin\AdminHub::tab_url( $section, $tab, array( 'bn_reset' => count( $keys ) ) );
		}
		if ( '' === $redirect ) {
			$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * A comparable scalar for a stored/default value, so an array option (reactions,
	 * offsets) compares by content rather than by reference.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalarise( $value ): string {
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return (string) $value;
	}

	/**
	 * A short human-readable rendering of a value for the confirm dialog.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function display( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'On', 'buddynext' ) : __( 'Off', 'buddynext' );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return __( '(empty)', 'buddynext' );
		}
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
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
		if ( 'number' === $type || 'optional_limit' === $type ) {
			return 'integer';
		}
		return 'string';
	}
}
