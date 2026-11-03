<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A link built from an old standalone page slug lands on its hub tab.
 *
 * Platform > Features "Open its settings" linked to ?page=buddynextpro-realtime,
 * which rendered without the BuddyNext sidebar (card 10470216210).
 *
 * @package BuddyNext\Tests\Admin
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Admin\AdminHub::legacy_page_url
 */
class HubLegacyPageUrlTest extends WP_UnitTestCase {

	public function test_declared_legacy_page_resolves_to_its_tab(): void {
		AdminHub::register_tab( 'realtime', 'qa-legacy-tab', 'QA', static function (): void {}, array( 'legacy_page' => 'bnqa-old-standalone' ) );

		$url = AdminHub::legacy_page_url( 'bnqa-old-standalone' );

		$this->assertSame( AdminHub::tab_url( 'realtime', 'qa-legacy-tab' ), $url );
		$this->assertStringContainsString( 'tab=qa-legacy-tab', $url );
		$this->assertStringNotContainsString( 'bnqa-old-standalone', $url, 'The link must not point at the bare legacy page.' );
	}

	public function test_unknown_slug_returns_empty_so_the_caller_keeps_its_fallback(): void {
		$this->assertSame( '', AdminHub::legacy_page_url( 'bnqa-never-registered' ) );
		$this->assertSame( '', AdminHub::legacy_page_url( '' ) );
	}
}
