<?php
/**
 * Tests for what the settings export carries and the import may write.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\ToolsTab;

/**
 * @covers \BuddyNext\Admin\ToolsTab
 */
class ToolsExportTest extends \WP_UnitTestCase {

	/**
	 * Call a private ToolsTab method.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( string $method, array $args = array() ) {
		return ( new \ReflectionMethod( ToolsTab::class, $method ) )->invokeArgs( new ToolsTab(), $args );
	}

	/**
	 * A setting travels; the site's own version, setup and hub page state does not.
	 *
	 * @return void
	 */
	public function test_export_carries_settings_and_leaves_internal_state(): void {
		update_option( 'buddynext_public_explore', 0 );
		update_option( 'buddynext_schema_version', '9.9.9' );
		update_option( 'buddynext_page_spaces', 123 );
		update_option( 'buddynext_email_defaults', 4 );

		$out = $this->call( 'collect_options' );

		$this->assertArrayHasKey( 'buddynext_public_explore', $out );
		foreach ( array( 'buddynext_schema_version', 'buddynext_page_spaces', 'buddynext_email_defaults' ) as $internal ) {
			$this->assertArrayNotHasKey( $internal, $out, "$internal is the site's own state" );
		}
	}

	/**
	 * The import writes only what the export would carry, so a file cannot roll a
	 * site's schema back, repoint its pages or plant a secret.
	 *
	 * @return void
	 */
	public function test_a_built_in_asset_url_is_left_out_of_the_export(): void {
		update_option( 'buddynext_auth_panel_image', BUDDYNEXT_URL . 'assets/images/auth-cover.svg' );
		update_option( 'buddynext_auth_panel_quote', 'Hello' );

		$out = $this->call( 'collect_options' );

		$this->assertArrayNotHasKey( 'buddynext_auth_panel_image', $out );
		$this->assertSame( 'Hello', $out['buddynext_auth_panel_quote'] );
	}

	public function test_import_rule_is_the_export_rule(): void {
		$this->assertTrue( $this->call( 'is_exportable', array( 'buddynext_never_saved_here' ) ), 'a key this site never saved is still a setting' );
		$this->assertFalse( $this->call( 'is_exportable', array( 'buddynext_schema_version' ) ) );
		$this->assertFalse( $this->call( 'is_exportable', array( 'buddynext_page_people' ) ) );
		$this->assertFalse( $this->call( 'is_exportable', array( 'buddynext_license_key' ) ) );
		$this->assertFalse( $this->call( 'is_exportable', array( 'bn_push_tokens' ) ) );
		$this->assertFalse( $this->call( 'is_exportable', array( 'blogname' ) ), 'never an option outside BuddyNext' );
	}

	/**
	 * Another prefix (Pro's) joins through the filter, and the sensitive filter still applies to it.
	 *
	 * @return void
	 */
	public function test_other_prefixes_join_through_the_filter(): void {
		$this->assertFalse( $this->call( 'is_exportable', array( 'buddynextpro_show_annual_savings' ) ), 'not exported until a plugin adds its prefix' );

		add_filter( 'buddynext_export_option_prefixes', static fn( $p ) => array_merge( $p, array( 'buddynextpro_' ) ) );
		add_filter( 'buddynext_export_option_is_sensitive', static fn( $s, $n ) => $s || 'buddynextpro_stripe_secret_key' === $n, 10, 2 );
		update_option( 'buddynextpro_show_annual_savings', 0 );

		$this->assertTrue( $this->call( 'is_exportable', array( 'buddynextpro_show_annual_savings' ) ) );
		$this->assertArrayHasKey( 'buddynextpro_show_annual_savings', $this->call( 'collect_options' ) );
		$this->assertFalse( $this->call( 'is_exportable', array( 'buddynextpro_stripe_secret_key' ) ) );
	}
}
