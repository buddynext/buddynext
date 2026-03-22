<?php
/**
 * Member directory service.
 *
 * Returns a cursor-paginated list of WordPress users for the member directory.
 * Each item includes core WP fields plus computed social-graph data.
 * The viewer is excluded from results. Cursor encodes a composite
 * (sort_key_value, user_id) pair as base64 JSON so that every sort mode
 * supports stable keyset pagination.
 *
 * @package BuddyNext\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Search;

use BuddyNext\SocialGraph\FollowService;

/**
 * Handles paginated member directory reads.
 */
class MemberDirectoryService {

	/**
	 * Default members per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Follow graph service — available for future "followed by viewer" sorting.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * Inject the follow graph service.
	 *
	 * @param FollowService $follows Follow service instance.
	 */
	public function __construct( FollowService $follows ) {
		$this->follows = $follows;
	}

	/**
	 * Return a cursor-paginated list of members.
	 *
	 * Supported $filters keys:
	 *   'search'            (string)  — LIKE match against display_name and user_login.
	 *   'location'          (string)  — LIKE match against bn_field_location usermeta.
	 *   'skills'            (string)  — LIKE match against bn_field_skills usermeta.
	 *   'space_id'          (int)     — restrict to active members of this space.
	 *   'connection_status' (string)  — 'connections' restricts to accepted connections
	 *                                   of the viewer. 'everyone' (default) applies no filter.
	 *   'online_only'       (bool)    — restrict to users active within the last 5 minutes.
	 *   'sort'              (string)  — 'newest' (default), 'alphabetical', 'most_active',
	 *                                   or 'online' (alias: implies online_only + most_active order).
	 *
	 * @param int         $viewer_id ID of the viewing user (excluded from results).
	 * @param string|null $cursor    Opaque pagination cursor from a previous page.
	 * @param int         $per_page  Number of members per page (max 50).
	 * @param array       $filters   Optional associative filter/sort options.
	 * @return array{items: array[], next_cursor: string|null, total: int}
	 */
	public function list_members( int $viewer_id = 0, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, array $filters = array() ): array {
		global $wpdb;

		$per_page    = min( $per_page, 50 );
		$cursor_data = $this->decode_cursor( $cursor );

		// Normalise filter values.
		$search            = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		$location          = isset( $filters['location'] ) ? trim( (string) $filters['location'] ) : '';
		$skills            = isset( $filters['skills'] ) ? trim( (string) $filters['skills'] ) : '';
		$space_id          = isset( $filters['space_id'] ) ? (int) $filters['space_id'] : 0;
		$connection_status = isset( $filters['connection_status'] ) ? (string) $filters['connection_status'] : 'everyone';
		$online_only       = ! empty( $filters['online_only'] );
		$sort              = isset( $filters['sort'] ) ? (string) $filters['sort'] : 'newest';

		// 'online' sort implies online_only filtering as well.
		if ( 'online' === $sort ) {
			$online_only = true;
		}

		// Result-set cache — keyed on all normalised inputs that shape the output.
		// 60-second TTL balances directory freshness against DB load at scale.
		$cache_key     = 'bn_dir_' . md5( (string) wp_json_encode( array( $viewer_id, $cursor, $per_page, $filters ) ) );
		$cached_result = wp_cache_get( $cache_key, 'buddynext' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false !== $cached_result ) {
			return (array) $cached_result;
		}

		// ------------------------------------------------------------------ //
		// Build SELECT — scalar subqueries for computed card fields.
		// ------------------------------------------------------------------ //

		// is_online is resolved after the main query via update_meta_cache() to avoid
		// an N+1 subquery per row. The SELECT column is omitted intentionally.

		$follower_count_subquery = "(SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows f WHERE f.following_id = u.ID) AS follower_count";

		// TODO: mutual connections require a graph pre-computation layer; returns 0 until implemented.
		$mutual_subquery = '0 AS mutual_connection_count';

		// ------------------------------------------------------------------ //
		// Build JOIN clauses.
		// ------------------------------------------------------------------ //

		$joins  = array();
		$params = array( $viewer_id );

		// Location JOIN — usermeta row for bn_field_location.
		if ( '' !== $location ) {
			$joins[] = "INNER JOIN {$wpdb->usermeta} AS um_loc
			            ON um_loc.user_id = u.ID
			            AND um_loc.meta_key = 'bn_field_location'";
		}

		// Skills JOIN — usermeta row for bn_field_skills.
		if ( '' !== $skills ) {
			$joins[] = "INNER JOIN {$wpdb->usermeta} um_skills
			            ON um_skills.user_id = u.ID
			            AND um_skills.meta_key = 'bn_field_skills'";
		}

		// Space membership JOIN — restrict to active members of a specific space.
		if ( $space_id > 0 ) {
			$joins[] = $wpdb->prepare(
				"INNER JOIN {$wpdb->prefix}bn_space_members sm
				 ON sm.user_id = u.ID AND sm.space_id = %d AND sm.status = 'active'",
				$space_id
			);
		}

		// Connection filter JOIN — restrict to accepted connections of the viewer.
		if ( 'connections' === $connection_status && $viewer_id > 0 ) {
			$joins[] = $wpdb->prepare(
				"INNER JOIN {$wpdb->prefix}bn_connections bcon
				 ON bcon.status = 'accepted'
				 AND (
				     (bcon.requester_id = %d AND bcon.recipient_id = u.ID)
				     OR (bcon.recipient_id = %d AND bcon.requester_id = u.ID)
				 )",
				$viewer_id,
				$viewer_id
			);
		}

		// Online-only / most_active JOIN — usermeta row for bn_last_active.
		if ( $online_only || 'online' === $sort || 'most_active' === $sort ) {
			$joins[] = "LEFT JOIN {$wpdb->usermeta} AS um_active
			            ON um_active.user_id = u.ID
			            AND um_active.meta_key = 'bn_last_active'";
		}

		$join_sql = $joins ? ( "\n" . implode( "\n", $joins ) ) : '';

		// ------------------------------------------------------------------ //
		// Build WHERE clauses.
		// ------------------------------------------------------------------ //

		// Exclude suspended users.
		$where_clauses = array(
			'u.ID != %d',
			"u.ID NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
			  )",
			"u.ID NOT IN (
			    SELECT user_id FROM {$wpdb->usermeta}
			    WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			  )",
		);

		if ( '' !== $search ) {
			$where_clauses[] = '(u.display_name LIKE %s OR u.user_login LIKE %s)';
			$like_search     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[]        = $like_search;
			$params[]        = $like_search;
		}

		if ( '' !== $location ) {
			$where_clauses[] = 'um_loc.meta_value LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( $location ) . '%';
		}

		if ( '' !== $skills ) {
			$where_clauses[] = 'um_skills.meta_value LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( $skills ) . '%';
		}

		if ( $online_only ) {
			$where_clauses[] = 'CAST(um_active.meta_value AS UNSIGNED) > UNIX_TIMESTAMP() - 300';
		}

		// ------------------------------------------------------------------ //
		// Cursor WHERE — per-sort composite keyset.
		// ------------------------------------------------------------------ //

		if ( null !== $cursor_data ) {
			switch ( $sort ) {
				case 'alphabetical':
					if ( isset( $cursor_data['name'], $cursor_data['id'] ) ) {
						$where_clauses[] = '(u.display_name > %s OR (u.display_name = %s AND u.ID > %d))';
						$params[]        = $cursor_data['name'];
						$params[]        = $cursor_data['name'];
						$params[]        = (int) $cursor_data['id'];
					}
					break;

				case 'most_active':
				case 'online':
					if ( isset( $cursor_data['last_active'], $cursor_data['id'] ) ) {
						$where_clauses[] = '(CAST(um_active.meta_value AS UNSIGNED) < %d OR (CAST(um_active.meta_value AS UNSIGNED) = %d AND u.ID < %d))';
						$params[]        = (int) $cursor_data['last_active'];
						$params[]        = (int) $cursor_data['last_active'];
						$params[]        = (int) $cursor_data['id'];
					}
					break;

				case 'newest':
				default:
					if ( isset( $cursor_data['registered'], $cursor_data['id'] ) ) {
						$where_clauses[] = '(u.user_registered < %s OR (u.user_registered = %s AND u.ID < %d))';
						$params[]        = $cursor_data['registered'];
						$params[]        = $cursor_data['registered'];
						$params[]        = (int) $cursor_data['id'];
					}
					break;
			}
		}

		$where_sql = implode( "\n   AND ", $where_clauses );

		// ------------------------------------------------------------------ //
		// Build ORDER BY clause.
		// ------------------------------------------------------------------ //

		switch ( $sort ) {
			case 'alphabetical':
				$order_sql = 'ORDER BY u.display_name ASC, u.ID ASC';
				break;

			case 'most_active':
			case 'online':
				$order_sql = 'ORDER BY CAST(COALESCE(um_active.meta_value, 0) AS UNSIGNED) DESC, u.ID DESC';
				break;

			case 'newest':
			default:
				$order_sql = 'ORDER BY u.user_registered DESC, u.ID DESC';
				break;
		}

		$params[] = $per_page + 1;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name, u.user_login, u.user_registered,
				        {$follower_count_subquery},
				        {$mutual_subquery}
				 FROM {$wpdb->users} u
				 {$join_sql}
				 WHERE {$where_sql}
				 {$order_sql}
				 LIMIT %d",
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Fetch total count (without LIMIT) for the same filter set.
		$count_params   = array_slice( $params, 0, count( $params ) - 1 );
		$count_params[] = PHP_INT_MAX;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				    SELECT u.ID
				    FROM {$wpdb->users} u
				    {$join_sql}
				    WHERE {$where_sql}
				    LIMIT %d
				 ) AS _ct",
				...$count_params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		// Prefetch all user meta in one query to avoid N+1 lookups in the map below.
		$user_ids = wp_list_pluck( $rows, 'ID' );
		if ( ! empty( $user_ids ) ) {
			update_meta_cache( 'user', $user_ids );
		}

		$sort_ref = $sort; // Capture for use inside closure.
		$items    = array_map(
			static function ( $r ) use ( $sort_ref ) {
				$uid         = (int) $r['ID'];
				$bio         = get_user_meta( $uid, 'bn_field_bio', true );
				$last_active = (int) get_user_meta( $uid, 'bn_last_active', true );
				$is_online   = $last_active > ( time() - 300 );
				return array(
					'user_id'                 => $uid,
					'display_name'            => $r['display_name'],
					'avatar_url'              => get_avatar_url( $uid, array( 'size' => 96 ) ),
					'registered_at'           => $r['user_registered'],
					'bio'                     => $bio ? $bio : '',
					'is_online'               => $is_online,
					'follower_count'          => (int) $r['follower_count'],
					// TODO: mutual connections require a graph pre-computation layer; returns 0 until implemented.
					'mutual_connection_count' => 0,
				);
			},
			$rows
		);

		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = $this->encode_cursor( $last, $sort );
		}

		$result = array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
			'total'       => $total,
		);

		wp_cache_set( $cache_key, $result, 'buddynext', 60 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching

		return $result;
	}

	/**
	 * Encode a cursor from the last row in a page.
	 *
	 * The cursor format depends on sort mode so that keyset pagination is stable
	 * across all sort orders. All cursors are base64-encoded JSON objects.
	 *
	 * Formats:
	 *   newest:       { "registered": "2024-01-15 10:00:00", "id": 42 }
	 *   alphabetical: { "name": "John Smith",                "id": 42 }
	 *   most_active:  { "last_active": "1705312800",         "id": 42 }
	 *   online:       { "last_active": "1705312800",         "id": 42 }
	 *
	 * @param array  $row  Row returned from the SELECT query.
	 * @param string $sort Active sort mode.
	 * @return string Opaque base64-encoded JSON cursor.
	 */
	private function encode_cursor( array $row, string $sort ): string {
		switch ( $sort ) {
			case 'alphabetical':
				$data = array(
					'name' => $row['display_name'],
					'id'   => (int) $row['ID'],
				);
				break;

			case 'most_active':
			case 'online':
				// Read bn_last_active from the meta cache populated by update_meta_cache()
				// earlier in list_members() — no extra DB query issued.
				$last_active = (string) ( (int) get_user_meta( (int) $row['ID'], 'bn_last_active', true ) );
				$data        = array(
					'last_active' => $last_active,
					'id'          => (int) $row['ID'],
				);
				break;

			case 'newest':
			default:
				$data = array(
					'registered' => $row['user_registered'],
					'id'         => (int) $row['ID'],
				);
				break;
		}

		return base64_encode( wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a cursor string into its component parts.
	 *
	 * Accepts both the new base64-JSON format and (for backwards compatibility)
	 * the legacy pipe-delimited format used before GAP-9 was fixed.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array<string, mixed>|null Null when the cursor is missing or invalid.
	 */
	private function decode_cursor( ?string $cursor ): ?array {
		if ( null === $cursor || '' === $cursor ) {
			return null;
		}

		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return null;
		}

		// Try JSON first (new format).
		$data = json_decode( $raw, true );

		if ( is_array( $data ) ) {
			return $data;
		}

		// Legacy pipe-delimited fallback: "2024-01-15 10:00:00|42".
		$parts = explode( '|', $raw, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		return array(
			'registered' => $parts[0],
			'id'         => (int) $parts[1],
		);
	}
}
