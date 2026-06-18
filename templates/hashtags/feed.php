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

// ── Resolve hashtag slug ───────────────────────────────────────────────────
$hashtag_slug = isset( $args['hashtag_slug'] )
	? sanitize_title( $args['hashtag_slug'] )
	: sanitize_title( get_query_var( 'bn_hashtag', '' ) );

// Headers are already sent by wp_head() when this template runs; use an
// inline not-found state rather than wp_safe_redirect().
$hashtag_not_found = ! $hashtag_slug;

// ── Load hashtag row (service layer — no raw SQL in templates) ──────────────
$bn_hashtag_service = new \BuddyNext\Hashtags\HashtagService();
$hashtag            = $hashtag_slug ? $bn_hashtag_service->get_by_slug( $hashtag_slug ) : null;

if ( $hashtag_slug && null === $hashtag ) {
	$hashtag_not_found = true;
}

// ── Current user context ───────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_logged_in    = ( $current_user_id > 0 );
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// ── Sort tab (Latest / Top / Following) ────────────────────────────────────
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL filter.
$bn_sort_raw = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : 'latest';
$bn_sort     = in_array( $bn_sort_raw, array( 'latest', 'top', 'following' ), true ) ? $bn_sort_raw : 'latest';

// ── Hashtag data (all via HashtagService) ──────────────────────────────────
$follows_hashtag   = false;
$hashtag_posts     = array();
$related_tags      = array();
$top_contributors  = array();
$contributor_count = 0;
$bn_next_cursor    = null;
$bn_prev_cursor    = '';

if ( ! $hashtag_not_found ) {
	$hashtag_id = (int) $hashtag['id'];

	if ( $is_logged_in ) {
		$follows_hashtag = $bn_hashtag_service->is_following( $current_user_id, $hashtag_id );
	}

	// ── Feed posts for this hashtag ────────────────────────────────────────
	// Keyset-cursor pagination (SCALE-CONTRACT): the service pages on an opaque
	// cursor, never OFFSET. ?cursor= carries the forward keyset; ?prev keeps a
	// Previous link visible after the first page.
	$bn_per_page = absint( $args['limit'] ?? 10 );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only opaque cursor.
	$bn_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['cursor'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag.
	$bn_prev_cursor = isset( $_GET['prev'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['prev'] ) ) : '';

	$bn_feed = $bn_hashtag_service->get_feed(
		$hashtag_slug,
		array(
			'per_page'  => $bn_per_page,
			'sort'      => $bn_sort,
			'viewer_id' => $current_user_id,
			'cursor'    => '' !== $bn_cursor ? $bn_cursor : null,
		)
	);

	$hashtag_posts  = (array) $bn_feed['items'];
	$bn_next_cursor = $bn_feed['next_cursor'];

	// ── Related hashtags / contributors / contributor total ────────────────
	$related_tags      = $bn_hashtag_service->related( $hashtag_slug, 4 );
	$top_contributors  = $bn_hashtag_service->top_contributors( $hashtag_id, 3 );
	$contributor_count = $bn_hashtag_service->contributor_count( $hashtag_id );

	// Resolve which related tags the viewer follows in ONE query (kills the
	// per-row is_following() N+1 the sidebar-related part used to run).
	$bn_related_following = $is_logged_in
		? $bn_hashtag_service->following_map( $current_user_id, wp_list_pluck( $related_tags, 'slug' ) )
		: array();
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
		$first_used_label = buddynext_date_local( (string) $hashtag->created_at );
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
			// Server-rendered prev/next pager keyed off HashtagService's opaque
			// keyset cursor (SCALE-CONTRACT §2 — never OFFSET). next_cursor drives
			// the forward link; ?prev preserves a Previous link once paged in. The
			// previous JS "Load more" stub bound to a state.hasMore that never
			// existed, so pagination was dead.
			$bn_pg_base  = \BuddyNext\Core\PageRouter::hashtag_feed_url( $hashtag_slug );
			$bn_pg_extra = ( isset( $bn_sort ) && '' !== (string) $bn_sort && 'latest' !== $bn_sort ) ? array( 'sort' => $bn_sort ) : array();
			$bn_pg_more  = ! empty( $bn_next_cursor );
			$bn_pg_prev  = '' !== (string) $bn_prev_cursor;
			$bn_cur_seen = isset( $bn_cursor ) ? (string) $bn_cursor : '';
			if ( $bn_pg_prev || $bn_pg_more ) :
				?>
				<nav class="bn-hashtag-pager" aria-label="<?php esc_attr_e( 'Hashtag feed pagination', 'buddynext' ); ?>">
					<?php if ( $bn_pg_prev ) : ?>
						<a class="bn-btn" data-variant="secondary" data-size="md" rel="prev"
							href="<?php echo esc_url( add_query_arg( array_merge( $bn_pg_extra, array( 'cursor' => $bn_prev_cursor ) ), $bn_pg_base ) ); ?>">
							<?php esc_html_e( 'Previous', 'buddynext' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $bn_pg_more ) : ?>
						<a class="bn-btn" data-variant="secondary" data-size="md" rel="next"
							href="
							<?php
							echo esc_url(
								add_query_arg(
									array_merge(
										$bn_pg_extra,
										array(
											'cursor' => $bn_next_cursor,
											'prev'   => $bn_cur_seen,
										)
									),
									$bn_pg_base
								)
							);
							?>
									">
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
					'following_map'   => isset( $bn_related_following ) ? $bn_related_following : array(),
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
