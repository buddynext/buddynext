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
	 * Drop a user's cached notification counts.
	 *
	 * Called from every write that changes read state or adds a notification, so the bell
	 * and its per-type badges refresh together. The recent-actors avatar stack is a soft
	 * display element carrying a per-limit suffix, so it is left to its 30s TTL rather than
	 * enumerating every limit variant to delete.
	 *
	 * @param int $user_id Recipient whose counts to forget.
	 * @return void
	 */
	private function forget_counts( int $user_id ): void {
		wp_cache_delete( "unread_{$user_id}", self::CACHE_GROUP );
		wp_cache_delete( "unread_by_type_{$user_id}", self::CACHE_GROUP );
		wp_cache_delete( "unseen_{$user_id}", self::CACHE_GROUP );
	}

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

				$this->forget_counts( $recipient_id );

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

		$this->forget_counts( $recipient_id );

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

		$this->forget_counts( $user_id );

		return true;
	}

	/**
	 * Mark a single notification unread (ownership-checked).
	 *
	 * The inverse of mark_read(): lets a member restore a notification to the
	 * unread state (a standard affordance on mainstream notification centres).
	 * Busts the per-user unread-count cache so the badge reflects the change.
	 *
	 * @param int $notif_id Notification id.
	 * @param int $user_id  Current user id (must own the notification).
	 * @return true|WP_Error True on success, WP_Error(403) when not the owner.
	 */
	public function mark_unread( int $notif_id, int $user_id ): bool|WP_Error {
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
				__( 'You cannot mark this notification as unread.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_notifications',
			array( 'is_read' => 0 ),
			array( 'id' => $notif_id ),
			array( '%d' ),
			array( '%d' )
		);

		$this->forget_counts( $user_id );

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

		$this->forget_counts( $user_id );

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

		// Marking everything read is a stronger engagement than merely viewing, so
		// it also clears the "unseen" badge — advance last-seen alongside is_read so
		// the bell drops to 0 (viewing alone only marks seen; this does both).
		update_user_meta( $user_id, self::LAST_SEEN_META, current_time( 'mysql', true ) );

		$this->forget_counts( $user_id );
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
	 * Usermeta key holding the GMT timestamp a user last VIEWED their
	 * notifications list. Drives the "seen" badge (distinct from per-item read).
	 */
	private const LAST_SEEN_META = 'bn_notifications_last_seen';

	/**
	 * Mark the whole notifications list as SEEN for a user.
	 *
	 * "Seen" is deliberately separate from "read": viewing the list clears the
	 * bell/nav badge (every mainstream app does this) but must NOT flip rows to
	 * is_read=1 — that would empty the Unread tab the instant the page opens and
	 * defeat Mark-unread. So this only advances a per-user "last seen" timestamp;
	 * per-item read state stays driven by an explicit click (mark_read) or the
	 * explicit "Mark all read" button.
	 *
	 * @param int $user_id User who viewed their notifications list.
	 * @return void
	 */
	public function mark_seen( int $user_id ): void {
		update_user_meta( $user_id, self::LAST_SEEN_META, current_time( 'mysql', true ) );
		$this->forget_counts( $user_id );
	}

	/**
	 * Return the UNSEEN notification count for a user — the badge number.
	 *
	 * Counts notifications created after the user last viewed the list
	 * (mark_seen). This is what the bell / nav / app badge shows: it drops to 0
	 * when the member opens notifications and climbs again as new ones arrive,
	 * WITHOUT marking anything read. The Unread tab keeps using unread_count()
	 * (is_read = 0), so the two are intentionally independent.
	 *
	 * @param int $user_id User to query.
	 * @return int
	 */
	public function unseen_count( int $user_id ): int {
		$cache_key = "unseen_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$last_seen = (string) get_user_meta( $user_id, self::LAST_SEEN_META, true );
		if ( '' === $last_seen ) {
			// Never opened the list — everything is unseen.
			$last_seen = '1970-01-01 00:00:00';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND created_at > %s",
				$user_id,
				$last_seen
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
	/**
	 * Normalise a caller-supplied `since` into a UTC MySQL datetime.
	 *
	 * Accepts what a client would naturally send: an ISO-8601 timestamp (what
	 * this API hands back as created_at_gmt) or a Unix epoch. Anything it cannot
	 * parse returns null and the bound is simply not applied — a malformed
	 * timestamp must never be interpreted as "since the epoch" and dump the
	 * member's entire history, nor as "since now" and silently return nothing.
	 *
	 * @since 1.1.3
	 *
	 * @param string|null $since Raw parameter.
	 * @return string|null `Y-m-d H:i:s` in UTC, or null when absent/unparseable.
	 */
	private function normalize_since( ?string $since ): ?string {
		$since = null === $since ? '' : trim( $since );
		if ( '' === $since ) {
			return null;
		}

		if ( ctype_digit( $since ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $since );
		}

		$ts = strtotime( $since );

		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Fetch a paginated slice of a member's notifications, newest first.
	 *
	 * Supports two paging modes — keyset (cursor) and LIMIT/OFFSET — plus a filter
	 * and a `since` delta bound that composes with paging so an app can poll for
	 * only what changed after its last sync. Passing $offset selects offset mode
	 * and takes precedence over $cursor.
	 *
	 * @param int         $user_id  Recipient user ID.
	 * @param string|null $cursor   Opaque keyset cursor from a previous page, or null for the first page.
	 * @param int         $per_page Page size (capped at 50).
	 * @param string      $filter   Filter slug ('all', 'unread', or a notification type).
	 * @param int|null    $offset   Row offset; when non-null, pages by LIMIT/OFFSET instead of by cursor.
	 * @param string|null $since    Only items created strictly after this timestamp (any strtotime-parseable value), or null.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string|null} Hydrated rows and the cursor for the next page (null when none/offset mode).
	 */
	public function list_for_user( int $user_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, string $filter = 'all', ?int $offset = null, ?string $since = null ): array {
		global $wpdb;

		$per_page      = min( $per_page, 50 );
		$filter_where  = $this->filter_where( $filter );
		$use_offset    = null !== $offset;
		$cursor_data   = $use_offset ? null : $this->decode_cursor( $cursor );
		$cursor_where  = '';
		$cursor_params = array();

		/*
		 * `since` is a DELTA bound, and it composes with paging rather than
		 * replacing it: an app asks "what changed after my last sync" and still
		 * pages if the answer is large. Without it the only way to poll was to
		 * walk the list newest-first and stop at a known id, which means fetching
		 * and discarding rows the client already had on every poll.
		 *
		 * Compared strictly greater-than so passing back the newest created_at
		 * from the previous response returns only what arrived after it, never a
		 * duplicate of the boundary row.
		 */
		$since_where  = '';
		$since_params = array();
		$since_gmt    = $this->normalize_since( $since );
		if ( null !== $since_gmt ) {
			$since_where  = 'AND created_at > %s';
			$since_params = array( $since_gmt );
		}

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
				   {$since_where}
				   {$cursor_where}
				 ORDER BY created_at DESC, id DESC
				 {$limit_sql}",
				...array_merge( array( $user_id ), $since_params, $cursor_params, $tail_params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = (array) $rows;

		// In offset mode the SQL already applied the page size, so there is no
		// trailing sentinel row to trim and no cursor to emit.
		if ( $use_offset ) {
			return array(
				'items'       => $this->group_page( array_map( array( $this, 'hydrate' ), $rows ) ),
				'next_cursor' => null,
			);
		}

		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = $this->group_page( array_map( array( $this, 'hydrate' ), $rows ) );

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
	 * Collapse repeats of the same event within a page into one item.
	 *
	 * READ-TIME grouping: every notification keeps its own row. Nothing is merged
	 * in the database, so WHO and WHEN survive, a collapsed entry can be expanded
	 * back into the individual notifications, per-item read state still works, and
	 * a change to how things group needs no migration. Collapsing at write time -
	 * minting a shared group_key - would have been cheaper to read and would have
	 * destroyed all four.
	 *
	 * Grouped by (type, object_type, object_id): "8 people asked to join Design
	 * Guild" is one entry, while a join request and a join for the same space stay
	 * apart because their types differ.
	 *
	 * Everything groups by default and types opt OUT through the filter. That way
	 * the next high-volume notification somebody adds is collapsed on the day it
	 * ships, instead of being discovered later as a wall of identical rows - which
	 * is exactly how this was found.
	 *
	 * Scope worth knowing: grouping is per PAGE. Repeats spanning a page boundary
	 * stay separate, because the alternative is aggregating in SQL, which breaks
	 * the keyset cursor this list pages on. The visible item count therefore
	 * varies per page; the cursor still advances by ROWS, so nothing is skipped.
	 *
	 * @param array<int,array<string,mixed>> $items Hydrated notifications, newest first.
	 * @return array<int,array<string,mixed>>
	 */
	private function group_page( array $items ): array {
		/**
		 * Notification types that must NEVER collapse.
		 *
		 * A type belongs here when each occurrence is individually actionable or
		 * individually meaningful - a direct message is not "3 messages", it is
		 * three things you have to read.
		 *
		 * @since 1.1.6
		 *
		 * @param string[] $types Type slugs excluded from grouping.
		 */
		$ungroupable = (array) apply_filters( 'buddynext_notification_ungroupable_types', array() );

		$grouped = array();
		$index   = array();

		foreach ( $items as $item ) {
			$type      = (string) ( $item['type'] ?? '' );
			$object_id = (int) ( $item['object_id'] ?? 0 );

			// No object to group ON, or the type opted out: passes through whole.
			if ( $object_id <= 0 || in_array( $type, $ungroupable, true ) ) {
				$grouped[] = $item;
				continue;
			}

			$key = $type . '|' . (string) ( $item['object_type'] ?? '' ) . '|' . $object_id;

			if ( ! isset( $index[ $key ] ) ) {
				// The NEWEST occurrence represents the group - it is already first,
				// because the query orders created_at DESC - so the group sorts by
				// its most recent activity, which is what a reader expects.
				$item['group_size']  = 1;
				$item['group_ids']   = array( (int) $item['id'] );
				$item['group_actors'] = array();
				if ( ! empty( $item['sender_id'] ) ) {
					$item['group_actors'][] = (int) $item['sender_id'];
				}

				$index[ $key ] = count( $grouped );
				$grouped[]     = $item;
				continue;
			}

			$at = $index[ $key ];
			++$grouped[ $at ]['group_size'];
			$grouped[ $at ]['group_ids'][] = (int) $item['id'];

			// Distinct actors, in the order they last acted. The collapsed row shows
			// their avatars, so a repeat actor must not appear twice.
			$sender = (int) ( $item['sender_id'] ?? 0 );
			if ( $sender > 0 && ! in_array( $sender, $grouped[ $at ]['group_actors'], true ) ) {
				$grouped[ $at ]['group_actors'][] = $sender;
			}

			// A group is unread when ANY member of it is. Marking the group read
			// marks them all, so the reverse has to hold or the badge would count
			// items the reader cannot see.
			if ( empty( $item['is_read'] ) ) {
				$grouped[ $at ]['is_read'] = false;
			}
		}

		return $grouped;
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
			// The data payload carries type-specific fields (message, url, emoji…)
			// that NotificationMessageService::compose() and the hub/REST consumers
			// read to render partner-mirrored (jt.*, suite.*) and data-driven
			// notifications. It was historically dropped here, so those types fell
			// back to generic copy + a home_url() link. Hydrated so every consumer
			// gets the real payload — this is the C2.1 fix.
			'data'        => $this->decode_data( $r['data'] ?? null ),
		);
	}

	/**
	 * Decode a notification's stored data payload into an array.
	 *
	 * The `bn_notifications.data` column holds a JSON blob of type-specific
	 * fields. Returned as a decoded array so callers receive a structured
	 * payload rather than a raw JSON string (an already-array value passes
	 * through unchanged).
	 *
	 * @param mixed $raw Raw JSON string (or array) from bn_notifications.data.
	 * @return array<string,mixed> Decoded payload, or an empty array.
	 */
	private function decode_data( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
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
		// Per-type grouped scan on the notification bell, uncached and re-run on every
		// bell render. Memo it under the same 30s TTL as unread_count, and bust it at the
		// same choke points (see forget_counts).
		$cache_key = "unread_by_type_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

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

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::CACHE_TTL );

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

		// The "recent actors" avatar stack, a grouped scan re-run on every notifications
		// render. Memo under the 30s TTL. It is a soft display element, so it rides the TTL
		// (and the forget_counts bust) rather than needing anything sharper.
		$cache_key = "recent_actors_{$user_id}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

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

		$ids = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::CACHE_TTL );

		return $ids;
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
