<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Connection (mutual friendship) service.
 *
 * Manages bi-directional connection requests between users. Connections pass
 * through a pending → accepted lifecycle; either party can decline or the
 * requester can withdraw before acceptance.
 *
 * All reads are cache-backed (group: buddynext_connections, TTL: 10 min).
 * Writes invalidate the relevant keys.
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;

/**
 * Handles connection requests and connection-graph queries.
 */
class ConnectionService {

	/**
	 * Cache group for all connection data.
	 */
	private const CACHE_GROUP = 'buddynext_connections';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Send a connection request from one user to another.
	 *
	 * Returns WP_Error if the requester tries to connect with themselves or
	 * if any connection row (in any status) already exists for this pair.
	 *
	 * @param int    $requester_id ID of the user sending the request.
	 * @param int    $recipient_id ID of the user receiving the request.
	 * @param string $note         Optional note to attach to the request (max 280 chars).
	 * @return true|WP_Error
	 */
	public function send_request( int $requester_id, int $recipient_id, string $note = '' ): true|WP_Error {
		if ( $requester_id === $recipient_id ) {
			return new WP_Error(
				'cannot_connect_self',
				__( 'A user cannot connect with themselves.', 'buddynext' )
			);
		}

		// Honour the recipient's who_can_connect preference (and block) via the
		// canonical privacy gate — previously this preference was never consulted.
		$privacy = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
		if ( $privacy && method_exists( $privacy, 'can_connect' ) && ! $privacy->can_connect( $requester_id, $recipient_id ) ) {
			return new WP_Error(
				'connect_not_allowed',
				__( 'This member does not accept connection requests from you.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Hard-cap the note so a stray client can't overflow the 280-char
		// column. Strip tags + sanitize to plain text — the note renders
		// inside notification text and the connection details panel.
		$note = wp_strip_all_tags( $note );
		if ( strlen( $note ) > 280 ) {
			$note = function_exists( 'mb_substr' )
				? mb_substr( $note, 0, 280 )
				: substr( $note, 0, 280 );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id = %d )
				    OR ( requester_id = %d AND recipient_id = %d )
				 LIMIT 1",
				$requester_id,
				$recipient_id,
				$recipient_id,
				$requester_id
			)
		);

		if ( null !== $existing ) {
			return new WP_Error(
				'request_already_exists',
				__( 'A connection request already exists for this pair.', 'buddynext' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_connections',
			array(
				'requester_id' => $requester_id,
				'recipient_id' => $recipient_id,
				'status'       => 'pending',
				'note'         => $note,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$connection_id = (int) $wpdb->insert_id;
		$this->invalidate_connection_cache();

		/**
		 * Fires after a connection request is sent.
		 *
		 * @param int    $connection_id Connection row ID.
		 * @param int    $requester_id  ID of the requesting user.
		 * @param int    $recipient_id  ID of the recipient.
		 * @param string $note          Optional note attached to the request.
		 */
		do_action( 'buddynext_connection_requested', $connection_id, $requester_id, $recipient_id, $note );

		return true;
	}

	/**
	 * Accept a pending connection request.
	 *
	 * @param int $recipient_id  ID of the user accepting the request.
	 * @param int $requester_id  ID of the original requester.
	 * @return true|WP_Error
	 */
	public function accept_request( int $recipient_id, int $requester_id ): true|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_connections',
			array( 'status' => 'accepted' ),
			array(
				'requester_id' => $requester_id,
				'recipient_id' => $recipient_id,
				'status'       => 'pending',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		if ( ! $updated ) {
			return new WP_Error(
				'request_not_found',
				__( 'No pending connection request was found.', 'buddynext' )
			);
		}

		$this->invalidate_connection_cache();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connection_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_connections
				 WHERE requester_id = %d AND recipient_id = %d AND status = 'accepted'
				 LIMIT 1",
				$requester_id,
				$recipient_id
			)
		);

		/**
		 * Fires after a connection request is accepted.
		 *
		 * @param int $connection_id Connection row ID.
		 * @param int $requester_id  ID of the original requester.
		 * @param int $recipient_id  ID of the accepting user.
		 */
		do_action( 'buddynext_connection_accepted', $connection_id, $requester_id, $recipient_id );

		return true;
	}

	/**
	 * Decline a pending connection request.
	 *
	 * @param int $recipient_id ID of the user declining the request.
	 * @param int $requester_id ID of the original requester.
	 * @return true|WP_Error
	 */
	public function decline_request( int $recipient_id, int $requester_id ): true|WP_Error {
		global $wpdb;

		// Fetch the connection ID before updating so we can pass it to the hook.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connection_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_connections
				 WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
				$requester_id,
				$recipient_id
			)
		);

		if ( 0 === $connection_id ) {
			return new WP_Error(
				'request_not_found',
				__( 'No pending connection request was found.', 'buddynext' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_connections',
			array( 'status' => 'declined' ),
			array( 'id' => $connection_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->invalidate_connection_cache();

		/**
		 * Fires after a connection request is declined.
		 *
		 * @param int $connection_id ID of the connection row.
		 * @param int $requester_id  ID of the original requester.
		 * @param int $recipient_id  ID of the declining user.
		 */
		do_action( 'buddynext_connection_declined', $connection_id, $requester_id, $recipient_id );

		return true;
	}

	/**
	 * Withdraw an outgoing connection request.
	 *
	 * @param int $requester_id ID of the user withdrawing their request.
	 * @param int $recipient_id ID of the original recipient.
	 * @return true|WP_Error
	 */
	public function withdraw_request( int $requester_id, int $recipient_id ): true|WP_Error {
		global $wpdb;

		// Fetch the connection ID before deleting so we can pass it to the hook.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connection_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_connections
				 WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
				$requester_id,
				$recipient_id
			)
		);

		if ( 0 === $connection_id ) {
			return new WP_Error(
				'not_found',
				__( 'No pending request found.', 'buddynext' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_connections',
			array( 'id' => $connection_id ),
			array( '%d' )
		);

		$this->invalidate_connection_cache();

		/**
		 * Fires after a connection request is withdrawn.
		 *
		 * @param int $connection_id ID of the connection row.
		 * @param int $requester_id  ID of the withdrawing user.
		 * @param int $recipient_id  ID of the original recipient.
		 */
		do_action( 'buddynext_connection_withdrawn', $connection_id, $requester_id, $recipient_id );

		return true;
	}

	/**
	 * Remove an accepted connection between two users.
	 *
	 * Either party may call this. The row is deleted regardless of which user
	 * was the original requester.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return true|WP_Error
	 */
	public function remove_connection( int $user_a, int $user_b ): true|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_connections
				 WHERE status = 'accepted'
				   AND ( ( requester_id = %d AND recipient_id = %d )
				      OR ( requester_id = %d AND recipient_id = %d ) )",
				$user_a,
				$user_b,
				$user_b,
				$user_a
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			return new WP_Error(
				'not_connected',
				__( 'No accepted connection found.', 'buddynext' )
			);
		}

		$this->invalidate_connection_cache();

		/**
		 * Fires after a connection is removed.
		 *
		 * @param int $user_a First user.
		 * @param int $user_b Second user.
		 */
		do_action( 'buddynext_connection_removed', $user_a, $user_b );

		return true;
	}

	/**
	 * Check whether two users share an accepted connection.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return bool
	 */
	public function are_connected( int $user_a, int $user_b ): bool {
		return 'accepted' === $this->status( $user_a, $user_b );
	}

	/**
	 * Return the connection status between two users.
	 *
	 * Status is symmetric — the order of arguments does not matter.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return string|null One of 'pending', 'accepted', 'declined', 'withdrawn', or null.
	 */
	public function status( int $user_a, int $user_b ): ?string {
		$row = $this->pair_row( $user_a, $user_b );

		return $row ? (string) $row->status : null;
	}

	/**
	 * Return the single connection row for a pair, in one cache-backed query.
	 *
	 * Unlike status(), this preserves the row's direction (requester_id /
	 * recipient_id) so a caller can distinguish a pending request the viewer
	 * SENT from one they RECEIVED without firing a second query. The profile
	 * view uses this to resolve is_connected, connection_pending and
	 * connection_received from a single round-trip.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return object|null Row with requester_id, recipient_id, status — or null if no row exists.
	 */
	public function pair_row( int $user_a, int $user_b ): ?object {
		global $wpdb;

		$low       = min( $user_a, $user_b );
		$high      = max( $user_a, $user_b );
		$cache_key = "pair_row_{$low}_{$high}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id = %d )
				    OR ( requester_id = %d AND recipient_id = %d )
				 LIMIT 1",
				$user_a,
				$user_b,
				$user_b,
				$user_a
			)
		);

		// Cache empty string as sentinel for "no row found" to distinguish from cache miss (false).
		wp_cache_set( $cache_key, null !== $row ? $row : '', self::CACHE_GROUP, self::CACHE_TTL );

		return $row;
	}

	/**
	 * Resolve the viewer↔peer connection status for many peers in one query.
	 *
	 * Avoids the N+1 that calling status() per peer would produce on a member
	 * directory page. Also primes the per-pair object cache so a later status()
	 * call for any of these peers is a cache hit.
	 *
	 * @param int   $viewer_id Viewer user ID.
	 * @param int[] $peer_ids  Peer user IDs on the current page.
	 * @return array<int, string> Peer-ID keyed status map (peers with no row are omitted).
	 */
	public function statuses_for( int $viewer_id, array $peer_ids ): array {
		$peer_ids = array_values( array_unique( array_filter( array_map( 'intval', $peer_ids ) ) ) );
		if ( $viewer_id <= 0 || ! $peer_ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $peer_ids ), '%d' ) );
		$params       = array_merge( array( $viewer_id ), $peer_ids, array( $viewer_id ), $peer_ids );

		// $placeholders is a generated list of %d for an int array; every value is
		// bound through $wpdb->prepare() below, so the interpolation is safe.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id IN ( {$placeholders} ) )
				    OR ( recipient_id = %d AND requester_id IN ( {$placeholders} ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();
		foreach ( (array) $rows as $row ) {
			$peer         = ( (int) $row->requester_id === $viewer_id ) ? (int) $row->recipient_id : (int) $row->requester_id;
			$map[ $peer ] = (string) $row->status;
			$low          = min( $viewer_id, $peer );
			$high         = max( $viewer_id, $peer );
			// Prime the per-pair cache that status() / pair_row() read, so a
			// later single-pair lookup for any of these peers is a cache hit.
			wp_cache_set( "pair_row_{$low}_{$high}", $row, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $map;
	}

	/**
	 * Return a paginated list of user IDs the given user is connected with (accepted only).
	 *
	 * @param int $user_id The user.
	 * @param int $limit   Maximum number of results to return. Default 20.
	 * @param int $offset  Number of rows to skip. Default 0.
	 * @return int[]
	 */
	public function connections( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "connections_{$user_id}_{$limit}_{$offset}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CASE
				    WHEN requester_id = %d THEN recipient_id
				    ELSE requester_id
				 END
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d OR recipient_id = %d )
				   AND status = 'accepted'
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$user_id,
				$user_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return a paginated list of recipient IDs for pending requests sent by the user.
	 *
	 * @param int $user_id The requesting user.
	 * @param int $limit   Maximum number of results to return. Default 20.
	 * @param int $offset  Number of rows to skip. Default 0.
	 * @return int[]
	 */
	public function pending_sent( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "pending_sent_{$user_id}_{$limit}_{$offset}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT recipient_id
				 FROM {$wpdb->prefix}bn_connections
				 WHERE requester_id = %d AND status = 'pending'
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return a paginated list of requester IDs for pending requests received by the user.
	 *
	 * @param int $user_id The recipient user.
	 * @param int $limit   Maximum number of results to return. Default 20.
	 * @param int $offset  Number of rows to skip. Default 0.
	 * @return int[]
	 */
	public function pending_received( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		$cache_key = "pending_received_{$user_id}_{$limit}_{$offset}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT requester_id
				 FROM {$wpdb->prefix}bn_connections
				 WHERE recipient_id = %d AND status = 'pending'
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return user IDs that both $user_a and $user_b are each accepted-connected to.
	 *
	 * Because bn_connections is directional (one row per pair, either
	 * requester_id or recipient_id can be the "acting" user), we first resolve
	 * both users' full accepted-connection lists via the cache-backed
	 * connections() method, then intersect them in PHP. This avoids a complex
	 * bidirectional SQL join and re-uses cached data.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return int[]
	 */
	public function mutual_connections( int $user_a, int $user_b ): array {
		$connections_a = $this->connections( $user_a );
		$connections_b = $this->connections( $user_b );

		return array_values( array_intersect( $connections_a, $connections_b ) );
	}

	/**
	 * Return the connection degree between two users.
	 *
	 * Degree 1 means the users are directly connected. Degree 2 means they
	 * share at least one mutual connection. Degree 3+ covers all other cases.
	 *
	 * @param int $viewer_id  The viewing user.
	 * @param int $subject_id The user being viewed.
	 * @return int 1, 2, or 3.
	 */
	public function connection_degree( int $viewer_id, int $subject_id ): int {
		if ( $this->are_connected( $viewer_id, $subject_id ) ) {
			return 1;
		}

		if ( ! empty( $this->mutual_connections( $viewer_id, $subject_id ) ) ) {
			return 2;
		}

		return 3;
	}

	/**
	 * Return the total number of accepted connections for a user.
	 *
	 * @param int $user_id The user.
	 * @return int
	 */
	public function connection_count( int $user_id ): int {
		global $wpdb;

		$cache_key = "connection_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d OR recipient_id = %d )
				   AND status = 'accepted'",
				$user_id,
				$user_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Invalidate all connection cache entries.
	 *
	 * The paginated list keys (connections, pending_sent, pending_received) embed
	 * limit/offset in their cache key, making targeted deletion impractical. A full
	 * group flush is the correct approach for WP 6.1+ (this plugin requires WP 6.9+).
	 * Status and count keys are also covered by the group flush.
	 */
	private function invalidate_connection_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
