<?php
/**
 * Member directory service.
 *
 * Returns a cursor-paginated list of WordPress users for the member directory.
 * Each item includes core WP fields (display_name, avatar_url, registered_at).
 * The viewer is excluded from results. Cursor encodes the user_registered
 * datetime and user ID of the last seen item — the same pattern used by
 * FeedService — so new registrations between pages do not cause gaps.
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
	 *   'location'   (string) — LIKE match against the `bn_field_location` usermeta key.
	 *   'online_only' (bool)  — restrict to users active within the last 5 minutes
	 *                           (reads `bn_last_active` usermeta, stored as a Unix timestamp).
	 *   'sort'       (string) — 'newest' (default), 'alphabetical', 'most_active', or
	 *                           'online' (alias: implies online_only + most_active order).
	 *
	 * @param int         $viewer_id ID of the viewing user (excluded from results).
	 * @param string|null $cursor    Opaque pagination cursor from a previous page.
	 * @param int         $per_page  Number of members per page (max 50).
	 * @param array       $filters   Optional associative filter/sort options.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function list_members( int $viewer_id = 0, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, array $filters = array() ): array {
		global $wpdb;

		$per_page    = min( $per_page, 50 );
		$cursor_data = $this->decode_cursor( $cursor );

		// Normalise filter values.
		$location    = isset( $filters['location'] ) ? trim( (string) $filters['location'] ) : '';
		$online_only = ! empty( $filters['online_only'] );
		$sort        = isset( $filters['sort'] ) ? (string) $filters['sort'] : 'newest';

		// 'online' sort implies online_only filtering as well.
		if ( 'online' === $sort ) {
			$online_only = true;
		}

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

		// Online-only JOIN — usermeta row for bn_last_active.
		if ( $online_only || 'online' === $sort || 'most_active' === $sort ) {
			$joins[] = "LEFT JOIN {$wpdb->usermeta} AS um_active
			            ON um_active.user_id = u.ID
			            AND um_active.meta_key = 'bn_last_active'";
		}

		$join_sql = $joins ? ( "\n" . implode( "\n", $joins ) ) : '';

		// ------------------------------------------------------------------ //
		// Build WHERE clauses.
		// ------------------------------------------------------------------ //

		$where_clauses = array( 'u.ID != %d' );

		if ( '' !== $location ) {
			$where_clauses[] = 'um_loc.meta_value LIKE %s';
			$params[]        = '%' . $wpdb->esc_like( $location ) . '%';
		}

		if ( $online_only ) {
			$where_clauses[] = 'CAST(um_active.meta_value AS UNSIGNED) > UNIX_TIMESTAMP() - 300';
		}

		// Cursor WHERE — only valid for 'newest' sort (datetime-based cursor).
		// For other sort modes the cursor is omitted to avoid cross-sort confusion.
		if ( null !== $cursor_data && 'newest' === $sort ) {
			$where_clauses[] = '(u.user_registered < %s OR (u.user_registered = %s AND u.ID < %d))';
			$params[]        = $cursor_data['registered_at'];
			$params[]        = $cursor_data['registered_at'];
			$params[]        = $cursor_data['id'];
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
				"SELECT u.ID, u.display_name, u.user_registered
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

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = array_map(
			fn( $r ) => array(
				'user_id'       => (int) $r['ID'],
				'display_name'  => $r['display_name'],
				'avatar_url'    => get_avatar_url( (int) $r['ID'], array( 'size' => 96 ) ),
				'registered_at' => $r['user_registered'],
			),
			$rows
		);

		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = $this->encode_cursor( $last );
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Encode a cursor from the last row in a page.
	 *
	 * @param array $row Row with ID and user_registered keys.
	 * @return string Opaque base64 cursor.
	 */
	private function encode_cursor( array $row ): string {
		return base64_encode( $row['user_registered'] . '|' . $row['ID'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a cursor string into its component parts.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array{registered_at: string, id: int}|null Null if invalid.
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
			'registered_at' => $parts[0],
			'id'            => (int) $parts[1],
		);
	}
}
