<?php
/**
 * Search results template.
 *
 * Performs a MySQL FULLTEXT search across the bn_search_index table for the
 * given query, then renders grouped results (People / Posts / Spaces) with
 * term highlighting and a filter sidebar.
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
$allowed_tabs = array( 'all', 'people', 'posts', 'spaces' );
$active_tab   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
$allowed_sorts = array( 'relevant', 'recent', 'popular' );
$sort_by       = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'relevant'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $sort_by, $allowed_sorts, true ) ) {
	$sort_by = 'relevant';
}

// Only run queries when there is a search term.
$results_people = array();
$results_posts  = array();
$results_spaces = array();
$total_counts   = array(
	'all'    => 0,
	'people' => 0,
	'posts'  => 0,
	'spaces' => 0,
);

if ( '' !== $raw_query ) {
	// Date boundary SQL fragment — literal SQL, no user data.
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

	// Excluded user IDs (suspended + shadow-banned + blocked by viewer) — literal ints, safe to interpolate.
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
	// $date_sql, $excluded_sql, and $order_sql contain only literal SQL — no user input.
	if ( 'recent' === $sort_by ) {
		$order_sql  = 'ORDER BY s.updated_at DESC';
		$query_args = array( $boolean_query );
	} else {
		$order_sql  = 'ORDER BY MATCH( s.title, s.content ) AGAINST( %s IN BOOLEAN MODE ) DESC';
		$query_args = array( $boolean_query, $boolean_query );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	// Fetch people.
	if ( 'all' === $active_tab || 'people' === $active_tab ) {
		$people_sql             = $wpdb->prepare(
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
		$results_people         = $wpdb->get_results( $people_sql ) ?? array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$total_counts['people'] = count( $results_people );
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

	$total_counts['all'] = $total_counts['people'] + $total_counts['posts'] + $total_counts['spaces'];
}

// Avatar colour palette — deterministic from object ID.
$avatar_palette = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0f766e', '#d97706', '#475569' );
$avatar_color   = static function ( int $id ) use ( $avatar_palette ): string {
	return $avatar_palette[ $id % count( $avatar_palette ) ];
};

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
$search_url_base = esc_url( remove_query_arg( array( 'q', 'type', 'date', 'sort' ) ) );

$bn_nav_active = 'feed';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<style>
/* ── Design tokens ── */
:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
}

/* ── Shell ── */
.bn-search-shell {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--s6) var(--s5);
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
}

/* ── Search hero ── */
.bn-search-hero {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s5);
	margin-bottom: var(--s5);
}
.bn-search-wrap { position: relative; }
.bn-search-input {
	width: 100%;
	border: 2px solid var(--brand);
	border-radius: var(--radius);
	padding: 14px 110px 14px 44px;
	font-size: var(--text-lg);
	font-family: var(--font-body);
	background: var(--bg);
	color: var(--text-1);
	outline: none;
}
.bn-search-input::placeholder { color: var(--text-3); }
.bn-search-icon {
	position: absolute;
	left: 14px;
	top: 50%;
	transform: translateY(-50%);
	font-size: 20px;
	line-height: 1;
	pointer-events: none;
}
.bn-search-submit {
	position: absolute;
	right: 8px;
	top: 50%;
	transform: translateY(-50%);
	background: var(--brand);
	color: #fff;
	border: none;
	padding: 8px 16px;
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
}
.bn-search-submit:hover { background: var(--brand-hover); }
.bn-search-meta {
	margin-top: var(--s2);
	font-size: var(--text-sm);
	color: var(--text-2);
}

/* ── Type tabs ── */
.bn-search-tabs {
	display: flex;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
	margin-bottom: var(--s5);
}
.bn-stab {
	padding: 11px 18px;
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-right: 1px solid var(--border);
	text-decoration: none;
	transition: background 0.1s;
	white-space: nowrap;
}
.bn-stab:last-child { border-right: none; }
.bn-stab:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-stab--active {
	background: var(--brand);
	color: #fff;
	font-weight: 600;
}
.bn-stab--active:hover { background: var(--brand-hover); }
.bn-stab-count {
	font-size: var(--text-xs);
	opacity: 0.75;
	margin-left: 4px;
}

/* ── Two-column layout ── */
.bn-search-layout {
	display: grid;
	grid-template-columns: 1fr 240px;
	gap: var(--s5);
	align-items: start;
}

/* ── Section headers ── */
.bn-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: var(--text-xs);
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: var(--text-3);
	margin-bottom: var(--s2);
}
.bn-see-all {
	color: var(--brand);
	font-size: var(--text-xs);
	text-transform: none;
	letter-spacing: 0;
	cursor: pointer;
	font-weight: 600;
	text-decoration: none;
}
.bn-see-all:hover { text-decoration: underline; }

/* ── People result ── */
.bn-people-row {
	display: flex;
	gap: var(--s2);
	align-items: center;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s3) 14px;
	margin-bottom: var(--s2);
	cursor: pointer;
	text-decoration: none;
	color: inherit;
	transition: border-color 0.1s;
}
.bn-people-row:hover { border-color: var(--brand); }
.bn-result-ava {
	width: 40px;
	height: 40px;
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	font-size: var(--text-sm);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.bn-person-info { flex: 1; min-width: 0; }
.bn-person-name {
	font-weight: 600;
	font-size: var(--text-sm);
	color: var(--text-1);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.bn-person-meta {
	font-size: var(--text-xs);
	color: var(--text-2);
}

/* ── Post result ── */
.bn-post-result {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 14px;
	margin-bottom: var(--s2);
	cursor: pointer;
	text-decoration: none;
	color: inherit;
	display: block;
	transition: border-color 0.1s;
}
.bn-post-result:hover { border-color: var(--brand); }
.bn-post-author-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-bottom: var(--s2);
}
.bn-post-author-ava {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	font-size: 10px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.bn-post-author-name { font-weight: 600; font-size: var(--text-xs); color: var(--text-1); }
.bn-post-time { font-size: var(--text-xs); color: var(--text-3); }
.bn-post-text {
	font-size: var(--text-sm);
	color: var(--text-2);
	line-height: 1.5;
	margin-bottom: var(--s2);
}
.bn-post-text mark {
	background: #fef9c3;
	font-style: normal;
	padding: 0 2px;
	border-radius: 2px;
	color: inherit;
}
[data-theme="dark"] .bn-post-text mark { background: #3d3a00; }
.bn-post-stats { font-size: var(--text-xs); color: var(--text-3); }

/* ── Space result ── */
.bn-space-result {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 14px;
	margin-bottom: var(--s2);
	display: flex;
	align-items: center;
	gap: var(--s3);
	cursor: pointer;
	text-decoration: none;
	color: inherit;
	transition: border-color 0.1s;
}
.bn-space-result:hover { border-color: var(--brand); }
.bn-space-icon {
	width: 48px;
	height: 48px;
	border-radius: var(--radius);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 22px;
	flex-shrink: 0;
}
.bn-space-info { flex: 1; min-width: 0; }
.bn-space-name { font-weight: 700; font-size: var(--text-sm); color: var(--text-1); }
.bn-space-meta { font-size: var(--text-xs); color: var(--text-2); }

/* ── Buttons ── */
.bn-btn-sm {
	padding: 6px 14px;
	border-radius: var(--radius);
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid;
	white-space: nowrap;
	flex-shrink: 0;
}
.bn-btn-follow { background: var(--brand); color: #fff; border-color: var(--brand); }
.bn-btn-follow:hover { background: var(--brand-hover); border-color: var(--brand-hover); }
.bn-btn-outline { background: var(--surface); color: var(--brand); border-color: var(--brand); }
.bn-btn-outline:hover { background: var(--brand-light); }

/* ── Filter sidebar ── */
.bn-filter-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
	margin-bottom: 14px;
}
.bn-filter-title {
	font-weight: 700;
	font-size: var(--text-sm);
	color: var(--text-1);
	margin-bottom: var(--s2);
}
.bn-filter-sublabel {
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--text-3);
	margin: var(--s2) 0 var(--s1);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}
.bn-filter-option {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: 5px 0;
	font-size: var(--text-xs);
	cursor: pointer;
	color: var(--text-1);
}
.bn-filter-option input { accent-color: var(--brand); cursor: pointer; }
.bn-related-tag {
	display: inline-flex;
	background: var(--brand-light);
	color: var(--brand);
	border-radius: var(--radius);
	padding: 4px var(--s2);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	margin: 3px 2px;
	transition: background 0.1s;
}
.bn-related-tag:hover { background: #d4ecf7; }
[data-theme="dark"] .bn-related-tag:hover { background: #1e3a4a; }

/* ── Section result container ── */
.bn-result-section { margin-bottom: var(--s6); }

/* ── Empty state ── */
.bn-search-empty {
	text-align: center;
	padding: var(--s8) var(--s6);
	color: var(--text-3);
	font-size: var(--text-sm);
}
.bn-search-empty-icon { font-size: 40px; display: block; margin-bottom: var(--s3); }

/* ── Responsive ── */
@media ( max-width: 1024px ) {
	.bn-search-layout { grid-template-columns: 1fr; }
	.bn-search-layout aside { order: -1; }
	.bn-filter-widget { display: none; }
}
@media ( max-width: 640px ) {
	.bn-search-shell { padding: var(--s4) var(--s3); }
	.bn-search-tabs { overflow-x: auto; }
	.bn-stab { flex: 0 0 auto; }
	.bn-search-input { font-size: var(--text-base); padding-left: 38px; padding-right: 90px; }
}
</style>

<div class="bn-hub-shell">

<div class="bn-search-shell"
	data-wp-interactive="buddynext/search"
	data-wp-context='{"query":"<?php echo esc_attr( $raw_query ); ?>","activeTab":"<?php echo esc_attr( $active_tab ); ?>"}'>

	<!-- Search hero -->
	<div class="bn-search-hero">
		<form action="" method="get" class="bn-search-wrap" role="search">
			<span class="bn-search-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
			<input
				class="bn-search-input"
				type="search"
				name="q"
				value="<?php echo esc_attr( $raw_query ); ?>"
				placeholder="<?php esc_attr_e( 'Search people, posts, spaces&hellip;', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Search', 'buddynext' ); ?>"
				autocomplete="off"
			>
			<button class="bn-search-submit" type="submit"><?php esc_html_e( 'Search', 'buddynext' ); ?></button>
			<?php if ( 'all' !== $active_tab ) : ?>
				<input type="hidden" name="type" value="<?php echo esc_attr( $active_tab ); ?>">
			<?php endif; ?>
		</form>
		<?php if ( '' !== $raw_query && $total_counts['all'] > 0 ) : ?>
			<div class="bn-search-meta">
				<?php
				// translators: %1$d is the number of results, %2$s is the search query.
				$search_result_msg = __( 'About %1$d results for %2$s', 'buddynext' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				printf( $search_result_msg, (int) $total_counts['all'], '<strong>' . esc_html( $raw_query ) . '</strong>' );
				?>
			</div>
		<?php elseif ( '' !== $raw_query ) : ?>
			<div class="bn-search-meta">
				<?php
				// translators: %s is the search query.
				printf( esc_html__( 'No results for %s', 'buddynext' ), '<strong>' . esc_html( $raw_query ) . '</strong>' );
				?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Type tabs -->
	<nav class="bn-search-tabs" aria-label="<?php esc_attr_e( 'Search result types', 'buddynext' ); ?>">
		<?php
		$type_tabs = array(
			'all'    => array(
				'label' => __( 'All', 'buddynext' ),
				'count' => $total_counts['all'],
			),
			'people' => array(
				'label' => __( 'People', 'buddynext' ),
				'count' => $total_counts['people'],
			),
			'posts'  => array(
				'label' => __( 'Posts', 'buddynext' ),
				'count' => $total_counts['posts'],
			),
			'spaces' => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'count' => $total_counts['spaces'],
			),
		);
		foreach ( $type_tabs as $tab_key => $search_tab ) :
			$is_active = ( $tab_key === $active_tab );
			$tab_href  = esc_url(
				add_query_arg(
					array(
						'q'    => $raw_query,
						'type' => $tab_key,
					)
				)
			);
			?>
			<a href="<?php echo $tab_href; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
				class="bn-stab<?php echo $is_active ? ' bn-stab--active' : ''; ?>"
				aria-current="<?php echo $is_active ? 'page' : 'false'; ?>">
				<?php echo esc_html( $search_tab['label'] ); ?>
				<?php if ( '' !== $raw_query ) : ?>
					<span class="bn-stab-count"><?php echo esc_html( (string) $search_tab['count'] ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( '' === $raw_query ) : ?>
		<div class="bn-search-empty">
			<span class="bn-search-empty-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
			<?php esc_html_e( 'Enter a search term above to find people, posts, and spaces.', 'buddynext' ); ?>
		</div>
	<?php else : ?>

	<div class="bn-search-layout">
		<div>

			<!-- People section -->
			<?php if ( ( 'all' === $active_tab || 'people' === $active_tab ) && ! empty( $results_people ) ) : ?>
				<div class="bn-result-section">
					<div class="bn-section-header">
						<?php esc_html_e( 'People', 'buddynext' ); ?>
						<?php if ( $total_counts['people'] > 0 ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'q'    => $raw_query,
										'type' => 'people',
									)
								)
							);
							?>
										" class="bn-see-all">
								<?php
								// translators: %d is the count of people results.
								echo esc_html( sprintf( __( 'See all %d', 'buddynext' ), $total_counts['people'] ) );
								?>
								&rarr;
							</a>
						<?php endif; ?>
					</div>

					<?php foreach ( $results_people as $person ) : ?>
						<?php
						$pid          = (int) $person->object_id;
						$puser        = get_userdata( $pid );
						$pname        = $puser ? $puser->display_name : '';
						$pinits       = strtoupper( substr( $pname, 0, 1 ) . substr( (string) strrchr( $pname, ' ' ), 1, 1 ) );
						$bio_raw      = (string) get_user_meta( $pid, 'bn_field_bio', true );
						$pmeta_tx     = esc_html( $bio_raw ? $bio_raw : '' );
						$is_following = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->prepare(
								"SELECT 1 FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d",
								$current_user_id,
								$pid
							)
						);
						?>
						<div class="bn-people-row">
							<div class="bn-result-ava" style="background:<?php echo esc_attr( $avatar_color( $pid ) ); ?>;">
								<?php echo esc_html( $pinits ); ?>
							</div>
							<div class="bn-person-info">
								<div class="bn-person-name"><?php echo esc_html( $pname ); ?></div>
								<?php if ( $pmeta_tx ) : ?>
									<div class="bn-person-meta"><?php echo $pmeta_tx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?></div>
								<?php endif; ?>
							</div>
							<?php if ( $current_user_id && $current_user_id !== $pid ) : ?>
								<button class="bn-btn-sm <?php echo $is_following ? 'bn-btn-outline' : 'bn-btn-follow'; ?>"
									data-wp-on--click="actions.toggleFollow"
									data-user-id="<?php echo esc_attr( (string) $pid ); ?>">
									<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Posts section -->
			<?php if ( ( 'all' === $active_tab || 'posts' === $active_tab ) && ! empty( $results_posts ) ) : ?>
				<div class="bn-result-section">
					<div class="bn-section-header">
						<?php esc_html_e( 'Posts', 'buddynext' ); ?>
						<?php if ( $total_counts['posts'] > 0 ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'q'    => $raw_query,
										'type' => 'posts',
									)
								)
							);
							?>
										" class="bn-see-all">
								<?php
								// translators: %d is the count of post results.
								echo esc_html( sprintf( __( 'See all %d', 'buddynext' ), $total_counts['posts'] ) );
								?>
								&rarr;
							</a>
						<?php endif; ?>
					</div>

					<?php foreach ( $results_posts as $post_item ) : ?>
						<?php
						$post_id_int = (int) $post_item->object_id;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$bn_post_row  = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, created_at, reaction_count, comment_count, share_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id_int ) );
						$author_id    = $bn_post_row ? (int) $bn_post_row->user_id : (int) $post_item->author_id;
						$author_user  = $author_id ? get_userdata( $author_id ) : null;
						$author_name  = $author_user ? $author_user->display_name : __( 'Unknown', 'buddynext' );
						$author_inits = strtoupper( substr( $author_name, 0, 1 ) . substr( (string) strrchr( $author_name, ' ' ), 1, 1 ) );
						$post_age     = $bn_post_row ? human_time_diff( (int) strtotime( (string) $bn_post_row->created_at ), time() ) . ' ' . __( 'ago', 'buddynext' ) : '';
						$reactions    = $bn_post_row ? (int) $bn_post_row->reaction_count : 0;
						$comments_c   = $bn_post_row ? (int) $bn_post_row->comment_count : 0;
						$shares_c     = $bn_post_row ? (int) $bn_post_row->share_count : 0;
						?>
						<div class="bn-post-result">
							<div class="bn-post-author-row">
								<div class="bn-post-author-ava" style="background:<?php echo esc_attr( $avatar_color( $author_id ) ); ?>;">
									<?php echo esc_html( $author_inits ); ?>
								</div>
								<span class="bn-post-author-name"><?php echo esc_html( $author_name ); ?></span>
								<?php if ( $post_age ) : ?>
									<span class="bn-post-time">&middot; <?php echo esc_html( $post_age ); ?></span>
								<?php endif; ?>
							</div>
							<div class="bn-post-text">
								<?php echo $highlight( $post_item->content, $raw_query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- highlight() returns safe HTML. ?>
							</div>
							<?php if ( $reactions || $comments_c || $shares_c ) : ?>
								<div class="bn-post-stats">
									<?php buddynext_icon( 'heart' ); ?> <?php echo esc_html( (string) $reactions ); ?>
									&nbsp;&middot;&nbsp;
									<?php buddynext_icon( 'message-circle' ); ?> <?php echo esc_html( (string) $comments_c ); ?>
									&nbsp;&middot;&nbsp;
									<?php buddynext_icon( 'share' ); ?> <?php echo esc_html( (string) $shares_c ); ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Spaces section -->
			<?php if ( ( 'all' === $active_tab || 'spaces' === $active_tab ) && ! empty( $results_spaces ) ) : ?>
				<div class="bn-result-section">
					<div class="bn-section-header">
						<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
						<?php if ( $total_counts['spaces'] > 0 ) : ?>
							<a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'q'    => $raw_query,
										'type' => 'spaces',
									)
								)
							);
							?>
										" class="bn-see-all">
								<?php
								// translators: %d is the count of space results.
								echo esc_html( sprintf( __( 'See all %d', 'buddynext' ), $total_counts['spaces'] ) );
								?>
								&rarr;
							</a>
						<?php endif; ?>
					</div>

					<?php foreach ( $results_spaces as $space ) : ?>
						<?php
						$space_id_int = (int) $space->object_id;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$bn_space_row = $wpdb->get_row( $wpdb->prepare( "SELECT name, description, member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id_int ) );
						$space_name   = esc_html( $bn_space_row ? (string) $bn_space_row->name : $space->content );
						$space_desc   = esc_html( $bn_space_row ? (string) $bn_space_row->description : '' );
						$member_count = $bn_space_row ? (int) $bn_space_row->member_count : 0;
						$is_member    = $current_user_id ? (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->prepare(
								"SELECT 1 FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
								$space_id_int,
								$current_user_id
							)
						) : false;
						?>
						<div class="bn-space-result">
							<div class="bn-space-icon" aria-hidden="true"><?php buddynext_icon( 'home' ); ?></div>
							<div class="bn-space-info">
								<div class="bn-space-name"><?php echo $space_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?></div>
								<?php if ( $space_desc ) : ?>
									<div class="bn-space-meta">
										<?php if ( $member_count ) : ?>
											<?php
											// translators: %d is the member count.
											printf( esc_html__( '%d members', 'buddynext' ), esc_html( (string) $member_count ) );
											echo ' &middot; ';
											?>
										<?php endif; ?>
										<?php echo $space_desc; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?>
									</div>
								<?php endif; ?>
							</div>
							<?php if ( $current_user_id ) : ?>
								<button class="bn-btn-sm bn-btn-outline"
									data-wp-on--click="actions.toggleSpaceMembership"
									data-space-id="<?php echo esc_attr( (string) $space_id_int ); ?>">
									<?php echo $is_member ? esc_html__( 'Joined', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- No results state -->
			<?php if ( 0 === $total_counts['all'] ) : ?>
				<div class="bn-search-empty">
					<span class="bn-search-empty-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
					<?php
					// translators: %s is the search query.
					printf( esc_html__( 'Nothing found for "%s". Try different keywords.', 'buddynext' ), esc_html( $raw_query ) );
					?>
				</div>
			<?php endif; ?>

		</div><!-- /main col -->

		<!-- Filter sidebar -->
		<aside>
			<div class="bn-filter-widget">
				<div class="bn-filter-title"><?php esc_html_e( 'Filter Results', 'buddynext' ); ?></div>

				<div class="bn-filter-sublabel"><?php esc_html_e( 'Date Posted', 'buddynext' ); ?></div>
				<?php
				$date_opts = array(
					'any'   => __( 'Any time', 'buddynext' ),
					'week'  => __( 'Past week', 'buddynext' ),
					'month' => __( 'Past month', 'buddynext' ),
					'year'  => __( 'Past year', 'buddynext' ),
				);
				foreach ( $date_opts as $dval => $dlabel ) :
					$dhref = esc_url(
						add_query_arg(
							array(
								'q'    => $raw_query,
								'type' => $active_tab,
								'date' => $dval,
								'sort' => $sort_by,
							)
						)
					);
					?>
					<label class="bn-filter-option">
						<input type="radio" name="bn_date_filter" value="<?php echo esc_attr( $dval ); ?>"
							<?php checked( $date_filter, $dval ); ?>
							data-wp-on--change="actions.applyDateFilter"
							data-href="<?php echo $dhref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>">
						<?php echo esc_html( $dlabel ); ?>
					</label>
				<?php endforeach; ?>

				<div class="bn-filter-sublabel"><?php esc_html_e( 'Sort By', 'buddynext' ); ?></div>
				<?php
				$sort_opts = array(
					'relevant' => __( 'Most relevant', 'buddynext' ),
					'recent'   => __( 'Most recent', 'buddynext' ),
				);
				foreach ( $sort_opts as $sval => $slabel ) :
					$shref = esc_url(
						add_query_arg(
							array(
								'q'    => $raw_query,
								'type' => $active_tab,
								'date' => $date_filter,
								'sort' => $sval,
							)
						)
					);
					?>
					<label class="bn-filter-option">
						<input type="radio" name="bn_sort_filter" value="<?php echo esc_attr( $sval ); ?>"
							<?php checked( $sort_by, $sval ); ?>
							data-wp-on--change="actions.applySortFilter"
							data-href="<?php echo $shref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>">
						<?php echo esc_html( $slabel ); ?>
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
				<div class="bn-filter-widget">
					<div class="bn-filter-title"><?php esc_html_e( 'Related Searches', 'buddynext' ); ?></div>
					<?php foreach ( array_slice( $related, 0, 6 ) as $rel_term ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'q', $rel_term ) ); ?>" class="bn-related-tag">
							<?php echo esc_html( $rel_term ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</aside>
	</div><!-- /layout -->

	<?php endif; // End: raw_query check. ?>
</div><!-- /.bn-search-shell -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
