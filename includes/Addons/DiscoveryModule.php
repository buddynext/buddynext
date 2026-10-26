<?php
/**
 * Addon module: a line of discovery in the space header.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon that points members from a space to what the space is about.
 *
 * Rendered below the space header, only while the addon is active, and only for a
 * viewer who can open what it points at: return '' otherwise. BuddyNext adds the
 * addon's `nav_deny_paths()` so those links load the partner page in full.
 *
 * @api
 * @since 1.2.7
 */
interface DiscoveryModule {

	/**
	 * Pre-escaped HTML for the banner, or '' for none.
	 *
	 * @param int $space_id  Space being viewed.
	 * @param int $viewer_id Viewer (0 = signed out).
	 * @return string
	 */
	public function space_banner( int $space_id, int $viewer_id ): string;

	/**
	 * Front-end path prefixes that sit outside the BuddyNext page region.
	 *
	 * @return string[]
	 */
	public function nav_deny_paths(): array;
}
