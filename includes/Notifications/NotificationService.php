<?php
/**
 * Notification creation and read-state service.
 *
 * Manages bn_notifications rows. Notifications with the same group_key are
 * merged into a single row (group_count incremented) so that, for example,
 * ten new followers produce one "X and 9 others followed you" notification
 * instead of ten separate rows. Rows without a group_key are always inserted
 * as new rows.
 *
 * Cursor-based pagination follows the same created_at|id pattern used by
 * FeedService.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use WP_Error;

/**
 * Handles notification creation, read-state, and listing.
 */
class NotificationService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_notifications';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 30;

	/**
	 * Default notifications per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Create a notification.
	 *
	 * If $data contains a non-empty group_key and an unread notification with
	 * that key already exists for the recipient, the existing row is updated
	 * (sender_id and group_count refreshed) rather than inserting a new one.
	 *
	 * @param array $data Notification data: recipient_id (required), sender_id,
	 *                    type (required), object_type, object_id, group_key, data.
	 * @return int Notification ID (inserted or updated row).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$recipient_id = (int) $data['recipient_id'];
		$group_key    = isset( $data['group_key'] ) ? sanitize_text_field( $data['group_key'] ) : null;

		// Attempt to merge into an existing unread group row within the 24-hour window.
		if ( null !== $group_key && '' !== $group_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_notifications
					 WHERE recipient_id = %d AND group_key = %s AND is_read = 0
					   AND created_at >= NOW() - INTERVAL 24 HOUR
					 LIMIT 1",
					$recipient_id,
					$group_key
				)
			);

			if ( null !== $existing_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_notifications
						 SET sender_id = %d, group_count = group_count + 1, created_at = NOW()
						 WHERE id = %d",
						(int) ( $data['sender_id'] ?? 0 ),
						(int) $existing_id
					)
				);

				wp_cache_delete( "unread_{$recipient_id}", self::CACHE_GROUP );

				/** This action is documented in includes/Notifications/NotificationService.php */
				do_action( 'buddynext_notification_created', (int) $existing_id, $recipient_id, $data );

				return (int) $existing_id;
			}
		}

		// Insert a new notification row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => $recipient_id,
				'sender_id'    => isset( $data['sender_id'] ) ? (int) $data['sender_id'] : null,
				'type'         => sanitize_text_field( $data['type'] ?? '' ),
				'object_type'  => isset( $data['object_type'] ) ? sanitize_key( $data['object_type'] ) : null,
				'object_id'    => isset( $data['object_id'] ) ? (int) $data['object_id'] : null,
				'group_key'    => $group_key,
				'group_count'  => 1,
				'data'         => isset( $data['data'] ) ? wp_json_encode( $data['data'] ) : null,
				'is_read'      => 0,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		wp_cache_delete( "unread_{$recipient_id}", self::CACHE_GROUP );

		$notif_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a new notification row is inserted.
		 *
		 * EmailDispatchListener hooks here to send transactional emails.
		 * Third-party integrations (e.g. mobile push) may also hook here.
		 *
		 * @param int   $notif_id     Notification row ID.
		 * @param int   $recipient_id Recipient user ID.
		 * @param array $data         Original $data array passed to create().
		 */
		do_action( 'buddynext_notification_created', $notif_id, $recipient_id, $data );

		return $notif_id;
	}

	/**
	 * Mark a single notification as read.
	 *
	 * Only the recipient may mark their own notifications as read.
	 *
	 * @param int $notif_id    Notification ID.
	 * @param int $user_id     User requesting the read-mark.
	 * @return true|WP_Error
	 */
	public function mark_read( int $notif_id, int $user_id ): true|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recipient_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT recipient_id FROM {$wpdb->prefix}bn_notifications WHERE id = %d",
				$notif_id
			)
		);

		if ( 0 === $recipient_id || $recipient_id !== $user_id ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot mark this notification as read.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_notifications',
			array( 'is_read' => 1 ),
			array( 'id' => $notif_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Mark all of a user's notifications as read.
	 *
	 * @param int $user_id User whose notifications to mark.
	 */
	public function mark_all_read( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_notifications',
			array( 'is_read' => 1 ),
			array(
				'recipient_id' => $user_id,
				'is_read'      => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );
	}

	/**
	 * Return the unread notification count for a user.
	 *
	 * @param int $user_id User to query.
	 * @return int
	 */
	public function unread_count( int $user_id ): int {
		$cache_key = "unread_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND is_read = 0",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return a cursor-paginated list of notifications for a user.
	 *
	 * @param int         $user_id  Recipient user ID.
	 * @param string|null $cursor   Opaque pagination cursor.
	 * @param int         $per_page Notifications per page (max 50).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function list_for_user( int $user_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page      = min( $per_page, 50 );
		$cursor_data   = $this->decode_cursor( $cursor );
		$cursor_where  = '';
		$cursor_params = array();

		if ( null !== $cursor_data ) {
			$cursor_where  = 'AND (created_at < %s OR (created_at = %s AND id < %d))';
			$cursor_params = array( $cursor_data['created_at'], $cursor_data['created_at'], $cursor_data['id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d
				   {$cursor_where}
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d",
				...array_merge( array( $user_id ), $cursor_params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = array_map(
			fn( $r ) => array(
				'id'          => (int) $r['id'],
				'sender_id'   => isset( $r['sender_id'] ) ? (int) $r['sender_id'] : null,
				'type'        => $r['type'],
				'object_type' => $r['object_type'],
				'object_id'   => isset( $r['object_id'] ) ? (int) $r['object_id'] : null,
				'group_key'   => $r['group_key'],
				'group_count' => (int) $r['group_count'],
				'is_read'     => (bool) $r['is_read'],
				'created_at'  => $r['created_at'],
			),
			$rows
		);

		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = base64_encode( $last['created_at'] . '|' . $last['id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Decode a cursor string.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array{created_at: string, id: int}|null
	 */
	private function decode_cursor( ?string $cursor ): ?array {
		if ( null === $cursor ) {
			return null;
		}

		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		return array(
			'created_at' => $parts[0],
			'id'         => (int) $parts[1],
		);
	}
}
