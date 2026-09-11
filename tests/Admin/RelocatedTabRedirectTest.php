<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Guards the relocated-tab redirect seam.
 *
 * The IA placement map lets a tab register against its domain section and be
 * RELOCATED to a different section for the final layout — ModerationQueue
 * registers ( 'moderation', 'log' ) but the map places it under `automod`, so
 * the tab renders at page=buddynext-automod&tab=log. A stale in-section URL
 * (page=buddynext-moderation&tab=log) used to hit render_section()'s "that
 * setting has moved" notice. AdminHub::redirect_relocated_tabs() forwards it by
 * resolving <section>:<tab> through the SAME map and redirecting via tab_url().
 *
 * This pins the coupling the redirect relies on: the map still relocates
 * moderation:log, and tab_url() (what the handler builds the redirect from)
 * resolves it to the destination page — not the stale moderation one.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use WP_UnitTestCase;

/**
 * Relocated-tab redirect target resolution.
 *
 * @covers \BuddyNext\Admin\AdminHub::redirect_relocated_tabs
 * @covers \BuddyNext\Admin\AdminHub::tab_url
 */
class RelocatedTabRedirectTest extends WP_UnitTestCase {

	/**
	 * The stale moderation:log URL must resolve to the automod page, not stay put.
	 *
	 * If the placement rule is dropped, tab_url() returns the moderation page and
	 * this fails — the exact signal that the redirect seam and the tab's real home
	 * have drifted apart (card 10264294456).
	 *
	 * @return void
	 */
	public function test_moderation_log_resolves_to_automod_page(): void {
		$url = AdminHub::tab_url( 'moderation', 'log' );

		$this->assertStringContainsString( 'page=buddynext-automod', $url, 'moderation:log must render under the automod section.' );
		$this->assertStringContainsString( 'tab=log', $url );
		$this->assertStringNotContainsString( 'page=buddynext-moderation', $url, 'The stale moderation page must not be the destination.' );
	}

	/**
	 * A tab that is NOT relocated resolves to its own section page unchanged, so
	 * the redirect handler leaves ordinary tabs alone.
	 *
	 * @return void
	 */
	public function test_non_relocated_tab_stays_on_its_section(): void {
		// reports is a real moderation tab (not relocated), so its URL stays on the
		// moderation page — redirect_relocated_tabs() has nothing to forward.
		$url = AdminHub::tab_url( 'moderation', 'reports' );

		$this->assertStringContainsString( 'page=buddynext-moderation', $url );
		$this->assertStringContainsString( 'tab=reports', $url );
	}
}
