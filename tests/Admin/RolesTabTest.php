<?php
/**
 * Tests for the Roles & Capabilities admin tab.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\RolesTab;

/**
 * @covers \BuddyNext\Admin\RolesTab
 */
class RolesTabTest extends \WP_UnitTestCase {

	/**
	 * Render the tab body via reflection — render_content() is protected and
	 * called by the inherited AdminPageBase::render_page() template method.
	 *
	 * @return string Rendered HTML.
	 */
	private function render(): string {
		$tab    = new RolesTab();
		$method = new \ReflectionMethod( RolesTab::class, 'render_content' );

		ob_start();
		$method->invoke( $tab );
		return (string) ob_get_clean();
	}

	/**
	 * One list: the screen offers exactly the abilities the permission map
	 * marks owner-facing. Two (interact, resolve reports) were in the map with
	 * comments promising an owner could remap them, and not on the screen.
	 *
	 * @return void
	 */
	public function test_the_screen_lists_exactly_the_owner_facing_abilities(): void {
		preg_match_all( '/name="cap\[([^\]]+)\]"/', $this->render(), $m );
		$shown    = $m[1];
		$expected = \BuddyNext\Core\PermissionService::owner_facing_abilities();
		sort( $shown );
		sort( $expected );

		$this->assertSame( $expected, $shown );
		$this->assertContains( 'buddynext-feed/interact', $shown );
		$this->assertContains( 'buddynext-moderation/dismiss', $shown );
	}

	/**
	 * The hand-rolled footer this tab used to print crammed Save and Reset into
	 * one <p> in the SAME form, and called the reset button "Reset to defaults"
	 * — a different wording from the "Restore defaults" every other settings
	 * tab uses (Settings::render_restore_defaults()). Card 10350960555.
	 *
	 * @return void
	 */
	public function test_restore_defaults_uses_the_canonical_wording_and_its_own_form(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Restore defaults', $html, 'must use the same wording as every other settings tab' );
		$this->assertStringNotContainsString( 'Reset to defaults', $html, 'the old, inconsistent wording must be gone' );

		// Two <form> elements: the save form and the restore-defaults form,
		// matching Settings::render_restore_defaults()'s separate-form pattern
		// (so an Enter keypress in a role <select> can never submit a reset).
		$this->assertSame( 2, substr_count( $html, '<form ' ), 'Save and Restore defaults must be two separate forms' );
	}

	/**
	 * The tab now renders through AdminPageBase::render_save_bar(), not a
	 * hand-rolled <p><button>.
	 *
	 * @return void
	 */
	public function test_save_button_uses_the_shared_save_bar(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'bn-save-bar', $html );
		$this->assertStringContainsString( 'Save changes', $html );
	}

	/**
	 * RolesTab must actually be an AdminPageBase now, not merely resemble one.
	 *
	 * @return void
	 */
	public function test_extends_admin_page_base(): void {
		$this->assertInstanceOf( \BuddyNext\Admin\AdminPageBase::class, new RolesTab() );
	}

	/**
	 * Restore defaults opens the same confirm as every settings tab, listing each
	 * capability a reset would change in the dropdown's own words. Card 10350960555.
	 *
	 * @return void
	 */
	public function test_restore_confirm_lists_each_changed_capability(): void {
		update_option( 'bn_role_map_overrides', array( 'buddynext-moderation/suspend-user' => 'admin' ) );

		preg_match( '/data-bn-restore-defaults="([^"]*)"/', $this->render(), $m );
		$payload = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );

		$this->assertSame(
			array(
				array(
					'key'     => 'buddynext-moderation/suspend-user',
					'label'   => 'Suspend members',
					'current' => 'Admins only',
					'default' => 'Moderators & up',
				),
			),
			$payload['changes'] ?? null
		);
	}
}
