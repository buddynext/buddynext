<?php
/**
 * Guards the control font-family reset in bn-base.css.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

/**
 * The reset that makes BuddyNext's form controls use the theme UI font
 * (buttons, inputs, selects) only works if it is scoped to a class that
 * actually wraps the front end. The first attempt scoped it to `.bn-wrap`,
 * which appears on zero front-end pages, so the rule matched nothing and two
 * cards bounced (10281446723 privacy options, 10281148124 comment buttons).
 *
 * This test ties the CSS selector to the root the shells really render, so a
 * future edit that re-scopes the reset to a dead class — or renames the shell
 * root without updating the CSS — fails here by name instead of shipping the
 * OS font again.
 *
 * @coversNothing
 */
class ControlFontResetTest extends \WP_UnitTestCase {

	/**
	 * The front-end root class the shells render and the reset must target.
	 */
	private const FRONTEND_ROOT = 'bn-app';

	/**
	 * Read a plugin file relative to BUDDYNEXT_DIR.
	 *
	 * @param string $relative Path under the plugin root.
	 * @return string File contents.
	 */
	private function read( string $relative ): string {
		$path = BUDDYNEXT_DIR . $relative;
		$this->assertFileExists( $path, "Expected {$relative} to exist." );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * The control font reset is scoped to the real front-end root, not a dead class.
	 *
	 * @return void
	 */
	public function test_control_font_reset_targets_the_frontend_root(): void {
		$css = $this->read( 'assets/css/bn-base.css' );

		$root = self::FRONTEND_ROOT;

		// The reset exists and is scoped to .bn-app.
		$this->assertMatchesRegularExpression(
			'/\.' . preg_quote( $root, '/' ) . '\s+button\s*,/',
			$css,
			'The control font-family reset must be scoped to .' . $root . ' (the front-end root the shells render), so it actually matches BuddyNext controls.'
		);

		// The reset really sets font-family: inherit (the whole point).
		$this->assertMatchesRegularExpression(
			'/\.' . preg_quote( $root, '/' ) . '\s+optgroup\s*\{\s*font-family:\s*inherit;/',
			$css,
			'The .' . $root . ' control reset must declare font-family: inherit.'
		);

		// Regression guard: the reset must NOT be scoped to .bn-wrap, the class
		// that appears on no front-end page and made the rule dead CSS.
		$this->assertDoesNotMatchRegularExpression(
			'/\.bn-wrap\s+button\s*,/',
			$css,
			'The control font reset must not be scoped to .bn-wrap — it matches nothing on the front end (that dead scope is exactly what bounced cards 10281446723 / 10281148124).'
		);
	}

	/**
	 * The shell templates still render the root class the reset targets.
	 *
	 * If the shell root is ever renamed, this fails so the CSS selector is
	 * updated in lockstep rather than silently going dead again.
	 *
	 * @return void
	 */
	public function test_shells_render_the_frontend_root_class(): void {
		$root = self::FRONTEND_ROOT;

		foreach ( array( 'templates/shell/hub-shell.php', 'templates/shell/auth-shell.php' ) as $shell ) {
			$markup = $this->read( $shell );
			$this->assertMatchesRegularExpression(
				'/class="' . preg_quote( $root, '/' ) . '(\s|")/',
				$markup,
				$shell . ' must render class="' . $root . '" — the control font reset in bn-base.css is scoped to it.'
			);
		}
	}
}
