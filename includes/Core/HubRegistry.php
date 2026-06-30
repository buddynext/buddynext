<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Central registry of BuddyNext hub surfaces (core + addon).
 *
 * The single source of truth for the hub list. PageRouter and Installer
 * iterate this instead of hardcoding per-hub arrays/switches.
 *
 * @package BuddyNext\Core
 * @since 1.0.4
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Central registry of BuddyNext hub surfaces (core + addon).
 */
final class HubRegistry {
	/**
	 * Registered hub descriptors keyed by hub key.
	 *
	 * @var array<string,HubDescriptor>
	 */
	private array $hubs = array();

	/**
	 * Shared singleton instance.
	 *
	 * @var HubRegistry|null
	 */
	private static ?HubRegistry $instance = null;

	/**
	 * Returns the shared singleton instance for production use.
	 *
	 * Tests should use `new HubRegistry()` for isolation.
	 *
	 * @return HubRegistry
	 */
	public static function instance(): HubRegistry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers a hub descriptor under its key.
	 *
	 * Overwrites any existing entry with the same key.
	 *
	 * @param HubDescriptor $descriptor Hub descriptor to register.
	 * @return void
	 */
	public function register( HubDescriptor $descriptor ): void {
		$this->hubs[ $descriptor->key ] = $descriptor;
	}

	/**
	 * Returns the descriptor for the given key, or null if not registered.
	 *
	 * @param string $key Hub key.
	 * @return HubDescriptor|null
	 */
	public function get( string $key ): ?HubDescriptor {
		return $this->hubs[ $key ] ?? null;
	}

	/**
	 * Returns true when a descriptor is registered under the given key.
	 *
	 * @param string $key Hub key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->hubs[ $key ] );
	}

	/**
	 * Returns all registered descriptors keyed by hub key.
	 *
	 * @return array<string,HubDescriptor>
	 */
	public function all(): array {
		return $this->hubs;
	}
}
