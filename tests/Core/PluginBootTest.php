<?php
/**
 * Tests for plugin bootstrap, constants, and service container.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Container;

/**
 * @covers \BuddyNext\Core\Plugin
 * @covers \BuddyNext\Core\Container
 */
class PluginBootTest extends \WP_UnitTestCase {

	public function test_version_constant_defined(): void {
		$this->assertTrue( defined( 'BUDDYNEXT_VERSION' ) );
	}

	public function test_dir_constant_defined(): void {
		$this->assertTrue( defined( 'BUDDYNEXT_DIR' ) );
		$this->assertDirectoryExists( BUDDYNEXT_DIR );
	}

	public function test_file_constant_defined(): void {
		$this->assertTrue( defined( 'BUDDYNEXT_FILE' ) );
		$this->assertFileExists( BUDDYNEXT_FILE );
	}

	public function test_buddynext_loaded_hook_fires(): void {
		$fired = false;
		add_action(
			'buddynext_loaded',
			function () use ( &$fired ) {
				$fired = true;
			}
		);
		do_action( 'buddynext_loaded' );
		$this->assertTrue( $fired );
	}

	public function test_container_returns_singleton(): void {
		$a = Container::instance();
		$b = Container::instance();
		$this->assertSame( $a, $b );
	}

	public function test_container_bind_and_get(): void {
		$container = Container::instance();
		$container->bind( 'test_service_boot', fn() => new \stdClass() );
		$obj1 = $container->get( 'test_service_boot' );
		$obj2 = $container->get( 'test_service_boot' );
		$this->assertSame( $obj1, $obj2 );
	}

	public function test_container_throws_for_unknown_key(): void {
		$this->expectException( \RuntimeException::class );
		Container::instance()->get( 'no_such_service_xyz' );
	}
}
