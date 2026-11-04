<?php
/**
 * The "setup is not complete" reminder can be hidden and stays out of other plugins' screens.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\SetupChecklist;

/**
 * Card 10461175462: the reminder printed on every wp-admin screen with a core X
 * that remembered nothing, so an owner who set up by hand saw it forever.
 *
 * @covers \BuddyNext\Admin\SetupChecklist::render_setup_notice
 */
class SetupNoticeTest extends \WP_UnitTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		delete_option( 'buddynext_setup_complete' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function notice_on( string $screen ): string {
		set_current_screen( $screen );
		ob_start();
		SetupChecklist::render_setup_notice();
		return (string) ob_get_clean();
	}

	public function test_shows_on_dashboard_and_buddynext_screens_only(): void {
		$this->assertStringContainsString( 'setup is not complete', $this->notice_on( 'dashboard' ) );
		$this->assertStringContainsString( 'setup is not complete', $this->notice_on( 'buddynext_page_buddynext-settings' ) );
		$this->assertSame( '', $this->notice_on( 'edit-post' ), 'not on other screens' );
		$this->assertSame( '', $this->notice_on( 'toplevel_page_buddynext' ), 'not on the landing, which has its own checklist' );
		$this->assertSame( '', $this->notice_on( 'admin_page_buddynext-setup' ), 'not on the wizard' );
	}

	public function test_hiding_sticks_for_that_admin_only(): void {
		$html = $this->notice_on( 'dashboard' );
		$this->assertStringContainsString( 'action=bn_hide_setup_notice', $html );
		$this->assertStringNotContainsString( 'is-dismissible', $html, 'no X that forgets' );

		update_user_meta( $this->admin, 'buddynext_setup_notice_hidden', 1 );
		$this->assertSame( '', $this->notice_on( 'dashboard' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'setup is not complete', $this->notice_on( 'dashboard' ), 'another admin still sees it' );
	}

	public function test_gone_once_setup_is_complete(): void {
		update_option( 'buddynext_setup_complete', '1' );
		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
	}
}
