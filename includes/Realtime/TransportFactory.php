<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Real-time transport factory.
 *
 * Returns the active transport implementation. Free always resolves to
 * PollingTransport (no-op). Pro overrides the result by hooking
 * `buddynext_realtime_transport` and returning its WebSocket transport.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Static factory for the active real-time transport.
 *
 * @since 1.0.0
 */
class TransportFactory {

	/**
	 * Return the current real-time transport.
	 *
	 * Free returns PollingTransport (no-op). Pro's WebSocket transport is
	 * supplied by filtering `buddynext_realtime_transport` at
	 * `plugins_loaded:20` inside BuddyNextPro\Core\Plugin::init().
	 *
	 * Example — how Pro registers its transport:
	 *
	 *     add_filter(
	 *         'buddynext_realtime_transport',
	 *         static fn() => new \BuddyNextPro\Realtime\WebSocketTransport( $config )
	 *     );
	 *
	 * @since 1.0.0
	 *
	 * @return RealtimeTransport Active transport instance.
	 */
	public static function current(): RealtimeTransport {
		$default = new PollingTransport();

		/**
		 * Filter the active real-time transport.
		 *
		 * Free returns a no-op PollingTransport. Pro replaces it with a
		 * WebSocket-backed transport (Soketi or Ratchet) so that events are
		 * pushed to connected clients instantly instead of waiting for a REST
		 * poll cycle.
		 *
		 * The returned value must implement RealtimeTransport. If it does not,
		 * Free falls back to PollingTransport at resolve time.
		 *
		 * @since 1.0.0
		 *
		 * @param RealtimeTransport $transport Default PollingTransport instance.
		 */
		$transport = apply_filters( 'buddynext_realtime_transport', $default );

		if ( ! ( $transport instanceof RealtimeTransport ) ) {
			return $default;
		}

		return $transport;
	}
}
