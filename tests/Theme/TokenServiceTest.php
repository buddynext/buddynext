<?php
/**
 * Tests for the theme CSS token service.
 *
 * @package BuddyNext\Tests\Theme
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Theme;

use BuddyNext\Theme\TokenService;

/**
 * Verifies that --bn-* CSS tokens are generated correctly.
 *
 * @covers \BuddyNext\Theme\TokenService
 */
class TokenServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var TokenService
	 */
	private TokenService $service;

	/**
	 * Create a fresh TokenService before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new TokenService();
	}

	/**
	 * The default token map contains the primary color token.
	 */
	public function test_get_defaults_includes_primary_color(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--bn-color-primary', $defaults );
	}

	/**
	 * The default token map contains the font family token.
	 */
	public function test_get_defaults_includes_font_family(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--bn-font-family', $defaults );
	}

	/**
	 * The default token map contains the base spacing token.
	 */
	public function test_get_defaults_includes_space_md(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--bn-space-md', $defaults );
	}

	/**
	 * The buddynext_css_vars filter can override token values.
	 */
	public function test_buddynext_css_vars_filter_overrides_value(): void {
		add_filter(
			'buddynext_css_vars',
			function ( array $vars ): array {
				$vars['--bn-color-primary'] = '#ff0000';
				return $vars;
			}
		);

		$css = $this->service->build_css();
		$this->assertStringContainsString( '--bn-color-primary: #ff0000', $css );
	}

	/**
	 * The built CSS is wrapped in a :root block.
	 */
	public function test_build_css_outputs_root_block(): void {
		$css = $this->service->build_css();
		$this->assertStringContainsString( ':root {', $css );
	}

	/**
	 * The built CSS contains a closing brace.
	 */
	public function test_build_css_has_closing_brace(): void {
		$css = $this->service->build_css();
		$this->assertStringContainsString( '}', $css );
	}

	/**
	 * The default token values use var(--wp--preset--*) references.
	 */
	public function test_defaults_use_wp_preset_vars(): void {
		$defaults = $this->service->get_defaults();
		$this->assertStringContainsString( '--wp--preset--', $defaults['--bn-color-primary'] );
	}

	/**
	 * Calling init() attaches the wp_head hook.
	 */
	public function test_init_adds_wp_head_hook(): void {
		$this->service->init();
		$this->assertNotFalse(
			has_action( 'wp_head', array( $this->service, 'output_css' ) )
		);
	}
}
