<?php
/**
 * Central registry of admin settings pages (free + Pro).
 *
 * Free registers its own page(s) on boot; Pro registers each of its admin pages
 * through the same collector when it loads — the identical seam AdminHub uses
 * for tabs. On a standalone free install the registry contains only free
 * fields; with Pro active it is the union. Nothing here references Pro.
 *
 * @package BuddyNext\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Settings;

use BuddyNext\Contracts\ProvidesSettings;

/**
 * Aggregates every registered settings page and flattens their fields.
 */
final class SettingsRegistry {

	/**
	 * Registered pages, in registration order.
	 *
	 * @var ProvidesSettings[]
	 */
	private static array $pages = array();

	/**
	 * Register a settings page.
	 *
	 * @param ProvidesSettings $page Page to include in the registry.
	 * @return void
	 */
	public static function register( ProvidesSettings $page ): void {
		self::$pages[] = $page;
	}

	/**
	 * All registered pages.
	 *
	 * @return ProvidesSettings[]
	 */
	public static function pages(): array {
		return self::$pages;
	}

	/**
	 * Every field across every registered page, flattened.
	 *
	 * @return Field[]
	 */
	public static function all_fields(): array {
		$out = array();
		foreach ( self::$pages as $page ) {
			foreach ( $page->settings_fields() as $section ) {
				foreach ( $section->fields as $field ) {
					$out[] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * Every declared option key across every registered page.
	 *
	 * @return string[]
	 */
	public static function all_keys(): array {
		return array_map(
			static fn( Field $field ) => $field->key,
			self::all_fields()
		);
	}

	/**
	 * Clear the registry. Test-support only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pages = array();
	}
}
