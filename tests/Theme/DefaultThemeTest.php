<?php
/**
 * Tests for the Appearance > Default theme seed.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use BuddyNext\Theme\Appearance;

/**
 * 'auto' now matches the host theme; following the visitor's device is its own
 * explicit choice, 'system', which must reach the shell bootstrap.
 *
 * @covers \BuddyNext\Theme\Appearance::seed_default_theme
 */
class DefaultThemeTest extends \WP_UnitTestCase {

	/**
	 * Inline "before" script attached to the bootstrap handle.
	 *
	 * @param string $theme Option value.
	 * @return string
	 */
	private function seed_for( string $theme ): string {
		update_option( 'buddynext_default_theme', $theme );
		wp_deregister_script( 'bn-shell-font-scale' );
		wp_register_script( 'bn-shell-font-scale', 'https://example.org/font-scale.js', array(), '1', false );
		( new Appearance() )->seed_default_theme();

		$before = wp_scripts()->get_data( 'bn-shell-font-scale', 'before' );
		return is_array( $before ) ? implode( "\n", $before ) : '';
	}

	public function test_follow_the_device_is_seeded_as_system(): void {
		$this->assertStringContainsString( 'window.bnThemeDefault="system"', $this->seed_for( 'system' ) );
	}

	public function test_fixed_choices_are_seeded(): void {
		$this->assertStringContainsString( 'window.bnThemeDefault="dark"', $this->seed_for( 'dark' ) );
	}

	public function test_match_the_theme_is_the_bootstrap_fallback_and_needs_no_seed(): void {
		$this->assertSame( '', $this->seed_for( 'auto' ) );
	}

	/**
	 * The bootstrap's 'auto' must not follow the OS on a theme that says nothing
	 * about colour mode: that painted a dark community inside a light theme.
	 */
	public function test_bootstrap_auto_consults_the_host_before_the_device(): void {
		$js = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/js/shell/font-scale.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "if ( 'system' === pref ) {", $js );
		$this->assertStringContainsString( "return hostFollowsSystem() ? systemMode() : 'light';", $js );
	}
}
