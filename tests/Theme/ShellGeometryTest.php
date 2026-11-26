<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shell geometry rules that no host theme may break.
 *
 * @package BuddyNext\Tests\Theme
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use WP_UnitTestCase;

/**
 * Three escapes found on a block theme: the auth shell scrolled sideways under
 * a classic scrollbar, the header panels ran off the left edge when the header
 * blocks sat at the start of the header, and the open account-menu chevron
 * jumped over the member's avatar on phones.
 *
 * @coversNothing
 */
class ShellGeometryTest extends WP_UnitTestCase {

	/**
	 * Read a plugin file.
	 *
	 * @param string $path Path under the plugin root.
	 * @return string
	 */
	private function source( string $path ): string {
		return (string) file_get_contents( BUDDYNEXT_DIR . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The auth shell reserves the scrollbar gutter like every other hub.
	 *
	 * @return void
	 */
	public function test_auth_shell_reserves_the_scrollbar_gutter(): void {
		$this->assertStringContainsString( 'html:has( .bn-app__main, .bn-app--auth ) { scrollbar-gutter: stable; }', $this->source( 'assets/css/bn-base.css' ) );
	}

	/**
	 * Both header panels are kept inside the screen, from either edge.
	 *
	 * @return void
	 */
	public function test_header_panels_are_kept_in_view_from_both_edges(): void {
		$js = $this->source( 'assets/js/header/user-menu.js' );

		$this->assertStringContainsString( "keepInView( root.querySelector( '.bn-notif-panel, .bn-header-user__dropdown' ) );", $js );
		$this->assertStringContainsString( 'if ( r.left < gutter ) {', $js );
	}

	/**
	 * The shared focus ring outranks a host theme's `input[type="search"]:focus`
	 * (0,2,1): Reign's beat it on the DM rail search, which then had no ring.
	 *
	 * @return void
	 */
	public function test_input_focus_ring_outranks_theme_search_focus(): void {
		$this->assertStringContainsString( '.bn-input.bn-input:focus-visible', $this->source( 'assets/css/bn-base.css' ) );
		$this->assertStringContainsString( '.bn-dm-search__input.bn-dm-search__input:focus-visible', $this->source( 'assets/css/bn-messages.css' ) );
	}

	/**
	 * Opening the menu turns the chevron glyph, not the button laid over the avatar.
	 *
	 * @return void
	 */
	public function test_open_menu_rotates_the_glyph_only(): void {
		$css = $this->source( 'assets/css/bn-header.css' );

		$this->assertStringContainsString( ".bn-header-user.is-open .bn-header-user__caret svg {\n\ttransform: rotate(180deg);", $css );
		$this->assertStringNotContainsString( ".bn-header-user.is-open .bn-header-user__caret {\n\ttransform: rotate(180deg);", $css );
	}
}
