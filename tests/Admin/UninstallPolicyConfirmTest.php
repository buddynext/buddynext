<?php
/**
 * Switching on the data wipe asks for a typed confirmation and links its docs.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\ToolsTab;

/**
 * @covers \BuddyNext\Admin\ToolsTab
 */
class UninstallPolicyConfirmTest extends \WP_UnitTestCase {

	/**
	 * The rendered Data on uninstall section.
	 *
	 * @return string
	 */
	private function section(): string {
		$render = new \ReflectionMethod( ToolsTab::class, 'render_uninstall_policy_section' );
		$render->setAccessible( true );
		ob_start();
		$render->invoke( new ToolsTab() );
		return (string) ob_get_clean();
	}

	public function test_turning_the_wipe_on_asks_for_a_typed_confirmation(): void {
		delete_option( 'buddynext_delete_data_on_uninstall' );
		$html = $this->section();

		$this->assertStringContainsString( 'data-bn-confirm-require="DELETE"', $html );
		$this->assertStringContainsString( 'data-bn-confirm-when="[name=delete_data]:checked:not([data-was-on])"', $html );
		$this->assertStringContainsString( 'getting-started/08c-uninstall-and-your-data/', $html );
		$this->assertStringNotContainsString( 'data-was-on', str_replace( ':not([data-was-on])', '', $html ), 'Off: the checkbox is not marked as already on.' );
	}

	public function test_saving_while_already_on_does_not_ask_again(): void {
		update_option( 'buddynext_delete_data_on_uninstall', 1 );

		$this->assertMatchesRegularExpression( '/name="delete_data"[^>]*data-was-on/', $this->section() );
	}
}
