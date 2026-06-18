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
	 * Hard ceiling on rows reachable across search pagination.
	 *
	 * SCALE-CONTRACT §1: "Search results cap at 100/page with hard ceiling at
	 * 1000 across pagination." Bounds the OFFSET scan so deep pages can never
	 * walk the full ~6M-row search index, and bounds the result COUNT.
	 */
	private const MAX_RESULTS = 1000;

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

		// The `media` pseudo-type is posts that carry attachments. It reuses the
		// whole post-search pipeline (FULLTEXT/LIKE seam, block + suspension
		// exclusions, date window, sort) and only adds a join to bn_posts on a
		// non-empty media_ids column. Map it to the real `post` object_type and
		// raise a flag so the SQL builders add that join; the BOOLEAN-MODE + date
		// logic the template used to own now lives entirely here.
		$media_only = ( 'media' === $type );
		if ( $media_only ) {
			$type = 'post';
		}

		// SCALE-CONTRACT §1: hard 1000-row ceiling across pagination. Bound the
		// scanned window so a deep `page` cannot OFFSET past the ceiling. Past
		// it, $row_limit is 0 (LIMIT 0 → no rows) — there is nothing reachable.
		$offset    = min( $offset, self::MAX_RESULTS );
		$row_limit = max( 0, min( $per_page, self::MAX_RESULTS - $offset ) );

		$type_where  = '';
		$type_params = array();

		if ( '' !== $type ) {
			$type_where  = ' AND si.object_type = %s';
			$type_params = array( sanitize_key( $type ) );
		}

		$block_where  = '';
		$block_params = array();

		if ( $viewer_id > 0 ) {
			$privacy = buddynext_service( 'privacy' );

			// Search semantics: exclude any block relationship (all types, both
			// directions) on the item subject, plus authors the viewer has
			// `restrict`ed (a search-surface limit, forward only). Both routed
			// through the one canonical builder so the rules can't drift.
			[ $subject_sql, $subject_params ]   = $privacy->block_exclude_sql( $viewer_id, 'si.object_id', null, null );
			[ $restrict_sql, $restrict_params ] = $privacy->block_exclude_sql( $viewer_id, 'si.author_id', array( 'restrict' ), array() );

			if ( '' !== $subject_sql ) {
				$block_where .= ' AND ' . $subject_sql;
				$block_params = array_merge( $block_params, $subject_params );
			}
			if ( '' !== $restrict_sql ) {
				$block_where .= ' AND ' . $restrict_sql;
				$block_params = array_merge( $block_params, $restrict_params );
			}
		}

		// Media-only join: restrict post results to those that have attachments.
		// Static SQL — no user data — so it is safe to interpolate alongside the
		// other fragment strings.
		$media_join  = $media_only ? " INNER JOIN {$wpdb->prefix}bn_posts mp ON mp.id = si.object_id" : '';
		$media_where = $media_only ? " AND mp.media_ids IS NOT NULL AND mp.media_ids != ''" : '';

		// Exclude suspended and shadow-banned users' content from all search results.
		$excluded_where =
			" AND si.author_id NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
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
		// Optional date window + sort order. Sourced from the seam args so the
		// web /search page (and any caller) can pass `date` / `sort` without a
		// signature change. `date` accepts week|month|year (anything else = no
		// window). `sort` accepts recent (updated_at DESC) — relevance is the
		// default when the FULLTEXT path is active. All values map to literal
		// SQL fragments; no user data is interpolated.
		// ------------------------------------------------------------------ //
		$date_where = '';
		$date_key   = isset( $search_args['date'] ) ? sanitize_key( (string) $search_args['date'] ) : '';
		switch ( $date_key ) {
			case 'week':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )';
				break;
			case 'month':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 1 MONTH )';
				break;
			case 'year':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 1 YEAR )';
				break;
		}
		$sort_recent = isset( $search_args['sort'] ) && 'recent' === sanitize_key( (string) $search_args['sort'] );

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
			$order_clause     = $sort_recent
				? 'si.updated_at DESC'
				: 'relevance DESC, si.updated_at DESC';

			// SCALE-CONTRACT §3: bound the COUNT so it never scans past the
			// 1000-row ceiling. Totals beyond it render as "1000+" in the UI.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
						SELECT 1
						 FROM {$wpdb->prefix}bn_search_index si
						 {$media_join}
						 WHERE si.visibility = 'public'
						   AND {$search_condition}
						   {$type_where}
						   {$media_where}
						   {$block_where}
						   {$excluded_where}
						   {$advanced_where}
						   {$date_where}
						 LIMIT %d
					) bn_bounded",
					...array_merge( $type_params, $block_params, $advanced_params, array( self::MAX_RESULTS + 1 ) )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.content, si.author_id, si.visibility, si.created_at,
					        MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE) AS relevance
					 FROM {$wpdb->prefix}bn_search_index si
					 {$media_join}
					 WHERE si.visibility = 'public'
					   AND MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE)
					   {$type_where}
					   {$media_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					   {$date_where}
					 ORDER BY {$order_clause}
					 LIMIT %d OFFSET %d",
					...array_merge( array( $safe_query . '*', $safe_query . '*' ), $type_params, $block_params, $advanced_params, array( $row_limit, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		} else {
			// LIKE fallback — used in test environments where TEMPORARY tables
			// do not support FULLTEXT indexes.
			$like = '%' . $wpdb->esc_like( $safe_query ) . '%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// SCALE-CONTRACT §3: bound the COUNT to the 1000-row ceiling.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
						SELECT 1
						 FROM {$wpdb->prefix}bn_search_index si
						 {$media_join}
						 WHERE si.visibility = 'public'
						   AND (si.title LIKE %s OR si.content LIKE %s)
						   {$type_where}
						   {$media_where}
						   {$block_where}
						   {$excluded_where}
						   {$advanced_where}
						   {$date_where}
						 LIMIT %d
					) bn_bounded",
					...array_merge( array( $like, $like ), $type_params, $block_params, $advanced_params, array( self::MAX_RESULTS + 1 ) )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.content, si.author_id, si.visibility, si.created_at
					 FROM {$wpdb->prefix}bn_search_index si
					 {$media_join}
					 WHERE si.visibility = 'public'
					   AND (si.title LIKE %s OR si.content LIKE %s)
					   {$type_where}
					   {$media_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					   {$date_where}
					 ORDER BY si.updated_at DESC
					 LIMIT %d OFFSET %d",
					...array_merge( array( $like, $like ), $type_params, $block_params, $advanced_params, array( $row_limit, $offset ) )
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
				'content'     => $r['content'] ?? '',
				'author_id'   => (int) $r['author_id'],
				'created_at'  => $r['created_at'],
			),
			(array) $rows
		);

		/**
		 * Filter each search result item before the set is returned.
		 *
		 * Fires once per item on the built-in SQL path (it does NOT run when an
		 * external driver short-circuits buddynext_search_results). Use it to
		 * enrich or annotate rows. To remove results, exclude them at the query
		 * level via buddynext_search_query_args — dropping items here would
		 * desync the `total` count and cursor, so this filter only mutates.
		 *
		 * @param array  $item      Result row (object_type, object_id, title, content, author_id, created_at).
		 * @param string $query     Sanitised search query.
		 * @param string $type      Object-type filter ('' for all).
		 * @param int    $viewer_id Viewing user ID (0 = anonymous).
		 * @param array  $args      Query args (per_page, page, type, viewer_id).
		 */
		$items = array_map(
			function ( $item ) use ( $safe_query, $type, $viewer_id, $per_page, $page ) {
				return (array) apply_filters(
					'buddynext_search_item',
					$item,
					$safe_query,
					$type,
					$viewer_id,
					array(
						'per_page'  => $per_page,
						'page'      => $page,
						'type'      => $type,
						'viewer_id' => $viewer_id,
					)
				);
			},
			$items
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
	 * Turn raw search() items into presentation-ready rows for a result section.
	 *
	 * The search index only stores object_id / title / content / author_id, so a
	 * naive section part re-queries the owning table (and the follow / membership
	 * tables) once per row — an N+1. This method does the enrichment in batch:
	 * spaces hydrate through SpaceService + a single membership_map; members
	 * resolve display name / bio / following-state via one following_map; posts
	 * hydrate through PostService::hydrate(). The section parts then render the
	 * returned arrays without touching the database.
	 *
	 * @param array[] $items     Items from search() (object_id / content / author_id).
	 * @param string  $type      One of 'user' | 'member', 'post', 'space', 'media'.
	 * @param int     $viewer_id Viewing user ID (0 = anonymous).
	 * @return array[] Presentation rows; shape depends on $type (see inline docs).
	 */
	public function enrich_results( array $items, string $type, int $viewer_id = 0 ): array {
		if ( empty( $items ) ) {
			return array();
		}

		$object_ids = array_values(
			array_filter(
				array_map(
					static fn( $item ): int => (int) ( $item['object_id'] ?? 0 ),
					$items
				)
			)
		);

		if ( empty( $object_ids ) ) {
			return array();
		}

		switch ( $type ) {
			case 'user':
			case 'member':
				return $this->enrich_members( $object_ids, $viewer_id );
			case 'space':
				return $this->enrich_spaces( $object_ids, $viewer_id );
			case 'post':
			case 'media':
				return $this->enrich_posts( $items );
			default:
				return array();
		}
	}

	/**
	 * Enrich member result rows: name, initials, bio, following-state in batch.
	 *
	 * @param int[] $user_ids  Member user IDs.
	 * @param int   $viewer_id Viewing user ID.
	 * @return array[] Each row: id, name, initials, bio, profile_url, is_following, is_self.
	 */
	private function enrich_members( array $user_ids, int $viewer_id ): array {
		$following = ( $viewer_id > 0 )
			? buddynext_service( 'follows' )->following_map( $viewer_id, $user_ids )
			: array();

		$rows = array();
		foreach ( $user_ids as $uid ) {
			$user = get_userdata( $uid );
			$name = $user ? $user->display_name : __( 'Unknown', 'buddynext' );

			$rows[] = array(
				'id'           => $uid,
				'name'         => $name,
				'initials'     => \BuddyNext\Profile\AvatarService::initials_for( $name ),
				'bio'          => (string) get_user_meta( $uid, 'bn_field_bio', true ),
				'profile_url'  => (string) \BuddyNext\Core\PageRouter::profile_url( $uid ),
				'is_self'      => ( $uid === $viewer_id ),
				'is_following' => ! empty( $following[ $uid ] ),
			);
		}

		return $rows;
	}

	/**
	 * Enrich space result rows: name, description, member count, membership in batch.
	 *
	 * @param int[] $space_ids Space IDs.
	 * @param int   $viewer_id Viewing user ID.
	 * @return array[] Each row: id, name, initials, description, member_count, space_url, is_member.
	 */
	private function enrich_spaces( array $space_ids, int $viewer_id ): array {
		$spaces     = buddynext_service( 'spaces' );
		$membership = ( $viewer_id > 0 )
			? buddynext_service( 'space_members' )->membership_map( $viewer_id, $space_ids )
			: array();

		$rows = array();
		foreach ( $space_ids as $sid ) {
			$space = $spaces->get( $sid );
			if ( null === $space ) {
				continue;
			}
			$name = (string) ( $space['name'] ?? '' );

			$rows[] = array(
				'id'           => $sid,
				'name'         => $name,
				'initials'     => \BuddyNext\Profile\AvatarService::initials_for( $name ),
				'description'  => (string) ( $space['description'] ?? '' ),
				'member_count' => (int) ( $space['member_count'] ?? 0 ),
				'space_url'    => (string) \BuddyNext\Core\PageRouter::space_url( $sid ),
				'is_member'    => isset( $membership[ $sid ] ),
			);
		}

		return $rows;
	}

	/**
	 * Enrich post (and media) result rows: author, age, stats, snippet source.
	 *
	 * Posts hydrate through PostService::hydrate() so the section part renders the
	 * canonical post shape rather than a hand-built row. The original search
	 * `content` is preserved as `snippet_source` so the highlight helper can still
	 * mark the matched terms.
	 *
	 * @param array[] $items search() items (object_id / content / author_id).
	 * @return array[] Each row: id, author_id, author_name, author_initials, age, reactions, comments, shares, snippet_source.
	 */
	private function enrich_posts( array $items ): array {
		$posts = buddynext_service( 'post_service' );

		$rows = array();
		foreach ( $items as $item ) {
			$post_id = (int) ( $item['object_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$post = $posts->get( $post_id );

			$author_id   = $post ? (int) $post['user_id'] : (int) ( $item['author_id'] ?? 0 );
			$author_user = $author_id ? get_userdata( $author_id ) : null;
			$author_name = $author_user ? $author_user->display_name : __( 'Unknown', 'buddynext' );

			$rows[] = array(
				'id'              => $post_id,
				'author_id'       => $author_id,
				'author_name'     => $author_name,
				'author_initials' => \BuddyNext\Profile\AvatarService::initials_for( $author_name ),
				'age'             => $post ? buddynext_time_ago( (string) ( $post['created_at'] ?? '' ) ) : '',
				'reactions'       => $post ? (int) $post['reaction_count'] : 0,
				'comments'        => $post ? (int) $post['comment_count'] : 0,
				'shares'          => $post ? (int) $post['share_count'] : 0,
				'snippet_source'  => (string) ( $item['content'] ?? '' ),
			);
		}

		return $rows;
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
