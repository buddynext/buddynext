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
	 * @param int $requester_id ID of the user sending the request.
	 * @param int $recipient_id ID of the user receiving the request.
	 * @return true|WP_Error
	 */
	public function send_request( int $requester_id, int $recipient_id ): true|WP_Error {
		if ( $requester_id === $recipient_id ) {
			return new WP_Error(
				'cannot_connect_self',
				__( 'A user cannot connect with themselves.', 'buddynext' )
			);
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
			),
			array( '%d', '%d', '%s' )
		);

		$connection_id = (int) $wpdb->insert_id;
		$this->invalidate_connection_cache( $requester_id, $recipient_id );

		/**
		 * Fires after a connection request is sent.
		 *
		 * @param int $connection_id Connection row ID.
		 * @param int $requester_id  ID of the requesting user.
		 * @param int $recipient_id  ID of the recipient.
		 */
		do_action( 'buddynext_connection_requested', $connection_id, $requester_id, $recipient_id );

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

		$this->invalidate_connection_cache( $requester_id, $recipient_id );

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

		$this->invalidate_connection_cache( $requester_id, $recipient_id );

		/**
		 * Fires after a connection request is rejected.
		 *
		 * @param int $connection_id ID of the connection row.
		 * @param int $requester_id  ID of the original requester.
		 * @param int $recipient_id  ID of the rejecting user.
		 */
		do_action( 'buddynext_connection_rejected', $connection_id, $requester_id, $recipient_id );

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

		$this->invalidate_connection_cache( $requester_id, $recipient_id );

		/**
		 * Fires after a connection request is withdrawn.
		 *
		 * @param int $connection_id ID of the connection row.
		 * @param int $requester_id  ID of the withdrawing user.
		 */
		do_action( 'buddynext_connection_withdrawn', $connection_id, $requester_id );

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

		$this->invalidate_connection_cache( $user_a, $user_b );

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
		global $wpdb;

		$low       = min( $user_a, $user_b );
		$high      = max( $user_a, $user_b );
		$cache_key = "status_{$low}_{$high}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return '' === $cached ? null : (string) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status
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
		wp_cache_set( $cache_key, null !== $result ? $result : '', self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the list of user IDs the given user is connected with (accepted only).
	 *
	 * @param int $user_id The user.
	 * @return int[]
	 */
	public function connections( int $user_id ): array {
		global $wpdb;

		$cache_key = "connections_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CASE
				    WHEN requester_id = %d THEN recipient_id
				    ELSE requester_id
				 END
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d OR recipient_id = %d )
				   AND status = 'accepted'",
				$user_id,
				$user_id,
				$user_id
			)
		);

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the list of recipient IDs for pending requests sent by the user.
	 *
	 * @param int $user_id The requesting user.
	 * @return int[]
	 */
	public function pending_sent( int $user_id ): array {
		global $wpdb;

		$cache_key = "pending_sent_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT recipient_id
				 FROM {$wpdb->prefix}bn_connections
				 WHERE requester_id = %d AND status = 'pending'
				 ORDER BY created_at DESC",
				$user_id
			)
		);

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the list of requester IDs for pending requests received by the user.
	 *
	 * @param int $user_id The recipient user.
	 * @return int[]
	 */
	public function pending_received( int $user_id ): array {
		global $wpdb;

		$cache_key = "pending_received_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT requester_id
				 FROM {$wpdb->prefix}bn_connections
				 WHERE recipient_id = %d AND status = 'pending'
				 ORDER BY created_at DESC",
				$user_id
			)
		);

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
	 * Invalidate all cache keys affected by a connection state change.
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 */
	private function invalidate_connection_cache( int $user_a, int $user_b ): void {
		wp_cache_delete( "status_{$user_a}_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "status_{$user_b}_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "connections_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "connections_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "pending_received_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "pending_received_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "pending_sent_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "pending_sent_{$user_b}", self::CACHE_GROUP );
		wp_cache_delete( "connection_count_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "connection_count_{$user_b}", self::CACHE_GROUP );
	}
}
