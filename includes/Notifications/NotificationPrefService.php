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

	/**
	 * Return all stored notification preferences for a user.
	 *
	 * Only types that have an explicit row in bn_notification_prefs are returned.
	 * Types without a row use the implicit defaults (on_site=true, email_freq='immediate')
	 * and do not appear in this list.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Keyed by notification type: type => {on_site, email_freq}.
	 */
	public function get_all_prefs( int $user_id ): array {
		$cache_key = "all_prefs_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, on_site, email_freq FROM {$wpdb->prefix}bn_notification_prefs WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$prefs = array();
		foreach ( (array) $rows as $row ) {
			$prefs[ $row['type'] ] = array(
				'on_site'    => (bool) $row['on_site'],
				'email_freq' => $row['email_freq'],
			);
		}

		wp_cache_set( $cache_key, $prefs, self::CACHE_GROUP, self::CACHE_TTL );

		return $prefs;
	}

	/**
	 * Bulk-update notification preferences for a user from a map of type => data pairs.
	 *
	 * Each value in $prefs_map should be an array with optional keys:
	 *   on_site    (bool)   — whether to show in-app notification.
	 *   email_freq (string) — one of 'immediate', 'daily', 'weekly', 'off'.
	 *
	 * Unknown keys within each value are ignored. Unknown notification types
	 * are stored as-is to be forward-compatible with new types.
	 *
	 * @param int   $user_id   User ID.
	 * @param array $prefs_map Associative array of type => {on_site?, email_freq?}.
	 */
	public function set_all_prefs( int $user_id, array $prefs_map ): void {
		foreach ( $prefs_map as $type => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$this->set_pref( $user_id, sanitize_text_field( (string) $type ), $data );
		}

		wp_cache_delete( "all_prefs_{$user_id}", self::CACHE_GROUP );
	}

	/**
	 * Return the per-space notification preference for a user.
	 *
	 * Reads the notification_pref column from bn_space_members for the given
	 * user/space pair. Returns 'all' when no membership row exists.
	 *
	 * @param int $user_id  User ID.
	 * @param int $space_id Space ID.
	 * @return string One of 'all', 'mentions', or 'none'.
	 */
	public function get_space_pref( int $user_id, int $space_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pref = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT notification_pref FROM {$wpdb->prefix}bn_space_members
				 WHERE user_id = %d AND space_id = %d",
				$user_id,
				$space_id
			)
		);

		if ( null === $pref || '' === $pref ) {
			return 'all';
		}

		return (string) $pref;
	}
}
