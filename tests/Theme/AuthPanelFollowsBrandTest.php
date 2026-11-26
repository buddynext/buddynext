<?php
/**
 * The login and sign-up side panel takes its colour from the brand, never from a fixed hue.
 *
 * It once showed blue fading into purple on every site: two of the three CSS stops were
 * fixed hues, and the shipped cover artwork had the same blue and purple painted in as an
 * opaque base that covered the gradient.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

/**
 * @coversNothing Static assets.
 */
class AuthPanelFollowsBrandTest extends \WP_UnitTestCase {

	/**
	 * Every gradient stop is derived from the accent or from another stop.
	 */
	public function test_gradient_stops_derive_from_the_brand(): void {
		$css = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/css/bn-base.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertGreaterThan( 0, preg_match_all( '/--auth-grad-(?:from|via|to):\s*([^;]+);/', $css, $m ), 'the stops are declared' );

		foreach ( $m[1] as $value ) {
			$this->assertMatchesRegularExpression(
				'/var\(--bn-accent\)|var\(--auth-grad-from\)/',
				$value,
				'a stop must follow the brand, not a fixed colour: ' . trim( $value )
			);
		}
	}

	/**
	 * The shipped artwork is texture only, so the brand gradient behind it shows through.
	 */
	public function test_default_cover_artwork_paints_no_fixed_colour(): void {
		$svg = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/images/auth-cover.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertNotSame( '', $svg );
		$this->assertDoesNotMatchRegularExpression( '/<rect[^>]*fill="url\(#bnAuthBase\)"/', $svg, 'no opaque base rectangle' );

		preg_match_all( '/(?:stop-color|fill|stroke)="(#[0-9a-fA-F]{3,6})"/', $svg, $m );
		foreach ( array_unique( array_map( 'strtolower', $m[1] ) ) as $colour ) {
			$this->assertSame( '#ffffff', $colour, 'only white texture is allowed in the artwork' );
		}
	}
}
