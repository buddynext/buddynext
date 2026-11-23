<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A page outside the hub shell that shows our blocks or shortcodes loads the
 * head bootstrap that applies the dark switch (data-bn-theme).
 *
 * @package BuddyNext\Tests\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

use BuddyNext\Blocks\BlockRegistrar;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Blocks\BlockRegistrar::enqueue_block_styles_early
 */
class ThemeBootstrapOnContentPagesTest extends WP_UnitTestCase {

	/**
	 * Whether the bootstrap is queued on a page with this content.
	 *
	 * @param string $content Page content.
	 * @return bool
	 */
	private function queued_for( string $content ): bool {
		$GLOBALS['wp_scripts'] = new \WP_Scripts();
		wp_register_script( 'bn-shell-font-scale', 'font-scale.js', array(), '1', false ); // Registered at wp_enqueue_scripts:10 on a real request.
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		$this->go_to( get_permalink( $page ) );
		( new BlockRegistrar() )->enqueue_block_styles_early();
		return wp_script_is( 'bn-shell-font-scale', 'enqueued' );
	}

	/**
	 * Blocks and shortcodes load it; a plain page does not.
	 *
	 * @return void
	 */
	public function test_bootstrap_follows_our_content(): void {
		$this->assertTrue( $this->queued_for( '<!-- wp:buddynext/member-directory {"perPage":6} /-->' ), 'A block page.' );
		$this->assertTrue( $this->queued_for( '[buddynext_people]' ), 'A shortcode page.' );
		$this->assertFalse( $this->queued_for( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ), 'A plain page.' );
	}

	/**
	 * Outside the shell the bootstrap follows the theme's colour mode only.
	 *
	 * @return void
	 */
	public function test_outside_the_shell_the_theme_decides(): void {
		$this->queued_for( '[buddynext_people]' );
		$this->assertStringContainsString( 'bnThemeHostOnly', implode( '', (array) wp_scripts()->get_data( 'bn-shell-font-scale', 'before' ) ) );
	}
}
