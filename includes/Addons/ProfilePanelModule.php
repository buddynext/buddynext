<?php
/**
 * Addon module: a panel on the member's Portfolio profile tab.
 *
 * @package BuddyNext\Addons
 */

declare( strict_types=1 );

namespace BuddyNext\Addons;

/**
 * An addon that shows what a member has done in the partner plugin.
 *
 * The panel becomes a sub-tab under Portfolio and is served by
 * GET buddynext/v1/members/{id}/portfolio for the app. Shown only while the
 * integration's "Show in navigation" switch is on.
 *
 * Each panel is an array: `key` (string, required), `label`, `icon` (a slug in
 * buddynext/assets/icons), `count`, `priority`, `items` (list of arrays), `cta`
 * (a link out to the partner), `owner_cta` (manage links, owner only), and
 * `owner_only` (true drops the panel for every other viewer).
 *
 * The result is cached for "the member viewing their own profile" and "anyone
 * else", never per viewer. A panel that is not `owner_only` must therefore return
 * the same data to every viewer; set `owner_only` for anything that depends on who
 * is looking. Call do_action( 'buddynext_suite_panels_changed', $member_id ) when
 * the member's data changes.
 *
 * @api
 * @since 1.2.7
 */
interface ProfilePanelModule {

	/**
	 * Panels for one member.
	 *
	 * @param int $member_id Profile owner.
	 * @return array<int,array<string,mixed>>
	 */
	public function panels( int $member_id ): array;
}
