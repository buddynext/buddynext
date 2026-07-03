<?php
/**
 * Contract for any admin page that declares WordPress options.
 *
 * A page returns its options as ordered sections of field descriptors. The
 * SettingsRegistry aggregates every implementor (free registers its own; Pro
 * plugs in via the same seam it uses for AdminHub tabs), and render, save,
 * sanitize, and the ⌘K search index all derive from the returned descriptors.
 *
 * @package BuddyNext\Contracts
 */

declare( strict_types=1 );

namespace BuddyNext\Contracts;

use BuddyNext\Admin\Settings\Section;

/**
 * Declares an admin page's settings as descriptors.
 */
interface ProvidesSettings {

	/**
	 * The page's options, grouped into ordered sections.
	 *
	 * @return Section[]
	 */
	public function settings_fields(): array;

	/**
	 * AdminHub section key the page's tabs render under (for tab_url()).
	 *
	 * @return string
	 */
	public function settings_page_section(): string;
}
