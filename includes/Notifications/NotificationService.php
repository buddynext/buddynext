<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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
		$type         = sanitize_text_field( $data['type'] ?? '' );

		// Respect the recipient's in-app notification preferences before creating
		// (or merging into) any row. The per-type 'on_site' pref (Settings ->
		// Notifications) and the master in-app channel toggle are the user/admin
		// opt-out; previously create() inserted unconditionally and only the email
		// channel consulted prefs, so disabling an in-app type had no effect.
		// Unknown/system types default to on_site = true (NotificationPrefService::
		// default_pref), so critical notices are never suppressed; the
		// buddynext_notification_force_on_site filter lets a caller force-send.
		if ( '' !== $type && ! (bool) apply_filters( 'buddynext_notification_force_on_site', false, $recipient_id, $type, $data ) ) {
			$prefs = function_exists( 'buddynext_service' ) ? buddynext_service( 'notification_prefs' ) : null;
			if ( is_object( $prefs ) && method_exists( $prefs, 'get_pref' ) ) {
				$pref = (array) $prefs->get_pref( $recipient_id, $type );
				if ( empty( $pref['on_site'] ) ) {
					return 0;
				}
				if ( method_exists( $prefs, 'get_channel_prefs' ) ) {
					$channels = (array) $prefs->get_channel_prefs( $recipient_id );
					// in_app defaults ON; suppress only when explicitly disabled.
					if ( array_key_exists( 'in_app', $channels ) && empty( $channels['in_app'] ) ) {
						return 0;
					}
				}
			}
		}

		// Gate + scheduling MUST resolve before any write — including the group-merge
		// path below. Previously the merge path returned early (firing the hook) before
		// these ran, so grouped notifications bypassed buddynext_notification_should_send
		// (Pro fatigue suppression) and fired buddynext_notification_created with a
		// payload missing send_at. Resolving here makes both paths consistent.
		/**
		 * Filter whether a new notification should be persisted at all.
		 *
		 * Pro AI notification fatigue detection hooks here to suppress low-signal
		 * notifications before they reach the DB or trigger email dispatch.
		 * Returning false causes create() to silently return 0 (no notification sent).
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $should  Whether to proceed with sending. Default true.
		 * @param array $payload The full $data array passed to create().
		 */
		$should_send = (bool) apply_filters( 'buddynext_notification_should_send', true, $data );
		if ( ! $should_send ) {
			return 0;
		}

		/**
		 * Filter the scheduled send time for a notification.
		 *
		 * Return a non-null ISO 8601 / MySQL datetime string to schedule the
		 * notification for deferred delivery. Pro uses this for batched digest
		 * and quiet-hours features. BuddyNext Free stores the value in the data
		 * JSON column but does not actively delay the insert.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null $send_at ISO timestamp for deferred delivery, or null for immediate.
		 * @param array       $payload The full $data array passed to create().
		 */
		$send_at = apply_filters( 'buddynext_notification_send_at', null, $data );
		if ( null !== $send_at ) {
			$data['send_at'] = (string) $send_at;
		}

		// Attempt to merge into an existing unread group row within the 24-hour window.
		if ( null !== $group_key && '' !== $group_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_notifications
					 WHERE recipient_id = %d AND group_key = %s AND is_read = 0
					   AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
					 LIMIT 1",
					$recipient_id,
					$group_key
				)
			);

			if ( null !== $existing_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_notifications
						 SET sender_id = %d, group_count = group_count + 1, created_at = UTC_TIMESTAMP()
						 WHERE id = %d",
						(int) ( $data['sender_id'] ?? 0 ),
						(int) $existing_id
					)
				);

				// A failed merge UPDATE must not bust the cache or fire the hook for
				// a row we did not actually touch.
				if ( false === $updated ) {
					return 0;
				}

				wp_cache_delete( "unread_{$recipient_id}", self::CACHE_GROUP );

				/** This action is documented in includes/Notifications/NotificationService.php */
				do_action( 'buddynext_notification_created', (int) $existing_id, $recipient_id, $data );

				return (int) $existing_id;
			}
		}

		// Insert a new notification row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
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
				// UTC write (not the column's local-time default) so the bell's
				// relative times are correct on any server timezone.
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' )
		);

		// A failed insert must not bust the cache, fire the listener, or return a
		// fake id — that would dispatch an email for a notification that doesn't exist.
		if ( false === $inserted ) {
			return 0;
		}

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
	public function mark_read( int $notif_id, int $user_id ): bool|WP_Error {
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
	 * Delete a single notification belonging to the given user.
	 *
	 * Returns a WP_Error with status 403 when the notification does not belong
	 * to $user_id so that the REST layer can propagate the correct HTTP code.
	 *
	 * @param int $notif_id Notification row ID.
	 * @param int $user_id  Requesting user ID.
	 * @return true|WP_Error
	 */
	public function delete( int $notif_id, int $user_id ): bool|WP_Error {
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
				__( 'You cannot delete this notification.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_notifications',
			array( 'id' => $notif_id ),
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
	 * Return a paginated list of notifications for a user.
	 *
	 * Supports two paging modes that are mutually exclusive:
	 *   - Cursor (default, backward-compatible): pass $cursor to keyset-paginate
	 *     on created_at|id. next_cursor in the return points to the next page.
	 *   - Offset: pass a non-null $offset (and leave $cursor null) to page by
	 *     LIMIT/OFFSET, which the notifications index uses for numbered/“load
	 *     more” paging alongside a separate count query. next_cursor is null in
	 *     offset mode.
	 *
	 * The optional $filter narrows by read-state: 'all' (default), 'unread', or
	 * 'read'. Unknown values fall back to 'all'.
	 *
	 * @param int         $user_id  Recipient user ID.
	 * @param string|null $cursor   Opaque pagination cursor (cursor mode).
	 * @param int         $per_page Notifications per page (max 50).
	 * @param string      $filter   Read-state filter: 'all', 'unread', 'read'.
	 * @param int|null    $offset   Offset for LIMIT/OFFSET paging (offset mode).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function list_for_user( int $user_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, string $filter = 'all', ?int $offset = null ): array {
		global $wpdb;

		$per_page      = min( $per_page, 50 );
		$filter_where  = $this->filter_where( $filter );
		$use_offset    = null !== $offset;
		$cursor_data   = $use_offset ? null : $this->decode_cursor( $cursor );
		$cursor_where  = '';
		$cursor_params = array();

		if ( null !== $cursor_data ) {
			$cursor_where  = 'AND (created_at < %s OR (created_at = %s AND id < %d))';
			$cursor_params = array( $cursor_data['created_at'], $cursor_data['created_at'], $cursor_data['id'] );
		}

		// Offset mode pages by LIMIT/OFFSET; cursor mode fetches per_page+1 to
		// derive has_more. The trailing tail params differ between the two.
		$tail_params = $use_offset
			? array( $per_page, max( 0, $offset ) )
			: array( $per_page + 1 );
		$limit_sql   = $use_offset ? 'LIMIT %d OFFSET %d' : 'LIMIT %d';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d
				   {$filter_where}
				   {$cursor_where}
				 ORDER BY created_at DESC, id DESC
				 {$limit_sql}",
				...array_merge( array( $user_id ), $cursor_params, $tail_params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = (array) $rows;

		// In offset mode the SQL already applied the page size, so there is no
		// trailing sentinel row to trim and no cursor to emit.
		if ( $use_offset ) {
			return array(
				'items'       => array_map( array( $this, 'hydrate' ), $rows ),
				'next_cursor' => null,
			);
		}

		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = array_map( array( $this, 'hydrate' ), $rows );

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
	 * Fetch a single notification by id in the canonical hydrated shape, or null.
	 *
	 * Lets consumers (e.g. the Pro push dispatcher) read a notification row
	 * without querying bn_notifications directly.
	 *
	 * @param int $id Notification id.
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_notifications WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Map a raw bn_notifications row into the canonical notification shape.
	 *
	 * @param array<string,mixed> $r Raw row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $r ): array {
		return array(
			'id'          => (int) $r['id'],
			'sender_id'   => isset( $r['sender_id'] ) ? (int) $r['sender_id'] : null,
			'type'        => $r['type'],
			'object_type' => $r['object_type'],
			'object_id'   => isset( $r['object_id'] ) ? (int) $r['object_id'] : null,
			'group_key'   => $r['group_key'],
			'group_count' => (int) $r['group_count'],
			'is_read'     => (bool) $r['is_read'],
			'created_at'  => $r['created_at'],
		);
	}

	/**
	 * Count a user's notifications, optionally narrowed by read-state.
	 *
	 * The 'unread' filter reuses the cached unread_count() path; 'all' and
	 * 'read' run an indexed COUNT. Powers the notifications index tab totals.
	 *
	 * @param int    $user_id Recipient user ID.
	 * @param string $filter  Read-state filter: 'all', 'unread', 'read'.
	 * @return int
	 */
	public function count_for_user( int $user_id, string $filter = 'all' ): int {
		if ( 'unread' === $filter ) {
			return $this->unread_count( $user_id );
		}

		global $wpdb;
		$filter_where = $this->filter_where( $filter );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d {$filter_where}",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return a map of notification type slug to unread count for a user.
	 *
	 * Drives the per-type badges on the notifications index. Types with zero
	 * unread notifications are omitted from the map.
	 *
	 * @param int $user_id Recipient user ID.
	 * @return array<string,int> type => unread count.
	 */
	public function unread_counts_by_type( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COUNT(*) AS cnt FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND is_read = 0
				 GROUP BY type",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['cnt'];
		}

		return $counts;
	}

	/**
	 * Return the most recent distinct sender (actor) IDs for a user's
	 * notifications, newest first. Powers the "recent actors" avatar stack on
	 * the notifications index without the template querying senders directly.
	 *
	 * @param int $user_id Recipient user ID.
	 * @param int $limit   Max actor IDs (1-50). Default 5.
	 * @return array<int,int>
	 */
	public function recent_actor_ids( int $user_id, int $limit = 5 ): array {
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sender_id FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND sender_id IS NOT NULL AND sender_id > 0
				 GROUP BY sender_id
				 ORDER BY MAX(created_at) DESC
				 LIMIT %d",
				$user_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Build the read-state WHERE fragment for a filter slug.
	 *
	 * Returns a fragment prefixed with AND (or empty for 'all') containing only
	 * a literal is_read condition — no user data, safe to interpolate.
	 *
	 * @param string $filter Read-state filter: 'all', 'unread', 'read'.
	 * @return string
	 */
	private function filter_where( string $filter ): string {
		switch ( $filter ) {
			case 'unread':
				return 'AND is_read = 0';
			case 'read':
				return 'AND is_read = 1';
			default:
				return '';
		}
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
