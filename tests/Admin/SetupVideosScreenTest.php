<?php
/**
 * The Setup videos tab on Home.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\SetupVideosScreen;

/**
 * The hardcoded film list stays well formed, and each theme gets its own film first.
 *
 * @covers \BuddyNext\Admin\SetupVideosScreen
 */
class SetupVideosScreenTest extends \WP_UnitTestCase {

	public function test_every_film_has_a_real_looking_youtube_id_and_no_id_repeats(): void {
		$ids = array_column( SetupVideosScreen::films(), 'id' );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{11}$/', $id );
		}
		$this->assertCount( count( $ids ), array_unique( $ids ) );
		$this->assertCount( 10, $ids );
	}

	public function test_each_theme_opens_on_its_all_in_one_film(): void {
		$this->assertSame( 'jLANmm3uAGI', SetupVideosScreen::featured( 'reign' )['id'] );
		$this->assertSame( 'W2rs9rpKEYk', SetupVideosScreen::featured( 'buddyxpro' )['id'] );
		$this->assertSame( 'Q1MPFOxc388', SetupVideosScreen::featured( 'buddyx' )['id'] );
	}

	public function test_the_live_film_is_never_the_one_that_opens_first(): void {
		foreach ( array( 'reign', 'buddyxpro', 'buddyx' ) as $theme ) {
			$this->assertFalse( SetupVideosScreen::featured( $theme )['live'] );
		}
	}

	public function test_a_theme_that_is_not_ours_gets_the_buddyx_film(): void {
		switch_theme( 'twentytwentyfive' );
		$this->assertSame( 'buddyx', SetupVideosScreen::site_theme() );
	}

	public function test_the_tab_is_registered_under_home_and_renders_the_player(): void {
		( new SetupVideosScreen() )->register();
		$tabs = \BuddyNext\Admin\AdminHub::get_tabs( 'get-started' );
		$this->assertArrayHasKey( 'videos', $tabs );

		ob_start();
		( new SetupVideosScreen() )->render();
		$html = (string) ob_get_clean();
		$this->assertSame( 10, preg_match_all( '/class="bn-videos__row[ "]/', $html ), 'One row per film.' );
		$this->assertStringContainsString( 'https://buddynext.com/videos/', $html );
		$this->assertStringContainsString( 'youtube.com/@WbcomDesigns', $html );
	}
}
