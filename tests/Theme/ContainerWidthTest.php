<?php
/**
 * Appearance > Layout resolves to one boxed width, or none.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use BuddyNext\Theme\Appearance;

/**
 * Card 10352875991: community pages can follow a boxed width instead of always
 * spanning the screen. Full width stays the default; boxed widths never go
 * below the point where the shell already drops to two columns.
 *
 * @covers \BuddyNext\Theme\Appearance::container_width_for
 * @covers \BuddyNext\Theme\Appearance::container_width
 */
class ContainerWidthTest extends \WP_UnitTestCase {

	/**
	 * Clean options and filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_container_width' );
		delete_option( 'buddynext_container_width_custom' );
		remove_all_filters( 'buddynext_theme_container_width' );
		parent::tear_down();
	}

	/**
	 * Unset means full width, exactly as before.
	 *
	 * @return void
	 */
	public function test_default_is_full_width(): void {
		$this->assertSame( 0, Appearance::container_width() );
	}

	/**
	 * A custom width is used, clamped to the supported range.
	 *
	 * @return void
	 */
	public function test_custom_width_is_clamped(): void {
		update_option( 'buddynext_container_width', 'custom' );

		update_option( 'buddynext_container_width_custom', 1300 );
		$this->assertSame( 1300, Appearance::container_width() );

		update_option( 'buddynext_container_width_custom', 600 );
		$this->assertSame( Appearance::CONTAINER_MIN, Appearance::container_width() );

		update_option( 'buddynext_container_width_custom', 9000 );
		$this->assertSame( Appearance::CONTAINER_MAX, Appearance::container_width() );
	}

	/**
	 * Theme default reads the theme, lets a theme override it, and never goes too narrow.
	 *
	 * @return void
	 */
	public function test_theme_width_follows_the_theme_and_its_filter(): void {
		$this->assertGreaterThanOrEqual( Appearance::CONTAINER_MIN, Appearance::container_width_for( 'theme' ) );

		add_filter( 'buddynext_theme_container_width', static fn(): int => 1320 );
		$this->assertSame( 1320, Appearance::container_width_for( 'theme' ) );

		remove_all_filters( 'buddynext_theme_container_width' );
		add_filter( 'buddynext_theme_container_width', static fn(): int => 0 );
		$this->assertSame( 1200, Appearance::container_width_for( 'theme' ), 'No theme width falls back to 1200.' );

		$this->assertSame( 0, Appearance::container_width_for( 'full' ) );
		$this->assertSame( 0, Appearance::container_width_for( 'nonsense' ) );
	}
}
