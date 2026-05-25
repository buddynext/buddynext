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
}
