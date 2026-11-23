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

	/**
	 * Post the setting form and return the result flag of the redirect.
	 *
	 * @param array<string, string> $fields Posted fields besides the nonce.
	 * @return string
	 */
	private function save( array $fields ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST    = array_merge( array( '_wpnonce' => wp_create_nonce( 'bn_tools_uninstall_policy' ) ), $fields );
		$_REQUEST = $_POST;
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( (string) $location );
			}
		);
		try {
			( new ToolsTab() )->handle_uninstall_policy();
		} catch ( \RuntimeException $redirect ) {
			parse_str( (string) wp_parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $query );
			return (string) ( $query['bn_tools'] ?? '' );
		} finally {
			remove_all_filters( 'wp_redirect' );
			$_POST    = array();
			$_REQUEST = array();
		}
		return '';
	}

	public function test_switching_the_wipe_on_without_the_typed_word_is_refused(): void {
		delete_option( 'buddynext_delete_data_on_uninstall' );

		$this->assertSame( 'uninstall_wipe_unconfirmed', $this->save( array( 'delete_data' => '1' ) ) );
		$this->assertFalse( (bool) get_option( 'buddynext_delete_data_on_uninstall', false ), 'JavaScript off: nothing saved without the word.' );

		$this->assertSame(
			'uninstall_wipe_on',
			$this->save(
				array(
					'delete_data'  => '1',
					'confirm_wipe' => 'DELETE',
				)
			)
		);
		$this->assertTrue( (bool) get_option( 'buddynext_delete_data_on_uninstall' ) );

		$this->assertSame( 'uninstall_wipe_on', $this->save( array( 'delete_data' => '1' ) ), 'Re-saving while on needs no word.' );
		$this->assertSame( 'uninstall_wipe_off', $this->save( array() ), 'Turning it off never asks.' );
	}

	public function test_saving_while_already_on_does_not_ask_again(): void {
		update_option( 'buddynext_delete_data_on_uninstall', 1 );

		$this->assertMatchesRegularExpression( '/name="delete_data"[^>]*data-was-on/', $this->section() );
	}
}
