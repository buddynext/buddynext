<?php
/**
 * Regression test: core hub template resolution must remain unchanged post-registry refactor.
 *
 * @package BuddyNext\Tests\Core
 */

declare(strict_types=1);
namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * Locks core hub template resolution after the A1-A6 registry refactor.
 *
 * @covers \BuddyNext\Core\PageRouter
 * @covers \BuddyNext\Core\HubRegistry
 * @covers \BuddyNext\Core\CoreHubs
 */
class CoreHubRegressionTest extends WP_UnitTestCase {
	/**
	 * Reset the singleton and seed the registry with core hubs before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$ref = new \ReflectionProperty( HubRegistry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
		CoreHubs::register( HubRegistry::instance() );
	}

	/**
	 * Verifies that each core hub resolves to its canonical template path.
	 *
	 * @dataProvider hub_template_cases
	 * @param string $hub      Hub key passed to resolve_hub_template.
	 * @param string $expected Expected relative template path.
	 * @return void
	 */
	public function test_core_hub_templates_unchanged( string $hub, string $expected ): void {
		$m = new \ReflectionMethod( PageRouter::class, 'resolve_hub_template' );
		$m->setAccessible( true );
		$this->assertSame( $expected, $m->invoke( new PageRouter(), $hub ) );
	}

	/**
	 * Data provider: the 7 core hub default template paths.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function hub_template_cases(): array {
		return array(
			array( 'feed', 'feed/home.php' ),
			array( 'post', 'feed/single-post.php' ),
			array( 'people', 'directory/members.php' ),
			array( 'spaces', 'spaces/directory.php' ),
			array( 'messages', 'messages/list.php' ),
			array( 'notifications', 'notifications/index.php' ),
			array( 'auth', 'auth/login.php' ),
		);
	}
}
