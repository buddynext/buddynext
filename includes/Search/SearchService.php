<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Search index service.
 *
 * Manages the bn_search_index table — a lightweight unified index over posts,
 * users, and spaces. Index writes use INSERT ... ON DUPLICATE KEY UPDATE so
 * they are idempotent. Reads use FULLTEXT MATCH … AGAINST when the ft_search
 * index exists on the production table, and fall back to LIKE-based matching
 * in test environments where TEMPORARY tables do not support FULLTEXT indexes.
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
	 * @param int    $space_id    Space ID for space-scoped content (0 = no space).
	 */
	public function index(
		string $object_type,
		int $object_id,
		string $title,
		string $content,
		int $author_id,
		string $visibility = 'public',
		int $space_id = 0
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_search_index
				    (object_type, object_id, title, content, author_id, space_id, visibility)
				 VALUES (%s, %d, %s, %s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
				    title = VALUES(title),
				    content = VALUES(content),
				    author_id = VALUES(author_id),
				    space_id = VALUES(space_id),
				    visibility = VALUES(visibility),
				    updated_at = NOW()",
				$object_type,
				$object_id,
				$title,
				$content,
				$author_id,
				$space_id,
				$visibility
			)
		);
	}

	/**
	 * Run a grouped search across all indexed content types.
	 *
	 * Returns up to $per_group results per content type, keyed by type.
	 * Types searched are discovered dynamically from the index table so the
	 * result adapts automatically as new object types are indexed by addons.
	 *
	 * @param string $query     Search term.
	 * @param int    $viewer_id Viewer user ID for block/suspension exclusions.
	 *                          Pass 0 to skip block filtering.
	 * @param int    $per_group Max results per content type.
	 * @return array {
	 *     @type array[] $types {
	 *         @type string  $type    Object type slug (e.g. 'user', 'space', 'post').
	 *         @type array[] $results Flat result rows (same shape as search() items).
	 *         @type int     $total   Total matching records for this type in the index.
	 *     }
	 * }
	 */
	public function grouped_search( string $query, int $viewer_id, int $per_group = 5 ): array {
		global $wpdb;

		// Discover active object types from the index — adapts to addon content.
		// Cached for 5 minutes: the type list is stable between deploys and does
		// not need to reflect brand-new addon registrations immediately.
		$types = wp_cache_get( 'search_object_types', 'buddynext' );
		if ( false === $types ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$types = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$wpdb->prefix}bn_search_index" );
			wp_cache_set( 'search_object_types', $types, 'buddynext', 300 );
		}

		$type_groups = array();

		foreach ( (array) $types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type ) {
				continue;
			}

			$result = $this->search( $query, $type, $per_group, 1, $viewer_id );

			if ( empty( $result['items'] ) ) {
				continue;
			}

			$type_groups[] = array(
				'type'    => $type,
				'results' => $result['items'],
				'total'   => $result['total'],
			);
		}

		return array( 'types' => $type_groups );
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
	 * Uses FULLTEXT MATCH … AGAINST when the ft_search index exists on the
	 * production table. Falls back to LIKE matching in environments where the
	 * index is absent (e.g. the WP test suite's TEMPORARY tables).
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

		$per_page   = min( $per_page, 50 );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;
		$safe_query = sanitize_text_field( $query );

		$type_where  = '';
		$type_params = array();

		if ( '' !== $type ) {
			$type_where  = ' AND si.object_type = %s';
			$type_params = array( sanitize_key( $type ) );
		}

		$block_where  = '';
		$block_params = array();

		if ( $viewer_id > 0 ) {
			$block_where  =
				" AND si.object_id NOT IN (
				    SELECT blocked_id FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d
				    UNION
				    SELECT blocker_id FROM {$wpdb->prefix}bn_blocks WHERE blocked_id = %d
				  )";
			$block_params = array( $viewer_id, $viewer_id );
		}

		// Exclude suspended and shadow-banned users' content from all search results.
		$excluded_where =
			" AND si.author_id NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
			  )
			  AND si.author_id NOT IN (
			    SELECT user_id FROM {$wpdb->usermeta}
			    WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			  )";

		/**
		 * Filter the query args before SQL is built for the search.
		 *
		 * Use this filter to modify per_page, page, or append additional WHERE
		 * constraints before the database query executes. Complements the
		 * buddynext_search_results filter which operates on the result set.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args      Query args: per_page, page, type, viewer_id.
		 * @param string $query     Raw (unsanitised) search string.
		 * @param int    $viewer_id Viewing user ID.
		 */
		$search_args = apply_filters(
			'buddynext_search_query_args',
			array(
				'per_page'  => $per_page,
				'page'      => $page,
				'type'      => $type,
				'viewer_id' => $viewer_id,
			),
			$query,
			$viewer_id
		);

		$per_page = min( (int) ( $search_args['per_page'] ?? $per_page ), 50 );
		$page     = max( 1, (int) ( $search_args['page'] ?? $page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// ------------------------------------------------------------------ //
		// Advanced member-search WHERE clauses contributed by Pro via the
		// buddynext_search_query_args filter. Each clause is only appended
		// when the corresponding arg is present, non-empty, and the search
		// type targets users / members.
		//
		// All referenced tables (bn_subscriptions, bn_membership_tiers,
		// bn_space_members, bn_member_label_assignments, bn_member_labels,
		// bn_analytics_events) are owned by buddynext-pro. When Pro is
		// inactive, no caller populates these args so no clause is emitted
		// and the missing tables are never referenced.
		// ------------------------------------------------------------------ //
		$advanced_where  = '';
		$advanced_params = array();
		$user_scope      = in_array( $type, array( 'user', 'member' ), true );

		if ( $user_scope ) {
			if ( isset( $search_args['tier_slug'] ) && '' !== $search_args['tier_slug'] ) {
				$advanced_where   .= " AND EXISTS (
					SELECT 1
					FROM {$wpdb->prefix}bn_subscriptions sub
					INNER JOIN {$wpdb->prefix}bn_membership_tiers tier
					        ON sub.tier_id = tier.id
					WHERE sub.user_id = si.object_id
					  AND sub.status  = 'active'
					  AND tier.slug   = %s
				)";
				$advanced_params[] = (string) $search_args['tier_slug'];
			}

			if ( isset( $search_args['space_id'] ) && (int) $search_args['space_id'] > 0 ) {
				$advanced_where   .= " AND EXISTS (
					SELECT 1
					FROM {$wpdb->prefix}bn_space_members sm
					WHERE sm.user_id  = si.object_id
					  AND sm.space_id = %d
					  AND sm.status   = 'active'
				)";
				$advanced_params[] = (int) $search_args['space_id'];
			}

			if ( isset( $search_args['member_label'] ) && '' !== $search_args['member_label'] ) {
				$advanced_where   .= " AND EXISTS (
					SELECT 1
					FROM {$wpdb->prefix}bn_member_label_assignments la
					INNER JOIN {$wpdb->prefix}bn_member_labels lbl
					        ON la.label_id = lbl.id
					WHERE la.user_id = si.object_id
					  AND lbl.slug   = %s
				)";
				$advanced_params[] = (string) $search_args['member_label'];
			}

			if ( isset( $search_args['joined_after'] ) && '' !== $search_args['joined_after'] ) {
				$advanced_where   .= " AND EXISTS (
					SELECT 1
					FROM {$wpdb->users} wpu
					WHERE wpu.ID = si.object_id
					  AND wpu.user_registered >= %s
				)";
				$advanced_params[] = (string) $search_args['joined_after'];
			}

			if ( isset( $search_args['active_within_days'] ) && (int) $search_args['active_within_days'] > 0 ) {
				$advanced_where   .= " AND EXISTS (
					SELECT 1
					FROM {$wpdb->prefix}bn_analytics_events ae
					WHERE ae.actor_id    = si.object_id
					  AND ae.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				)";
				$advanced_params[] = (int) $search_args['active_within_days'];
			}
		}

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

		if ( $this->has_fulltext_index() ) {
			// FULLTEXT path — uses ft_search index for performance on production.
			// $search_condition is the output of a prior $wpdb->prepare() call — it is already
			// escaped and safe to embed. $excluded_where and $block_where contain only
			// table/column names with no user-supplied data. ReplacementsWrongNumber is a
			// false-positive here because $search_condition contains MATCH syntax with '%'.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$search_condition = $wpdb->prepare(
				'MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE)',
				$safe_query . '*'
			);
			$order_clause     = 'relevance DESC, si.updated_at DESC';

			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}bn_search_index si
					 WHERE si.visibility = 'public'
					   AND {$search_condition}
					   {$type_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}",
					...array_merge( $type_params, $block_params, $advanced_params )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.author_id, si.visibility, si.created_at,
					        MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE) AS relevance
					 FROM {$wpdb->prefix}bn_search_index si
					 WHERE si.visibility = 'public'
					   AND MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE)
					   {$type_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					 ORDER BY {$order_clause}
					 LIMIT %d OFFSET %d",
					...array_merge( array( $safe_query . '*', $safe_query . '*' ), $type_params, $block_params, $advanced_params, array( $per_page, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// LIKE fallback — used in test environments where TEMPORARY tables
			// do not support FULLTEXT indexes.
			$like = '%' . $wpdb->esc_like( $safe_query ) . '%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->prefix}bn_search_index si
					 WHERE si.visibility = 'public'
					   AND (si.title LIKE %s OR si.content LIKE %s)
					   {$type_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}",
					...array_merge( array( $like, $like ), $type_params, $block_params, $advanced_params )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.author_id, si.visibility, si.created_at
					 FROM {$wpdb->prefix}bn_search_index si
					 WHERE si.visibility = 'public'
					   AND (si.title LIKE %s OR si.content LIKE %s)
					   {$type_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					 ORDER BY si.updated_at DESC
					 LIMIT %d OFFSET %d",
					...array_merge( array( $like, $like ), $type_params, $block_params, $advanced_params, array( $per_page, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

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

		$results = array(
			'items' => $items,
			'total' => $total,
		);

		// Apply the existing buddynext_search_results filter (results side).
		// Note: the filter is already applied above in the driver short-circuit path.
		// This second call is intentionally skipped here to avoid double-filtering.

		/**
		 * Fires after a search is performed and results are computed.
		 *
		 * Use: Pro saved searches, AI relevance signals. Viewer ID is available
		 * for personalised logging without altering the returned result set.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query     Sanitised search query string.
		 * @param int    $viewer_id Viewing user ID (0 = anonymous).
		 * @param array  $args      Query args used: per_page, page, type, viewer_id.
		 * @param array  $results   Result set: items[], total.
		 */
		do_action(
			'buddynext_search_performed',
			$safe_query,
			$viewer_id,
			array(
				'per_page'  => $per_page,
				'page'      => $page,
				'type'      => $type,
				'viewer_id' => $viewer_id,
			),
			$results
		);

		return $results;
	}

	/**
	 * Check whether the FULLTEXT index exists on the search index table.
	 *
	 * Returns false in test environments where the table is a TEMPORARY table
	 * (which does not support FULLTEXT). The result is not cached because the
	 * index may be added after initial activation.
	 *
	 * @return bool True when the ft_search FULLTEXT index is present.
	 */
	private function has_fulltext_index(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_search_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				'ft_search'
			)
		);
		return (bool) $result;
	}

	/**
	 * Enqueue a full re-index of all users and spaces.
	 *
	 * Called on plugin activation. Uses Action Scheduler when available,
	 * otherwise runs synchronously (development/small-site fallback).
	 *
	 * @return void
	 */
	public static function schedule_reindex_all(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_reindex_all', array(), 'buddynext' );
		} elseif ( ! wp_next_scheduled( 'buddynext_reindex_all_cron' ) ) {
			// Schedule via WP-Cron so the container is fully bootstrapped before
			// reindex_all_sync() calls buddynext_service(). Running it inline here
			// would fire before plugins_loaded and the container would be empty.
			wp_schedule_single_event( time() + 30, 'buddynext_reindex_all_cron' );
		}
	}

	/**
	 * WP-Cron callback for the one-time post-activation reindex.
	 *
	 * Registered in Plugin::init() so the container is fully bootstrapped
	 * by the time this fires (unlike Installer::run() which runs at activation).
	 *
	 * @return void
	 */
	public static function reindex_all_cron(): void {
		self::reindex_all_sync();
	}

	/**
	 * Synchronously re-index all users.
	 *
	 * Only used as fallback when Action Scheduler is absent. Capped at 500
	 * users to stay within acceptable execution time on small sites.
	 *
	 * @return void
	 */
	private static function reindex_all_sync(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} LIMIT 500" );

		$profiles = buddynext_service( 'profiles' );
		foreach ( $user_ids as $uid ) {
			$profiles->index_user( (int) $uid );
		}
	}
}
