<?php
/**
 * Tests for the hub-extension seams that let a Pro plugin own a native
 * top-level surface (e.g. /jobs/) without Free knowing about it.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use BuddyNext\Core\TemplateLoader;

/**
 * @covers \BuddyNext\Core\PageRouter
 * @covers \BuddyNext\Core\TemplateLoader
 */
class HubSeamsTest extends \WP_UnitTestCase {

	/**
	 * Invoke a private PageRouter method.
	 *
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args   Arguments.
	 * @return mixed
	 */
	private function call_router( string $method, array $args ) {
		$router = new PageRouter();
		$ref    = new \ReflectionMethod( PageRouter::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $router, $args );
	}

	public function test_unknown_hub_resolves_via_filter(): void {
		// Without a filter, an unknown hub resolves to null (WP handles it).
		$this->assertNull( $this->call_router( 'resolve_hub_template', array( 'jobs' ) ) );

		add_filter(
			'buddynext_hub_template',
			static function ( $template, string $hub ) {
				return 'jobs' === $hub ? 'jobs/list.php' : $template;
			},
			10,
			2
		);

		$this->assertSame( 'jobs/list.php', $this->call_router( 'resolve_hub_template', array( 'jobs' ) ) );
		// A known Free hub is unaffected by the filter.
		$this->assertSame( 'notifications/index.php', $this->call_router( 'resolve_hub_template', array( 'notifications' ) ) );
	}

	public function test_unknown_hub_context_via_filter(): void {
		add_filter(
			'buddynext_hub_context',
			static function ( array $context, string $hub ): array {
				if ( 'jobs' === $hub ) {
					$context['jobs_payload'] = array( 'count' => 3 );
				}
				return $context;
			},
			10,
			2
		);

		$context = $this->call_router( 'build_hub_context', array( 'jobs' ) );
		$this->assertSame( array( 'count' => 3 ), $context['jobs_payload'] );
	}

	public function test_enqueue_hub_assets_fires_external_action(): void {
		$fired = array();
		add_action(
			'buddynext_enqueue_hub_assets',
			static function ( string $hub, array $context ) use ( &$fired ): void {
				$fired[] = $hub;
			},
			10,
			2
		);

		$this->call_router( 'enqueue_hub_assets', array( 'jobs', array() ) );

		$this->assertContains( 'jobs', $fired );
	}

	public function test_template_paths_filter_adds_root(): void {
		$root = get_temp_dir() . 'bn-tpl-' . wp_generate_password( 8, false );
		wp_mkdir_p( $root . '/jobs' );
		file_put_contents( $root . '/jobs/list.php', '<?php // jobs list' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$loader = new TemplateLoader();

		// Not found before the filter is registered.
		$this->assertNull( $loader->locate( 'jobs/list.php' ) );

		add_filter(
			'buddynext_template_paths',
			static function ( array $roots ) use ( $root ): array {
				$roots[] = $root;
				return $roots;
			}
		);

		$this->assertSame( $root . '/jobs/list.php', $loader->locate( 'jobs/list.php' ) );

		// Cleanup.
		unlink( $root . '/jobs/list.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( $root . '/jobs' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
		rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
	}
}
