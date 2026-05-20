<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Polling-based (no-op) real-time transport.
 *
 * This is Free's default transport. Because Free relies on REST polling for
 * near-real-time updates (5s for messages, 30s for notifications, 60s for
 * the feed new-posts bar), there is nothing to push — the client will pick
 * up the event on its next poll cycle.
 *
 * Pro replaces this class entirely by binding a WebSocket-backed transport
 * via the `buddynext_realtime_transport` filter.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Default Free transport — no-op (REST polling handles delivery).
 *
 * @since 1.0.0
 */
class PollingTransport implements RealtimeTransport {

	/**
	 * No-op push.
	 *
	 * Polling clients discover the event on their next REST poll. No push
	 * infrastructure is required on the Free tier.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event      Event name.
	 * @param array  $payload    Event data.
	 * @param int[]  $recipients Recipient user IDs.
	 * @return void
	 */
	public function push( string $event, array $payload, array $recipients ): void {
		// Intentional no-op. Free tier uses REST polling; no push is required.
		// Pro's WebSocket transport override is wired via buddynext_realtime_transport.
	}
}
