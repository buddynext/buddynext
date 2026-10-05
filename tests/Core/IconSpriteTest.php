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
}
