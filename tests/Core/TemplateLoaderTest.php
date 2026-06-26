<?php
/**
 * Tests for the BuddyNext template loader.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\TemplateLoader;

/**
 * Verifies template resolution and rendering.
 *
 * @covers \BuddyNext\Core\TemplateLoader
 */
class TemplateLoaderTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $loader;

	/**
	 * Temporary directory for test templates.
	 *
	 * @var string
	 */
	private string $tmp_dir;

	/**
	 * Set up the test environment.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->loader  = new TemplateLoader();
		$this->tmp_dir = sys_get_temp_dir() . '/bn_template_test_' . uniqid( '', true );
		mkdir( $this->tmp_dir, 0755, true );
	}

	/**
	 * Clean up temporary files.
	 */
	public function tear_down(): void {
		parent::tear_down();
		$this->rrmdir( $this->tmp_dir );
	}

	// ── locate() ──────────────────────────────────────────────────────────────

	/**
	 * locate() returns null when the template does not exist anywhere.
	 */
	public function test_locate_returns_null_for_missing_template(): void {
		$result = $this->loader->locate( 'nonexistent/template.php' );
		$this->assertNull( $result );
	}

	/**
	 * locate() returns the plugin path when the plugin template exists.
	 *
	 * Uses a test-only filename so we never collide with a shipped template —
	 * previously this overwrote then unlink()'d `templates/feed/home.php`,
	 * destroying the real production template on every test run.
	 */
	public function test_locate_finds_plugin_template(): void {
		$relative = '__phpunit-locate-plugin-template.php';
		$target   = BUDDYNEXT_DIR . 'templates/' . $relative;

		file_put_contents( $target, '<?php // test' );

		try {
			$result = $this->loader->locate( $relative );
			$this->assertSame( $target, $result );
		} finally {
			if ( file_exists( $target ) ) {
				unlink( $target );
			}
		}
	}

	/**
	 * locate() strips a leading slash from the relative path.
	 */
	public function test_locate_strips_leading_slash(): void {
		// No template exists — just verify no error and returns null.
		$result = $this->loader->locate( '/nonexistent/file.php' );
		$this->assertNull( $result );
	}

	// ── render() ──────────────────────────────────────────────────────────────

	/**
	 * render() outputs no actual template content when the template does not exist.
	 *
	 * In WP_DEBUG mode a HTML comment is emitted; production returns an empty
	 * string. Either way, there should be no meaningful content beyond the
	 * debug comment.
	 */
	public function test_render_silent_for_missing_template(): void {
		$output = $this->loader->render( 'nonexistent/missing.php' );
		// Strip any debug HTML comment — the meaningful assertion is that no
		// template content was produced.
		$stripped = preg_replace( '/<!--.*?-->/s', '', $output );
		$this->assertSame( '', trim( $stripped ) );
	}

	/**
	 * render() includes the template file and returns its output.
	 */
	public function test_render_includes_template_and_outputs_content(): void {
		$file = $this->tmp_dir . '/test.php';
		file_put_contents( $file, '<?php echo "hello from template"; ?>' );

		$loader = $this->make_loader_with_dir( $this->tmp_dir . '/' );
		$output = $loader->render( 'test.php' );
		$this->assertSame( 'hello from template', $output );
	}

	/**
	 * render() passes variables into the template scope.
	 */
	public function test_render_passes_variables_to_template(): void {
		$file = $this->tmp_dir . '/vars.php';
		file_put_contents( $file, '<?php echo $greeting . " " . $name; ?>' );

		$loader = $this->make_loader_with_dir( $this->tmp_dir . '/' );

		$output = $loader->render(
			'vars.php',
			array(
				'greeting' => 'Hello',
				'name'     => 'World',
			)
		);
		$this->assertSame( 'Hello World', $output );
	}

	/**
	 * render() fires buddynext_before_template before the template.
	 */
	public function test_render_fires_before_template_hook(): void {
		$file = $this->tmp_dir . '/hook.php';
		file_put_contents( $file, '<?php // empty ?>' );

		$loader  = $this->make_loader_with_dir( $this->tmp_dir . '/' );
		$fired   = false;

		add_action(
			'buddynext_before_template',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$loader->render( 'hook.php' );
		$this->assertTrue( $fired );
	}

	/**
	 * render() fires buddynext_after_template after the template.
	 */
	public function test_render_fires_after_template_hook(): void {
		$file = $this->tmp_dir . '/hook-after.php';
		file_put_contents( $file, '<?php // empty ?>' );

		$loader = $this->make_loader_with_dir( $this->tmp_dir . '/' );
		$fired  = false;

		add_action(
			'buddynext_after_template',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$loader->render( 'hook-after.php' );
		$this->assertTrue( $fired );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Create a TemplateLoader whose default templates directory points to $dir.
	 *
	 * Uses a reflection trick to override the private property.
	 *
	 * @param string $dir Absolute path with trailing slash.
	 * @return TemplateLoader
	 */
	private function make_loader_with_dir( string $dir ): TemplateLoader {
		$loader    = new TemplateLoader();
		$reflector = new \ReflectionObject( $loader );
		$prop      = $reflector->getProperty( 'templates_dir' );
		$prop->setAccessible( true );
		$prop->setValue( $loader, $dir );
		return $loader;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Path to remove.
	 * @return void
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getRealPath() ) : unlink( $item->getRealPath() );
		}
		rmdir( $dir );
	}
}
