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
 * Verifies that CSS custom-property tokens are generated correctly.
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
	 * The default token map contains the brand color token.
	 */
	public function test_get_defaults_includes_brand_color(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--brand', $defaults );
	}

	/**
	 * The default token map contains the font-body token.
	 */
	public function test_get_defaults_includes_font_body(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--font-body', $defaults );
	}

	/**
	 * The default token map contains the s4 spacing token (16 px base).
	 */
	public function test_get_defaults_includes_s4_spacing(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--s4', $defaults );
	}

	/**
	 * The default token map contains all nine spacing steps.
	 */
	public function test_get_defaults_includes_all_spacing_steps(): void {
		$defaults = $this->service->get_defaults();
		foreach ( array( '--s1', '--s2', '--s3', '--s4', '--s5', '--s6', '--s8', '--s10', '--s12' ) as $token ) {
			$this->assertArrayHasKey( $token, $defaults, "Missing spacing token: $token" );
		}
	}

	/**
	 * The default token map contains all five radius tokens.
	 */
	public function test_get_defaults_includes_all_radius_tokens(): void {
		$defaults = $this->service->get_defaults();
		foreach ( array( '--r-sm', '--r-md', '--r-lg', '--r-xl', '--r-full' ) as $token ) {
			$this->assertArrayHasKey( $token, $defaults, "Missing radius token: $token" );
		}
	}

	/**
	 * The default token map contains dark-mode-related integration accent tokens.
	 */
	public function test_get_defaults_includes_integration_accents(): void {
		$defaults = $this->service->get_defaults();
		$this->assertArrayHasKey( '--jetonomy', $defaults );
		$this->assertArrayHasKey( '--mvs', $defaults );
	}

	/**
	 * The buddynext_css_vars filter can override token values.
	 */
	public function test_buddynext_css_vars_filter_overrides_value(): void {
		add_filter(
			'buddynext_css_vars',
			function ( array $vars ): array {
				$vars['--brand'] = '#ff0000';
				return $vars;
			}
		);

		$css = $this->service->build_css();
		$this->assertStringContainsString( '--brand: #ff0000', $css );
	}

	/**
	 * The built CSS is wrapped in a :root block.
	 */
	public function test_build_css_outputs_root_block(): void {
		$css = $this->service->build_css();
		$this->assertStringContainsString( ':root {', $css );
	}

	/**
	 * The built CSS contains a dark-mode override block.
	 */
	public function test_build_css_outputs_dark_mode_block(): void {
		$css = $this->service->build_css();
		$this->assertStringContainsString( '[data-theme="dark"]', $css );
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
		$this->assertStringContainsString( '--wp--preset--', $defaults['--brand'] );
	}

	/**
	 * Calling init() attaches the wp_enqueue_scripts hook at priority 20.
	 */
	public function test_init_adds_wp_enqueue_scripts_hook(): void {
		$this->service->init();
		$this->assertSame(
			20,
			has_action( 'wp_enqueue_scripts', array( $this->service, 'attach_tokens' ) )
		);
	}

	/**
	 * The buddynext_css_vars_dark filter can override dark-mode token values.
	 */
	public function test_buddynext_css_vars_dark_filter_overrides_value(): void {
		add_filter(
			'buddynext_css_vars_dark',
			function ( array $vars ): array {
				$vars['--bg'] = '#000000';
				return $vars;
			}
		);

		$css = $this->service->build_css();
		$this->assertStringContainsString( '--bg: #000000', $css );
	}
}
