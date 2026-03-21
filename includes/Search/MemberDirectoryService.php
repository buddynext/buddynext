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
	 * @param int         $viewer_id ID of the viewing user (excluded from results).
	 * @param string|null $cursor    Opaque pagination cursor from a previous page.
	 * @param int         $per_page  Number of members per page (max 50).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function list_members( int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page    = min( $per_page, 50 );
		$cursor_data = $this->decode_cursor( $cursor );

		$cursor_where  = '';
		$cursor_params = array();

		if ( null !== $cursor_data ) {
			$cursor_where  = 'AND (u.user_registered < %s OR (u.user_registered = %s AND u.ID < %d))';
			$cursor_params = array( $cursor_data['registered_at'], $cursor_data['registered_at'], $cursor_data['id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name, u.user_registered
				 FROM {$wpdb->users} u
				 WHERE u.ID != %d
				   {$cursor_where}
				 ORDER BY u.user_registered DESC, u.ID DESC
				 LIMIT %d",
				...array_merge( array( $viewer_id ), $cursor_params, array( $per_page + 1 ) )
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
