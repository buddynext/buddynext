<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member directory service.
 *
 * Returns a cursor-paginated list of WordPress users for the member directory.
 * Each item includes core WP fields plus computed social-graph data.
 * The viewer is excluded from results. Cursor encodes a composite
 * (sort_key_value, user_id) pair as base64 JSON so that every sort mode
 * supports stable keyset pagination.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Handles paginated member directory reads.
 */
class MemberDirectoryService {

	/**
	 * Default members per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Return a cursor-paginated list of members.
	 *
	 * Supported $filters keys:
	 *   'search'            (string)  — OR LIKE match against display_name, user_login,
	 *                                   and every searchable field's privacy-safe
	 *                                   `bn_field_{key}` usermeta mirror. Because the
	 *                                   mirror only holds values whose effective
	 *                                   visibility resolves to public (written by
	 *                                   ProfileService per the searchable_mirror
	 *                                   contract), matching the mirror needs no
	 *                                   per-row visibility check.
	 *   'location'          (string)  — LIKE match against bn_field_location usermeta.
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
		$member_type       = isset( $filters['member_type'] ) ? sanitize_key( (string) $filters['member_type'] ) : '';
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

		// mutual_connection_count is computed post-query to avoid the MySQL 5.7
		// "Can't reopen table" error that occurs when bn_connections is referenced
		// twice inside a correlated SELECT-list subquery (even with different aliases).
		$viewer_id_safe = (int) $viewer_id;

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

		// Exclude suspended and shadow-banned users.
		// Uses NOT EXISTS instead of NOT IN to avoid MySQL 5.7 "Can't reopen table" error
		// when combined with the self-joined bn_connections mutual_subquery.
		$where_clauses = array(
			'u.ID != %d',
			"NOT EXISTS (
			    SELECT 1 FROM {$wpdb->prefix}bn_user_suspensions s_ex
			    WHERE s_ex.user_id = u.ID
			      AND s_ex.lifted_at IS NULL
			      AND (s_ex.expires_at IS NULL OR s_ex.expires_at > NOW())
			  )",
			"NOT EXISTS (
			    SELECT 1 FROM {$wpdb->usermeta} um_ban
			    WHERE um_ban.user_id = u.ID
			      AND um_ban.meta_key = 'bn_shadow_banned'
			      AND um_ban.meta_value = '1'
			  )",
			// Honor the "Show me in the member directory" privacy toggle
			// (usermeta bn_privacy_show_in_directory). Default-visible: only
			// members who EXPLICITLY set the meta to '0' are excluded; an
			// absent meta (every existing member) leaves them listed.
			"NOT EXISTS (
			    SELECT 1 FROM {$wpdb->usermeta} um_dir
			    WHERE um_dir.user_id = u.ID
			      AND um_dir.meta_key = 'bn_privacy_show_in_directory'
			      AND um_dir.meta_value = '0'
			  )",
		);

		// Bidirectional block exclusion — viewer should not see users they have
		// blocked, AND users who have blocked the viewer should not appear either.
		// Skip for logged-out viewers (viewer_id=0) since there's no relationship.
		if ( $viewer_id > 0 ) {
			$where_clauses[] = $wpdb->prepare(
				"NOT EXISTS (
				    SELECT 1 FROM {$wpdb->prefix}bn_blocks b
				    WHERE b.type = 'block'
				      AND (
				          (b.blocker_id = %d AND b.blocked_id = u.ID)
				          OR (b.blocker_id = u.ID AND b.blocked_id = %d)
				      )
				  )",
				$viewer_id,
				$viewer_id
			);
		}

		if ( '' !== $search ) {
			$like_search = '%' . $wpdb->esc_like( $search ) . '%';

			// Always match core identity columns.
			$search_or = array(
				'u.display_name LIKE %s',
				'u.user_login LIKE %s',
			);
			$params[] = $like_search;
			$params[] = $like_search;

			// Dynamically OR-match every searchable field's privacy-safe mirror.
			// One correlated EXISTS per mirror key; the mirror only contains
			// public-visibility values, so this stays privacy-safe with no
			// per-row checks. Each EXISTS is its own bn_field_{key} usermeta row.
			foreach ( $this->searchable_mirror_keys() as $meta_key ) {
				$search_or[] = "EXISTS (
				    SELECT 1 FROM {$wpdb->usermeta} um_search
				    WHERE um_search.user_id = u.ID
				      AND um_search.meta_key = %s
				      AND um_search.meta_value LIKE %s
				  )";
				$params[] = $meta_key;
				$params[] = $like_search;
			}

			$where_clauses[] = '(' . implode( ' OR ', $search_or ) . ')';
		}

		if ( '' !== $location ) {
			$where_clauses[] = 'um_loc.meta_value LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( $location ) . '%';
		}

		if ( '' !== $member_type ) {
			// Filter by the bn_member_type write-through usermeta (the fast-read
			// cache MemberTypeService maintains on every assign).
			$where_clauses[] = "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} um_mtype WHERE um_mtype.user_id = u.ID AND um_mtype.meta_key = 'bn_member_type' AND um_mtype.meta_value = %s )";
			$params[]        = $member_type;
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
				        {$follower_count_subquery}
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

		// Compute mutual connection counts post-query in a single batch to avoid
		// the MySQL 5.7 "Can't reopen table" error that would occur if bn_connections
		// were referenced twice in correlated SELECT-list subqueries.
		$mutual_counts = array();
		if ( $viewer_id_safe > 0 && ! empty( $user_ids ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$viewer_peer_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT CASE WHEN c.requester_id = %d THEN c.recipient_id ELSE c.requester_id END
					 FROM {$wpdb->prefix}bn_connections c
					 WHERE c.status = 'accepted'
					   AND ( c.requester_id = %d OR c.recipient_id = %d )",
					$viewer_id_safe,
					$viewer_id_safe,
					$viewer_id_safe
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $viewer_peer_ids ) ) {
				$uid_in  = implode( ',', array_map( 'intval', $user_ids ) );
				$peer_in = implode( ',', array_map( 'intval', $viewer_peer_ids ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$m_rows = $wpdb->get_results(
					"SELECT CASE WHEN c.requester_id IN ({$uid_in}) THEN c.requester_id
					            ELSE c.recipient_id END AS target_id,
					        COUNT(*) AS cnt
					 FROM {$wpdb->prefix}bn_connections c
					 WHERE c.status = 'accepted'
					   AND ( c.requester_id IN ({$uid_in}) OR c.recipient_id IN ({$uid_in}) )
					   AND ( CASE WHEN c.requester_id IN ({$uid_in})
					             THEN c.recipient_id
					             ELSE c.requester_id END ) IN ({$peer_in})
					 GROUP BY target_id",
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( (array) $m_rows as $mr ) {
					$mutual_counts[ (int) $mr['target_id'] ] = (int) $mr['cnt'];
				}
			}
		}

		$blocks = buddynext_service( 'blocks' );
		$items  = array_map(
			static function ( $r ) use ( $mutual_counts, $viewer_id, $blocks ) {
				$uid = (int) $r['ID'];
				$bio = get_user_meta( $uid, 'bn_field_bio', true );
				return array(
					'user_id'                 => $uid,
					'display_name'            => $r['display_name'],
					'avatar_url'              => get_avatar_url( $uid, array( 'size' => 96 ) ),
					'registered_at'           => $r['user_registered'],
					'bio'                     => $bio ? $bio : '',
					'is_online'               => $blocks->is_user_online( $viewer_id, $uid ),
					'follower_count'          => (int) $r['follower_count'],
					'mutual_connection_count' => $mutual_counts[ $uid ] ?? 0,
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
	 * Return user IDs whose name, login, email, or any privacy-safe searchable
	 * field mirror matches a free-text term.
	 *
	 * Shares the exact match surface used by list_members() so the
	 * server-rendered directory page (templates/directory/members.php, which
	 * builds a WP_User_Query) gets the same dynamic, privacy-aware search as the
	 * REST/live path — no duplicate matching logic, no mirror search for
	 * private/tightened values (their mirrors are absent by contract).
	 *
	 * @param string $term Search term.
	 * @return int[] Matching user IDs (empty array when the term is blank or matches nothing).
	 */
	public function matching_user_ids( string $term ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array();
		}

		global $wpdb;

		$like   = '%' . $wpdb->esc_like( $term ) . '%';
		$ors    = array( 'u.display_name LIKE %s', 'u.user_login LIKE %s', 'u.user_email LIKE %s' );
		$params = array( $like, $like, $like );

		foreach ( $this->searchable_mirror_keys() as $meta_key ) {
			$ors[]    = "EXISTS ( SELECT 1 FROM {$wpdb->usermeta} ums WHERE ums.user_id = u.ID AND ums.meta_key = %s AND ums.meta_value LIKE %s )";
			$params[] = $meta_key;
			$params[] = $like;
		}

		// Honor the directory opt-out here too so the server-rendered directory
		// search (WP_User_Query built from these IDs) never surfaces a member
		// who turned off "Show me in the member directory". Default-visible:
		// only an explicit '0' excludes; an absent meta leaves the member found.
		$dir_optout = "NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} um_dir WHERE um_dir.user_id = u.ID AND um_dir.meta_key = 'bn_privacy_show_in_directory' AND um_dir.meta_value = '0' )";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT u.ID FROM {$wpdb->users} u WHERE ( " . implode( ' OR ', $ors ) . " ) AND {$dir_optout}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Build the list of `bn_field_{key}` usermeta keys whose mirrors are
	 * eligible for free-text directory search.
	 *
	 * A field qualifies only when it is flagged searchable by the admin AND its
	 * type is free-text searchable per FieldType::is_text_searchable(). The
	 * resulting mirror rows are written by ProfileService exclusively for values
	 * whose effective visibility resolves to public (searchable_mirror contract),
	 * so matching them carries no privacy risk and needs no per-row checks.
	 *
	 * The list is memoised per request and cached for 5 minutes to avoid a field
	 * definition lookup on every directory query.
	 *
	 * @return string[] Usermeta keys, e.g. array( 'bn_field_skills', 'bn_field_role' ).
	 */
	private function searchable_mirror_keys(): array {
		static $keys = null;

		if ( null !== $keys ) {
			return $keys;
		}

		$cached = wp_cache_get( 'bn_dir_searchable_mirrors', 'buddynext' );
		if ( is_array( $cached ) ) {
			$keys = $cached;
			return $keys;
		}

		$keys     = array();
		$profiles = buddynext_service( 'profiles' );

		if ( is_object( $profiles ) && method_exists( $profiles, 'get_fields' ) ) {
			// get_fields() returns GROUPS, each with a nested 'fields' array —
			// flatten to the field rows (which carry field_key + is_searchable).
			$fields = array();
			foreach ( (array) $profiles->get_fields() as $grp ) {
				if ( is_array( $grp ) && isset( $grp['fields'] ) && is_array( $grp['fields'] ) ) {
					foreach ( $grp['fields'] as $gf ) {
						$fields[] = $gf;
					}
				} elseif ( is_array( $grp ) && isset( $grp['field_key'] ) ) {
					$fields[] = $grp; // Defensive: already a flat field row.
				}
			}

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				if ( empty( $field['is_searchable'] ) ) {
					continue;
				}

				$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
				if ( ! FieldType::is_text_searchable( $type ) ) {
					continue;
				}

				$field_key = isset( $field['field_key'] ) ? sanitize_key( (string) $field['field_key'] ) : '';
				if ( '' === $field_key ) {
					continue;
				}

				$keys[] = 'bn_field_' . $field_key;
			}

			$keys = array_values( array_unique( $keys ) );
		}

		wp_cache_set( 'bn_dir_searchable_mirrors', $keys, 'buddynext', 300 );

		return $keys;
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
