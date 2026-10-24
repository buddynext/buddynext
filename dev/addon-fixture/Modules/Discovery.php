<?php
/**
 * Fixture discovery.
 *
 * @package BuddyNext\Dev\AddonFixture
 */

declare( strict_types=1 );

namespace BuddyNext\Dev\AddonFixture\Modules;

use BuddyNext\Addons\DiscoveryModule;

/**
 * A banner for viewers who can open the target (any signed-in member).
 */
final class Discovery implements DiscoveryModule {

	/**
	 * Banner.
	 *
	 * @param int $space_id  Space.
	 * @param int $viewer_id Viewer.
	 * @return string
	 */
	public function space_banner( int $space_id, int $viewer_id ): string {
		return $viewer_id > 0 ? '<p class="bn-fixture-banner">Linked: space ' . (int) $space_id . '</p>' : '';
	}

	/**
	 * Full-load paths.
	 *
	 * @return string[]
	 */
	public function nav_deny_paths(): array {
		return array( '/fixture/' );
	}
}
