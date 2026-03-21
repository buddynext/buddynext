<?php
/**
 * Feed aggregation and pagination service.
 *
 * Builds the home, profile, and explore feeds using cursor-based pagination.
 * The cursor encodes the created_at datetime and post id of the last seen item
 * so that new posts inserted between pages do not cause duplicates or gaps.
 *
 * Home-feed privacy rules (Phase 3 — spaces and hashtags deferred):
 *   - public or followers posts from followed users are shown.
 *   - The viewer's own posts (any privacy) are always included.
 *   - Scheduled posts (scheduled_at in the future) are excluded.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\SocialGraph\FollowService;

/**
 * Aggregates posts into paginated feed responses.
 */
class FeedService {

	/**
	 * Default posts per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Follow graph service — used to resolve the home-feed author list.
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
	 * Return the home feed for the given user.
	 *
	 * @param int         $user_id     Viewing user ID.
	 * @param string|null $cursor      Opaque pagination cursor from a previous response.
	 * @param int         $per_page    Number of posts to return (max 50).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function home_feed( int $user_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page   = min( $per_page, 50 );
		$author_ids = array_merge( array( $user_id ), $this->follows->following( $user_id ) );

		if ( empty( $author_ids ) ) {
			return array(
				'items'       => array(),
				'next_cursor' => null,
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $author_ids ), '%d' ) );
		$cursor_where = $this->cursor_where( $cursor );

		// $placeholders is built via array_fill('%d') — only integers, safe.
		// $cursor_where is either '' or the single hardcoded SQL constant — safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE user_id IN ({$placeholders})
			   AND (
			         (user_id = %d)
			         OR (privacy IN ('public','followers'))
			       )
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( $author_ids, array( $user_id ), $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->paginate( (array) $rows, $per_page );
	}

	/**
	 * Return the profile feed for a given user as seen by a viewer.
	 *
	 * @param int         $profile_user_id  User whose posts to show.
	 * @param int         $viewer_id        Viewing user ID (0 = anonymous).
	 * @param string|null $cursor           Pagination cursor.
	 * @param int         $per_page         Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function profile_feed( int $profile_user_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page = min( $per_page, 50 );

		if ( $viewer_id === $profile_user_id ) {
			// Owner sees everything.
			$privacy_clause = '';
			$privacy_params = array();
		} else {
			// Others see only public posts.
			$privacy_clause = "AND privacy = 'public'";
			$privacy_params = array();
		}

		$cursor_where = $this->cursor_where( $cursor );

		// $privacy_clause is a hardcoded SQL constant — safe.
		// $cursor_where is either '' or the single hardcoded SQL constant — safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE user_id = %d
			   {$privacy_clause}
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( array( $profile_user_id ), $privacy_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->paginate( (array) $rows, $per_page );
	}

	/**
	 * Return the public explore feed (all public posts, newest first).
	 *
	 * @param string|null $cursor   Pagination cursor.
	 * @param int         $per_page Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function explore_feed( ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page     = min( $per_page, 50 );
		$cursor_where = $this->cursor_where( $cursor );

		// $cursor_where is either '' or the single hardcoded SQL constant — safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE privacy = 'public'
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->paginate( (array) $rows, $per_page );
	}

	/**
	 * Encode a cursor from the last item in a page.
	 *
	 * Cursor format: base64( "{created_at}|{id}" ).
	 *
	 * @param array $row A hydrated post row.
	 * @return string Opaque cursor string.
	 */
	public function encode_cursor( array $row ): string {
		return base64_encode( $row['created_at'] . '|' . $row['id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a cursor into its component parts.
	 *
	 * @param string $cursor Opaque cursor.
	 * @return array{created_at: string, id: int}|null Null if cursor is invalid.
	 */
	private function decode_cursor( string $cursor ): ?array {
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

	/**
	 * Build the WHERE fragment for cursor-based pagination.
	 *
	 * Returns an empty string when no cursor is given (first page).
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return string SQL fragment (already safe to embed — placeholders handled separately).
	 */
	private function cursor_where( ?string $cursor ): string {
		if ( null === $cursor ) {
			return '';
		}

		$decoded = $this->decode_cursor( $cursor );
		if ( null === $decoded ) {
			return '';
		}

		return 'AND (created_at < %s OR (created_at = %s AND id < %d))';
	}

	/**
	 * Return the ordered parameter values for cursor_where placeholders.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array
	 */
	private function cursor_params( ?string $cursor ): array {
		if ( null === $cursor ) {
			return array();
		}

		$decoded = $this->decode_cursor( $cursor );
		if ( null === $decoded ) {
			return array();
		}

		return array( $decoded['created_at'], $decoded['created_at'], $decoded['id'] );
	}

	/**
	 * Slice the result set and build a paginated response.
	 *
	 * Fetches $per_page + 1 rows to detect whether a next page exists, then
	 * trims to $per_page and encodes the cursor from the last item.
	 *
	 * @param array $rows     Raw rows from wpdb (ARRAY_A).
	 * @param int   $per_page Page size.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	private function paginate( array $rows, int $per_page ): array {
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = array_map(
			fn( $row ) => array(
				'id'         => (int) $row['id'],
				'user_id'    => (int) $row['user_id'],
				'type'       => $row['type'],
				'content'    => $row['content'],
				'privacy'    => $row['privacy'],
				'created_at' => $row['created_at'],
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
}
