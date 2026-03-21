<?php
/**
 * Notification preference service.
 *
 * Reads and writes per-user, per-type notification preferences stored in
 * bn_notification_prefs. If no row exists for a user/type pair, defaults
 * are returned: on_site=true, email_freq='immediate'.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Handles per-user notification preference reads and writes.
 */
class NotificationPrefService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_notif_prefs';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 600;

	/**
	 * Valid email frequency values.
	 */
	private const VALID_FREQ = array( 'immediate', 'daily', 'weekly', 'off' );

	/**
	 * Return the notification preference for a user and type.
	 *
	 * Returns defaults if no row exists.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Notification type key (e.g. 'bn.new_follower').
	 * @return array{on_site: bool, email_freq: string}
	 */
	public function get_pref( int $user_id, string $type ): array {
		$cache_key = "pref_{$user_id}_{$type}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT on_site, email_freq FROM {$wpdb->prefix}bn_notification_prefs
				 WHERE user_id = %d AND type = %s",
				$user_id,
				sanitize_text_field( $type )
			),
			ARRAY_A
		);

		$pref = null !== $row
			? array(
				'on_site'    => (bool) $row['on_site'],
				'email_freq' => $row['email_freq'],
			)
			: array(
				'on_site'    => true,
				'email_freq' => 'immediate',
			);

		wp_cache_set( $cache_key, $pref, self::CACHE_GROUP, self::CACHE_TTL );

		return $pref;
	}

	/**
	 * Set the notification preference for a user and type.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Notification type key.
	 * @param array  $data    Keys: on_site (bool), email_freq (string).
	 */
	public function set_pref( int $user_id, string $type, array $data ): void {
		global $wpdb;

		$on_site    = isset( $data['on_site'] ) ? (int) $data['on_site'] : 1;
		$email_freq = in_array( $data['email_freq'] ?? 'immediate', self::VALID_FREQ, true )
			? $data['email_freq']
			: 'immediate';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_notification_prefs (user_id, type, on_site, email_freq)
				 VALUES (%d, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE on_site = VALUES(on_site), email_freq = VALUES(email_freq)",
				$user_id,
				sanitize_text_field( $type ),
				$on_site,
				$email_freq
			)
		);

		wp_cache_delete( "pref_{$user_id}_{$type}", self::CACHE_GROUP );
	}
}
