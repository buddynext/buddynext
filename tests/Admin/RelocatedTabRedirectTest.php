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
		'automation',
		'monetization',
		'campaigns',
		'realtime',
		'platform',
		'integration-settings',
		'settings',
		'upgrade',
	);

	/**
	 * Moderation's five tabs, the daily work, in the order a moderator meets it
	 * (owner decision, card 10393846782: no section holds more than five).
	 */
	private const MODERATION_TABS = array( 'pending', 'reports', 'suspensions', 'appeals', 'log' );

	/**
	 * The tabs that are set up once and then run, now in Automation.
	 */
	private const AUTOMATION_TABS = array( 'rules', 'ai', 'bulk' );

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
	 * Rules, AI and Bulk actions live in Automation, never on the retired page,
	 * and an old Moderation address for them still lands there.
	 *
	 * @return void
	 */
	public function test_set_up_once_tabs_live_under_automation(): void {
		foreach ( self::AUTOMATION_TABS as $tab ) {
			$url = AdminHub::tab_url( 'moderation', $tab );
			$this->assertStringContainsString( 'page=buddynext-automation', $url, $tab . ' must render under Automation.' );
			$this->assertStringContainsString( 'tab=' . $tab, $url );
			$this->assertStringNotContainsString( 'buddynext-automod', $url, $tab . ' must not point at the retired automod page.' );
		}

		// The reporting and auto-hide controls open Automation, not Settings
		// (which would then be the section with six tabs).
		$this->assertStringContainsString( 'page=buddynext-automation', AdminHub::tab_url( 'settings', 'moderation' ) );
	}

	/**
	 * Moderation keeps its five daily-work tabs, and no section holds more than five.
	 *
	 * @return void
	 */
	public function test_moderation_keeps_five_tabs_and_no_section_exceeds_five(): void {
		foreach ( self::MODERATION_TABS as $tab ) {
			$url = AdminHub::tab_url( 'moderation', $tab );
			$this->assertStringContainsString( 'page=buddynext-moderation', $url, $tab . ' must be a Moderation tab.' );
			$this->assertStringContainsString( 'tab=' . $tab, $url );
		}

		$per_section = array();
		foreach ( (array) ( new \ReflectionClass( AdminHub::class ) )->getConstant( 'TAB_PLACEMENT' ) as $rule ) {
			if ( isset( $rule['position'] ) ) {
				$per_section[ (string) $rule['section'] ] = ( $per_section[ (string) $rule['section'] ] ?? 0 ) + 1;
			}
		}
		$this->assertSame( array(), array_filter( $per_section, static fn( int $count ): bool => $count > 5 ), 'No admin section holds more than five tabs.' );
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
	 * The retired page redirects from `load-<hook>`, before admin-header.php prints.
	 *
	 * A redirect from the render callback runs after output has started, so on a host
	 * without output buffering the headers are already sent and a bookmark to the
	 * retired page shows an empty admin screen.
	 *
	 * @return void
	 */
	public function test_retired_page_redirects_before_output(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new AdminHub() )->build_menu();

		$this->assertNotFalse( has_action( 'load-admin_page_buddynext-automod' ), 'The redirect must hang off the load- action, not the page callback.' );
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
