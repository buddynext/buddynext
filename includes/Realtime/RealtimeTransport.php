<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Real-time transport interface.
 *
 * Defines the contract every transport implementation must satisfy. The default
 * Free transport (PollingTransport) is a no-op. Pro swaps in a WebSocket-backed
 * transport via the `buddynext_realtime_transport` filter.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Contract for real-time transport implementations.
 *
 * Free ships PollingTransport (no-op — the client polls via REST).
 * Pro binds a WebSocket-backed transport (Soketi / Ratchet) via the
 * `buddynext_realtime_transport` filter in TransportFactory::current().
 *
 * @since 1.0.0
 */
interface RealtimeTransport {

	/**
	 * Push an event payload to one or more recipients.
	 *
	 * Implementations must be idempotent: calling push() when the transport
	 * is unavailable must not throw — it should silently no-op or log
	 * internally without bubbling an exception to the caller.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event      Event name, e.g. 'notification.created' or 'post.published'.
	 * @param array  $payload    Arbitrary data to deliver to recipients.
	 * @param int[]  $recipients Array of WordPress user IDs that should receive the event.
	 * @return void
	 */
	public function push( string $event, array $payload, array $recipients ): void;
}
