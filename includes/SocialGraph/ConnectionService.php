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
	/**
	 * Default ceiling on a member's accepted connections.
	 *
	 * FollowService has had `buddynext_max_following` since day one; the connection graph
	 * had no write cap at all, so a single account could accumulate rows without limit and
	 * make every surface that touches its peer set expensive for everyone.
	 *
	 * Enforced on ACCEPT, not on request. A connection is bilateral: capping only the
	 * requester would let the recipient's side grow unbounded, which is the same bug with
	 * an extra step.
	 */
	private const MAX_CONNECTIONS = 5000;

	/**
	 * Ceiling on how many mutual IDs a single call will materialise.
	 *
	 * The mutual_connections() method took `$limit = 0` to mean "no LIMIT clause at all", and both of
	 * its real callers used that default — one of them purely to call count() on the
	 * result. Two hub accounts could pull tens of thousands of IDs into PHP to render one
	 * integer.
	 */
	private const MUTUAL_LIST_CAP = 500;

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
	 * @param int         $requester_id ID of the user sending the request.
	 * @param int         $recipient_id ID of the user receiving the request.
	 * @param string      $note         Optional note to attach to the request (max 280 chars).
	 * @param string|null $created_at   Optional backdated UTC timestamp (importer
	 *                                  seam — see Core\Backdate). When null the
	 *                                  column default applies, as before.
	 * @return true|WP_Error
	 */
	public function send_request( int $requester_id, int $recipient_id, string $note = '', ?string $created_at = null ): bool|WP_Error {
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
			$error = new WP_Error(
				'connect_not_allowed',
				__( 'This member does not accept connection requests from you.', 'buddynext' ),
				array( 'status' => 403 )
			);

			/** This filter is documented in includes/SocialGraph/FollowService.php. */
			return apply_filters( 'buddynext_social_denied_error', $error, 'connect', $requester_id, $recipient_id );
		}

		// Hard-cap the note so a stray client can't overflow the column. Strip
		// tags + sanitize to plain text — the note renders inside notification
		// text and the connection details panel. The cap is filterable but stays
		// bounded to the column width (max 280) so a filter can shorten but never
		// overflow the schema.
		/**
		 * Filter the maximum connection-note length (characters).
		 *
		 * @param int $max Default 280. Clamped to [0, 280] to respect the column.
		 */
		$note_max = (int) apply_filters( 'buddynext_connect_note_max_length', 280 );
		$note_max = max( 0, min( 280, $note_max ) );

		$note = wp_strip_all_tags( $note );
		if ( strlen( $note ) > $note_max ) {
			$note = function_exists( 'mb_substr' )
				? mb_substr( $note, 0, $note_max )
				: substr( $note, 0, $note_max );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, requester_id, declined_at
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

		$existing = null !== $existing_row ? $existing_row->id : null;

		/*
		 * A DECLINED row is not a live relationship — it is the record of one that
		 * ended, and it must not wall the pair off forever.
		 *
		 * decline_request() only flips status to 'declined'; every other exit path
		 * (withdraw, disconnect) deletes its row. This lookup matched on the PAIR
		 * regardless of status, so after a single decline every future attempt died
		 * on request_already_exists — while the Connect button kept rendering
		 * normally, promising an action that could never succeed. The two members
		 * could never connect again without someone editing the database.
		 *
		 * Re-opening the existing row rather than inserting a second one keeps the
		 * one-row-per-pair shape the rest of this service assumes, and correctly
		 * re-points requester/recipient when the DECLINER is the one now reaching
		 * out (Priya declined Alex; Priya may still send her own request later).
		 *
		 * Only 'declined' re-opens. pending / accepted / anything else still block,
		 * so this cannot be used to spam a live request or silently re-friend.
		 */
		if ( null !== $existing_row && 'declined' === (string) $existing_row->status ) {
			// Declining has to mean something.
			//
			// Re-opening a declined pair is right - two people should be able to
			// connect later - but with nothing between the decline and the next
			// request, a declined member could re-send instantly and forever, and
			// each attempt fired a fresh notification at the person who had just
			// said no. Declining was not a way to make it stop. Verified before
			// this guard: five requests, five declines, back to back, all accepted.
			//
			// So the decline holds for a cooldown, the way LinkedIn and Facebook
			// both handle a declined invitation. It is a pause, not a permanent
			// block: after it passes the request goes through as before.
			//
			// Only the person who was declined waits. If the DECLINER later reaches
			// out themselves that is a different, welcome action, and the branch
			// below already re-points requester/recipient for it.
			$declined_at = isset( $existing_row->declined_at ) ? (string) $existing_row->declined_at : '';
			$same_asker  = (int) $existing_row->requester_id === $requester_id;

			if ( $same_asker && '' !== $declined_at && '0000-00-00 00:00:00' !== $declined_at ) {
				/**
				 * Filter how long a declined member must wait before asking again.
				 *
				 * @param int $seconds  Cooldown length. Default 7 days.
				 * @param int $requester_id Member who was declined.
				 * @param int $recipient_id Member who declined.
				 */
				$cooldown = (int) apply_filters(
					'buddynext_connection_redeclare_cooldown',
					7 * DAY_IN_SECONDS,
					$requester_id,
					$recipient_id
				);

				$elapsed = time() - (int) strtotime( $declined_at . ' UTC' );

				if ( $cooldown > 0 && $elapsed < $cooldown ) {
					return new WP_Error(
						'request_declined_recently',
						__( 'This member declined your connection request. You can try again later.', 'buddynext' ),
						array( 'status' => 429 )
					);
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$reopened = $wpdb->update(
				$wpdb->prefix . 'bn_connections',
				array(
					'requester_id' => $requester_id,
					'recipient_id' => $recipient_id,
					'status'       => 'pending',
					'note'         => $note,
					'created_at'   => $created_at ?? current_time( 'mysql', true ),
					// Cleared on re-open so the cooldown measures the LAST decline,
					// not the first one this pair ever had.
					'declined_at'  => null,
				),
				array( 'id' => (int) $existing_row->id ),
				array( '%d', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $reopened ) {
				return new WP_Error(
					'db_error',
					__( 'The connection request could not be saved. Please try again.', 'buddynext' )
				);
			}

			$this->invalidate_connection_cache( $requester_id, $recipient_id );

			/** This action is documented below, on the insert path. */
			do_action( 'buddynext_connection_requested', (int) $existing_row->id, $requester_id, $recipient_id, $note );

			return true;
		}

		if ( null !== $existing ) {
			return new WP_Error(
				'request_already_exists',
				__( 'A connection request already exists for this pair.', 'buddynext' )
			);
		}

		$row     = array(
			'requester_id' => $requester_id,
			'recipient_id' => $recipient_id,
			'status'       => 'pending',
			'note'         => $note,
		);
		$formats = array( '%d', '%d', '%s', '%s' );

		// Importer seam: only include created_at when a backdate was supplied —
		// otherwise the column's DEFAULT CURRENT_TIMESTAMP applies, unchanged.
		if ( null !== $created_at && '' !== $created_at ) {
			$row['created_at'] = \BuddyNext\Core\Backdate::resolve( $created_at );
			$formats[]         = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $wpdb->prefix . 'bn_connections', $row, $formats );

		// Surface a write failure instead of reporting success with a 0 id (which
		// would also fire buddynext_connection_requested for a row that never
		// existed). $wpdb->insert() returns false on error.
		if ( false === $inserted ) {
			return new WP_Error(
				'db_error',
				__( 'The connection request could not be saved. Please try again.', 'buddynext' )
			);
		}

		$connection_id = (int) $wpdb->insert_id;
		$this->invalidate_connection_cache( $requester_id, $recipient_id );

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
	public function accept_request( int $recipient_id, int $requester_id ): bool|WP_Error {
		global $wpdb;

		// Enforce the cap HERE rather than on send_request(), because a connection is
		// bilateral: it lands on both members' graphs. Capping only the requester would let
		// the recipient's side grow without limit. Both sides are checked.
		//
		// connection_count() reads a denormalised counter, so this guard is effectively
		// free — it is not a COUNT(*) per accept.
		$cap = (int) apply_filters( 'buddynext_max_connections', self::MAX_CONNECTIONS, $recipient_id );
		if ( $cap > 0 ) {
			foreach ( array( $recipient_id, $requester_id ) as $party_id ) {
				if ( $this->connection_count( $party_id ) >= $cap ) {
					return new WP_Error(
						'connection_limit_reached',
						sprintf(
							/* translators: %s: the maximum number of connections. */
							__( 'This connection cannot be accepted: one of the two members has reached the limit of %s connections.', 'buddynext' ),
							number_format_i18n( $cap )
						)
					);
				}
			}
		}

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

		$this->invalidate_connection_cache( $recipient_id, $requester_id );

		// A pending request just became an accepted connection — count it for both
		// peers (a connection is one shared row, counted from either side).
		$counters = buddynext_service( 'counters' );
		$counters->adjust_user_counter( $requester_id, 'bn_connection_count', 1 );
		$counters->adjust_user_counter( $recipient_id, 'bn_connection_count', 1 );

		// Re-bust AFTER the counters are written — see invalidate_connection_counts().
		$this->invalidate_connection_counts( $requester_id, $recipient_id );

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
	public function decline_request( int $recipient_id, int $requester_id ): bool|WP_Error {
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

		// Guard the UPDATE on status = 'pending' so the decline is atomic: if a
		// concurrent request accepted/withdrew the row between the SELECT above
		// and here, this matches zero rows instead of clobbering the new state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_connections',
			array(
				'status'      => 'declined',
				// When the decline happened, so the cooldown in send_request() has
				// something to measure against. The row is REUSED on re-open, which
				// overwrites created_at - without this stamp the fact that a decline
				// ever occurred is destroyed on the very next request.
				'declined_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $connection_id,
				'status' => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( empty( $updated ) ) {
			return new WP_Error(
				'request_not_found',
				__( 'No pending connection request was found.', 'buddynext' )
			);
		}

		$this->invalidate_connection_cache( $recipient_id, $requester_id );

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
	public function withdraw_request( int $requester_id, int $recipient_id ): bool|WP_Error {
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

		// Guard the delete on status = 'pending' so the withdraw is atomic: if a
		// concurrent request accepted the connection between the SELECT above and
		// here, this matches zero rows instead of deleting an accepted connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_connections',
			array(
				'id'     => $connection_id,
				'status' => 'pending',
			),
			array( '%d', '%s' )
		);

		if ( empty( $deleted ) ) {
			return new WP_Error(
				'not_found',
				__( 'No pending request found.', 'buddynext' )
			);
		}

		$this->invalidate_connection_cache( $requester_id, $recipient_id );

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
	public function remove_connection( int $user_a, int $user_b ): bool|WP_Error {
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

		// The accepted connection is gone — decrement the counter for both peers.
		$counters = buddynext_service( 'counters' );
		$counters->adjust_user_counter( $user_a, 'bn_connection_count', -1 );
		$counters->adjust_user_counter( $user_b, 'bn_connection_count', -1 );

		// Re-bust AFTER the counters are written — see invalidate_connection_counts().
		$this->invalidate_connection_counts( $user_a, $user_b );

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
	 * The viewer's connection status with a peer, WITH the direction resolved.
	 *
	 * Status() is deliberately symmetric, so a pending request reads 'pending'
	 * whichever side asks — which is why a profile screen could not tell
	 * "Requested" from "Respond" without a second trip through
	 * /me/connection-requests. This reads the same single cached row and reports
	 * 'pending-sent' or 'pending-received' from the viewer's point of view,
	 * matching the vocabulary statuses_for() already uses for the directory.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $peer_id   The other member.
	 * @return string|null accepted | pending-sent | pending-received | declined | withdrawn, or null.
	 */
	public function directional_status( int $viewer_id, int $peer_id ): ?string {
		$row = $this->pair_row( $viewer_id, $peer_id );

		if ( ! $row ) {
			return null;
		}

		$status = (string) $row->status;

		if ( 'pending' !== $status ) {
			return $status;
		}

		return (int) $row->requester_id === $viewer_id ? 'pending-sent' : 'pending-received';
	}

	/**
	 * The canonical {state, can_message} block for a direction-aware status.
	 *
	 * ONE shaping, shared by the member directory and the profile payload, so the
	 * two surfaces cannot describe the same relationship differently — the app
	 * draws the same button from either. Keep new states here, never in a caller.
	 *
	 * @param string|null $status Direction-aware status (see directional_status()).
	 * @return array{state:string,can_message:bool}
	 */
	public static function state_block( ?string $status ): array {
		$none = array(
			'state'       => 'none',
			'can_message' => false,
		);

		if ( null === $status || 'declined' === $status || 'withdrawn' === $status ) {
			return $none;
		}

		if ( 'accepted' === $status ) {
			return array(
				'state'       => 'accepted',
				'can_message' => true,
			);
		}

		if ( 'pending-sent' === $status ) {
			return array(
				'state'       => 'pending-sent',
				'can_message' => false,
			);
		}

		// A bare 'pending' can only reach here from a caller that did not resolve
		// direction; treat it as received, which is the safe default (it offers
		// Respond rather than falsely claiming the viewer already asked).
		if ( 'pending-received' === $status || 'pending' === $status ) {
			return array(
				'state'       => 'pending-received',
				'can_message' => false,
			);
		}

		return $none;
	}

	/**
	 * The connection block for a single viewer/peer pair, in one cached query.
	 *
	 * @param int $viewer_id Viewer.
	 * @param int $peer_id   The other member.
	 * @return array{state:string,can_message:bool}
	 */
	public function connection_block( int $viewer_id, int $peer_id ): array {
		if ( $viewer_id <= 0 || $peer_id <= 0 || $viewer_id === $peer_id ) {
			return self::state_block( null );
		}

		return self::state_block( $this->directional_status( $viewer_id, $peer_id ) );
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated %d list; $params binds viewer_id + all peer IDs twice, matching the two IN() clauses.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status
				 FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id IN ( {$placeholders} ) )
				    OR ( recipient_id = %d AND requester_id IN ( {$placeholders} ) )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( (array) $rows as $row ) {
			$peer   = ( (int) $row->requester_id === $viewer_id ) ? (int) $row->recipient_id : (int) $row->requester_id;
			$status = (string) $row->status;
			// Encode the pending direction here — this query already knows who the
			// requester is, so the directory can label sent vs received without a
			// per-row pending_sent() lookup (which was both an N+1 and capped at
			// LIMIT 20, mislabelling peers beyond the first 20).
			if ( 'pending' === $status ) {
				$status = ( (int) $row->requester_id === $viewer_id ) ? 'pending-sent' : 'pending-received';
			}
			$map[ $peer ] = $status;
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

		$cache_key = "connections_{$user_id}_{$limit}_{$offset}_v" . $this->version( $user_id );
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

		$cache_key = "pending_sent_{$user_id}_{$limit}_{$offset}_v" . $this->version( $user_id );
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

		$cache_key = "pending_received_{$user_id}_{$limit}_{$offset}_v" . $this->version( $user_id );
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
	 * Return the notes attached to pending requests received by a user, keyed by requester.
	 *
	 * The note a member writes on a connection request is stored on the connection
	 * row (`bn_connections.note`) and it is what the recipient is meant to judge the
	 * request on — but pending_received() returns bare requester IDs, so until this
	 * existed the note reached no surface that reviews the request: not the profile
	 * request inbox, not the REST endpoint behind it. That gap is why the note was
	 * bolted onto the messaging engine instead (Basecamp 10244757451).
	 *
	 * Batched deliberately: the caller already has the requester list, so one query
	 * covers a whole page of requests rather than one per row. It rides the same
	 * recipient_status index pending_received() uses.
	 *
	 * @since 1.1.6
	 *
	 * @param int   $user_id       The recipient user.
	 * @param int[] $requester_ids Requesters to fetch notes for (a page of pending_received()).
	 * @return array<int, string> requester_id => note. Requesters with no note are omitted.
	 */
	public function pending_notes_for( int $user_id, array $requester_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $requester_ids ) ) ) );
		if ( $user_id <= 0 || empty( $ids ) ) {
			return array();
		}

		$cache_key = 'pending_notes_' . $user_id . '_' . md5( implode( ',', $ids ) ) . '_v' . $this->version( $user_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is a counted "%d, ..." list and every id is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requester_id, note
				 FROM {$wpdb->prefix}bn_connections
				 WHERE recipient_id = %d AND status = 'pending' AND note <> ''
				   AND requester_id IN ( {$placeholders} )",
				array_merge( array( $user_id ), $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (int) $row->requester_id ] = (string) $row->note;
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * How many connections $user_a and $user_b have in common.
	 *
	 * The profile header renders a single "N mutual connections" number, and it used to get
	 * it with `count( mutual_connections( $a, $b ) )` — materialising the ENTIRE mutual set
	 * into a PHP array to produce one integer. Between two hub accounts that is tens of
	 * thousands of IDs loaded, counted, and thrown away, on a page every member opens.
	 *
	 * A count is a COUNT(*). It reuses the same derived-table self-join, so it stays correct
	 * as the graph shape changes, and it rides the existing per-member version keys — no new
	 * invalidation path to keep in sync (which is its own class of bug).
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @return int
	 */
	public function mutual_count( int $user_a, int $user_b ): int {
		if ( $user_a <= 0 || $user_b <= 0 || $user_a === $user_b ) {
			return 0;
		}

		global $wpdb;

		$cache_key = "mutual_count_{$user_a}_{$user_b}_v" . $this->version( $user_a ) . '_' . $this->version( $user_b );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				     SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END AS uid
				       FROM {$wpdb->prefix}bn_connections
				      WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'
				 ) ca
				 INNER JOIN (
				     SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END AS uid
				       FROM {$wpdb->prefix}bn_connections
				      WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'
				 ) cb ON cb.uid = ca.uid
				 WHERE ca.uid NOT IN ( %d, %d )",
				$user_a,
				$user_a,
				$user_a,
				$user_b,
				$user_b,
				$user_b,
				$user_a,
				$user_b
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Return user IDs that both $user_a and $user_b are each accepted-connected to.
	 *
	 * The bn_connections table is directional (one row per pair; either
	 * requester_id or recipient_id can be the "acting" user), so each side's set is
	 * the non-self party of its accepted rows. The intersection is computed
	 * entirely in SQL via a self-join of the two sets — the database returns
	 * only the mutual IDs, instead of loading both users' full connection lists
	 * into PHP for an array_intersect() (which exhausted memory on large graphs).
	 *
	 * @param int $user_a First user.
	 * @param int $user_b Second user.
	 * @param int $limit  Optional cap on returned IDs (0 = all). A degree check
	 *                    can pass 1 to test existence without materialising all.
	 * @return int[]
	 */
	public function mutual_connections( int $user_a, int $user_b, int $limit = 0 ): array {
		if ( $user_a <= 0 || $user_b <= 0 || $user_a === $user_b ) {
			return array();
		}

		global $wpdb;

		// `0` used to mean "no LIMIT clause at all", and every caller took the default. Two
		// hub accounts would materialise their whole mutual set into PHP. It now means
		// "the site's ceiling", so there is no way to ask for an unbounded list by accident.
		// A caller that genuinely needs one row (connection_degree passes 1) is unaffected.
		if ( $limit <= 0 ) {
			$limit = (int) apply_filters( 'buddynext_mutual_list_cap', self::MUTUAL_LIST_CAP, $user_a, $user_b );
		}

		$cache_key = "mutual_{$user_a}_{$user_b}_{$limit}_v" . $this->version( $user_a ) . '_' . $this->version( $user_b );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$limit_sql = $limit > 0 ? ' LIMIT ' . (int) $limit : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ca.uid FROM (
				     SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END AS uid
				       FROM {$wpdb->prefix}bn_connections
				      WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'
				 ) ca
				 INNER JOIN (
				     SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END AS uid
				       FROM {$wpdb->prefix}bn_connections
				      WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'
				 ) cb ON cb.uid = ca.uid
				 WHERE ca.uid NOT IN ( %d, %d )
				 ORDER BY ca.uid{$limit_sql}",
				$user_a,
				$user_a,
				$user_a,
				$user_b,
				$user_b,
				$user_b,
				$user_a,
				$user_b
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = array_map( 'intval', (array) $rows );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Mutual-connection IDs between a viewer and many peers, in one query.
	 *
	 * A member-directory card shows a mutual count + a few avatars, and derives
	 * its 1st/2nd/3rd-degree badge from whether any mutuals exist. Doing that per
	 * row calls mutual_connections() once for the card and again inside
	 * connection_degree() — up to 2N queries for a page of N members. This resolves
	 * every peer's mutuals against the viewer in a single self-join: the viewer's
	 * accepted-connection partners intersected with each peer's, grouped by peer.
	 * The intersection runs entirely in SQL (no full connection set loaded into
	 * PHP), matching mutual_connections()'s memory-safe approach.
	 *
	 * @param int   $viewer_id Viewer user ID.
	 * @param int[] $peer_ids  Peer user IDs on the current page.
	 * @param int   $cap       Optional max mutuals kept per peer (0 = all); ordered
	 *                         by ascending ID, so a small cap feeds the avatar pile.
	 * @return array<int, int[]> Peer-ID keyed map of mutual IDs (peers with none omitted).
	 */
	public function mutual_ids_for( int $viewer_id, array $peer_ids, int $cap = 0 ): array {
		$peer_ids = array_values( array_unique( array_filter( array_map( 'intval', $peer_ids ) ) ) );
		$peer_ids = array_values(
			array_filter(
				$peer_ids,
				static function ( $id ) use ( $viewer_id ) {
					return $id !== $viewer_id;
				}
			)
		);
		if ( $viewer_id <= 0 || ! $peer_ids ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $peer_ids ), '%d' ) );
		// pb derives (peer, partner) for every accepted row touching the peer set,
		// once per endpoint that is a peer (the UNION handles peer-to-peer rows on
		// both sides). va is the viewer's accepted-connection partner set. Joining
		// on partner equality yields each peer's mutuals with the viewer.
		$params = array_merge(
			$peer_ids,                                    // pb half 1: requester_id IN (peers).
			$peer_ids,                                    // pb half 2: recipient_id IN (peers).
			array( $viewer_id, $viewer_id, $viewer_id ),  // va: CASE + WHERE pair.
			array( $viewer_id )                           // Exclude the viewer as a self-mutual.
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated %d list; $params binds the peer set twice then the viewer ID for the va sub-select and the self-mutual guard.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pb.peer_id, pb.partner FROM (
				     SELECT requester_id AS peer_id, recipient_id AS partner
				       FROM {$wpdb->prefix}bn_connections
				      WHERE status = 'accepted' AND requester_id IN ( {$placeholders} )
				     UNION
				     SELECT recipient_id AS peer_id, requester_id AS partner
				       FROM {$wpdb->prefix}bn_connections
				      WHERE status = 'accepted' AND recipient_id IN ( {$placeholders} )
				 ) pb
				 INNER JOIN (
				     SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END AS partner
				       FROM {$wpdb->prefix}bn_connections
				      WHERE status = 'accepted' AND ( requester_id = %d OR recipient_id = %d )
				 ) va ON va.partner = pb.partner
				 WHERE pb.partner <> %d
				 ORDER BY pb.peer_id, pb.partner",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->peer_id ][] = (int) $row->partner;
		}

		if ( $cap > 0 ) {
			foreach ( $map as $peer => $ids ) {
				$map[ $peer ] = array_slice( $ids, 0, $cap );
			}
		}

		return $map;
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

		if ( ! empty( $this->mutual_connections( $viewer_id, $subject_id, 1 ) ) ) {
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
		$cache_key = "connection_count_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Read the denormalised counter (O(1) cache-cold) instead of COUNT(*)-ing
		// bn_connections. A missing key lazy-recounts so the store self-heals; the
		// accept/remove paths and the daily reconcile keep it current after.
		$meta = get_user_meta( $user_id, 'bn_connection_count', true );
		if ( '' === $meta ) {
			buddynext_service( 'counters' )->recount_connection_counts( $user_id );
			$meta = get_user_meta( $user_id, 'bn_connection_count', true );
		}
		$count = (int) $meta;

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * The per-member cache version.
	 *
	 * Every unbounded key set (the paged lists, and the pairwise `mutual_` keys) embeds the
	 * version of the member(s) it depends on. Bumping the version makes every one of those keys
	 * unreachable at once, without touching any other member's cache — which is what
	 * delete-by-key cannot do for a key set whose offsets you cannot enumerate.
	 *
	 * Seeded from `time()`, not from 1, and deliberately. If the object cache evicts a version
	 * key under memory pressure while data keyed on it is still cached, a re-seed must produce a
	 * NEW value — never one that was used before, or the old entries become reachable again and
	 * we would resurrect stale data. A monotonic seed makes that impossible.
	 *
	 * Stored with no expiry: the version must outlive the data it versions.
	 *
	 * @since 1.0.8
	 *
	 * @param int $uid Member ID.
	 * @return int Current version.
	 */
	private function version( int $uid ): int {
		$key = "conn_ver_{$uid}";
		$ver = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false === $ver ) {
			$ver = time();
			wp_cache_set( $key, $ver, self::CACHE_GROUP, 0 );
		}

		return (int) $ver;
	}

	/**
	 * Bump a member's cache version, retiring every unbounded key that embeds it.
	 *
	 * @since 1.0.8
	 *
	 * @param int $uid Member ID.
	 * @return void
	 */
	private function bump_version( int $uid ): void {
		$key = "conn_ver_{$uid}";

		if ( false === wp_cache_get( $key, self::CACHE_GROUP ) ) {
			// Nothing cached under this version yet; seeding is enough.
			wp_cache_set( $key, time(), self::CACHE_GROUP, 0 );

			return;
		}

		wp_cache_incr( $key, 1, self::CACHE_GROUP );
	}

	/**
	 * Invalidate the cache for the two members a write actually touched.
	 *
	 * This used to be `wp_cache_flush_group()`, and that was wrong twice over.
	 *
	 * It is a silent no-op on any persistent drop-in that does not implement `flush_group` (some
	 * Memcached, older Redis). Core's default cache DOES implement it — so the bug was invisible
	 * locally and only bit production sites that had installed the very thing that makes caching
	 * work.
	 *
	 * And where it DID work it was a sledgehammer: one member accepting one connection request
	 * destroyed the cached connection state of EVERY member on the site. On a busy 100k-member
	 * graph, connections are accepted continuously — so the cache was wiped faster than it could
	 * warm, every read fell through to the database anyway, and the cache was pure cost. That is
	 * over-INVALIDATION, the twin of over-caching.
	 *
	 * Two mechanisms, chosen by key-set shape (CACHING.md §4b):
	 *
	 *   DELETE-BY-KEY — the write knows exactly which keys it changed:
	 *     pair_row_{low}_{high}, connection_count_{a}, connection_count_{b}
	 *
	 *   VERSION KEY — the key set cannot be enumerated at write time:
	 *     connections_{u}_{limit}_{offset}      (offsets unknowable)
	 *     pending_sent_{u}_{limit}_{offset}
	 *     pending_received_{u}_{limit}_{offset}
	 *     pending_notes_{u}_{md5(ids)}          (id set unknowable)
	 *     mutual_{a}_{b}_{limit}                (PAIRWISE — see below)
	 *
	 * `mutual_` is the one that forces the design. When A's connections change, every
	 * `mutual_{A}_{anyone}_{any_limit}` key is stale — and every `mutual_{anyone}_{A}_…` too. That
	 * set is unbounded across the entire user base, which is exactly why someone reached for the
	 * group flush in the first place. It was the wrong answer to a real problem. Because the key
	 * embeds BOTH members' versions, bumping either one retires it.
	 *
	 * @param int $user_a One member in the write.
	 * @param int $user_b The other.
	 * @return void
	 */
	private function invalidate_connection_cache( int $user_a, int $user_b ): void {
		$low  = min( $user_a, $user_b );
		$high = max( $user_a, $user_b );

		wp_cache_delete( "pair_row_{$low}_{$high}", self::CACHE_GROUP );

		$this->invalidate_connection_counts( $user_a, $user_b );

		$this->bump_version( $user_a );
		$this->bump_version( $user_b );
	}

	/**
	 * Bust ONLY the denormalised connection-count keys.
	 *
	 * Same reason as FollowService::invalidate_follow_counts(). The pair row and the
	 * list versions are correct the moment the bn_connections row is written, but the
	 * COUNT keys are backed by the bn_connection_count usermeta counter, which
	 * CounterService::adjust_user_counter() writes a few lines LATER.
	 *
	 * Busting the count keys before that write opens a race: connection_count() falls
	 * back to usermeta on a miss, so a concurrent request landing in the window reads
	 * the pre-change counter and re-caches the wrong number for the whole TTL.
	 * adjust_user_counter() only busts WP's 'user_meta' cache, never this group.
	 *
	 * The counter-changing write paths therefore call this AGAIN after the counters
	 * are written. Deleting an absent key is a no-op, so the double bust is free.
	 *
	 * @param int $user_a One side of the pair.
	 * @param int $user_b The other side.
	 */
	private function invalidate_connection_counts( int $user_a, int $user_b ): void {
		wp_cache_delete( "connection_count_{$user_a}", self::CACHE_GROUP );
		wp_cache_delete( "connection_count_{$user_b}", self::CACHE_GROUP );
	}
}
