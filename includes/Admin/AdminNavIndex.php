<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin command-palette index.
 *
 * Builds the searchable list of every admin screen from the AdminHub tab
 * registry — the single source of truth for "what exists and where" — so the
 * Cmd/K palette never drifts from the real navigation. Free + standalone:
 * indexes whatever AdminHub currently has registered, so with Pro inactive it
 * lists only Free tabs, and Pro tabs appear automatically when Pro registers
 * them through the same hub. Capability-filtered to the current user.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use BuddyNext\Core\IconService;

/**
 * Compiles the AdminHub registry into a flat, searchable index.
 */
final class AdminNavIndex {

	/**
	 * Build the palette index: one entry per visible tab the user can access.
	 *
	 * @return array<int, array{label:string, sub:string, section:string, icon:string, url:string}>
	 */
	public static function build(): array {
		$out = array();

		foreach ( AdminHub::sections() as $section_key => $section ) {
			$section_label = isset( $section['label'] ) ? (string) $section['label'] : (string) $section_key;

			foreach ( AdminHub::get_tabs( $section_key ) as $slug => $tab ) {
				$cap = isset( $tab['cap'] ) ? (string) $tab['cap'] : 'manage_options';
				if ( ! current_user_can( $cap ) ) {
					continue;
				}

				$icon_slug = isset( $tab['icon'] ) ? (string) $tab['icon'] : 'settings';

				$out[] = array(
					'label'   => (string) $tab['label'],
					'sub'     => isset( $tab['subtitle'] ) ? (string) $tab['subtitle'] : '',
					'section' => $section_label,
					'icon'    => IconService::render( $icon_slug ),
					'url'     => AdminHub::tab_url( (string) $section_key, (string) $slug ),
				);
			}
		}

		return $out;
	}
}
