<?php
/**
 * Search results template - v2 design system.
 *
 * Performs a MySQL FULLTEXT search across the bn_search_index table for the
 * given query, then renders grouped results (Members / Posts / Spaces /
 * Hashtags / Media) using v2 primitives (.bn-input, .bn-tabs, .bn-tab,
 * .bn-card, .bn-badge, .bn-avatar, .bn-kbd) and tokens.
 *
 * Mirrors `docs/v2 Plans/v2/search-results.html`.
 *
 * Composer responsibilities:
 *   - Sanitize query / tab / date / sort input.
 *   - Run the MySQL FULLTEXT queries against bn_search_index (members /
 *     posts / spaces / hashtags / media), respecting viewer blocks +
 *     suspended / shadow-banned exclusions.
 *   - Compute per-tab counts.
 *   - Build the highlight + initials helpers.
 *   - Wire the right-sidebar hook.
 *   - Delegate every visual block to a part under templates/parts/.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Sanitize query input.
$raw_query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Allowed tabs.
$allowed_tabs = array( 'all', 'members', 'posts', 'spaces', 'hashtags', 'media' );
$active_tab   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// Accept legacy "people" alias from older bookmarks.
if ( 'people' === $active_tab ) {
	$active_tab = 'members';
}
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'all';
}

// Allowed date filters.
$allowed_dates = array( 'any', 'week', 'month', 'year' );
$date_filter   = isset( $_GET['date'] ) ? sanitize_key( wp_unslash( $_GET['date'] ) ) : 'any'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $date_filter, $allowed_dates, true ) ) {
	$date_filter = 'any';
}

// Allowed sort options.
$allowed_sorts = array( 'relevant', 'recent' );
$sort_by       = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'relevant'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $sort_by, $allowed_sorts, true ) ) {
	$sort_by = 'relevant';
}

// Only run queries when there is a search term.
$results_members  = array();
$results_posts    = array();
$results_spaces   = array();
$results_hashtags = array();
$results_media    = array();
$total_counts     = array(
	'all'      => 0,
	'members'  => 0,
	'posts'    => 0,
	'spaces'   => 0,
	'hashtags' => 0,
	'media'    => 0,
);

if ( '' !== $raw_query ) {
	// Date boundary SQL fragment - literal SQL, no user data.
	$date_sql = '';
	switch ( $date_filter ) {
		case 'week':
			$date_sql = ' AND s.updated_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )';
			break;
		case 'month':
			$date_sql = ' AND s.updated_at >= DATE_SUB( NOW(), INTERVAL 1 MONTH )';
			break;
		case 'year':
			$date_sql = ' AND s.updated_at >= DATE_SUB( NOW(), INTERVAL 1 YEAR )';
			break;
	}

	// Excluded user IDs (suspended + shadow-banned + blocked by viewer) - literal ints, safe to interpolate.
	$viewer_id = get_current_user_id();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$excluded_raw = (array) $wpdb->get_col(
		"SELECT DISTINCT user_id FROM {$wpdb->prefix}usermeta
		 WHERE meta_key IN ('bn_suspended','bn_shadow_banned') AND meta_value = '1'"
	);
	// Add users blocked by (or who blocked) the current viewer.
	if ( $viewer_id > 0 ) {
		$blocked_raw  = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT IF( blocker_id = %d, blocked_id, blocker_id )
				 FROM {$wpdb->prefix}bn_blocks
				 WHERE blocker_id = %d OR blocked_id = %d",
				$viewer_id,
				$viewer_id,
				$viewer_id
			)
		);
		$excluded_raw = array_unique( array_merge( $excluded_raw, $blocked_raw ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$excluded_sql = ! empty( $excluded_raw )
		? ' AND s.author_id NOT IN (' . implode( ',', array_map( 'absint', $excluded_raw ) ) . ')'
		: '';

	$boolean_query = '+' . implode( ' +', array_map( 'trim', explode( ' ', $raw_query ) ) );

	// Build the ORDER BY clause. When sorting by relevance, pass the query as a second %s.
	// $date_sql, $excluded_sql, and $order_sql contain only literal SQL - no user input.
	if ( 'recent' === $sort_by ) {
		$order_sql  = 'ORDER BY s.updated_at DESC';
		$query_args = array( $boolean_query );
	} else {
		$order_sql  = 'ORDER BY MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE ) DESC';
		$query_args = array( $boolean_query, $boolean_query );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	// Fetch members.
	if ( 'all' === $active_tab || 'members' === $active_tab ) {
		$members_sql             = $wpdb->prepare(
			"SELECT s.object_id, s.content, s.author_id
			 FROM {$wpdb->prefix}bn_search_index AS s
			 WHERE s.object_type = 'user'
			   AND s.visibility = 'public'
			   AND MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE )
			   {$date_sql}{$excluded_sql}
			 {$order_sql}
			 LIMIT 5",
			...$query_args
		);
		$results_members         = $wpdb->get_results( $members_sql ) ?? array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$total_counts['members'] = count( $results_members );
	}

	// Fetch posts.
	if ( 'all' === $active_tab || 'posts' === $active_tab ) {
		$posts_sql             = $wpdb->prepare(
			"SELECT s.object_id, s.content, s.author_id
			 FROM {$wpdb->prefix}bn_search_index AS s
			 WHERE s.object_type = 'post'
			   AND s.visibility = 'public'
			   AND MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE )
			   {$date_sql}{$excluded_sql}
			 {$order_sql}
			 LIMIT 5",
			...$query_args
		);
		$results_posts         = $wpdb->get_results( $posts_sql ) ?? array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$total_counts['posts'] = count( $results_posts );
	}

	// Fetch spaces.
	if ( 'all' === $active_tab || 'spaces' === $active_tab ) {
		$spaces_sql             = $wpdb->prepare(
			"SELECT s.object_id, s.content, s.author_id
			 FROM {$wpdb->prefix}bn_search_index AS s
			 WHERE s.object_type = 'space'
			   AND s.visibility = 'public'
			   AND MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE )
			   {$date_sql}{$excluded_sql}
			 {$order_sql}
			 LIMIT 5",
			...$query_args
		);
		$results_spaces         = $wpdb->get_results( $spaces_sql ) ?? array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$total_counts['spaces'] = count( $results_spaces );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	// Hashtags: name match via bn_hashtags slug.
	if ( 'all' === $active_tab || 'hashtags' === $active_tab ) {
		$tag_q    = ltrim( $raw_query, '#' );
		$like_tag = '%' . $wpdb->esc_like( $tag_q ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results_hashtags = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, slug, post_count
				 FROM {$wpdb->prefix}bn_hashtags
				 WHERE slug LIKE %s
				 ORDER BY post_count DESC, slug ASC
				 LIMIT 10",
				$like_tag
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_counts['hashtags'] = count( $results_hashtags );
	}

	// Media: posts that have media_ids and match the query.
	if ( 'all' === $active_tab || 'media' === $active_tab ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$media_sql             = $wpdb->prepare(
			"SELECT s.object_id, s.content, s.author_id
			 FROM {$wpdb->prefix}bn_search_index AS s
			 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = s.object_id
			 WHERE s.object_type = 'post'
			   AND s.visibility = 'public'
			   AND p.media_ids IS NOT NULL
			   AND p.media_ids != ''
			   AND MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE )
			   {$date_sql}{$excluded_sql}
			 {$order_sql}
			 LIMIT 12",
			...$query_args
		);
		$results_media         = (array) $wpdb->get_results( $media_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$total_counts['media'] = count( $results_media );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	$total_counts['all'] = $total_counts['members']
		+ $total_counts['posts']
		+ $total_counts['spaces']
		+ $total_counts['hashtags']
		+ $total_counts['media'];
}

/**
 * Highlight search terms in a text snippet by wrapping them in <mark> tags.
 *
 * Returns a safely escaped snippet with allowed <mark> HTML only.
 *
 * @param string $text  Raw text to highlight.
 * @param string $query Search query string.
 * @return string Escaped HTML with highlighted terms.
 */
$highlight = static function ( string $text, string $query ): string {
	if ( '' === $query ) {
		return esc_html( $text );
	}
	// Trim to 200 chars around first match.
	$pos = stripos( $text, $query );
	if ( false !== $pos ) {
		$start = max( 0, $pos - 60 );
		$text  = ( $start > 0 ? '&hellip;' : '' ) . substr( $text, $start, 200 );
	} else {
		$text = substr( $text, 0, 200 );
	}
	// Escape first, then wrap terms in <mark>.
	$escaped = esc_html( $text );
	$terms   = array_filter( array_map( 'trim', explode( ' ', $query ) ) );
	foreach ( $terms as $term ) {
		$escaped = (string) preg_replace(
			'/(' . preg_quote( esc_html( $term ), '/' ) . ')/i',
			'<mark>$1</mark>',
			$escaped
		);
	}
	return $escaped;
};

$current_user_id = get_current_user_id();

add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => $current_user_id,
			)
		);
	}
);

/**
 * Fires before the search results inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_search_before', $current_user_id );

// Pre-compute type tab definitions for the type-tabs part.
$type_tabs = array(
	'all'      => array(
		'label' => __( 'All', 'buddynext' ),
		'count' => $total_counts['all'],
	),
	'members'  => array(
		'label' => __( 'Members', 'buddynext' ),
		'count' => $total_counts['members'],
	),
	'posts'    => array(
		'label' => __( 'Posts', 'buddynext' ),
		'count' => $total_counts['posts'],
	),
	'spaces'   => array(
		'label' => __( 'Spaces', 'buddynext' ),
		'count' => $total_counts['spaces'],
	),
	'hashtags' => array(
		'label' => __( 'Hashtags', 'buddynext' ),
		'count' => $total_counts['hashtags'],
	),
	'media'    => array(
		'label' => __( 'Media', 'buddynext' ),
		'count' => $total_counts['media'],
	),
);
?>

<div class="bn-feed-stack bn-search-shell"
	data-wp-interactive="buddynext/search"
	data-wp-context='{"query":"<?php echo esc_attr( $raw_query ); ?>","activeTab":"<?php echo esc_attr( $active_tab ); ?>"}'>

	<h1 class="bn-search-shell__h1 screen-reader-text">
		<?php
		if ( '' !== $raw_query ) {
			printf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for %s', 'buddynext' ),
				esc_html( $raw_query )
			);
		} else {
			esc_html_e( 'Search', 'buddynext' );
		}
		?>
	</h1>

	<!-- Search hero -->
	<?php
	buddynext_get_template(
		'parts/search-hero.php',
		array(
			'query'         => $raw_query,
			'total_results' => (int) $total_counts['all'],
			'active_type'   => $active_tab,
		)
	);
	?>

	<!-- Type filter tabs -->
	<?php
	buddynext_get_template(
		'parts/search-type-tabs.php',
		array(
			'active_type'    => $active_tab,
			'tabs'           => $type_tabs,
			'counts_by_type' => $total_counts,
			'query'          => $raw_query,
		)
	);
	?>

	<?php if ( '' === $raw_query ) : ?>

		<!-- Empty state: no query -->
		<?php
		buddynext_get_template(
			'parts/search-empty-state.php',
			array(
				'state' => 'no_query',
				'query' => $raw_query,
			)
		);
		?>

	<?php else : ?>

		<div class="bn-search-layout">
			<div class="bn-search-results">

				<!-- Members section -->
				<?php
				if ( 'all' === $active_tab || 'members' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-members.php',
						array(
							'members'     => $results_members,
							'viewer_id'   => $current_user_id,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['members'],
						)
					);
				}
				?>

				<!-- Posts section -->
				<?php
				if ( 'all' === $active_tab || 'posts' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-posts.php',
						array(
							'posts'        => $results_posts,
							'viewer_id'    => $current_user_id,
							'query'        => $raw_query,
							'active_type'  => $active_tab,
							'total_count'  => (int) $total_counts['posts'],
							'highlight_fn' => $highlight,
						)
					);
				}
				?>

				<!-- Spaces section -->
				<?php
				if ( 'all' === $active_tab || 'spaces' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-spaces.php',
						array(
							'spaces'      => $results_spaces,
							'viewer_id'   => $current_user_id,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['spaces'],
						)
					);
				}
				?>

				<!-- Hashtags section -->
				<?php
				if ( 'all' === $active_tab || 'hashtags' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-hashtags.php',
						array(
							'hashtags'    => $results_hashtags,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['hashtags'],
						)
					);
				}
				?>

				<!-- Media section -->
				<?php
				if ( 'all' === $active_tab || 'media' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-media.php',
						array(
							'media'        => $results_media,
							'viewer_id'    => $current_user_id,
							'query'        => $raw_query,
							'active_type'  => $active_tab,
							'total_count'  => (int) $total_counts['media'],
							'highlight_fn' => $highlight,
						)
					);
				}
				?>

				<!-- No results state -->
				<?php
				if ( 0 === $total_counts['all'] ) {
					buddynext_get_template(
						'parts/search-empty-state.php',
						array(
							'state' => 'no_results',
							'query' => $raw_query,
						)
					);
				}
				?>

			</div><!-- /.bn-search-results -->

			<!-- Filter aside -->
			<aside class="bn-search-aside" aria-label="<?php esc_attr_e( 'Refine search', 'buddynext' ); ?>">

				<div class="bn-card bn-search-aside__card" data-interactive>
					<h3 class="bn-search-aside__title">
						<?php esc_html_e( 'Date', 'buddynext' ); ?>
					</h3>
					<?php
					$date_opts = array(
						'any'   => __( 'Any time', 'buddynext' ),
						'week'  => __( 'Past week', 'buddynext' ),
						'month' => __( 'Past month', 'buddynext' ),
						'year'  => __( 'Past year', 'buddynext' ),
					);
					foreach ( $date_opts as $dval => $dlabel ) :
						$dhref  = esc_url(
							add_query_arg(
								array(
									'q'    => $raw_query,
									'type' => $active_tab,
									'date' => $dval,
									'sort' => $sort_by,
								)
							)
						);
						$opt_id = 'bn-search-date-' . sanitize_key( $dval );
						?>
						<label class="bn-search-aside__opt" for="<?php echo esc_attr( $opt_id ); ?>">
							<input
								id="<?php echo esc_attr( $opt_id ); ?>"
								type="radio"
								name="bn_date_filter"
								value="<?php echo esc_attr( $dval ); ?>"
								<?php checked( $date_filter, $dval ); ?>
								data-wp-on--change="actions.applyDateFilter"
								data-href="<?php echo esc_url( $dhref ); ?>">
							<span><?php echo esc_html( $dlabel ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="bn-card bn-search-aside__card" data-interactive>
					<h3 class="bn-search-aside__title">
						<?php esc_html_e( 'Sort by', 'buddynext' ); ?>
					</h3>
					<?php
					$sort_opts = array(
						'relevant' => __( 'Most relevant', 'buddynext' ),
						'recent'   => __( 'Most recent', 'buddynext' ),
					);
					foreach ( $sort_opts as $sval => $slabel ) :
						$shref  = esc_url(
							add_query_arg(
								array(
									'q'    => $raw_query,
									'type' => $active_tab,
									'date' => $date_filter,
									'sort' => $sval,
								)
							)
						);
						$opt_id = 'bn-search-sort-' . sanitize_key( $sval );
						?>
						<label class="bn-search-aside__opt" for="<?php echo esc_attr( $opt_id ); ?>">
							<input
								id="<?php echo esc_attr( $opt_id ); ?>"
								type="radio"
								name="bn_sort_filter"
								value="<?php echo esc_attr( $sval ); ?>"
								<?php checked( $sort_by, $sval ); ?>
								data-wp-on--change="actions.applySortFilter"
								data-href="<?php echo esc_url( $shref ); ?>">
							<span><?php echo esc_html( $slabel ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<?php
				// Related searches: derive from query tokens.
				$related = array_filter(
					array_map( 'trim', explode( ' ', $raw_query ) ),
					static fn( string $t ): bool => mb_strlen( $t ) > 3
				);
				if ( ! empty( $related ) ) :
					?>
					<div class="bn-card bn-search-aside__card" data-interactive>
						<h3 class="bn-search-aside__title">
							<?php esc_html_e( 'Related searches', 'buddynext' ); ?>
						</h3>
						<div class="bn-search-aside__tags">
							<?php foreach ( array_slice( $related, 0, 6 ) as $rel_term ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'q', $rel_term ) ); ?>"
									class="bn-badge bn-search-aside__tag"
									data-tone="accent">
									<span aria-hidden="true"><?php buddynext_icon( 'hash' ); ?></span>
									<?php echo esc_html( $rel_term ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

			</aside>
		</div><!-- /.bn-search-layout -->

	<?php endif; // End: raw_query check. ?>
</div><!-- /.bn-search-shell -->

<?php
/**
 * Fires after the search results inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_search_after', $current_user_id );
?>
