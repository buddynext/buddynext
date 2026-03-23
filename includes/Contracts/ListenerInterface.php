<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Listener interface.
 *
 * Every new domain listener must implement this contract.
 * Called during Plugin::register_listeners() bootstrap.
 *
 * Note: existing listeners (HashtagListener, VerificationListener,
 * EmailDispatchListener) use an init() method and are NOT required to
 * implement this interface — they pre-date it and are not being changed.
 *
 * @package BuddyNext\Contracts
 */

declare( strict_types=1 );

namespace BuddyNext\Contracts;

/**
 * Contract for new domain event listeners.
 */
interface ListenerInterface {

	/**
	 * Register all WordPress hooks for this domain listener.
	 *
	 * Called once during plugin bootstrap (plugins_loaded:15).
	 */
	public function register(): void;
}
