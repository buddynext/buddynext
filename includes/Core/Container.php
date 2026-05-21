<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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
	 * @var self|null
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
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
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
	 * Check whether a service binding exists for the key.
	 *
	 * Used by the plug-and-play model: features check has() before resolving
	 * a sibling feature's service. When a feature is disabled via filter,
	 * its key is never bound and has() returns false.
	 *
	 * @param string $key Service identifier.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->bindings[ $key ] );
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
