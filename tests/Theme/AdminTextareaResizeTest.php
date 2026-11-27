<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin textareas keep the height their resize handle sets.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

/**
 * The shared admin field rule forced height:auto !important, which beats the inline
 * height the browser's resize handle writes, so no admin textarea could be resized.
 *
 * @coversNothing
 */
class AdminTextareaResizeTest extends \WP_UnitTestCase {

	/**
	 * No bn-admin.css rule that selects a textarea forces its height.
	 *
	 * @return void
	 */
	public function test_no_admin_rule_forces_a_textarea_height(): void {
		$css = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );
		foreach ( $rules as $rule ) {
			$selects_textarea = (bool) preg_match( '/textarea/', str_replace( ':not(textarea)', '', $rule[1] ) );
			if ( $selects_textarea ) {
				$this->assertDoesNotMatchRegularExpression(
					'/(^|;|\s)height\s*:[^;]*!important/',
					$rule[2],
					'A textarea rule forces height, so the resize handle does nothing: ' . trim( $rule[1] )
				);
			}
		}
	}
}
