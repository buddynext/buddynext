<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
declare(strict_types=1);
namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\HubDescriptor;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * Tests that addon hubs registered via buddynext_register_hubs get their
 * rewrite rules, template resolution, and query vars handled by PageRouter.
 */
class AddonHubDispatchTest extends WP_UnitTestCase {
	/**
	 * Reset the HubRegistry singleton before each test to avoid cross-test leakage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$ref = new \ReflectionProperty( HubRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * Verify all three dispatch paths for an addon hub: rules, template, and query vars.
	 *
	 * @return void
	 */
	public function test_addon_hub_rules_run_and_template_resolves_and_query_vars_register(): void {
		$ran = false;
		add_action(
			'buddynext_register_hubs',
			function ( HubRegistry $reg ) use ( &$ran ) {
				$reg->register(
					new HubDescriptor(
						'demo',
						'buddynext_slug_demo',
						'demo',
						'buddynext_page_demo',
						'Demo',
						'[demo]',
						'demo',
						function () use ( &$ran ) {
							$ran = true; },
						static function ( string $hub ): ?string {
							return 'demo' === $hub ? 'demo/index.php' : null;
						},
						array( 'bn_demo_action' )
					)
				);
			}
		);

		// Populate registry (fires the action above, adding the demo hub).
		CoreHubs::register( HubRegistry::instance() );

		$router = new PageRouter();

		// 1) addon register_rules runs during register_rewrites().
		$router->register_rewrites();
		$this->assertTrue( $ran, 'addon register_rules did not run' );

		// 2) addon resolve_template resolves its hub (core switch returns null for 'demo').
		$resolve = new \ReflectionMethod( PageRouter::class, 'resolve_hub_template' );
		$resolve->setAccessible( true );
		$this->assertSame( 'demo/index.php', $resolve->invoke( $router, 'demo' ) );

		// 3) addon query var is whitelisted.
		$vars = $router->register_directory_query_vars( array() );
		$this->assertContains( 'bn_demo_action', $vars );

		// 4) a core hub still resolves via the core switch (unaffected).
		$this->assertSame( 'feed/home.php', $resolve->invoke( $router, 'feed' ) );
	}
}
