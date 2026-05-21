<?php
/**
 * BuddyNext explore feed template.
 *
 * Renders the public explore / discovery feed: a masonry-style three-column
 * grid of recent public posts, a search bar, content-type filter chips, a
 * trending topics sidebar and a spaces sidebar. Accessible to guests and
 * logged-in users alike. Guests see a dismissable join banner.
 *
 * Overridable: copy to {theme}/buddynext/feed/explore.php
 *
 * REST endpoint: GET buddynext/v1/feed?scope=explore&sort=trending|recent|top
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

global $wpdb;

// ── Current user context ───────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_guest        = ( 0 === $current_user_id );

// ── Grid posts (public, sorted by trending score) ─────────────────────────
$bn_explore_per_page = 12;
$posts_table         = $wpdb->prefix . 'bn_posts';
$user_meta_table     = $wpdb->usermeta;

// Cursor-based pagination: cursor encodes last-seen created_at|id.
$explore_raw_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$explore_cursor_sql = '';
if ( $explore_raw_cursor ) {
	$decoded = base64_decode( $explore_raw_cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( $decoded && 2 === count( explode( '|', $decoded ) ) ) {
		list( $cursor_dt, $cursor_id ) = explode( '|', $decoded, 2 );
		$cursor_id                     = absint( $cursor_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$explore_cursor_sql = $wpdb->prepare( ' AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))', $cursor_dt, $cursor_dt, $cursor_id );
	}
}

// Suspended / shadow-banned exclusion — mirrors FeedService::excluded_users_where().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$explore_suspended = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_suspended' AND meta_value = '1'"
);
$explore_shadow    = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$explore_excluded = array_unique( array_map( 'intval', array_merge( $explore_suspended ?? array(), $explore_shadow ?? array() ) ) );
$explore_excl_sql = '';
if ( ! empty( $explore_excluded ) ) {
	$excl_placeholders = implode( ',', array_fill( 0, count( $explore_excluded ), '%d' ) );
	// $excl_placeholders is built via array_fill('%d') — only integers, safe to interpolate.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$explore_excl_sql = $wpdb->prepare( " AND p.user_id NOT IN ({$excl_placeholders})", $explore_excluded );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$grid_posts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT p.id, p.user_id, p.content, p.type, p.media_ids, p.link_url, p.link_meta,
		        p.created_at, p.reaction_count, p.comment_count, p.share_count
		   FROM {$posts_table} p
		  WHERE p.status = 'published'
		    AND p.privacy = 'public'
		    {$explore_excl_sql}
		    {$explore_cursor_sql}
		  ORDER BY (p.reaction_count + p.comment_count * 2 + p.share_count * 3) DESC, p.created_at DESC, p.id DESC
		  LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bn_explore_per_page + 1
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$explore_has_more = count( $grid_posts ) > $bn_explore_per_page;
if ( $explore_has_more ) {
	array_pop( $grid_posts );
}
$explore_next_cursor = '';
if ( $explore_has_more && ! empty( $grid_posts ) ) {
	$last_explore        = end( $grid_posts );
	$explore_next_cursor = base64_encode( $last_explore->created_at . '|' . $last_explore->id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

// ── Trending hashtags ──────────────────────────────────────────────────────
$hashtags_table = $wpdb->prefix . 'bn_hashtags';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$trending_tags = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT slug, post_count FROM {$hashtags_table} WHERE post_count > 0 ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		6
	)
);

// ── Popular spaces ─────────────────────────────────────────────────────────
$spaces_table = $wpdb->prefix . 'bn_spaces';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$popular_spaces = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, name, avatar_url, member_count FROM {$spaces_table} WHERE type = 'open' ORDER BY member_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		3
	)
);

// ── REST nonce ─────────────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Avatar colour palette (deterministic by user ID) ──────────────────────
$avatar_colours = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-jt', 'av-mvs' );

/**
 * Format a UTC timestamp as a human-readable relative time label.
 *
 * @param string $datetime MySQL datetime string.
 * @return string Escaped, translated relative time.
 */
if ( ! function_exists( 'bn_explore_relative_time' ) ) {
	/**
	 * Format a UTC timestamp as a human-readable relative time label.
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string Escaped, translated relative time.
	 */
	function bn_explore_relative_time( string $datetime ): string {
		$diff = time() - (int) strtotime( $datetime );
		if ( $diff < 60 ) {
			return esc_html__( 'just now', 'buddynext' );
		}
		if ( $diff < 3600 ) {
			$mins = (int) round( $diff / 60 );
			/* translators: %d: number of minutes */
			return esc_html( sprintf( _n( '%dm ago', '%dm ago', $mins, 'buddynext' ), $mins ) );
		}
		if ( $diff < 86400 ) {
			$hours = (int) round( $diff / 3600 );
			/* translators: %d: number of hours */
			return esc_html( sprintf( _n( '%dh ago', '%dh ago', $hours, 'buddynext' ), $hours ) );
		}
		$days = (int) round( $diff / 86400 );
		/* translators: %d: number of days */
		return esc_html( sprintf( _n( '%dd ago', '%dd ago', $days, 'buddynext' ), $days ) );
	}
}

/**
 * Truncate post content to a maximum character count, appending an ellipsis.
 *
 * @param string $content    Raw text content.
 * @param int    $max_length Maximum character count before truncation.
 * @return string Escaped, possibly truncated text.
 */
if ( ! function_exists( 'bn_excerpt' ) ) {
	/**
	 * Truncate post content to a maximum character count, appending an ellipsis.
	 *
	 * @param string $content    Raw text content.
	 * @param int    $max_length Maximum character count before truncation.
	 * @return string Escaped, possibly truncated text.
	 */
	function bn_excerpt( string $content, int $max_length = 140 ): string {
		$plain = wp_strip_all_tags( $content );
		if ( mb_strlen( $plain ) > $max_length ) {
			$plain = mb_substr( $plain, 0, $max_length ) . '...';
		}
		return esc_html( $plain );
	}
}
?>
<?php
$bn_nav_active = 'feed';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-explore"
	data-wp-interactive="buddynext/feed"
	data-wp-context='{"scope":"explore","sort":"trending","filter":"all","page":1}'
>
	<div class="bn-hub-shell">
	<div class="bn-explore-content">

		<!-- Page header -->
		<div class="bn-explore-page-header">
			<h1 class="bn-explore-title">
				<?php buddynext_icon( 'search' ); ?> <?php esc_html_e( 'Explore', 'buddynext' ); ?>
			</h1>
			<p class="bn-explore-sub">
				<?php esc_html_e( 'Discover posts, people, and spaces from the community', 'buddynext' ); ?>
			</p>
		</div>

		<!-- Guest join banner -->
		<?php if ( $is_guest ) : ?>
			<div
				class="bn-guest-banner"
				role="banner"
				data-wp-bind--hidden="state.guestBannerDismissed"
			>
				<h3><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h3>
				<p>
					<?php esc_html_e( "You're browsing as a guest. Create an account to post, follow people, and join spaces.", 'buddynext' ); ?>
				</p>
				<div class="bn-banner-btns">
					<a
						href="<?php echo esc_url( wp_registration_url() ); ?>"
						class="bn-btn"
						data-variant="primary"
					><?php esc_html_e( 'Sign up free', 'buddynext' ); ?></a>
					<a
						href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
						class="bn-btn"
						data-variant="ghost"
					><?php esc_html_e( 'Log in', 'buddynext' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<!-- Search bar -->
		<div class="bn-explore-search" role="search">
			<span class="bn-explore-search-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
			<label for="bn-explore-search-input" class="screen-reader-text">
				<?php esc_html_e( 'Search the community', 'buddynext' ); ?>
			</label>
			<input
				id="bn-explore-search-input"
				class="bn-input bn-explore-search__input"
				type="search"
				placeholder="<?php esc_attr_e( 'Search posts, people, spaces, hashtags...', 'buddynext' ); ?>"
				autocomplete="off"
				data-wp-on--input="actions.onSearch"
			>
		</div>

		<!-- Filter chips -->
		<div class="bn-filter-row" role="group" aria-label="<?php esc_attr_e( 'Content type filter', 'buddynext' ); ?>">
			<?php
			$filters = array(
				'all'    => __( 'All', 'buddynext' ),
				'people' => __( 'People', 'buddynext' ),
				'posts'  => __( 'Posts', 'buddynext' ),
				'spaces' => __( 'Spaces', 'buddynext' ),
				'media'  => __( 'Media', 'buddynext' ),
			);
			foreach ( $filters as $filter_key => $filter_label ) :
				$is_active = ( 'all' === $filter_key );
				?>
				<button
					class="bn-filter-chip<?php echo $is_active ? ' active' : ''; ?>"
					type="button"
					data-filter="<?php echo esc_attr( $filter_key ); ?>"
					data-wp-on--click="actions.setFilter"
					aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
				><?php echo esc_html( $filter_label ); ?></button>
			<?php endforeach; ?>

			<?php if ( ! empty( $trending_tags ) ) : ?>
				<?php foreach ( array_slice( $trending_tags, 0, 3 ) as $chip_tag ) : ?>
					<button
						class="bn-filter-chip"
						type="button"
						data-filter="tag:<?php echo esc_attr( $chip_tag->slug ); ?>"
						data-wp-on--click="actions.setFilter"
						aria-pressed="false"
					>#<?php echo esc_html( $chip_tag->slug ); ?></button>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<!-- Post grid -->

			<!-- Main masonry grid -->
			<div class="bn-explore-grid" role="feed" aria-label="<?php esc_attr_e( 'Explore posts', 'buddynext' ); ?>">

				<?php if ( ! empty( $grid_posts ) ) : ?>
					<?php foreach ( $grid_posts as $card ) : ?>
						<?php
						// Normalise stdClass row to array for the post-card partial.
						$explore_post = array(
							'id'                   => (int) $card->id,
							'user_id'              => (int) $card->user_id,
							'type'                 => $card->type ?? 'text',
							'content'              => $card->content ?? '',
							'privacy'              => 'public',
							'space_id'             => null,
							'media_ids'            => isset( $card->media_ids ) ? json_decode( (string) $card->media_ids, true ) : null,
							'link_url'             => $card->link_url ?? null,
							'link_meta'            => isset( $card->link_meta ) ? json_decode( (string) $card->link_meta, true ) : null,
							'poll_options'         => array(),
							'is_pinned'            => 0,
							'is_announcement'      => 0,
							'content_warning'      => false,
							'content_warning_type' => null,
							'reaction_count'       => absint( $card->reaction_count ?? 0 ),
							'comment_count'        => absint( $card->comment_count ?? 0 ),
							'share_count'          => absint( $card->share_count ?? 0 ),
							'edited_at'            => null,
							'created_at'           => $card->created_at ?? '',
							'updated_at'           => null,
						);
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $explore_post,
								'current_user_id' => $current_user_id,
								'context'         => 'explore',
							)
						);
						?>
					<?php endforeach; ?>
				<?php else : ?>
					<!-- Empty state — shown when no public posts exist yet -->
					<div class="bn-explore-empty" role="status">
						<div class="bn-explore-empty__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></div>
						<div class="bn-explore-empty__title">
							<?php esc_html_e( 'Nothing to explore yet', 'buddynext' ); ?>
						</div>
						<p class="bn-explore-empty__text">
							<?php esc_html_e( 'Be the first to post something.', 'buddynext' ); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $explore_has_more && $explore_next_cursor ) : ?>
				<div class="bn-load-more">
					<a
						href="<?php echo esc_url( add_query_arg( 'cursor', $explore_next_cursor ) ); ?>"
						class="bn-btn bn-load-more__btn"
						data-variant="secondary"
					>
						<?php esc_html_e( 'Load more', 'buddynext' ); ?>
					</a>
				</div>
			<?php endif; ?>

	</div><!-- /.bn-explore-content -->
	<?php buddynext_get_template( 'partials/sidebar.php' ); ?>
	</div><!-- /.bn-hub-shell -->

	<!-- REST config for Interactivity API store -->
	<script type="application/json" id="bn-feed-explore-config">
	<?php
	echo wp_json_encode(
		array(
			'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
			'restNonce' => $rest_nonce,
			'userId'    => $current_user_id,
			'isGuest'   => $is_guest,
		)
	);
	?>
	</script>
</div>
