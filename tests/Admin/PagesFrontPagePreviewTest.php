<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Pages & URLs shows the address a hub really answers at.
 *
 * The Activity hub set as the site's front page answers at the site root, but
 * its row previewed /activity/. Every other row carries a live preview hook.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\NavManager;
use WP_UnitTestCase;

/**
 * Front-page hub preview.
 *
 * @covers \BuddyNext\Admin\NavManager::render_pages_tab
 */
class PagesFrontPagePreviewTest extends WP_UnitTestCase {

	/**
	 * The front-page hub previews the site root; the others keep a live preview.
	 *
	 * @return void
	 */
	public function test_front_page_hub_previews_the_site_root(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'buddynext_page_activity', $page );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );

		ob_start();
		( new NavManager() )->render_pages_tab();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'front page, so it opens at the site address', $html );
		$this->assertStringContainsString( 'data-bn-slug-preview', $html );
		$this->assertStringContainsString( 'bn-save-bar', $html );
	}
}
