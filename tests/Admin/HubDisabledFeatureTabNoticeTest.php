<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A link to a tab whose feature is off says so, instead of "that setting has moved".
 *
 * Automation > AI Moderation is a Pro feature, off by default. With it off the tab
 * is not registered, and a link to it claimed the setting had moved (card 10407360302).
 *
 * @package BuddyNext\Tests\Admin
 * @since 1.2.6
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use WP_UnitTestCase;

/**
 * Locks the two fallback notices apart.
 */
class HubDisabledFeatureTabNoticeTest extends WP_UnitTestCase {

	/**
	 * Render the Automation hub for a requested tab.
	 *
	 * @param string $tab Requested tab slug.
	 * @return string
	 */
	private function render( string $tab ): string {
		$_GET['page'] = 'buddynext-automation';
		$_GET['tab']  = $tab;
		ob_start();
		( new AdminHub() )->render_section();
		return (string) ob_get_clean();
	}

	/**
	 * A known-but-unregistered tab points to Features; an unknown one still reads as moved.
	 *
	 * @return void
	 */
	public function test_disabled_feature_tab_is_named_as_off_not_moved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		AdminHub::register_tab( 'moderation', 'rules', 'Rules', static function (): void {} );

		$off = $this->render( 'ai' );
		$this->assertStringContainsString( 'feature that is turned off', $off );
		$this->assertStringNotContainsString( 'That setting has moved', $off );

		$this->assertStringContainsString( 'That setting has moved', $this->render( 'no-such-tab' ) );
	}

	/**
	 * Clean request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['page'], $_GET['tab'] );
		parent::tear_down();
	}
}
