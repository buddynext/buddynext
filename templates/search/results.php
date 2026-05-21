<?php
/**
 * Search results template - v2 design system.
 *
 * Performs a MySQL FULLTEXT search across the bn_search_index table for the
 * given query, then renders grouped results (Members / Posts / Spaces) using
 * v2 primitives (.bn-input, .bn-tabs, .bn-tab, .bn-card, .bn-badge,
 * .bn-avatar, .bn-kbd) and tokens.
 *
 * Mirrors `docs/v2 Plans/v2/search-results.html`.
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
$allowed_tabs = array( 'all', 'members', 'posts', 'spaces', 'hashtags' );
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
$results_members = array();
$results_posts   = array();
$results_spaces  = array();
$total_counts    = array(
	'all'      => 0,
	'members'  => 0,
	'posts'    => 0,
	'spaces'   => 0,
	'hashtags' => 0,
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

	$total_counts['all'] = $total_counts['members'] + $total_counts['posts'] + $total_counts['spaces'];
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

/**
 * Build initials from a display name.
 *
 * @param string $name Display name.
 * @return string Up to two-letter initials, uppercased.
 */
$initials = static function ( string $name ): string {
	$name = trim( $name );
	if ( '' === $name ) {
		return '?';
	}
	$first = mb_substr( $name, 0, 1 );
	$last  = '';
	$space = strrpos( $name, ' ' );
	if ( false !== $space ) {
		$last = mb_substr( $name, $space + 1, 1 );
	}
	return strtoupper( $first . $last );
};

$current_user_id = get_current_user_id();

$bn_nav_active = 'feed';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );

// Pre-compute URLs.
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
);
?>

<div class="bn-hub-shell">

<div class="bn-search-shell"
	data-wp-interactive="buddynext/search"
	data-wp-context='{"query":"<?php echo esc_attr( $raw_query ); ?>","activeTab":"<?php echo esc_attr( $active_tab ); ?>"}'>

	<!-- Search hero -->
	<form action="" method="get" class="bn-search-hero" role="search" aria-label="<?php esc_attr_e( 'Search community', 'buddynext' ); ?>">
		<label for="bn-search-q" class="bn-visually-hidden">
			<?php esc_html_e( 'Search', 'buddynext' ); ?>
		</label>
		<div class="bn-search-hero__field">
			<span class="bn-search-hero__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
			<input
				id="bn-search-q"
				class="bn-input bn-search-hero__input"
				type="search"
				name="q"
				value="<?php echo esc_attr( $raw_query ); ?>"
				placeholder="<?php esc_attr_e( 'Search members, posts, spaces, hashtags', 'buddynext' ); ?>"
				autocomplete="off"
			>
			<?php if ( 'all' !== $active_tab ) : ?>
				<input type="hidden" name="type" value="<?php echo esc_attr( $active_tab ); ?>">
			<?php endif; ?>
			<button type="submit" class="bn-btn bn-search-hero__submit" data-variant="primary" data-size="md">
				<?php esc_html_e( 'Search', 'buddynext' ); ?>
			</button>
		</div>
		<div class="bn-search-hero__hint">
			<?php
			if ( '' !== $raw_query && $total_counts['all'] > 0 ) {
				printf(
					/* translators: %1$s = count, %2$s = search query (escaped). */
					esc_html__( '%1$s results for %2$s', 'buddynext' ),
					'<strong>' . esc_html( (string) $total_counts['all'] ) . '</strong>',
					'<strong>"' . esc_html( $raw_query ) . '"</strong>'
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
			} elseif ( '' !== $raw_query ) {
				printf(
					/* translators: %s = search query (escaped). */
					esc_html__( 'No results for %s', 'buddynext' ),
					'<strong>"' . esc_html( $raw_query ) . '"</strong>'
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
			} else {
				esc_html_e( 'Tip: press', 'buddynext' );
				echo ' <kbd class="bn-kbd">/</kbd> ';
				esc_html_e( 'anywhere to focus search.', 'buddynext' );
			}
			?>
		</div>
	</form>

	<!-- Type filter tabs -->
	<nav class="bn-tabs bn-search-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by type', 'buddynext' ); ?>">
		<?php
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
			<a href="<?php echo esc_url( $tab_href ); ?>"
				class="bn-tab"
				role="tab"
				aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
				<?php echo esc_html( $search_tab['label'] ); ?>
				<?php if ( '' !== $raw_query ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( (string) $search_tab['count'] ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( '' === $raw_query ) : ?>

		<!-- Empty state: no query -->
		<div class="bn-search-empty">
			<span class="bn-search-empty__icon" aria-hidden="true">
				<?php buddynext_icon( 'search' ); ?>
			</span>
			<h2 class="bn-search-empty__title">
				<?php esc_html_e( 'Search the community', 'buddynext' ); ?>
			</h2>
			<p class="bn-search-empty__body">
				<?php esc_html_e( 'Find members, posts, spaces and hashtags. Press', 'buddynext' ); ?>
				<kbd class="bn-kbd">/</kbd>
				<?php esc_html_e( 'anywhere to focus, or', 'buddynext' ); ?>
				<kbd class="bn-kbd">Esc</kbd>
				<?php esc_html_e( 'to clear.', 'buddynext' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="bn-search-layout">
			<div class="bn-search-results">

				<!-- Members section -->
				<?php if ( ( 'all' === $active_tab || 'members' === $active_tab ) && ! empty( $results_members ) ) : ?>
					<section class="bn-search-section" aria-labelledby="bn-search-section-members">
						<header class="bn-search-section__header">
							<h2 id="bn-search-section-members" class="bn-search-section__title">
								<?php esc_html_e( 'Members', 'buddynext' ); ?>
							</h2>
							<span class="bn-search-section__count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %1$d shown, %2$d total. */
										__( '%1$d of %2$d', 'buddynext' ),
										count( $results_members ),
										$total_counts['members']
									)
								);
								?>
							</span>
							<?php if ( 'all' === $active_tab && $total_counts['members'] > 0 ) : ?>
								<a class="bn-search-section__seeall"
									href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'q'    => $raw_query,
												'type' => 'members',
											)
										)
									);
									?>
											">
									<?php esc_html_e( 'See all', 'buddynext' ); ?>
									<span aria-hidden="true"><?php buddynext_icon( 'arrow-right' ); ?></span>
								</a>
							<?php endif; ?>
						</header>

						<div class="bn-search-results__list">
							<?php foreach ( $results_members as $person ) : ?>
								<?php
								$pid          = (int) $person->object_id;
								$puser        = get_userdata( $pid );
								$pname        = $puser ? $puser->display_name : __( 'Unknown', 'buddynext' );
								$pinits       = $initials( $pname );
								$bio_raw      = (string) get_user_meta( $pid, 'bn_field_bio', true );
								$is_following = false;
								if ( $current_user_id && $current_user_id !== $pid ) {
									$is_following = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
										$wpdb->prepare(
											"SELECT 1 FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d",
											$current_user_id,
											$pid
										)
									);
								}
								$profile_url = '';
								if ( function_exists( 'bp_core_get_user_domain' ) ) {
									$profile_url = (string) bp_core_get_user_domain( $pid );
								}
								if ( '' === $profile_url ) {
									$profile_url = (string) get_author_posts_url( $pid );
								}
								?>
								<article class="bn-card bn-search-row bn-search-row--member" data-interactive>
									<a class="bn-search-row__link" href="<?php echo esc_url( $profile_url ); ?>">
										<span class="bn-avatar" data-size="md" aria-hidden="true">
											<?php echo esc_html( $pinits ); ?>
										</span>
										<span class="bn-search-row__info">
											<span class="bn-search-row__title">
												<?php echo esc_html( $pname ); ?>
												<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Member', 'buddynext' ); ?></span>
											</span>
											<?php if ( '' !== $bio_raw ) : ?>
												<span class="bn-search-row__meta">
													<?php echo esc_html( $bio_raw ); ?>
												</span>
											<?php endif; ?>
										</span>
									</a>
									<?php if ( $current_user_id && $current_user_id !== $pid ) : ?>
										<button type="button"
											class="bn-btn"
											data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
											data-size="sm"
											data-wp-on--click="actions.toggleFollow"
											data-user-id="<?php echo esc_attr( (string) $pid ); ?>"
											aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>">
											<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
										</button>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- Posts section -->
				<?php if ( ( 'all' === $active_tab || 'posts' === $active_tab ) && ! empty( $results_posts ) ) : ?>
					<section class="bn-search-section" aria-labelledby="bn-search-section-posts">
						<header class="bn-search-section__header">
							<h2 id="bn-search-section-posts" class="bn-search-section__title">
								<?php esc_html_e( 'Posts', 'buddynext' ); ?>
							</h2>
							<span class="bn-search-section__count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %1$d shown, %2$d total. */
										__( '%1$d of %2$d', 'buddynext' ),
										count( $results_posts ),
										$total_counts['posts']
									)
								);
								?>
							</span>
							<?php if ( 'all' === $active_tab && $total_counts['posts'] > 0 ) : ?>
								<a class="bn-search-section__seeall"
									href="
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
											">
									<?php esc_html_e( 'See all', 'buddynext' ); ?>
									<span aria-hidden="true"><?php buddynext_icon( 'arrow-right' ); ?></span>
								</a>
							<?php endif; ?>
						</header>

						<div class="bn-search-results__list">
							<?php foreach ( $results_posts as $post_item ) : ?>
								<?php
								$post_id_int = (int) $post_item->object_id;
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
								$bn_post_row  = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, created_at, reaction_count, comment_count, share_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id_int ) );
								$author_id    = $bn_post_row ? (int) $bn_post_row->user_id : (int) $post_item->author_id;
								$author_user  = $author_id ? get_userdata( $author_id ) : null;
								$author_name  = $author_user ? $author_user->display_name : __( 'Unknown', 'buddynext' );
								$author_inits = $initials( $author_name );
								$post_age     = $bn_post_row ? human_time_diff( (int) strtotime( (string) $bn_post_row->created_at ), time() ) . ' ' . __( 'ago', 'buddynext' ) : '';
								$reactions    = $bn_post_row ? (int) $bn_post_row->reaction_count : 0;
								$comments_c   = $bn_post_row ? (int) $bn_post_row->comment_count : 0;
								$shares_c     = $bn_post_row ? (int) $bn_post_row->share_count : 0;
								?>
								<article class="bn-card bn-search-row bn-search-row--post" data-interactive>
									<header class="bn-search-row__head">
										<span class="bn-avatar" data-size="sm" aria-hidden="true">
											<?php echo esc_html( $author_inits ); ?>
										</span>
										<span class="bn-search-row__author"><?php echo esc_html( $author_name ); ?></span>
										<?php if ( '' !== $post_age ) : ?>
											<span class="bn-search-row__time">&middot; <?php echo esc_html( $post_age ); ?></span>
										<?php endif; ?>
										<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Post', 'buddynext' ); ?></span>
									</header>
									<div class="bn-search-row__text">
										<?php echo $highlight( (string) $post_item->content, $raw_query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- highlight() returns safe HTML. ?>
									</div>
									<?php if ( $reactions || $comments_c || $shares_c ) : ?>
										<footer class="bn-search-row__stats">
											<span class="bn-search-row__stat">
												<span aria-hidden="true"><?php buddynext_icon( 'heart' ); ?></span>
												<?php echo esc_html( (string) $reactions ); ?>
											</span>
											<span class="bn-search-row__stat">
												<span aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></span>
												<?php echo esc_html( (string) $comments_c ); ?>
											</span>
											<span class="bn-search-row__stat">
												<span aria-hidden="true"><?php buddynext_icon( 'share' ); ?></span>
												<?php echo esc_html( (string) $shares_c ); ?>
											</span>
										</footer>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- Spaces section -->
				<?php if ( ( 'all' === $active_tab || 'spaces' === $active_tab ) && ! empty( $results_spaces ) ) : ?>
					<section class="bn-search-section" aria-labelledby="bn-search-section-spaces">
						<header class="bn-search-section__header">
							<h2 id="bn-search-section-spaces" class="bn-search-section__title">
								<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
							</h2>
							<span class="bn-search-section__count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %1$d shown, %2$d total. */
										__( '%1$d of %2$d', 'buddynext' ),
										count( $results_spaces ),
										$total_counts['spaces']
									)
								);
								?>
							</span>
							<?php if ( 'all' === $active_tab && $total_counts['spaces'] > 0 ) : ?>
								<a class="bn-search-section__seeall"
									href="
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
											">
									<?php esc_html_e( 'See all', 'buddynext' ); ?>
									<span aria-hidden="true"><?php buddynext_icon( 'arrow-right' ); ?></span>
								</a>
							<?php endif; ?>
						</header>

						<div class="bn-search-results__list">
							<?php foreach ( $results_spaces as $space ) : ?>
								<?php
								$space_id_int = (int) $space->object_id;
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
								$bn_space_row = $wpdb->get_row( $wpdb->prepare( "SELECT name, description, member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $space_id_int ) );
								$space_name   = $bn_space_row ? (string) $bn_space_row->name : (string) $space->content;
								$space_desc   = $bn_space_row ? (string) $bn_space_row->description : '';
								$member_count = $bn_space_row ? (int) $bn_space_row->member_count : 0;
								$space_inits  = $initials( $space_name );
								$is_member    = false;
								if ( $current_user_id ) {
									$is_member = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
										$wpdb->prepare(
											"SELECT 1 FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
											$space_id_int,
											$current_user_id
										)
									);
								}
								$space_url = '';
								if ( class_exists( '\\BuddyNext\\Core\\PageRouter' ) ) {
									$space_url = (string) \BuddyNext\Core\PageRouter::space_url( $space_id_int );
								}
								?>
								<article class="bn-card bn-search-row bn-search-row--space" data-interactive>
									<a class="bn-search-row__link" href="<?php echo esc_url( $space_url ); ?>">
										<span class="bn-avatar" data-size="md" aria-hidden="true">
											<?php echo esc_html( $space_inits ); ?>
										</span>
										<span class="bn-search-row__info">
											<span class="bn-search-row__title">
												<?php echo esc_html( $space_name ); ?>
												<span class="bn-badge" data-tone="info"><?php esc_html_e( 'Space', 'buddynext' ); ?></span>
											</span>
											<?php if ( $member_count > 0 || '' !== $space_desc ) : ?>
												<span class="bn-search-row__meta">
													<?php if ( $member_count > 0 ) : ?>
														<?php
														echo esc_html(
															sprintf(
																/* translators: %d = member count. */
																_n( '%d member', '%d members', $member_count, 'buddynext' ),
																$member_count
															)
														);
														?>
														<?php if ( '' !== $space_desc ) : ?>
															<span aria-hidden="true"> &middot; </span>
														<?php endif; ?>
													<?php endif; ?>
													<?php if ( '' !== $space_desc ) : ?>
														<?php echo esc_html( $space_desc ); ?>
													<?php endif; ?>
												</span>
											<?php endif; ?>
										</span>
									</a>
									<?php if ( $current_user_id ) : ?>
										<button type="button"
											class="bn-btn"
											data-variant="<?php echo $is_member ? 'secondary' : 'primary'; ?>"
											data-size="sm"
											data-wp-on--click="actions.toggleSpaceMembership"
											data-space-id="<?php echo esc_attr( (string) $space_id_int ); ?>"
											aria-pressed="<?php echo $is_member ? 'true' : 'false'; ?>">
											<?php echo $is_member ? esc_html__( 'Joined', 'buddynext' ) : esc_html__( 'Join', 'buddynext' ); ?>
										</button>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- No results state -->
				<?php if ( 0 === $total_counts['all'] ) : ?>
					<div class="bn-search-empty">
						<span class="bn-search-empty__icon" aria-hidden="true">
							<?php buddynext_icon( 'search' ); ?>
						</span>
						<h2 class="bn-search-empty__title">
							<?php
							printf(
								/* translators: %s = search query (escaped). */
								esc_html__( 'Nothing found for %s', 'buddynext' ),
								'<strong>"' . esc_html( $raw_query ) . '"</strong>'
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
							?>
						</h2>
						<p class="bn-search-empty__body">
							<?php esc_html_e( 'Try different keywords, or remove a filter from the sidebar.', 'buddynext' ); ?>
						</p>
						<a class="bn-btn" data-variant="primary" data-size="md"
							href="<?php echo esc_url( remove_query_arg( array( 'q', 'type', 'date', 'sort' ) ) ); ?>">
							<?php esc_html_e( 'Search the entire community', 'buddynext' ); ?>
						</a>
					</div>
				<?php endif; ?>

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

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
