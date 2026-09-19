<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Guards the admin IA order and the Moderation merge (card 10114177928), plus the
 * relocated-tab redirect seam it builds on.
 *
 * The menu follows the owner's journey, and the former "Moderation Tools"
 * (automod) section was retired with its four tabs merged into Moderation. Nothing
 * may disappear or misroute: these pins assert the section order, that the automod
 * section is gone, that every former automod tab now resolves under Moderation,
 * and that no tab_url() still points at the retired automod page. tab_url() reads
 * the placement map directly, so this needs no admin-menu bootstrap — if a
 * placement drifts, the exact tab that moved fails by name.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Admin\AdminHub::tab_url
 * @covers \BuddyNext\Admin\AdminHub::sections
 */
class RelocatedTabRedirectTest extends WP_UnitTestCase {

	/**
	 * The admin sections, in the owner-journey order the card ships.
	 */
	private const EXPECTED_ORDER = array(
		'get-started',
		'members',
		'spaces',
		'engagement',
		'notifications',
		'moderation',
		'monetization',
		'campaigns',
		'realtime',
		'platform',
		'integration-settings',
		'settings',
		'upgrade',
	);

	/**
	 * The nine tabs Moderation holds after the merge, in the order a moderator works.
	 */
	private const MODERATION_TABS = array( 'moderation', 'pending', 'reports', 'suspensions', 'appeals', 'rules', 'ai', 'bulk', 'log' );

	/**
	 * The four tabs that moved out of the retired "Moderation Tools" section.
	 */
	private const FORMER_AUTOMOD_TABS = array( 'rules', 'ai', 'bulk', 'log' );

	/**
	 * The menu order matches the owner journey, and the automod section is gone.
	 *
	 * @return void
	 */
	public function test_section_order_follows_owner_journey_and_drops_automod(): void {
		$order = array_keys( AdminHub::sections() );

		$this->assertSame( self::EXPECTED_ORDER, $order, 'The admin section order must match the shipped owner-journey order.' );
		$this->assertNotContains( 'automod', $order, 'The "Moderation Tools" (automod) section must be retired.' );
	}

	/**
	 * Every former automod tab now resolves under Moderation, not the retired page —
	 * the "nothing disappears, everything lands in one place" guarantee.
	 *
	 * @return void
	 */
	public function test_former_automod_tabs_live_under_moderation(): void {
		foreach ( self::FORMER_AUTOMOD_TABS as $tab ) {
			$url = AdminHub::tab_url( 'moderation', $tab );
			$this->assertStringContainsString( 'page=buddynext-moderation', $url, $tab . ' must render under Moderation now.' );
			$this->assertStringContainsString( 'tab=' . $tab, $url );
			$this->assertStringNotContainsString( 'buddynext-automod', $url, $tab . ' must not point at the retired automod page.' );
		}
	}

	/**
	 * All nine Moderation tabs resolve to the Moderation page (the full merged set).
	 *
	 * @return void
	 */
	public function test_moderation_holds_the_full_merged_tab_set(): void {
		foreach ( self::MODERATION_TABS as $tab ) {
			$url = AdminHub::tab_url( 'moderation', $tab );
			$this->assertStringContainsString( 'page=buddynext-moderation', $url, $tab . ' must be a Moderation tab.' );
			$this->assertStringContainsString( 'tab=' . $tab, $url );
		}
	}

	/**
	 * No tab in the whole IA still routes to the retired automod page — a leftover
	 * placement would silently strand a tab on a dead section.
	 *
	 * @return void
	 */
	public function test_no_tab_routes_to_the_retired_automod_page(): void {
		foreach ( self::MODERATION_TABS as $tab ) {
			$this->assertStringNotContainsString(
				'buddynext-automod',
				AdminHub::tab_url( 'moderation', $tab ),
				'No tab may resolve to the retired automod page.'
			);
		}
	}

	/**
	 * A non-relocated tab resolves to its own section page unchanged.
	 *
	 * @return void
	 */
	public function test_non_relocated_tab_stays_on_its_section(): void {
		$url = AdminHub::tab_url( 'moderation', 'reports' );

		$this->assertStringContainsString( 'page=buddynext-moderation', $url );
		$this->assertStringContainsString( 'tab=reports', $url );
	}

	/**
	 * Integration Settings is its own top-level section, and the integration-controls
	 * tab resolves there — not under Platform (owner decision 3, card 10114177928).
	 *
	 * @return void
	 */
	public function test_integration_controls_lives_in_its_own_section(): void {
		$this->assertContains( 'integration-settings', array_keys( AdminHub::sections() ), 'Integration Settings must be a top-level section.' );

		// The tab registers under `settings`; its URL must now resolve to the new
		// Integration Settings page, and no longer to Platform.
		$url = AdminHub::tab_url( 'settings', 'integration-controls' );
		$this->assertStringContainsString( 'page=buddynext-integration-settings', $url, 'integration-controls must render under Integration Settings.' );
		$this->assertStringContainsString( 'tab=integration-controls', $url );
		$this->assertStringNotContainsString( 'page=buddynext-platform', $url, 'integration-controls must no longer resolve under Platform.' );

		// The former Platform home of the tab forwards to the new section too.
		$from_platform = AdminHub::tab_url( 'platform', 'integration-controls' );
		$this->assertStringContainsString( 'page=buddynext-integration-settings', $from_platform, 'a Platform bookmark for the tab forwards to Integration Settings.' );
	}
}
