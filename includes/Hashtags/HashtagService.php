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

/**
 * Handles hashtag extraction, sync, and lookup.
 */
class HashtagService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_hashtags';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

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

		$banned = (array) get_option( 'buddynext_banned_hashtags', array() );
		if ( in_array( $slug, $banned, true ) ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_hashtags (name, slug)
				 VALUES (%s, %s)
				 ON DUPLICATE KEY UPDATE name = VALUES(name)",
				$slug,
				$slug
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
	 * In production the post_count is maintained async by a cron job (see
	 * CronService) — this method reads the cached counter column directly.
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
	 * @param array  $args Optional args: per_page (int, max 50), cursor (string).
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

		$per_page = min( (int) ( $args['per_page'] ?? 20 ), 50 );
		$cursor   = ( isset( $args['cursor'] ) && is_string( $args['cursor'] ) ) ? $args['cursor'] : null;

		// SCALE-CONTRACT §2: keyset cursor, never OFFSET. Key on the pivot's
		// (hashtag_id, created_at) index so every page is O(per_page) regardless
		// of depth. has_more is derived from a per_page+1 fetch, so no COUNT(*)
		// runs on the read path (SCALE-CONTRACT §3).
		$cursor_where  = '';
		$cursor_params = array();
		$decoded       = ( null !== $cursor ) ? $this->decode_feed_cursor( $cursor ) : null;
		if ( null !== $decoded ) {
			$cursor_where  = ' AND ( ph.created_at < %s OR ( ph.created_at = %s AND ph.post_id < %d ) )';
			$cursor_params = array( $decoded['created_at'], $decoded['created_at'], $decoded['id'] );
		}

		global $wpdb;

		$posts_tbl = $wpdb->prefix . 'bn_posts';
		$pivot_tbl = $wpdb->prefix . 'bn_post_hashtags';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.content, p.type, p.privacy,
				        p.reaction_count, p.comment_count, p.share_count, p.created_at,
				        ph.created_at AS bn_cursor_ts
				 FROM {$pivot_tbl} ph
				 INNER JOIN {$posts_tbl} p ON p.id = ph.post_id
				 WHERE ph.hashtag_id  = %d
				   AND ph.object_type = 'post'
				   AND p.status       = 'published'
				   AND p.privacy      = 'public'
				   {$cursor_where}
				 ORDER BY ph.created_at DESC, ph.post_id DESC
				 LIMIT %d",
				...array_merge( array( $hashtag['id'] ), $cursor_params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows        = (array) $rows;
		$next_cursor = null;
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$last = $rows[ count( $rows ) - 1 ];
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
		return base64_encode( $created_at . '|' . $post_id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a hashtag-feed keyset cursor.
	 *
	 * @param string $cursor Opaque cursor produced by encode_feed_cursor().
	 * @return array{created_at: string, id: int}|null Null when malformed.
	 */
	private function decode_feed_cursor( string $cursor ): ?array {
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
		$pattern = (string) apply_filters( 'buddynext_hashtag_pattern', '/#([a-zA-Z][a-zA-Z0-9_]{0,49})/u' );
		preg_match_all( $pattern, $content, $matches );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$slugs = array_map( 'strtolower', $matches[1] );
		$slugs = array_values( array_unique( $slugs ) );

		$banned = (array) get_option( 'buddynext_banned_hashtags', array() );
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
					"INSERT INTO {$wpdb->prefix}bn_hashtags (name, slug)
					 VALUES (%s, %s)
					 ON DUPLICATE KEY UPDATE name = VALUES(name)",
					$slug,
					$slug
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
					"INSERT IGNORE INTO {$wpdb->prefix}bn_post_hashtags (post_id, object_type, hashtag_id)
					 VALUES (%d, %s, %d)",
					$object_id,
					$object_type,
					$hashtag_id
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
					"UPDATE {$wpdb->prefix}bn_hashtags SET follower_count = GREATEST(follower_count - 1, 0) WHERE id = %d",
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
	 * Return the top trending hashtags by usage in the last 24 hours.
	 *
	 * Counts only bn_post_hashtags rows whose created_at falls within the
	 * rolling 24-hour window so trending reflects recent activity, not
	 * all-time post_count totals.
	 *
	 * @param int $limit Maximum number of hashtags to return (1–50). Default 10.
	 * @return array[]
	 */
	public function get_trending( int $limit = 10 ): array {
		$limit     = max( 1, min( 50, $limit ) );
		$cache_key = "trending_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.id, h.name, h.slug, h.post_count, h.follower_count, h.created_at,
				        COUNT(ph.hashtag_id) AS recent_count
				 FROM {$wpdb->prefix}bn_hashtags h
				 INNER JOIN {$wpdb->prefix}bn_post_hashtags ph ON ph.hashtag_id = h.id
				 WHERE ph.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
				 GROUP BY h.id
				 ORDER BY recent_count DESC, h.post_count DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = array_map( array( $this, 'hydrate' ), (array) $rows );

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_TTL );

		return $results;
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
	 * Called whenever post_count values change so callers always get fresh data.
	 */
	private function bust_trending_cache(): void {
		foreach ( array( 10, 20, 50 ) as $limit ) {
			wp_cache_delete( "trending_{$limit}", self::CACHE_GROUP );
		}
	}
}
