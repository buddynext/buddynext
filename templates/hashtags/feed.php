<?php
/**
 * BuddyNext hashtag feed template.
 *
 * Renders a single hashtag's page: hashtag header card with stats, a follow
 * toggle, sort tabs (Latest / Top / Following), and a list of posts tagged
 * with that hashtag rendered through partials/post-card.php. The sidebar
 * shows hashtag metadata, related tags and top contributors.
 *
 * URL pattern : /community/tag/{slug}/
 * Overridable : copy to {theme}/buddynext/hashtags/feed.php
 *
 * REST endpoint: GET buddynext/v1/feed?hashtag={slug}&sort=latest|top|following
 *
 * Layer-3 composition: render is factored into named parts under
 * `templates/parts/hashtag-*.php`. Each part fires the standard 4-hook
 * contract per `docs/specs/TEMPLATE-PARTS.md`.
 *
 * @package BuddyNext
 * @since   1.0.0
 *
 * @var string $hashtag_slug Slug of the hashtag being viewed (set by the router).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

global $wpdb;

// ── Resolve hashtag slug ───────────────────────────────────────────────────
$hashtag_slug = isset( $args['hashtag_slug'] )
	? sanitize_title( $args['hashtag_slug'] )
	: sanitize_title( get_query_var( 'bn_hashtag', '' ) );

// Headers are already sent by wp_head() when this template runs; use an
// inline not-found state rather than wp_safe_redirect().
$hashtag_not_found = ! $hashtag_slug;

// ── Load hashtag row ───────────────────────────────────────────────────────
$hashtag        = null;
$hashtags_table = $wpdb->prefix . 'bn_hashtags';

if ( $hashtag_slug ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$hashtag = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, slug, post_count, created_at
			FROM {$hashtags_table}
			WHERE slug = %s
			LIMIT 1",
			$hashtag_slug
		)
	); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $hashtag ) {
		$hashtag_not_found = true;
	}
}

// ── Current user context ───────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_logged_in    = ( $current_user_id > 0 );
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// ── Sort tab (Latest / Top / Following) ────────────────────────────────────
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL filter.
$bn_sort_raw = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : 'latest';
$bn_sort     = in_array( $bn_sort_raw, array( 'latest', 'top', 'following' ), true ) ? $bn_sort_raw : 'latest';

// ── Hashtag data ───────────────────────────────────────────────────────────
$follows_hashtag    = false;
$hashtag_posts      = array();
$related_tags       = array();
$top_contributors   = array();
$contributor_count  = 0;
$posts_table        = $wpdb->prefix . 'bn_posts';
$post_hashtags_tbl  = $wpdb->prefix . 'bn_post_hashtags';
$hashtag_follows_tb = $wpdb->prefix . 'bn_hashtag_follows';

if ( ! $hashtag_not_found ) {
	if ( $is_logged_in ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$follows_hashtag = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$hashtag_follows_tb} WHERE user_id = %d AND hashtag_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user_id,
				(int) $hashtag->id
			)
		);
	}

	// ── Feed posts for this hashtag ────────────────────────────────────────
	$limit = absint( $args['limit'] ?? 10 );

		// Pagination — LIMIT + OFFSET keyed off ?paged, fetching one extra row to
		// detect whether a further page exists (no separate COUNT(*) needed).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$bn_offset = ( $bn_paged - 1 ) * $limit;
		$bn_fetch  = $limit + 1;

	if ( 'top' === $bn_sort ) {
		// Top of last 7 days, by engagement.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hashtag_posts = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.content, p.type, p.created_at,
				        p.reaction_count, p.comment_count, p.share_count
				FROM {$posts_table} p
				INNER JOIN {$post_hashtags_tbl} ph ON ph.post_id = p.id
				WHERE ph.hashtag_id = %d
				  AND p.status = 'published'
				  AND p.privacy = 'public'
				  AND p.created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )
				ORDER BY (p.reaction_count + p.comment_count * 2) DESC, p.created_at DESC
				LIMIT %d OFFSET %d",
				(int) $hashtag->id,
				$bn_fetch,
				$bn_offset
			)
		); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} elseif ( 'following' === $bn_sort && $is_logged_in ) {
		// Posts by users the viewer follows.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hashtag_posts = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.content, p.type, p.created_at,
				        p.reaction_count, p.comment_count, p.share_count
				FROM {$posts_table} p
				INNER JOIN {$post_hashtags_tbl} ph ON ph.post_id = p.id
				INNER JOIN {$wpdb->prefix}bn_follows f ON f.followed_id = p.user_id AND f.follower_id = %d
				WHERE ph.hashtag_id = %d
				  AND p.status = 'published'
				  AND p.privacy = 'public'
				ORDER BY p.created_at DESC
				LIMIT %d OFFSET %d",
				$current_user_id,
				(int) $hashtag->id,
				$bn_fetch,
				$bn_offset
			)
		); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// Sort by latest (default branch).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hashtag_posts = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.content, p.type, p.created_at,
				        p.reaction_count, p.comment_count, p.share_count
				FROM {$posts_table} p
				INNER JOIN {$post_hashtags_tbl} ph ON ph.post_id = p.id
				WHERE ph.hashtag_id = %d
				  AND p.status = 'published'
				  AND p.privacy = 'public'
				ORDER BY p.created_at DESC
				LIMIT %d OFFSET %d",
				(int) $hashtag->id,
				$bn_fetch,
				$bn_offset
			)
		); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

		// Detect a further page from the probe row, then trim to the page size.
		$bn_has_more = count( $hashtag_posts ) > $limit;
	if ( $bn_has_more ) {
		$hashtag_posts = array_slice( $hashtag_posts, 0, $limit );
	}

	// ── Related hashtags ───────────────────────────────────────────────────
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$related_tags = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT h2.slug, h2.post_count
			FROM {$post_hashtags_tbl} ph1
			INNER JOIN {$post_hashtags_tbl} ph2 ON ph2.post_id = ph1.post_id AND ph2.hashtag_id != %d
			INNER JOIN {$hashtags_table} h2 ON h2.id = ph2.hashtag_id
			WHERE ph1.hashtag_id = %d
			GROUP BY h2.id
			ORDER BY COUNT(*) DESC, h2.post_count DESC
			LIMIT 4",
			(int) $hashtag->id,
			(int) $hashtag->id
		)
	); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// ── Top contributors ───────────────────────────────────────────────────
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$top_contributors = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.user_id, COUNT(*) AS post_count
			FROM {$posts_table} p
			INNER JOIN {$post_hashtags_tbl} ph ON ph.post_id = p.id
			WHERE ph.hashtag_id = %d
			  AND p.status = 'published'
			GROUP BY p.user_id
			ORDER BY post_count DESC
			LIMIT 3",
			(int) $hashtag->id
		)
	); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// ── Contributor total (for stat-grid) ──────────────────────────────────
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$contributor_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.user_id)
			FROM {$posts_table} p
			INNER JOIN {$post_hashtags_tbl} ph ON ph.post_id = p.id
			WHERE ph.hashtag_id = %d
			  AND p.status = 'published'",
			(int) $hashtag->id
		)
	); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Hook the right sidebar widgets onto the shell. has_action() detects
// this registration and the shell auto-renders the right column.
add_action(
	'buddynext_right_sidebar',
	static function () {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => get_current_user_id(),
			)
		);
	}
);

/**
 * Fires before the hashtag feed inner content.
 */
do_action( 'buddynext_hashtag_feed_before' );
?>
<div class="bn-feed-stack bn-hashtag-feed">

<?php
if ( $hashtag_not_found ) :
	?>
	<div class="bn-hashtag-feed">
		<div class="bn-card bn-hashtag-notfound">
			<div class="bn-hashtag-notfound__icon" aria-hidden="true"><?php buddynext_icon( 'hash' ); ?></div>
			<h1 class="bn-hashtag-notfound__title">
				<?php
				echo $hashtag_slug
					? esc_html( sprintf( /* translators: %s: hashtag */ __( '#%s not found', 'buddynext' ), $hashtag_slug ) )
					: esc_html__( 'Hashtag not found', 'buddynext' );
				?>
			</h1>
			<p class="bn-hashtag-notfound__lede">
				<?php esc_html_e( 'This hashtag does not exist yet. Be the first to use it!', 'buddynext' ); ?>
			</p>
		</div>
	</div>
	<?php
else :

	// ── First-use date label ─────────────────────────────────────────────────
	$first_used_label = '';
	if ( null !== $hashtag && $hashtag->created_at ) {
		$first_used_label = date_i18n( get_option( 'date_format' ), (int) strtotime( $hashtag->created_at ) );
	}

	$post_count_total = absint( $hashtag->post_count );
	?>
<div
	class="bn-hashtag-feed"
	data-wp-interactive="buddynext/feed"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'scope'     => 'hashtag',
				'hashtag'   => $hashtag_slug,
				'sort'      => $bn_sort,
				'tab'       => 'posts',
				'page'      => 1,
				'following' => $follows_hashtag,
				'restUrl'   => rest_url( 'buddynext/v1/' ),
				'restNonce' => $rest_nonce,
				'userId'    => $current_user_id,
			)
		)
	);
	?>
	'
>
	<div class="bn-hashtag-shell">

		<!-- ── Feed column ── -->
		<main class="bn-hashtag-feed-area" id="bn-hashtag-feed-main" role="main">

			<?php
			buddynext_get_template(
				'parts/hashtag-hero.php',
				array(
					'hashtag_slug'      => $hashtag_slug,
					'post_count_total'  => $post_count_total,
					'contributor_count' => $contributor_count,
					'first_used_label'  => $first_used_label,
					'follows_hashtag'   => $follows_hashtag,
					'current_user_id'   => $current_user_id,
					'is_logged_in'      => $is_logged_in,
					'bn_sort'           => $bn_sort,
				)
			);

			// Posts list (native posts OR Jetonomy bridge discussions). The part
			// returns silently when both are empty, in which case we render the
			// empty-state below.
			$bn_has_native_posts = ! empty( $hashtag_posts );
			$bn_bridge_posts     = $bn_has_native_posts
				? array()
				: (array) apply_filters( 'buddynext_hashtag_related_discussions', array(), $hashtag_slug );

			if ( $bn_has_native_posts || ! empty( $bn_bridge_posts ) ) {
				buddynext_get_template(
					'parts/hashtag-posts-list.php',
					array(
						'hashtag_posts'   => $hashtag_posts,
						'current_user_id' => $current_user_id,
						'hashtag_slug'    => $hashtag_slug,
					)
				);
			} else {
				buddynext_get_template(
					'parts/hashtag-empty-state.php',
					array(
						'hashtag_slug' => $hashtag_slug,
						'bn_sort'      => $bn_sort,
						'is_logged_in' => $is_logged_in,
					)
				);
			}
			?>

			<?php
			// Server-rendered prev/next pager (LIMIT + OFFSET keyset off ?paged). The
			// previous JS "Load more" stub bound to state.hasMore, which never
			// existed, so pagination was dead. $bn_paged/$bn_has_more are set in the
			// feed-query block above; guard defensively for the not-found path.
			$bn_pg_cur   = isset( $bn_paged ) ? (int) $bn_paged : 1;
			$bn_pg_more  = ! empty( $bn_has_more );
			$bn_pg_base  = \BuddyNext\Core\PageRouter::hashtag_feed_url( $hashtag_slug );
			$bn_pg_extra = ( isset( $bn_sort ) && '' !== (string) $bn_sort && 'latest' !== $bn_sort ) ? array( 'sort' => $bn_sort ) : array();
			if ( $bn_pg_cur > 1 || $bn_pg_more ) :
				?>
				<nav class="bn-hashtag-pager" aria-label="<?php esc_attr_e( 'Hashtag feed pagination', 'buddynext' ); ?>">
					<?php if ( $bn_pg_cur > 1 ) : ?>
						<a class="bn-btn" data-variant="secondary" data-size="md" rel="prev"
							href="<?php echo esc_url( add_query_arg( array_merge( $bn_pg_extra, array( 'paged' => $bn_pg_cur - 1 ) ), $bn_pg_base ) ); ?>">
							<?php esc_html_e( 'Previous', 'buddynext' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $bn_pg_more ) : ?>
						<a class="bn-btn" data-variant="secondary" data-size="md" rel="next"
							href="<?php echo esc_url( add_query_arg( array_merge( $bn_pg_extra, array( 'paged' => $bn_pg_cur + 1 ) ), $bn_pg_base ) ); ?>">
							<?php esc_html_e( 'Next', 'buddynext' ); ?>
						</a>
					<?php endif; ?>
				</nav>
				<?php
			endif;
			?>
		</main>

		<!-- ── Sidebar (hashtag-specific) ── -->
		<aside class="bn-hashtag-sidebar" aria-label="<?php esc_attr_e( 'Hashtag details sidebar', 'buddynext' ); ?>">
			<?php
			buddynext_get_template(
				'parts/hashtag-sidebar-about.php',
				array(
					'hashtag_slug'     => $hashtag_slug,
					'post_count_total' => $post_count_total,
					'first_used_label' => $first_used_label,
					'follows_hashtag'  => $follows_hashtag,
					'is_logged_in'     => $is_logged_in,
				)
			);
			buddynext_get_template(
				'parts/hashtag-sidebar-related.php',
				array(
					'related_tags'    => $related_tags,
					'is_logged_in'    => $is_logged_in,
					'current_user_id' => $current_user_id,
				)
			);
			buddynext_get_template(
				'parts/hashtag-sidebar-top-contributors.php',
				array(
					'top_contributors' => $top_contributors,
				)
			);
			?>
		</aside>
	</div>

</div><!-- /.bn-hashtag-feed -->
<?php endif; ?>

<?php
/**
 * Fires after the hashtag feed inner content.
 */
do_action( 'buddynext_hashtag_feed_after' );
?>
