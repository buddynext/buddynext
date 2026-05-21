<?php
/**
 * Tests for the DI container, focusing on the has() / bind() / get() public API.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Container;
use RuntimeException;

/**
 * @covers \BuddyNext\Core\Container
 */
class ContainerTest extends \WP_UnitTestCase {

	public function test_has_returns_false_for_unknown_key(): void {
		$container = Container::instance();
		$this->assertFalse( $container->has( 'nonexistent_service' ) );
	}

	public function test_has_returns_true_after_bind(): void {
		$container = Container::instance();
		$container->bind( 'my_service', static fn() => new \stdClass() );
		$this->assertTrue( $container->has( 'my_service' ) );
	}

	public function test_get_returns_singleton(): void {
		$container = Container::instance();
		$container->bind(
			'svc',
			static fn() => new class() {
				public int $id;
				public function __construct() {
					$this->id = wp_rand( 1, PHP_INT_MAX );
				}
			}
		);
		$a = $container->get( 'svc' );
		$b = $container->get( 'svc' );
		$this->assertSame( $a, $b, 'Container::get must cache resolved instances.' );
	}

	public function test_get_throws_for_unbound_key(): void {
		$container = Container::instance();
		$this->expectException( RuntimeException::class );
		$container->get( 'unbound' );
	}

	public function test_rebind_resets_cached_instance(): void {
		$container = Container::instance();
		$container->bind( 'svc', static fn() => (object) array( 'v' => 1 ) );
		$first = $container->get( 'svc' );
		$container->bind( 'svc', static fn() => (object) array( 'v' => 2 ) );
		$second = $container->get( 'svc' );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $second->v );
	}

	public function test_factory_receives_container_instance(): void {
		$container = Container::instance();
		$container->bind( 'a', static fn() => 'A-value' );
		$container->bind(
			'b',
			static fn( Container $c ) => $c->get( 'a' ) . '-bridged'
		);
		$this->assertSame( 'A-value-bridged', $container->get( 'b' ) );
	}
}
