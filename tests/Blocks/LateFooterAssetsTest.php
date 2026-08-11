<?php
/**
 * Blocks rendered during wp_footer keep their view modules.
 *
 * Core prints script modules on wp_footer at priority 10 and walks the queue
 * exactly once. A page-builder footer renders during wp_footer, so its blocks
 * enqueue into a queue that has already been printed: the markup paints, the
 * view module never loads, and the block is silently inert (Basecamp
 * 10181441816 — same defect wb-listora fixed in 1.4.2). BlockRegistrar wires
 * a second, idempotent print pass at wp_footer:20.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Blocks;

use BuddyNext\Blocks\BlockRegistrar;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Blocks\BlockRegistrar
 */
class LateFooterAssetsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'buddynext_late_print_script_modules' );
		parent::tear_down();
	}

	/**
	 * A module enqueued DURING wp_footer (a builder-rendered footer block)
	 * still reaches the page.
	 */
	public function test_module_enqueued_during_wp_footer_still_prints(): void {
		wp_register_script_module( 'bn-test/late-module', 'https://example.test/bn-late-module.js', array(), '1.0' );
		add_action(
			'wp_footer',
			static function (): void {
				wp_enqueue_script_module( 'bn-test/late-module' );
			},
			15
		);

		( new BlockRegistrar() )->init();

		// The block-theme test env fires this core deprecation on wp_footer;
		// unrelated to the behavior under test.
		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		ob_start();
		do_action( 'wp_footer' );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'bn-late-module.js', $out, 'A view module enqueued by a wp_footer-rendered block must still be printed.' );
		$this->assertSame( 1, substr_count( $out, 'bn-late-module.js' ), 'The late pass must print the module exactly once.' );
	}

	/**
	 * The kill-switch filter disables the pass.
	 */
	public function test_late_pass_is_filterable(): void {
		wp_register_script_module( 'bn-test/late-module-b', 'https://example.test/bn-late-module-b.js', array(), '1.0' );
		add_action(
			'wp_footer',
			static function (): void {
				wp_enqueue_script_module( 'bn-test/late-module-b' );
			},
			15
		);
		add_filter( 'buddynext_late_print_script_modules', '__return_false' );

		( new BlockRegistrar() )->init();

		// The block-theme test env fires this core deprecation on wp_footer;
		// unrelated to the behavior under test.
		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		ob_start();
		do_action( 'wp_footer' );
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'bn-late-module-b.js', $out, 'With the filter off, BuddyNext adds no second pass (core behavior).' );
	}
}
