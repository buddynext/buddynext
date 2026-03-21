<?php
/**
 * Simple dependency injection container.
 *
 * Resolves services as singletons. Rebinding a key resets the cached instance.
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use RuntimeException;

/**
 * Service container.
 */
class Container {

	/**
	 * Singleton instance.
	 *
	 * @var static|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered factory callbacks.
	 *
	 * @var array<string, callable>
	 */
	private array $bindings = []; // phpcs:ignore Universal.Arrays.DisallowShortArraySyntax.Found

	/**
	 * Resolved singletons.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = []; // phpcs:ignore Universal.Arrays.DisallowShortArraySyntax.Found

	/**
	 * Private — use instance().
	 */
	private function __construct() {}

	/**
	 * Return the shared container instance.
	 */
	public static function instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Register a factory for a service key.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Receives the container; returns the service.
	 */
	public function bind( string $key, callable $factory ): void {
		$this->bindings[ $key ] = $factory;
		unset( $this->resolved[ $key ] );
	}

	/**
	 * Resolve and return a service (cached after first call).
	 *
	 * @param string $key Service identifier.
	 * @return mixed
	 * @throws RuntimeException When no binding exists for the key.
	 */
	public function get( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->resolved ) ) {
			if ( ! isset( $this->bindings[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException( sprintf( 'BuddyNext Container: no binding registered for "%s".', $key ) );
			}

			$this->resolved[ $key ] = ( $this->bindings[ $key ] )( $this );
		}

		return $this->resolved[ $key ];
	}
}
