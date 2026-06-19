<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PSR-4 naming; all queries use custom bn_* tables.
/**
 * Hashtag service.
 *
 * Extracts hashtags from content, keeps the bn_hashtags registry up to date,
 * and maintains the bn_post_hashtags link table. Re-syncing a post replaces
 * the old tag set with the new one (old links deleted, new ones inserted).
 *
 * @package BuddyNext\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Hashtags;

use BuddyNext\Core\CursorCodec;

/**
 * Handles hashtag extraction, sync, and lookup.
 */
class HashtagService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_hashtags';

	/**
	 * Object-cache TTL in seconds (within-request + persistent object cache).
	 */
	private const CACHE_TTL = 300;

	/**
	 * Transient TTL for trending data.
	 *
	 * On sites without a persistent object cache (Memcached / Redis), the
	 * in-memory wp_cache hits only last for the duration of one PHP request.
	 * A transient ensures the expensive trending JOIN is skipped for ~30 min
	 * across requests on any installation, replacing the old 30-min cron.
	 */
	private const TRENDING_TRANSIENT_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Extract unique hashtag slugs from a string (spec-named alias for extract()).
	 *
	 * @param string $text Raw text that may contain #tags.
	 * @return string[] Lowercased, deduplicated array of tag slugs (no leading #).
	 */
	public function extract_from_text( string $text ): array {
		return $this->extract( $text );
	}

	/**
	 * Parse the buddynext_banned_hashtags option into a list of normalized slugs.
	 *
	 * The option is a newline-separated textarea string, so the previous
	 * `(array) get_option(...)` cast produced a single-element array containing
	 * the whole blob — meaning the ban check only ever matched when exactly one
	 * tag was configured. Split on newlines and normalize each entry the same way
	 * tags themselves are (strip a leading #, sanitize_key) so matching is
	 * case-insensitive and #-agnostic.
	 *
	 * @return array<int,string> Normalized banned slugs.
	 */
	private function banned_hashtag_slugs(): array {
		$raw = (string) get_option( 'buddynext_banned_hashtags', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$slugs = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) ?: array() as $line ) {
			$slug = sanitize_key( ltrim( trim( (string) $line ), '#' ) );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Ensure a hashtag exists and return its ID (spec-named registration method).
	 *
	 * If the hashtag already exists the existing row ID is returned. If not, a
	 * new row is created. Returns 0 when the slug is empty or banned.
	 *
	 * @param string $tag Hashtag slug (with or without leading #).
	 * @return int Hashtag ID, or 0 on failure.
	 */
	public function register( string $tag ): int {
		$slug = sanitize_key( ltrim( $tag, '#' ) );

		if ( '' === $slug ) {
			return 0;
		}

		$banned = $this->banned_hashtag_slugs();
		if ( in_array( $slug, $banned, true ) ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_hashtags (name, slug, created_at)
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE name = VALUES(name)",
				$slug,
				$slug,
				current_time( 'mysql', true )
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hashtag_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s",
				$slug
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		if ( $hashtag_id > 0 ) {
			wp_cache_delete( "hashtag_{$slug}", self::CACHE_GROUP );
		}

		return $hashtag_id;
	}

	/**
	 * Return the top trending hashtags (spec-named alias for get_trending()).
	 *
	 * Trending data is computed lazily on the first read within each cache window
	 * (transient ~30 min) rather than by a recurring cron job. The computation
	 * uses a live JOIN on bn_post_hashtags with a 24-hour rolling window, so the
	 * result reflects actual recent activity regardless of the post_count column.
	 *
	 * @param int $limit Maximum number to return (1–50). Default 10.
	 * @return array[]
	 */
	public function trending( int $limit = 10 ): array {
		return $this->get_trending( $limit );
	}

	/**
	 * Return paginated public posts associated with a hashtag.
	 *
	 * Only public, published BuddyNext feed posts are surfaced here — the spec
	 * states that followers-only/private posts must not appear in hashtag feeds.
	 *
	 * @param string $tag  Hashtag slug (without #).
	 * @param array  $args Optional args:
	 *                     per_page (int, max 50),
	 *                     cursor (string),
	 *                     sort ('latest' default | 'top' | 'following'),
	 *                     viewer_id (int) — required for the 'following' sort,
	 *                       which restricts results to posts whose author the
	 *                       viewer follows.
	 * @return array{items: array[], next_cursor: string|null, hashtag: array|null}
	 */
	public function get_feed( string $tag, array $args = array() ): array {
		$slug    = sanitize_key( ltrim( $tag, '#' ) );
		$hashtag = $this->get_by_slug( $slug );

		if ( null === $hashtag ) {
			return array(
				'items'       => array(),
				'next_cursor' => null,
				'hashtag'     => null,
			);
		}

		$per_page  = min( (int) ( $args['per_page'] ?? 20 ), 50 );
		$cursor    = ( isset( $args['cursor'] ) && is_string( $args['cursor'] ) ) ? $args['cursor'] : null;
		$sort      = in_array( $args['sort'] ?? 'latest', array( 'latest', 'top', 'following' ), true ) ? (string) $args['sort'] : 'latest';
		$viewer_id = isset( $args['viewer_id'] ) ? (int) $args['viewer_id'] : 0;

		// SCALE-CONTRACT §2: keyset cursor, never OFFSET. Key on the pivot's
		// (hashtag_id, created_at) index so every page is O(per_page) regardless
		// of depth. has_more is derived from a per_page+1 fetch, so no COUNT(*)
		// runs on the read path (SCALE-CONTRACT §3). The cursor is always the
		// recency keyset; 'top' re-orders within that window rather than paging
		// on a score, keeping the keyset stable across sorts.
		$cursor_where  = '';
		$cursor_params = array();
		$decoded       = ( null !== $cursor ) ? $this->decode_feed_cursor( $cursor ) : null;
		if ( null !== $decoded ) {
			$cursor_where  = ' AND ( ph.created_at < %s OR ( ph.created_at = %s AND ph.post_id < %d ) )';
			$cursor_params = array( $decoded['created_at'], $decoded['created_at'], $decoded['id'] );
		}

		// ORDER BY: 'top' ranks by engagement first (still tie-broken on the
		// recency keyset so pagination is deterministic); 'latest'/'following'
		// page purely by recency.
		$order_sql = ( 'top' === $sort )
			? '( p.reaction_count + p.comment_count + p.share_count ) DESC, ph.created_at DESC, ph.post_id DESC'
			: 'ph.created_at DESC, ph.post_id DESC';

		global $wpdb;

		$posts_tbl = $wpdb->prefix . 'bn_posts';
		$pivot_tbl = $wpdb->prefix . 'bn_post_hashtags';

		// 'following' restricts to posts whose author the viewer follows; with no
		// viewer (guest) it degrades to the latest feed rather than an empty list.
		$follow_join  = '';
		$follow_param = array();
		if ( 'following' === $sort && $viewer_id > 0 ) {
			$follow_join  = " INNER JOIN {$wpdb->prefix}bn_follows f ON f.following_id = p.user_id AND f.follower_id = %d AND f.status = 'approved'";
			$follow_param = array( $viewer_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.content, p.type, p.privacy,
				        p.reaction_count, p.comment_count, p.share_count, p.created_at,
				        ph.created_at AS bn_cursor_ts
				 FROM {$pivot_tbl} ph
				 INNER JOIN {$posts_tbl} p ON p.id = ph.post_id
				 {$follow_join}
				 WHERE ph.hashtag_id  = %d
				   AND ph.object_type = 'post'
				   AND p.status       = 'published'
				   AND p.privacy      = 'public'
				   {$cursor_where}
				 ORDER BY {$order_sql}
				 LIMIT %d",
				...array_merge( $follow_param, array( $hashtag['id'] ), $cursor_params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows        = (array) $rows;
		$next_cursor = null;
		if ( count( $rows ) > $per_page ) {
			$rows        = array_slice( $rows, 0, $per_page );
			$last        = $rows[ count( $rows ) - 1 ];
			$next_cursor = $this->encode_feed_cursor( (string) $last['bn_cursor_ts'], (int) $last['id'] );
		}

		// Strip the internal cursor column from the returned items.
		foreach ( $rows as &$bn_row ) {
			unset( $bn_row['bn_cursor_ts'] );
		}
		unset( $bn_row );

		return array(
			'items'       => $rows,
			'next_cursor' => $next_cursor,
			'hashtag'     => $hashtag,
		);
	}

	/**
	 * Encode a keyset cursor for the hashtag feed.
	 *
	 * @param string $created_at Pivot row timestamp of the last returned post.
	 * @param int    $post_id    Post ID of the last returned post.
	 * @return string Opaque base64 cursor.
	 */
	private function encode_feed_cursor( string $created_at, int $post_id ): string {
		return CursorCodec::encode( $created_at, $post_id );
	}

	/**
	 * Decode a hashtag-feed keyset cursor.
	 *
	 * @param string $cursor Opaque cursor produced by encode_feed_cursor().
	 * @return array{created_at: string, id: int}|null Null when malformed.
	 */
	private function decode_feed_cursor( string $cursor ): ?array {
		return CursorCodec::decode( $cursor );
	}

	/**
	 * Extract unique hashtag slugs from a string.
	 *
	 * @param string $content Raw content that may contain #tags.
	 * @return string[] Lowercased, deduplicated array of tag slugs (no leading #).
	 */
	public function extract( string $content ): array {
		/**
		 * Filter the regex used to extract hashtags from content.
		 *
		 * Must return a valid PCRE pattern with a single capture group (group 1)
		 * yielding the hashtag slug without the leading '#'. Default supports a
		 * letter-first, alphanumeric/underscore slug up to 50 chars. Applies to
		 * every extraction surface (posts, bridged forum/media/job content).
		 *
		 * @param string $pattern Default extraction regex.
		 */
		$pattern = (string) apply_filters( 'buddynext_hashtag_pattern', '/#([\p{L}][\p{L}\p{N}_]{0,49})/u' );
		preg_match_all( $pattern, $content, $matches );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$slugs = array_map( 'strtolower', $matches[1] );
		$slugs = array_values( array_unique( $slugs ) );

		$banned = $this->banned_hashtag_slugs();
		if ( ! empty( $banned ) ) {
			$slugs = array_values( array_diff( $slugs, $banned ) );
		}

		return $slugs;
	}

	/**
	 * Synchronise hashtags for a post/object.
	 *
	 * Upserts each tag into bn_hashtags, then replaces the bn_post_hashtags
	 * link set for this object so that stale tags are removed automatically.
	 * Recomputes post_count for all affected hashtag IDs and busts trending cache.
	 *
	 * @param string   $object_type Object type (e.g. 'post', 'comment').
	 * @param int      $object_id   Object ID.
	 * @param string[] $slugs       Tag slugs to set (empty array removes all tags).
	 */
	public function sync( string $object_type, int $object_id, array $slugs ): void {
		global $wpdb;

		$object_type = sanitize_key( $object_type );

		// Capture old hashtag IDs before deleting so counts can be recomputed.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_hashtag_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT hashtag_id FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id = %d AND object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$object_id,
				$object_type
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		// Remove all existing links for this object first.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_post_hashtags',
			array(
				'post_id'     => $object_id,
				'object_type' => $object_type,
			),
			array( '%d', '%s' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Recompute counts for tags that were previously linked to this object.
		foreach ( $old_hashtag_ids as $old_id ) {
			$old_id = (int) $old_id;
			if ( 0 === $old_id ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_hashtags SET post_count = (SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE hashtag_id = %d) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$old_id,
					$old_id
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
		}

		if ( empty( $slugs ) ) {
			$this->bust_trending_cache();
			return;
		}

		$new_hashtag_ids = array();

		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				continue;
			}

			// Upsert the hashtag registry row.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}bn_hashtags (name, slug, created_at)
					 VALUES (%s, %s, %s)
					 ON DUPLICATE KEY UPDATE name = VALUES(name)",
					$slug,
					$slug,
					current_time( 'mysql', true )
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);

			// Retrieve the hashtag ID.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$hashtag_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s",
					$slug
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);

			if ( 0 === $hashtag_id ) {
				continue;
			}

			// Insert the post↔hashtag link (ignore if it already exists).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_post_hashtags (post_id, object_type, hashtag_id, created_at)
					 VALUES (%d, %s, %d, %s)",
					$object_id,
					$object_type,
					$hashtag_id,
					current_time( 'mysql', true )
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);

			$new_hashtag_ids[] = $hashtag_id;

			wp_cache_delete( "hashtag_{$slug}", self::CACHE_GROUP );
		}

		// Recompute counts for newly linked tags.
		foreach ( $new_hashtag_ids as $hashtag_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_hashtags SET post_count = (SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE hashtag_id = %d) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$hashtag_id,
					$hashtag_id
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
		}

		$this->bust_trending_cache();
	}

	/**
	 * Follow a hashtag.
	 *
	 * @param int $user_id    User who wants to follow.
	 * @param int $hashtag_id Hashtag to follow.
	 * @return bool True if newly followed, false if already following.
	 */
	public function follow( int $user_id, int $hashtag_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_hashtag_follows (user_id, hashtag_id) VALUES (%d, %d)",
				$user_id,
				$hashtag_id
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		if ( $inserted ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_hashtags SET follower_count = follower_count + 1 WHERE id = %d",
					$hashtag_id
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
			wp_cache_delete( "hashtag_following_{$user_id}_{$hashtag_id}", self::CACHE_GROUP );
		}

		return (bool) $inserted;
	}

	/**
	 * Unfollow a hashtag.
	 *
	 * @param int $user_id    User who wants to unfollow.
	 * @param int $hashtag_id Hashtag to unfollow.
	 * @return bool True if unfollowed, false if was not following.
	 */
	public function unfollow( int $user_id, int $hashtag_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_hashtag_follows',
			array(
				'user_id'    => $user_id,
				'hashtag_id' => $hashtag_id,
			),
			array( '%d', '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $deleted ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_hashtags SET follower_count = GREATEST(1, follower_count) - 1 WHERE id = %d",
					$hashtag_id
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
			wp_cache_delete( "hashtag_following_{$user_id}_{$hashtag_id}", self::CACHE_GROUP );
		}

		return (bool) $deleted;
	}

	/**
	 * Check whether a user follows a given hashtag.
	 *
	 * @param int $user_id    User to check.
	 * @param int $hashtag_id Hashtag to check.
	 * @return bool
	 */
	public function is_following( int $user_id, int $hashtag_id ): bool {
		$cache_key = "hashtag_following_{$user_id}_{$hashtag_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}bn_hashtag_follows WHERE user_id = %d AND hashtag_id = %d",
				$user_id,
				$hashtag_id
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		wp_cache_set( $cache_key, (int) $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return hashtag suggestions matching a prefix.
	 *
	 * @param string $prefix Search prefix (without #). Minimum 1 character.
	 * @param int    $limit  Maximum results (1–20). Default 10.
	 * @return array[]
	 */
	public function autocomplete( string $prefix, int $limit = 10 ): array {
		$prefix = sanitize_key( $prefix );
		if ( '' === $prefix ) {
			return array();
		}

		$limit = max( 1, min( 20, $limit ) );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, slug, post_count FROM {$wpdb->prefix}bn_hashtags WHERE slug LIKE %s ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( $prefix ) . '%',
				$limit
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'slug'       => $row['slug'],
					'name'       => $row['name'],
					'post_count' => (int) $row['post_count'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Return a hashtag by its slug, or null if not found.
	 *
	 * @param string $slug Hashtag slug (without leading #).
	 * @return array|null
	 */
	public function get_by_slug( string $slug ): ?array {
		$slug      = sanitize_key( $slug );
		$cache_key = "hashtag_{$slug}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return empty( $cached ) ? null : (array) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s",
				$slug
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hydrated = null !== $row ? $this->hydrate( $row ) : null;

		wp_cache_set( $cache_key, $hydrated ?? array(), self::CACHE_GROUP, self::CACHE_TTL );

		return $hydrated;
	}

	/**
	 * Return the top trending hashtags, ordered by activity in the last 24 hours.
	 *
	 * Computed lazily on the first call within each cache window — no background
	 * cron required. The query joins bn_post_hashtags directly with a 24-hour
	 * rolling window so the ranking reflects actual recent activity rather than
	 * the stale post_count column. Results are stored in two layers:
	 *
	 *   1. wp_cache (in-memory, within-request) — avoids repeated DB hits when
	 *      the trending widget and the REST endpoint both call this in one page load.
	 *   2. Transient (~30 min) — ensures the expensive JOIN is skipped across
	 *      requests on sites without a persistent object cache (Memcached / Redis).
	 *
	 * The transient is busted by bust_trending_cache() whenever post_count values
	 * change (post published, hashtag synced), keeping data fresh within the window.
	 *
	 * @param int $limit Maximum number of hashtags to return (1–50). Default 10.
	 * @return array[]
	 */
	public function get_trending( int $limit = 10 ): array {
		$limit     = max( 1, min( 50, $limit ) );
		$cache_key = "trending_{$limit}";

		// Layer 1: in-memory object cache (within-request dedup).
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		// Layer 2: transient (cross-request persistence on all installations).
		$transient_key  = 'bn_trending_' . $limit;
		$from_transient = get_transient( $transient_key ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_get_transient -- transient is the cache layer; not a DB query.
		if ( false !== $from_transient && is_array( $from_transient ) ) {
			wp_cache_set( $cache_key, $from_transient, self::CACHE_GROUP, self::CACHE_TTL );
			return $from_transient;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.id, h.name, h.slug, h.post_count, h.follower_count, h.created_at,
				        COUNT(ph.hashtag_id) AS recent_count
				 FROM {$wpdb->prefix}bn_hashtags h
				 INNER JOIN {$wpdb->prefix}bn_post_hashtags ph ON ph.hashtag_id = h.id
				 WHERE ph.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
				 GROUP BY h.id
				 ORDER BY recent_count DESC, h.post_count DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = array_map( array( $this, 'hydrate' ), (array) $rows );

		set_transient( $transient_key, $results, self::TRENDING_TRANSIENT_TTL );
		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Return hashtags frequently used alongside a given hashtag — the "related
	 * tags" rail on the hashtag feed — ordered by co-occurrence count.
	 *
	 * Finds posts carrying $slug, then the other hashtags on those same posts,
	 * ranked by how often they co-occur. The seed tag itself is excluded.
	 *
	 * @param string $slug  Hashtag slug (without #).
	 * @param int    $limit Max related tags (1-20). Default 6.
	 * @return array[] Hydrated hashtag rows (with a co_occurrence count).
	 */
	public function related( string $slug, int $limit = 6 ): array {
		$hashtag = $this->get_by_slug( $slug );
		if ( null === $hashtag ) {
			return array();
		}
		$limit = max( 1, min( 20, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.id, h.name, h.slug, h.post_count, h.follower_count, h.created_at,
				        COUNT(*) AS co_occurrence
				 FROM {$wpdb->prefix}bn_post_hashtags seed
				 INNER JOIN {$wpdb->prefix}bn_post_hashtags other
				         ON other.post_id = seed.post_id
				        AND other.object_type = seed.object_type
				        AND other.hashtag_id <> seed.hashtag_id
				 INNER JOIN {$wpdb->prefix}bn_hashtags h ON h.id = other.hashtag_id
				 WHERE seed.hashtag_id = %d AND seed.object_type = 'post'
				 GROUP BY h.id
				 ORDER BY co_occurrence DESC, h.post_count DESC
				 LIMIT %d",
				$hashtag['id'],
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			function ( array $row ): array {
				$out                  = $this->hydrate( $row );
				$out['co_occurrence'] = isset( $row['co_occurrence'] ) ? (int) $row['co_occurrence'] : 0;
				return $out;
			},
			(array) $rows
		);
	}

	/**
	 * Return the top contributors to a hashtag — the members who have posted the
	 * most public, published posts carrying it, most prolific first.
	 *
	 * @param int $hashtag_id Hashtag ID.
	 * @param int $limit      Max contributors (1-20). Default 5.
	 * @return array<int,array{user_id:int, display_name:string, post_count:int}>
	 */
	public function top_contributors( int $hashtag_id, int $limit = 5 ): array {
		if ( $hashtag_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 20, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.user_id, u.display_name, COUNT(*) AS post_count
				 FROM {$wpdb->prefix}bn_post_hashtags ph
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = ph.post_id
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE ph.hashtag_id = %d AND ph.object_type = 'post'
				   AND p.status = 'published' AND p.privacy = 'public'
				 GROUP BY p.user_id
				 ORDER BY post_count DESC
				 LIMIT %d",
				$hashtag_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static function ( array $row ): array {
				return array(
					'user_id'      => (int) $row['user_id'],
					'display_name' => (string) $row['display_name'],
					'post_count'   => (int) $row['post_count'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Count the distinct members who have posted public, published posts under a
	 * hashtag. Backs the "N contributors" figure on the hashtag feed.
	 *
	 * @param int $hashtag_id Hashtag ID.
	 * @return int
	 */
	public function contributor_count( int $hashtag_id ): int {
		if ( $hashtag_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.user_id)
				 FROM {$wpdb->prefix}bn_post_hashtags ph
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = ph.post_id
				 WHERE ph.hashtag_id = %d AND ph.object_type = 'post'
				   AND p.status = 'published' AND p.privacy = 'public'",
				$hashtag_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return a slug => bool map of which of the given hashtag slugs a user
	 * follows, resolved in a single query. Kills the per-row is_following() N+1
	 * in the hashtag sidebar's "related/trending" list.
	 *
	 * Slugs the user does not follow are present in the map with a false value,
	 * so callers can index by slug without an isset() guard.
	 *
	 * @param int               $user_id User to check.
	 * @param array<int,string> $slugs Hashtag slugs (without #).
	 * @return array<string,bool>
	 */
	public function following_map( int $user_id, array $slugs ): array {
		$normalized = array();
		foreach ( $slugs as $slug ) {
			$norm = sanitize_key( ltrim( (string) $slug, '#' ) );
			if ( '' !== $norm ) {
				$normalized[ $norm ] = false;
			}
		}
		if ( $user_id <= 0 || empty( $normalized ) ) {
			return $normalized;
		}

		$slug_list    = array_keys( $normalized );
		$placeholders = implode( ',', array_fill( 0, count( $slug_list ), '%s' ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$followed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT h.slug
				 FROM {$wpdb->prefix}bn_hashtag_follows hf
				 INNER JOIN {$wpdb->prefix}bn_hashtags h ON h.id = hf.hashtag_id
				 WHERE hf.user_id = %d AND h.slug IN ({$placeholders})",
				...array_merge( array( $user_id ), $slug_list )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( (array) $followed as $slug ) {
			$normalized[ (string) $slug ] = true;
		}

		return $normalized;
	}

	/**
	 * Hydrate a raw DB row into a typed hashtag array.
	 *
	 * @param array $row Raw ARRAY_A row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'name'           => $row['name'],
			'slug'           => $row['slug'],
			'post_count'     => (int) $row['post_count'],
			'follower_count' => (int) $row['follower_count'],
			'created_at'     => $row['created_at'],
		);
	}

	/**
	 * Delete trending cache entries for the common limit values.
	 *
	 * Clears both the in-memory object cache (wp_cache) and the persistent
	 * transient so the next get_trending() call recomputes from the DB.
	 * Called whenever post_count values change (hashtag sync, post delete).
	 */
	private function bust_trending_cache(): void {
		foreach ( array( 10, 20, 50 ) as $limit ) {
			wp_cache_delete( "trending_{$limit}", self::CACHE_GROUP );
			delete_transient( 'bn_trending_' . $limit );
		}
	}
}
