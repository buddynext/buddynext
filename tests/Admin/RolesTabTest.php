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

	/**
	 * Drop the memoised role map so the next check rebuilds it.
	 *
	 * @return void
	 */
	private function forget_role_map(): void {
		$memo = new \ReflectionProperty( \BuddyNext\Core\PermissionService::class, 'role_map_cache' );
		$memo->setValue( null, null );
	}

	/**
	 * Post the whole matrix the way the form does, as an admin, and return.
	 *
	 * @param array<string,string> $cap Capability => role rows to post.
	 * @return void
	 */
	private function save_matrix( array $cap ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['cap']        = $cap;
		$_POST['_wpnonce']   = wp_create_nonce( 'bn_roles_save' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		$stop                = static function (): never {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $stop );
		try {
			( new RolesTab() )->handle_save();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $stop );
		$this->forget_role_map();
	}

	/**
	 * A save with nothing changed stores nothing, so it cannot freeze a capability
	 * and the Spaces-tab "who can create spaces" keeps working afterwards.
	 *
	 * @return void
	 */
	public function test_saving_an_unchanged_matrix_stores_no_overrides(): void {
		$this->save_matrix( array( 'buddynext-spaces/create' => 'member', 'buddynext-spaces/join' => 'member' ) );
		$this->assertSame( array(), get_option( 'bn_role_map_overrides', array() ) );

		update_option( 'buddynext_space_creation_role', 'admin' );
		$this->forget_role_map();
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( ( new \BuddyNext\Core\PermissionService() )->can( $member, 'buddynext-spaces/create' ) );
	}

	/**
	 * Only a row the owner really changed is stored.
	 *
	 * @return void
	 */
	public function test_only_a_changed_row_is_stored(): void {
		$this->save_matrix( array( 'buddynext-spaces/create' => 'moderator', 'buddynext-spaces/join' => 'member' ) );
		$this->assertSame( array( 'buddynext-spaces/create' => 'moderator' ), get_option( 'bn_role_map_overrides' ) );
	}

	/**
	 * Saving the Spaces-tab setting replaces an older Roles row for the same ability.
	 *
	 * @return void
	 */
	public function test_spaces_setting_replaces_an_older_roles_row(): void {
		update_option( 'bn_role_map_overrides', array( 'buddynext-spaces/create' => 'member' ), false );
		update_option( 'buddynext_space_creation_role', 'admin' );
		$this->forget_role_map();
		$this->assertArrayNotHasKey( 'buddynext-spaces/create', (array) get_option( 'bn_role_map_overrides' ) );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( ( new \BuddyNext\Core\PermissionService() )->can( $member, 'buddynext-spaces/create' ) );
	}

	/**
	 * The Spaces-tab select shows the rule that is enforced, so a Roles & Capabilities
	 * choice is not contradicted by it (and an unchanged save there cannot undo it).
	 *
	 * @return void
	 */
	public function test_spaces_tab_shows_the_enforced_rule(): void {
		$field = static function (): \BuddyNext\Admin\Settings\Field {
			$settings = ( new \ReflectionClass( \BuddyNext\Admin\Settings::class ) )->newInstanceWithoutConstructor();
			$method   = new \ReflectionMethod( $settings, 'fields_spaces' );
			foreach ( $method->invoke( $settings ) as $section ) {
				foreach ( $section->fields as $f ) {
					if ( 'buddynext_space_creation_role' === $f->key ) {
						return $f;
					}
				}
			}
			throw new \RuntimeException( 'field missing' );
		};

		$this->assertSame( 'member', $field()->display_value() );

		update_option( 'bn_role_map_overrides', array( 'buddynext-spaces/create' => 'admin' ), false );
		$this->forget_role_map();
		$this->assertSame( 'admin', $field()->display_value(), 'Roles says admins only, so the Spaces tab must not say Any member' );
	}
}
