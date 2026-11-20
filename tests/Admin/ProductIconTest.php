<?php
/**
 * ProductIcon resolves a product's icon and falls back to a letter tile.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\ProductIcon;

/**
 * @covers \BuddyNext\Admin\ProductIcon
 */
class ProductIconTest extends \WP_UnitTestCase {

	/**
	 * A declared URL wins over the bundled mark.
	 */
	public function test_declared_icon_wins(): void {
		$html = ProductIcon::render( 'Jetonomy', 'https://cdn.example.com/icon.svg', 'jetonomy' );

		$this->assertStringContainsString( 'src="https://cdn.example.com/icon.svg"', $html );
	}

	/**
	 * A plugins-folder-relative path resolves through plugins_url().
	 */
	public function test_plugin_relative_path_resolves(): void {
		$this->assertSame( plugins_url( 'my-addon/assets/icon.svg' ), ProductIcon::url( 'my-addon/assets/icon.svg' ) );
	}

	/**
	 * With nothing declared, a Wbcom companion gets the mark bundled with BuddyNext.
	 */
	public function test_bundled_default(): void {
		$this->assertSame( BUDDYNEXT_URL . 'assets/img/companions/jetonomy.svg', ProductIcon::url( '', 'jetonomy' ) );
	}

	/**
	 * Nothing declared and nothing bundled: the first letter on a tile.
	 */
	public function test_letter_fallback(): void {
		$html = ProductIcon::render( 'qa product', '', 'no-such-mark' );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '>Q</span>', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/**
	 * Script, data, protocol-relative and traversal values are never used.
	 *
	 * @dataProvider unsafe_icons
	 *
	 * @param string $icon Declared value.
	 */
	public function test_unsafe_icon_is_rejected( string $icon ): void {
		$this->assertSame( '', ProductIcon::url( $icon ) );
		$this->assertSame( BUDDYNEXT_URL . 'assets/img/companions/jetonomy.svg', ProductIcon::url( $icon, 'jetonomy' ), 'Falls through to the bundled mark.' );
	}

	/**
	 * Unsafe declared icons.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function unsafe_icons(): array {
		return array(
			'javascript'        => array( 'javascript:alert(1)' ),
			'data'              => array( 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' ),
			'protocol-relative' => array( '//evil.example/icon.svg' ),
			'traversal'         => array( '../../wp-config.php' ),
			'absolute path'     => array( '/etc/passwd' ),
		);
	}

	/**
	 * The image is decorative and sized, so it is skipped by readers and nothing shifts.
	 */
	public function test_image_is_decorative_and_sized(): void {
		$html = ProductIcon::render( 'Jetonomy', '', 'jetonomy' );

		$this->assertStringContainsString( 'aria-hidden="true"', $html );
		$this->assertStringContainsString( 'alt=""', $html );
		$this->assertStringContainsString( 'width="40"', $html );
		$this->assertStringContainsString( 'height="40"', $html );
	}
}
