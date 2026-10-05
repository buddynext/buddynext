<?php
/**
 * Icons drawn once per page (sprite mode).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\IconService;

/**
 * Sprite mode.
 *
 * @covers \BuddyNext\Core\IconService::open_sprite
 * @covers \BuddyNext\Core\IconService::print_sprite
 */
class IconSpriteTest extends \WP_UnitTestCase {

	/**
	 * Inline by default; in sprite mode the same outer <svg> holds a <use>, the
	 * shape is printed once, and after printing icons are inline again.
	 *
	 * @return void
	 */
	public function test_sprite_draws_each_icon_once(): void {
		$inline = IconService::render( 'bell' );
		$this->assertStringContainsString( '<path', $inline, 'Inline outside a hub page.' );
		$this->assertStringNotContainsString( '<use', $inline );

		IconService::open_sprite();
		$a = IconService::render( 'bell' );
		$b = IconService::render( 'bell', 'extra' );
		$this->assertStringContainsString( '<use href="#bn-i-bell"></use>', $a );
		$this->assertStringNotContainsString( '<path', $a );
		$this->assertStringContainsString( 'class="bn-icon bn-icon--bell extra"', $b, 'Classes stay on the outer svg.' );

		ob_start();
		IconService::print_sprite();
		$sprite = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $sprite, '<symbol id="bn-i-bell"' ), 'One shape for every use.' );
		$this->assertStringContainsString( '<path', $sprite );

		$this->assertStringContainsString( '<path', IconService::render( 'bell' ), 'After the sprite is printed, icons are inline again.' );
		$this->assertSame( '', IconService::render( '' ), 'No icon is still no icon.' );
	}

	/**
	 * Each router region carries the shapes it uses, so a swapped-in page draws.
	 *
	 * "Load more" swaps only the feed region; a sprite printed only in wp_footer
	 * never reached the page, and icons first used on page two drew blank (card
	 * 10369460065). A shape already printed in a region is not printed again.
	 *
	 * @return void
	 */
	public function test_region_sprite_carries_its_shapes_once(): void {
		IconService::open_sprite();
		IconService::render( 'bell' );

		ob_start();
		IconService::print_region_sprite();
		$this->assertStringContainsString( '<symbol id="bn-i-bell"', (string) ob_get_clean(), 'The region holds the shape its icons point at.' );

		IconService::render( 'bell' );
		IconService::render( 'heart' );
		ob_start();
		IconService::print_sprite();
		$footer = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'bn-i-bell', $footer, 'Already on the page: not printed twice.' );
		$this->assertStringContainsString( '<symbol id="bn-i-heart"', $footer, 'First used after the region: the footer has it.' );

		IconService::open_sprite();
		IconService::render( 'bell' );
		ob_start();
		IconService::print_sprite();
		$this->assertStringContainsString( '<symbol id="bn-i-bell"', (string) ob_get_clean(), 'A new page starts with nothing printed.' );
	}

	/**
	 * buddynext_icon_sprite false keeps icons inline (the site's CSS can reach them).
	 *
	 * @return void
	 */
	public function test_filter_keeps_icons_inline(): void {
		add_filter( 'buddynext_icon_sprite', '__return_false' );
		IconService::open_sprite();
		$this->assertStringContainsString( '<path', IconService::render( 'bell' ) );
		ob_start();
		IconService::print_sprite();
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing to print.' );
	}
}
