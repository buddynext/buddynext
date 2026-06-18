<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Bookmark service.
 *
 * Stores and removes private saved-post entries in bn_bookmarks.
 * Bookmarks are user-private: only the bookmarking user can see them.
 * Cache group: buddynext_bookmarks, TTL: 10 min.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

/**
 * Manages private post bookmarks.
 */
class BookmarkService {

	/**
	 * Cache group for bookmark lists.
	 */
	private const CACHE_GROUP = 'buddynext_bookmarks';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Save a post to the user's bookmarks.
	 *
	 * Silently ignores duplicate bookmarks (INSERT IGNORE).
	 *
	 * @param int $user_id User saving the post.
	 * @param int $post_id Post to save.
	 */
	public function bookmark( int $user_id, int $post_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_bookmarks (user_id, post_id)
				 VALUES (%d, %d)",
				$user_id,
				$post_id
			)
		);
		$inserted = $wpdb->rows_affected > 0;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "bookmarks_{$user_id}", self::CACHE_GROUP );

		if ( $inserted ) {
			/**
			 * Fires after a post is bookmarked.
			 *
			 * Only fires on first-time bookmark. Duplicate bookmark calls
			 * (INSERT IGNORE no-ops) do not re-fire the event.
			 *
			 * @param int $post_id Post that was bookmarked.
			 * @param int $user_id User who bookmarked the post.
			 */
			do_action( 'buddynext_post_bookmarked', $post_id, $user_id );
		}
	}

	/**
	 * Remove a post from the user's bookmarks.
	 *
	 * @param int $user_id User removing the bookmark.
	 * @param int $post_id Post to unsave.
	 */
	public function unbookmark( int $user_id, int $post_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_bookmarks',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
			),
			array( '%d', '%d' )
		);
		$deleted = $wpdb->rows_affected > 0;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "bookmarks_{$user_id}", self::CACHE_GROUP );

		if ( $deleted ) {
			/**
			 * Fires after a bookmark is removed.
			 *
			 * Only fires when a row was actually deleted. Calling unbookmark
			 * on a post that was not bookmarked is a silent no-op.
			 *
			 * @param int $post_id Post that was unbookmarked.
			 * @param int $user_id User who removed the bookmark.
			 */
			do_action( 'buddynext_post_unbookmarked', $post_id, $user_id );
		}
	}

	/**
	 * Check whether the user has bookmarked a post.
	 *
	 * @param int $user_id User to check.
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	public function is_bookmarked( int $user_id, int $post_id ): bool {
		return in_array( $post_id, $this->user_bookmarks( $user_id ), true );
	}

	/**
	 * Return the list of post IDs bookmarked by the user (newest first).
	 *
	 * @param int $user_id User whose bookmarks to retrieve.
	 * @return int[]
	 */
	public function user_bookmarks( int $user_id ): array {
		global $wpdb;

		$cache_key = "bookmarks_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}bn_bookmarks
				 WHERE user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array_map( 'intval', (array) $rows );
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return a cursor-paginated list of the user's bookmarked posts, hydrated
	 * and visibility-filtered for the viewer.
	 *
	 * BookmarkService stores raw post_id rows (not denormalised visibility), so
	 * the post-privacy gates are re-applied at read time through
	 * PostService::filter_visible() — unfollowing an author or losing space
	 * membership immediately hides the bookmarked post here. Deleted and
	 * non-published posts drop out at hydrate time.
	 *
	 * The cursor follows the same created_at|id keyset pattern as
	 * NotificationService::list_for_user(), keyed on the bookmark row's
	 * created_at and the post_id tiebreaker (bn_bookmarks has a composite
	 * (user_id, post_id) primary key and no surrogate id column).
	 *
	 * @param int         $user_id  Viewing user whose bookmarks to list.
	 * @param string|null $cursor   Opaque pagination cursor.
	 * @param int         $per_page Bookmarks per page (max 50).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function user_bookmarks_paged( int $user_id, ?string $cursor = null, int $per_page = 15 ): array {
		global $wpdb;

		$per_page      = max( 1, min( $per_page, 50 ) );
		$cursor_data   = $this->decode_cursor( $cursor );
		$cursor_where  = '';
		$cursor_params = array();

		if ( null !== $cursor_data ) {
			$cursor_where  = 'AND (b.created_at < %s OR (b.created_at = %s AND b.post_id < %d))';
			$cursor_params = array( $cursor_data['created_at'], $cursor_data['created_at'], $cursor_data['post_id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.created_at AS bookmark_created_at, b.post_id
				   FROM {$wpdb->prefix}bn_bookmarks b
				  WHERE b.user_id = %d
				  {$cursor_where}
				  ORDER BY b.created_at DESC, b.post_id DESC
				  LIMIT %d",
				...array_merge( array( $user_id ), $cursor_params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = base64_encode( $last['bookmark_created_at'] . '|' . $last['post_id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Re-apply the canonical post-visibility gate, then hydrate the survivors
		// in the bookmark order. filter_visible() keeps only published posts the
		// viewer may see (blocks, secret-space, followers-only, private, author
		// suspension/shadow-ban).
		$post_ids = array_map( static fn ( array $r ): int => (int) $r['post_id'], $rows );
		$post_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'post_service' ) : new PostService();
		$visible_ids  = $post_service->filter_visible( $post_ids, $user_id );

		$items = array();
		foreach ( $post_ids as $pid ) {
			if ( ! in_array( $pid, $visible_ids, true ) ) {
				continue;
			}
			$post = $post_service->get( $pid );
			if ( null !== $post ) {
				$items[] = $post;
			}
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}

	/**
	 * Decode a bookmark cursor string into its created_at + post_id parts.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array{created_at: string, post_id: int}|null
	 */
	private function decode_cursor( ?string $cursor ): ?array {
		if ( null === $cursor || '' === $cursor ) {
			return null;
		}

		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) ) {
			return null;
		}

		return array(
			'created_at' => $parts[0],
			'post_id'    => (int) $parts[1],
		);
	}
}
