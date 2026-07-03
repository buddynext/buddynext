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

use BuddyNext\Admin\Settings\SettingsRegistry;
use BuddyNext\Core\IconService;

/**
 * Compiles the AdminHub registry into a flat, searchable index.
 */
final class AdminNavIndex {

	/**
	 * Build the palette index: navigate-to-screen tab entries plus one
	 * find-a-setting entry per declared option field (deep-linked to the exact
	 * control). Capability-filtered to the current user.
	 *
	 * @return array<int, array{label:string, sub:string, section:string, icon:string, url:string}>
	 */
	public static function build(): array {
		$out = array();

		// Tab slug => { label, icon, section_key } for accessible tabs, so field
		// entries can resolve their tab's display label + deep-link URL.
		$tab_meta = array();

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

				$tab_meta[ (string) $slug ] = array(
					'label'   => (string) $tab['label'],
					'icon'    => IconService::render( $icon_slug ),
					'section' => (string) $section_key,
				);
			}
		}

		// Field-level entries: one per option declared via the settings registry
		// (free standalone = free fields; with Pro = the union). Deep-links to the
		// field's anchor within its tab so ⌘K lands on the exact control.
		foreach ( SettingsRegistry::pages() as $page ) {
			$page_section = $page->settings_page_section();
			foreach ( $page->settings_fields() as $section ) {
				$meta = $tab_meta[ $section->tab ] ?? null;
				if ( null === $meta ) {
					continue; // Tab not accessible to this user, or not registered.
				}
				foreach ( $section->fields as $field ) {
					if ( '' === $field->label ) {
						continue;
					}
					$out[] = array(
						'label'   => $field->label,
						'sub'     => $meta['label'] . ' › ' . $section->title,
						'section' => $meta['label'],
						'icon'    => $meta['icon'],
						'url'     => AdminHub::tab_url( $page_section, $section->tab ) . '#' . $field->anchor(),
					);
				}
			}
		}

		return $out;
	}
}
