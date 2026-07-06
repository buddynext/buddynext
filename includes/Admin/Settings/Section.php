<?php
/**
 * A titled group of option fields within a settings tab.
 *
 * Maps 1:1 to an `open_section()` card on the rendered page. Carries the tab it
 * belongs to (for save-grouping + URL building) so individual fields never
 * repeat it.
 *
 * @package BuddyNext\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Settings;

/**
 * A section = { tab, title, Field[] }.
 */
final class Section {

	/**
	 * Build a section from its tab, title, and ordered fields.
	 *
	 * @param string  $tab    Settings tab slug this section renders under.
	 * @param string  $title  Section heading.
	 * @param Field[] $fields Ordered fields in this section.
	 */
	public function __construct(
		public string $tab,
		public string $title,
		public array $fields
	) {}
}
