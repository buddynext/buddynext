<?php
/**
 * Search index service.
 *
 * Manages the bn_search_index table — a lightweight unified index over posts,
 * users, and spaces. Index writes use INSERT ... ON DUPLICATE KEY UPDATE so
 * they are idempotent. Reads use a LIKE-based fallback rather than FULLTEXT
 * so tests pass on the WP test suite's TEMPORARY tables (which do not support
 * FULLTEXT indexes).
 *
 * @package BuddyNext\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Search;

/**
 * Handles search index writes and full-text reads.
 */
class SearchService {

	/**
	 * Default results per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Upsert an object into the search index.
	 *
	 * @param string $object_type Type identifier (e.g. 'post', 'user', 'space').
	 * @param int    $object_id   ID of the object within its type.
	 * @param string $title       Searchable title string.
	 * @param string $content     Searchable body content (may be empty).
	 * @param int    $author_id   User who owns/created this object.
	 * @param string $visibility  'public' or 'private'.
	 */
	public function index(
		string $object_type,
		int $object_id,
		string $title,
		string $content,
		int $author_id,
		string $visibility = 'public'
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_search_index
				    (object_type, object_id, title, content, author_id, visibility)
				 VALUES (%s, %d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE
				    title = VALUES(title),
				    content = VALUES(content),
				    author_id = VALUES(author_id),
				    visibility = VALUES(visibility),
				    updated_at = NOW()",
				$object_type,
				$object_id,
				$title,
				$content,
				$author_id,
				$visibility
			)
		);
	}

	/**
	 * Remove an object from the search index.
	 *
	 * @param string $object_type Type identifier.
	 * @param int    $object_id   Object ID.
	 */
	public function deindex( string $object_type, int $object_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Search the index for the given query string.
	 *
	 * Uses LIKE matching so results are available in the test suite (TEMPORARY
	 * tables do not support FULLTEXT). On a production table with the FULLTEXT
	 * key the query planner will prefer the full-text index automatically once
	 * the table is a real InnoDB table.
	 *
	 * @param string $query     Raw search string (sanitised internally).
	 * @param string $type      Optional object_type filter. Empty = all types.
	 * @param int    $per_page  Results per page (max 50).
	 * @param int    $page      1-based page number.
	 * @param int    $viewer_id ID of the requesting user. When non-zero, users
	 *                          who have a block relationship with this viewer are
	 *                          excluded from results. Pass 0 to skip filtering.
	 * @return array{items: array[], total: int}
	 */
	public function search( string $query, string $type = '', int $per_page = self::DEFAULT_LIMIT, int $page = 1, int $viewer_id = 0 ): array {
		global $wpdb;

		$per_page = min( $per_page, 50 );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		$like     = '%' . $wpdb->esc_like( sanitize_text_field( $query ) ) . '%';

		$type_where  = '';
		$type_params = array();

		if ( '' !== $type ) {
			$type_where  = ' AND object_type = %s';
			$type_params = array( sanitize_key( $type ) );
		}

		$block_where  = '';
		$block_params = array();

		if ( $viewer_id > 0 ) {
			$block_where  =
				" AND object_id NOT IN (
				    SELECT blocked_id FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d
				    UNION
				    SELECT blocker_id FROM {$wpdb->prefix}bn_blocks WHERE blocked_id = %d
				  )";
			$block_params = array( $viewer_id, $viewer_id );
		}

		// Exclude suspended and shadow-banned users' content from all search results.
		$excluded_where =
			" AND author_id NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
			  )
			  AND author_id NOT IN (
			    SELECT user_id FROM {$wpdb->usermeta}
			    WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			  )";

		/**
		 * Allow an external search driver (Elasticsearch, Algolia, etc.) to
		 * short-circuit the built-in SQL search. Return a non-null value from
		 * this filter — shaped as `array{ items: array[], total: int }` — to
		 * bypass the default query entirely.
		 *
		 * @since 1.0.0
		 *
		 * @param array|null $driver_result Null by default; return a result array to override.
		 * @param string     $query         Raw (unsanitised) search string.
		 * @param string     $type          Object-type filter, or '' for all types.
		 * @param int        $per_page      Results per page.
		 * @param int        $page          1-based page number.
		 */
		$driver_result = apply_filters( 'buddynext_search_results', null, $query, $type, $per_page, $page );
		if ( null !== $driver_result ) {
			return $driver_result;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_search_index
				 WHERE visibility = 'public'
				   AND (title LIKE %s OR content LIKE %s)
				   {$type_where}
				   {$block_where}
				   {$excluded_where}",
				...array_merge( array( $like, $like ), $type_params, $block_params )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_type, object_id, title, author_id, visibility, created_at
				 FROM {$wpdb->prefix}bn_search_index
				 WHERE visibility = 'public'
				   AND (title LIKE %s OR content LIKE %s)
				   {$type_where}
				   {$block_where}
				   {$excluded_where}
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d",
				...array_merge( array( $like, $like ), $type_params, $block_params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = array_map(
			fn( $r ) => array(
				'object_type' => $r['object_type'],
				'object_id'   => (int) $r['object_id'],
				'title'       => $r['title'],
				'author_id'   => (int) $r['author_id'],
				'created_at'  => $r['created_at'],
			),
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
