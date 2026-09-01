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
	 * Ceiling on the full "every id I ever saved" list. Filterable.
	 */
	private const LIST_CAP = 1000;

	/**
	 * Per-request memo for bookmarked_among(), keyed "userId:postId".
	 *
	 * A class property rather than a function static SPECIFICALLY so that
	 * writing a bookmark can invalidate it. As a function static it could not
	 * be: bookmark() cleared the object cache but had no way to reach the memo,
	 * so anything that wrote a bookmark and then asked about it IN THE SAME
	 * REQUEST got the stale answer from before the write.
	 *
	 * No member-facing surface did that (the REST handler returns a fixed
	 * `bookmarked: true` rather than reading back), which is why it went
	 * unnoticed - but the BuddyPress bookmark importer does exactly this to
	 * confirm each row landed, and every successful import was reported as
	 * refused while the rows were in fact written.
	 *
	 * @var array<string,bool>
	 */
	private static array $memo = array();

	/**
	 * Forget the memoised answer for one member/post pair.
	 *
	 * Called by both writers, so the pair is re-read from the table on the next
	 * question rather than answered from before the write.
	 *
	 * @param int $user_id Member.
	 * @param int $post_id Post.
	 * @return void
	 */
	private static function forget( int $user_id, int $post_id ): void {
		unset( self::$memo[ $user_id . ':' . $post_id ] );
	}

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
		self::forget( $user_id, $post_id );

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
		self::forget( $user_id, $post_id );

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
		// A one-row PK lookup, not "load every bookmark this member has ever made and
		// then in_array() through it" — which is what asking user_bookmarks() meant.
		return ! empty( $this->bookmarked_among( $user_id, array( $post_id ) ) );
	}

	/**
	 * Which of THESE posts has the user bookmarked?
	 *
	 * The only question the feed ever needs to answer, and the only one that scales.
	 * It is bounded by the page (20-50 ids), and it is a straight index range scan on
	 * the PRIMARY KEY (user_id, post_id) — no ORDER BY, so no filesort, and the row
	 * count is independent of how many bookmarks the member has.
	 *
	 * The feed used to answer it by loading the member's ENTIRE bookmark history
	 * (`SELECT post_id … ORDER BY created_at DESC`, no LIMIT) and doing the matching
	 * in PHP. That query filesorts — bn_bookmarks has no created_at index, its PK is
	 * (user_id, post_id) — and it ran on EVERY feed paint. A member with years of
	 * saved posts paid for all of them to render twenty.
	 *
	 * Memoised per request, so several enrichment passes over the same page cost one
	 * query. (wp_cache alone is not enough: the target deployment has no persistent
	 * object cache, so wp_cache_get is a no-op across requests.)
	 *
	 * @param int   $user_id  Viewer.
	 * @param int[] $post_ids The posts on this page.
	 * @return array<int,bool> post_id => true, for the bookmarked ones only. O(1) lookup.
	 */
	public function bookmarked_among( int $user_id, array $post_ids ): array {
		$user_id  = absint( $user_id );
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( $user_id <= 0 || empty( $post_ids ) ) {
			return array();
		}

		$out     = array();
		$missing = array();
		foreach ( $post_ids as $pid ) {
			$k = $user_id . ':' . $pid;
			if ( isset( self::$memo[ $k ] ) ) {
				if ( self::$memo[ $k ] ) {
					$out[ $pid ] = true;
				}
			} else {
				$missing[] = $pid;
			}
		}

		if ( empty( $missing ) ) {
			return $out;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}bn_bookmarks
				 WHERE user_id = %d AND post_id IN ( {$placeholders} )",
				array_merge( array( $user_id ), $missing )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$found = array_flip( array_map( 'intval', (array) $rows ) );

		foreach ( $missing as $pid ) {
			$hit                                 = isset( $found[ $pid ] );
			self::$memo[ $user_id . ':' . $pid ] = $hit;
			if ( $hit ) {
				$out[ $pid ] = true;
			}
		}

		return $out;
	}

	/**
	 * One page of the member's bookmarks, newest first, paged IN SQL.
	 *
	 * For the bookmarks hub — the one surface that genuinely wants them in order.
	 * LIMIT/OFFSET on an index (user_id, created_at); the caller gets a total from
	 * count_bookmarks() rather than by measuring an array it had to build first.
	 *
	 * The controller used to fetch every id and `array_slice()` page 1 out of it,
	 * which is the same unbounded read wearing a pagination costume.
	 *
	 * @param int $user_id  Owner (bookmarks are private).
	 * @param int $per_page Rows to return.
	 * @param int $offset   Rows to skip.
	 * @return int[] Post IDs, newest-bookmarked first.
	 */
	public function paged_bookmarks( int $user_id, int $per_page, int $offset = 0 ): array {
		global $wpdb;

		$user_id  = absint( $user_id );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = max( 0, $offset );

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}bn_bookmarks
				 WHERE user_id = %d
				 ORDER BY created_at DESC, post_id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * How many posts the member has bookmarked.
	 *
	 * A COUNT(*), not count( user_bookmarks() ) — never build a list to measure it.
	 *
	 * @param int $user_id Owner.
	 * @return int
	 */
	public function count_bookmarks( int $user_id ): int {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_bookmarks WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Every post ID the user has bookmarked, newest first.
	 *
	 * UNBOUNDED — it loads the member's whole bookmark history and filesorts it. Do
	 * NOT call this on a render path. It exists for the native app's "give me all my
	 * saved ids" REST shape and for callers that genuinely need the full set.
	 *
	 * If you want to know whether the posts ON THIS PAGE are bookmarked, use
	 * bookmarked_among(). If you want to show them in order, use paged_bookmarks().
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
		// Capped. This is the native app's "all my saved ids" shape, so it is self-scoped and
		// not on any render path (render uses bookmarked_among() / paged_bookmarks()) — but an
		// unbounded SELECT is still an unbounded SELECT, and a member who has been saving posts
		// for five years is the one who gets to find out.
		//
		// The cap is filterable rather than paginated, deliberately: the contract of this
		// method is "the whole set", and paginating it would silently change that into "some of
		// the set" for every caller. A ceiling with a loud _doing_it_wrong() is honest; a quiet
		// truncation is the bug this exists to avoid.
		$cap = (int) apply_filters( 'buddynext_bookmark_list_cap', self::LIST_CAP, $user_id );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}bn_bookmarks
				 WHERE user_id = %d
				 ORDER BY created_at DESC, post_id DESC
				 LIMIT %d",
				$user_id,
				$cap
			)
		);

		if ( count( (array) $rows ) === $cap ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %d: the bookmark list cap. */
						__( 'A member has hit the %d-bookmark ceiling for the full-list shape. Use paged_bookmarks() for anything that renders.', 'buddynext' ),
						$cap
					)
				),
				'1.0.8'
			);
		}
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
	 * @param int         $per_page Bookmarks per page (ceiling: FeedService::MAX_PER_PAGE).
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function user_bookmarks_paged( int $user_id, ?string $cursor = null, int $per_page = 15 ): array {
		global $wpdb;

		// Shares the feed ceiling because it shares the feed's "Load more": the
		// bookmarks page re-renders cumulatively (?shown=15, 30, 45 ...) exactly
		// like the activity feed, so a lower private limit here would have cut
		// the same surface off at a different, equally invisible point.
		$per_page      = max( 1, min( $per_page, FeedService::MAX_PER_PAGE ) );
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
		$post_ids     = array_map( static fn ( array $r ): int => (int) $r['post_id'], $rows );
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
