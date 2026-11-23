<?php
/**
 * Control edges use the 3:1 control token (WCAG 1.4.11).
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

/**
 * The browser crawler (control-contrast, light and dark, 1280 and 390) is the real
 * proof; this pins the token and the shared control rules so a later edit cannot drop
 * an outlined button or field back to the subtle divider colour unnoticed.
 *
 * @coversNothing
 */
class ControlEdgeContrastTest extends \WP_UnitTestCase {

	/**
	 * The body of the first rule whose selector list ends with $selector.
	 *
	 * @param string $css      Stylesheet.
	 * @param string $selector Exact selector text before the opening brace.
	 * @return string
	 */
	private function rule( string $css, string $selector ): string {
		$this->assertMatchesRegularExpression( '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $selector );
		preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $m );
		return $m[1];
	}

	/**
	 * The token is half the ink in light and dark, and the shared controls use it.
	 *
	 * @return void
	 */
	public function test_controls_draw_their_edge_with_the_control_token(): void {
		$base = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-base.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertGreaterThanOrEqual( 3, substr_count( $base, '--bn-line-control: color-mix(in oklab, var(--bn-ink) 50%, transparent);' ), 'Light root, dark block and the auto-dark media query each define the token.' );

		$this->assertStringContainsString( 'var(--bn-line-control)', $this->rule( $base, '.bn-btn[data-variant="secondary"]' ) );
		$this->assertStringContainsString( 'var(--bn-line-control)', $this->rule( $base, ".bn-textarea,\n.bn-select" ) );
		$this->assertStringContainsString( 'var(--bn-line-control)', $this->rule( $base, '.bn-page-btn' ) );

		$feed = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-feed.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'var(--bn-line-control)', $this->rule( $feed, '.bn-composer__poll-option' ) );
		$this->assertStringContainsString( 'var(--bn-line-control)', $this->rule( $feed, '.bn-composer__privacy-chip' ) );

		$admin = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertMatchesRegularExpression( '/--bn-a-input-border:\s*var\(--bn-line-control\)/', $admin );
	}
}
