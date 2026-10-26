<?php
/**
 * The sign-up panel draws its image only when one is set.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

class AuthAsideImageTest extends \WP_UnitTestCase {

	private function render(): string {
		ob_start();
		include BUDDYNEXT_DIR . 'templates/auth/parts/auth-aside.php';
		return (string) ob_get_clean();
	}

	public function tear_down(): void {
		delete_option( 'buddynext_auth_panel_image' );
		parent::tear_down();
	}

	public function test_a_cleared_image_renders_no_image_hook(): void {
		update_option( 'buddynext_auth_panel_image', '' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'data-has-image', $html );
		$this->assertStringNotContainsString( "url('')", $html );
	}

	public function test_a_set_image_is_drawn(): void {
		update_option( 'buddynext_auth_panel_image', 'https://example.test/panel.jpg' );
		$html = $this->render();
		$this->assertStringContainsString( 'data-has-image="1"', $html );
		$this->assertStringContainsString( "url('https://example.test/panel.jpg')", $html );
	}
}
