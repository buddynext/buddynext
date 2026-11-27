<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Sortable admin column headings read as headings, not as body links.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

/**
 * The Member, Joined and Last active headings (and the Spaces list's dates) had no
 * style of their own, so they drew as blue underlined links beside plain headings.
 *
 * @coversNothing
 */
class AdminSortHeadingTest extends \WP_UnitTestCase {

	/**
	 * The sort link takes the heading's ink and drops the underline, in tokens only.
	 *
	 * @return void
	 */
	public function test_sort_links_take_the_heading_ink(): void {
		$css = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertMatchesRegularExpression( '/table\.bn-table th a\.bn-th-sort\s*\{[^}]*color:\s*inherit;[^}]*text-decoration:\s*none;/', $css );
		preg_match_all( '/[^{}]*\.bn-th-sort[^{}]*\{([^}]*)\}/', $css, $rules );
		foreach ( $rules[1] as $body ) {
			$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgb\(/i', $body, 'Colours come from tokens so dark mode follows.' );
		}
	}
}
