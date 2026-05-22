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
 * @package BuddyNext
 * @since   1.0.0
 *
 * @var string $hashtag_slug Slug of the hashtag being viewed (set by the router).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

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
				LIMIT %d",
				(int) $hashtag->id,
				$limit
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
				LIMIT %d",
				$current_user_id,
				(int) $hashtag->id,
				$limit
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
				LIMIT %d",
				(int) $hashtag->id,
				$limit
			)
		); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

			<!-- Hashtag header card -->
			<section class="bn-card bn-hashtag-header" aria-labelledby="bn-hashtag-title">
				<header class="bn-hashtag-header__top">
					<div class="bn-hashtag-header__heading">
						<h1 class="bn-hashtag-header__title" id="bn-hashtag-title">
							<span aria-hidden="true">#</span><?php echo esc_html( $hashtag_slug ); ?>
						</h1>
						<?php if ( $post_count_total > 0 ) : ?>
							<span class="bn-hashtag-header__trend" aria-hidden="true">
								<?php buddynext_icon( 'trending-up' ); ?>
							</span>
						<?php endif; ?>
					</div>

					<div class="bn-hashtag-header__actions">
						<?php if ( $is_logged_in ) : ?>
							<button
								class="bn-btn"
								data-variant="<?php echo $follows_hashtag ? 'secondary' : 'primary'; ?>"
								data-size="md"
								data-current-state="<?php echo $follows_hashtag ? 'following' : 'follow'; ?>"
								type="button"
								data-wp-on--click="actions.toggleFollowHashtag"
								data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
								aria-pressed="<?php echo $follows_hashtag ? 'true' : 'false'; ?>"
							>
								<?php if ( $follows_hashtag ) : ?>
									<?php buddynext_icon( 'check' ); ?>
									<span><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
								<?php else : ?>
									<span>
										<?php
										printf(
											/* translators: %s: hashtag slug */
											esc_html__( 'Follow #%s', 'buddynext' ),
											esc_html( $hashtag_slug )
										);
										?>
									</span>
								<?php endif; ?>
							</button>
							<button
								class="bn-btn"
								data-variant="ghost"
								data-size="md"
								type="button"
								data-wp-on--click="actions.openComposerWithTag"
								data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
							>
								<?php buddynext_icon( 'edit' ); ?>
								<span><?php esc_html_e( 'Create post', 'buddynext' ); ?></span>
							</button>
						<?php else : ?>
							<a
								class="bn-btn"
								data-variant="primary"
								data-size="md"
								href="<?php echo esc_url( wp_registration_url() ); ?>"
							>
								<span>
									<?php
									printf(
										/* translators: %s: hashtag slug */
										esc_html__( 'Follow #%s', 'buddynext' ),
										esc_html( $hashtag_slug )
									);
									?>
								</span>
							</a>
						<?php endif; ?>
					</div>
				</header>

				<div class="bn-stat-grid bn-hashtag-header__stats">
					<div class="bn-stat">
						<div class="bn-stat__label"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
						<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $post_count_total ) ); ?></div>
					</div>
					<div class="bn-stat">
						<div class="bn-stat__label"><?php esc_html_e( 'Contributors', 'buddynext' ); ?></div>
						<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $contributor_count ) ); ?></div>
					</div>
					<?php if ( $first_used_label ) : ?>
						<div class="bn-stat">
							<div class="bn-stat__label"><?php esc_html_e( 'First used', 'buddynext' ); ?></div>
							<div class="bn-stat__value bn-hashtag-header__date"><?php echo esc_html( $first_used_label ); ?></div>
						</div>
					<?php endif; ?>
				</div>

				<nav class="bn-tabs bn-hashtag-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Sort posts', 'buddynext' ); ?>">
					<?php
					$bn_ht_tabs = array(
						'latest'    => array(
							'label' => __( 'Latest', 'buddynext' ),
							'count' => $post_count_total,
						),
						'top'       => array(
							'label' => __( 'Top', 'buddynext' ),
							'count' => null,
						),
						'following' => array(
							'label' => __( 'Following only', 'buddynext' ),
							'count' => null,
							'guard' => ! $is_logged_in,
						),
					);
					foreach ( $bn_ht_tabs as $tab_key => $tab_info ) :
						if ( ! empty( $tab_info['guard'] ) ) {
							continue;
						}
						$tab_active = ( $bn_sort === $tab_key );
						$tab_url    = add_query_arg( 'sort', $tab_key, PageRouter::hashtag_feed_url( $hashtag_slug ) );
						?>
						<a
							class="bn-tab"
							role="tab"
							href="<?php echo esc_url( $tab_url ); ?>"
							data-sort="<?php echo esc_attr( $tab_key ); ?>"
							data-wp-on--click="actions.setSort"
							aria-selected="<?php echo $tab_active ? 'true' : 'false'; ?>"
						>
							<?php echo esc_html( $tab_info['label'] ); ?>
							<?php if ( null !== $tab_info['count'] ) : ?>
								<span class="bn-tab__count"><?php echo esc_html( number_format_i18n( (int) $tab_info['count'] ) ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</nav>
			</section>

			<!-- ── Posts ── -->
			<?php if ( ! empty( $hashtag_posts ) ) : ?>
				<div class="bn-feed-list bn-hashtag-feed-list" role="feed" aria-label="<?php esc_attr_e( 'Hashtag feed', 'buddynext' ); ?>">
					<?php foreach ( $hashtag_posts as $post_row ) : ?>
						<?php
						$ht_post = array(
							'id'                   => (int) $post_row->id,
							'user_id'              => (int) $post_row->user_id,
							'type'                 => $post_row->type ?? 'text',
							'content'              => $post_row->content ?? '',
							'privacy'              => 'public',
							'space_id'             => null,
							'media_ids'            => null,
							'link_url'             => null,
							'link_meta'            => null,
							'poll_options'         => array(),
							'is_pinned'            => 0,
							'is_announcement'      => 0,
							'content_warning'      => false,
							'content_warning_type' => null,
							'reaction_count'       => absint( $post_row->reaction_count ?? 0 ),
							'comment_count'        => absint( $post_row->comment_count ?? 0 ),
							'share_count'          => absint( $post_row->share_count ?? 0 ),
							'edited_at'            => null,
							'created_at'           => $post_row->created_at ?? '',
							'updated_at'           => null,
						);
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $ht_post,
								'current_user_id' => $current_user_id,
								'context'         => 'home',
							)
						);
						?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<?php
				/**
				 * Filter: related discussions for this hashtag from bridge plugins.
				 *
				 * JetonomyBridge hooks this to query jt_tags for matching tag via
				 * Jetonomy's own model API.
				 *
				 * @param array  $discussions  Empty array; bridges append results.
				 * @param string $hashtag_slug The hashtag being viewed.
				 * @return array Each item: {id, title, url, reply_count, vote_score, author_name}
				 */
				$jt_posts = apply_filters( 'buddynext_hashtag_related_discussions', array(), $hashtag_slug );
				?>
				<?php if ( ! empty( $jt_posts ) ) : ?>
					<div class="bn-feed-list bn-hashtag-feed-list">
						<?php foreach ( $jt_posts as $jt_post ) : ?>
							<?php
							$jt_post    = (object) $jt_post;
							$jt_id      = (int) ( $jt_post->id ?? 0 );
							$jt_title   = (string) ( $jt_post->title ?? '' );
							$jt_author  = (string) ( $jt_post->author_name ?? __( 'Community Member', 'buddynext' ) );
							$jt_uid     = (int) ( $jt_post->author_id ?? 0 );
							$jt_init    = function_exists( 'bn_initials' ) ? bn_initials( $jt_author ) : strtoupper( substr( $jt_author, 0, 2 ) );
							$jt_avatar  = $jt_uid ? get_avatar_url( $jt_uid, array( 'size' => 72 ) ) : '';
							$jt_replies = absint( $jt_post->reply_count ?? 0 );
							$jt_votes   = (int) ( $jt_post->vote_score ?? 0 );

							$jt_space_slug = '';
							if ( ! empty( $jt_post->space_id ) && function_exists( '\\Jetonomy\\table' ) ) {
								// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								$jt_space_slug = (string) $wpdb->get_var(
									$wpdb->prepare(
										'SELECT slug FROM ' . \Jetonomy\table( 'spaces' ) . ' WHERE id = %d',
										(int) $jt_post->space_id
									)
								);
								// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							}
							$jt_url = $jt_space_slug
								? home_url( '/community/s/' . $jt_space_slug . '/t/' . ( $jt_post->slug ?? $jt_id ) . '/' )
								: home_url( '/community/' );
							?>
							<article class="bn-card bn-card-bridge bn-card-bridge--jetonomy" data-interactive>
								<header class="bn-card-bridge__source">
									<span class="bn-badge" data-tone="jetonomy">
										<?php buddynext_icon( 'message-circle' ); ?>
										<?php esc_html_e( 'Jetonomy Discussion', 'buddynext' ); ?>
									</span>
								</header>
								<div class="bn-card-bridge__head">
									<?php if ( $jt_avatar ) : ?>
										<img
											src="<?php echo esc_url( $jt_avatar ); ?>"
											alt=""
											class="bn-avatar"
											data-size="md"
											loading="lazy"
											width="36"
											height="36"
										>
									<?php else : ?>
										<div class="bn-avatar" data-size="md" aria-hidden="true">
											<?php echo esc_html( $jt_init ); ?>
										</div>
									<?php endif; ?>
									<div class="bn-card-bridge__byline">
										<div class="bn-card-bridge__author"><?php echo esc_html( $jt_author ); ?></div>
										<div class="bn-card-bridge__meta"><?php esc_html_e( 'Started a discussion', 'buddynext' ); ?></div>
									</div>
								</div>
								<a class="bn-card-bridge__title" href="<?php echo esc_url( $jt_url ); ?>">
									<?php echo esc_html( $jt_title ); ?>
								</a>
								<footer class="bn-card-bridge__footer">
									<button class="bn-btn" data-variant="ghost" data-size="sm" type="button" data-wp-on--click="actions.voteJt" data-jt-id="<?php echo esc_attr( (string) $jt_id ); ?>" data-direction="up">
										<?php buddynext_icon( 'arrow-up' ); ?>
										<span><?php echo esc_html( (string) max( 0, $jt_votes ) ); ?></span>
									</button>
									<span class="bn-card-bridge__stat">
										<?php buddynext_icon( 'message-circle' ); ?>
										<?php
										printf(
											/* translators: %d: reply count */
											esc_html( _n( '%d reply', '%d replies', $jt_replies, 'buddynext' ) ),
											(int) $jt_replies
										);
										?>
									</span>
									<a class="bn-btn bn-card-bridge__open" data-variant="ghost" data-size="sm" href="<?php echo esc_url( $jt_url ); ?>">
										<span><?php esc_html_e( 'Open', 'buddynext' ); ?></span>
										<?php buddynext_icon( 'arrow-right' ); ?>
									</a>
								</footer>
							</article>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<!-- True empty state -->
					<div class="bn-card bn-hashtag-empty">
						<div class="bn-hashtag-empty__icon" aria-hidden="true"><?php buddynext_icon( 'hash' ); ?></div>
						<h2 class="bn-hashtag-empty__title">
							<?php
							if ( 'following' === $bn_sort ) {
								printf(
									/* translators: %s: hashtag slug */
									esc_html__( 'No posts from people you follow tagged #%s', 'buddynext' ),
									esc_html( $hashtag_slug )
								);
							} elseif ( 'top' === $bn_sort ) {
								printf(
									/* translators: %s: hashtag slug */
									esc_html__( 'No top posts in the last 7 days for #%s', 'buddynext' ),
									esc_html( $hashtag_slug )
								);
							} else {
								printf(
									/* translators: %s: hashtag slug */
									esc_html__( 'No posts with #%s yet', 'buddynext' ),
									esc_html( $hashtag_slug )
								);
							}
							?>
						</h2>
						<p class="bn-hashtag-empty__lede">
							<?php esc_html_e( 'Be the first to share something on this topic.', 'buddynext' ); ?>
						</p>
						<?php if ( $is_logged_in ) : ?>
							<button
								class="bn-btn"
								data-variant="primary"
								data-size="md"
								type="button"
								data-wp-on--click="actions.openComposerWithTag"
								data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
							>
								<?php buddynext_icon( 'edit' ); ?>
								<span><?php esc_html_e( 'Be the first to post', 'buddynext' ); ?></span>
							</button>
						<?php else : ?>
							<a
								class="bn-btn"
								data-variant="primary"
								data-size="md"
								href="<?php echo esc_url( wp_registration_url() ); ?>"
							>
								<span><?php esc_html_e( 'Join to post', 'buddynext' ); ?></span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="bn-load-more" data-wp-bind--hidden="!state.hasMore" aria-live="polite">
				<?php esc_html_e( 'Loading more posts…', 'buddynext' ); ?>
			</div>
		</main>

		<!-- ── Sidebar (hashtag-specific) ── -->
		<aside class="bn-hashtag-sidebar" aria-label="<?php esc_attr_e( 'Hashtag details sidebar', 'buddynext' ); ?>">

			<!-- About this hashtag -->
			<div class="bn-card bn-sidebar-widget">
				<h2 class="bn-sidebar-widget__title">
					<?php
					printf(
						/* translators: %s: hashtag slug */
						esc_html__( 'About #%s', 'buddynext' ),
						esc_html( $hashtag_slug )
					);
					?>
				</h2>
				<dl class="bn-hashtag-about">
					<div class="bn-hashtag-about__row">
						<dt class="bn-hashtag-about__label"><?php esc_html_e( 'Created by', 'buddynext' ); ?></dt>
						<dd class="bn-hashtag-about__value"><?php esc_html_e( 'Community', 'buddynext' ); ?></dd>
					</div>
					<div class="bn-hashtag-about__row">
						<dt class="bn-hashtag-about__label"><?php esc_html_e( 'Total posts', 'buddynext' ); ?></dt>
						<dd class="bn-hashtag-about__value"><?php echo esc_html( number_format_i18n( $post_count_total ) ); ?></dd>
					</div>
					<?php if ( $first_used_label ) : ?>
						<div class="bn-hashtag-about__row">
							<dt class="bn-hashtag-about__label"><?php esc_html_e( 'First used', 'buddynext' ); ?></dt>
							<dd class="bn-hashtag-about__value"><?php echo esc_html( $first_used_label ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>

				<?php if ( $is_logged_in ) : ?>
					<button
						class="bn-btn bn-hashtag-about__cta"
						data-variant="<?php echo $follows_hashtag ? 'secondary' : 'primary'; ?>"
						data-size="sm"
						type="button"
						data-wp-on--click="actions.toggleFollowHashtag"
						data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
						aria-pressed="<?php echo $follows_hashtag ? 'true' : 'false'; ?>"
					>
						<?php if ( $follows_hashtag ) : ?>
							<?php buddynext_icon( 'check' ); ?>
							<span><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'Follow hashtag', 'buddynext' ); ?></span>
						<?php endif; ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Related hashtags -->
			<?php if ( ! empty( $related_tags ) ) : ?>
				<div class="bn-card bn-sidebar-widget">
					<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'Related hashtags', 'buddynext' ); ?></h2>
					<ul class="bn-hashtag-related">
						<?php foreach ( $related_tags as $rel_tag ) : ?>
							<?php
							$rel_following = false;
							if ( $is_logged_in ) {
								// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								$rel_following = (bool) $wpdb->get_var(
									$wpdb->prepare(
										"SELECT 1 FROM {$hashtag_follows_tb} hf
										 INNER JOIN {$hashtags_table} h ON h.id = hf.hashtag_id
										 WHERE hf.user_id = %d AND h.slug = %s LIMIT 1",
										$current_user_id,
										$rel_tag->slug
									)
								);
								// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							}
							?>
							<li class="bn-hashtag-related__row">
								<a class="bn-badge bn-hashtag-related__chip" data-tone="accent"
									href="<?php echo esc_url( PageRouter::hashtag_feed_url( $rel_tag->slug ) ); ?>"
								>#<?php echo esc_html( $rel_tag->slug ); ?></a>
								<span class="bn-hashtag-related__count">
									<?php
									printf(
										/* translators: %s: post count */
										esc_html__( '%s posts', 'buddynext' ),
										esc_html( number_format_i18n( absint( $rel_tag->post_count ) ) )
									);
									?>
								</span>
								<?php if ( $is_logged_in ) : ?>
									<button
										class="bn-btn bn-hashtag-related__follow"
										data-variant="<?php echo $rel_following ? 'secondary' : 'primary'; ?>"
										data-size="xs"
										type="button"
										data-wp-on--click="actions.toggleFollowHashtag"
										data-hashtag="<?php echo esc_attr( $rel_tag->slug ); ?>"
										aria-pressed="<?php echo $rel_following ? 'true' : 'false'; ?>"
									>
										<?php echo $rel_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
									</button>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<!-- Top contributors -->
			<?php if ( ! empty( $top_contributors ) ) : ?>
				<div class="bn-card bn-sidebar-widget">
					<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'Top contributors', 'buddynext' ); ?></h2>
					<ul class="bn-hashtag-contributors">
						<?php foreach ( $top_contributors as $contrib ) : ?>
							<?php
							$contrib_id      = (int) $contrib->user_id;
							$contrib_user    = get_userdata( $contrib_id );
							$contrib_display = $contrib_user instanceof WP_User ? $contrib_user->display_name : __( 'Community Member', 'buddynext' );
							$contrib_init    = function_exists( 'bn_initials' ) ? bn_initials( $contrib_display ) : strtoupper( substr( $contrib_display, 0, 2 ) );
							$contrib_avatar  = get_avatar_url( $contrib_id, array( 'size' => 72 ) );
							$contrib_url     = PageRouter::profile_url( $contrib_id );
							?>
							<li class="bn-hashtag-contributors__row">
								<a class="bn-hashtag-contributors__link" href="<?php echo esc_url( $contrib_url ); ?>">
									<?php if ( $contrib_avatar ) : ?>
										<img
											src="<?php echo esc_url( $contrib_avatar ); ?>"
											alt=""
											class="bn-avatar"
											data-size="sm"
											loading="lazy"
											width="28"
											height="28"
										>
									<?php else : ?>
										<span class="bn-avatar" data-size="sm" aria-hidden="true">
											<?php echo esc_html( $contrib_init ); ?>
										</span>
									<?php endif; ?>
									<span class="bn-hashtag-contributors__info">
										<span class="bn-hashtag-contributors__name"><?php echo esc_html( $contrib_display ); ?></span>
										<span class="bn-hashtag-contributors__sub">
											<?php
											printf(
												/* translators: %d: number of posts */
												esc_html( _n( '%d post', '%d posts', (int) $contrib->post_count, 'buddynext' ) ),
												(int) $contrib->post_count
											);
											?>
										</span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

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
