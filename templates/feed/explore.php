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
$limit       = absint( $args['limit'] ?? 9 );
$posts_table = $wpdb->prefix . 'bn_posts';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$grid_posts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT p.id, p.user_id, p.content, p.type, p.created_at, p.reaction_count, p.comment_count, p.share_count FROM {$posts_table} p WHERE p.status = 'published' AND p.privacy = 'public' ORDER BY (p.reaction_count + p.comment_count * 2 + p.share_count * 3) DESC, p.created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$limit
	)
);

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
$avatar_colours = [ 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-jt', 'av-mvs' ];

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
		$plain = mb_substr( $plain, 0, $max_length ) . '\u2026';
	}
	return esc_html( $plain );
}
?>
<style>
/* ── BuddyNext design tokens ── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px;
	--text-sm:  13px;
	--text-base: 15px;
	--text-lg:  17px;
	--text-xl:  20px;
	--text-2xl: 24px;
	--leading-body: 1.7;
	--bg:          #ffffff;
	--bg-subtle:   #f8f8f7;
	--bg-hover:    #f1f1f0;
	--surface:     #ffffff;
	--border:      #e8e8e5;
	--border-soft: #f1f1ee;
	--text-1:      #37352f;
	--text-2:      #787774;
	--text-3:      #aeaca8;
	--brand:       #0073aa;
	--brand-light: #e8f4fb;
	--brand-hover: #005f8e;
	--jetonomy:    #5b21b6;
	--jetonomy-bg: #f5f3ff;
	--mvs:         #0f766e;
	--mvs-bg:      #f0fdf9;
	--green:       #059669;
	--green-bg:    #ecfdf5;
	--amber:       #d97706;
	--amber-bg:    #fffbeb;
	--red:         #dc2626;
	--red-bg:      #fef2f2;
	--s1: 4px;
	--s2: 8px;
	--s3: 12px;
	--s4: 16px;
	--s5: 20px;
	--s6: 24px;
	--s8: 32px;
	--radius-sm: 6px;
	--radius:    10px;
	--radius-lg: 14px;
}
[data-theme="dark"] {
	--bg:          #191919;
	--bg-subtle:   #202020;
	--bg-hover:    #2a2a2a;
	--surface:     #252525;
	--border:      #333330;
	--border-soft: #2c2c2a;
	--text-1:      #e8e8e6;
	--text-2:      #9b9b97;
	--text-3:      #6b6b67;
	--brand:       #4dabdb;
	--brand-light: #1a2e3a;
	--brand-hover: #5fbfe8;
	--jetonomy:    #a78bfa;
	--jetonomy-bg: #1e1830;
	--mvs:         #34d399;
	--mvs-bg:      #0d2420;
	--green:       #34d399;
	--green-bg:    #0d2420;
	--amber:       #fbbf24;
	--amber-bg:    #2a2000;
	--red:         #f87171;
	--red-bg:      #2d0f0f;
}

/* ── Component styles ── */
.bn-explore {
	font-family: var(--font-body);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
}

/* Page shell — 2-column on desktop */
.bn-explore-shell {
	max-width: 1160px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
}

.bn-explore-page-header { margin-bottom: var(--s5); }
.bn-explore-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
}
.bn-explore-sub { font-size: var(--text-sm); color: var(--text-2); margin-top: var(--s1); }

/* Guest banner */
.bn-guest-banner {
	background: linear-gradient(135deg, var(--brand), var(--brand-hover));
	color: #fff;
	border-radius: var(--radius-lg);
	padding: var(--s5);
	margin-bottom: var(--s5);
}
.bn-guest-banner h3 { font-family: var(--font-display); font-size: var(--text-lg); font-weight: 700; margin-bottom: var(--s1); }
.bn-guest-banner p  { font-size: var(--text-sm); opacity: 0.85; margin-bottom: var(--s4); line-height: 1.6; }
.bn-banner-btns     { display: flex; gap: var(--s2); flex-wrap: wrap; }
.bn-btn-white {
	background: #fff;
	color: var(--brand);
	padding: 7px var(--s4);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
}
.bn-btn-white:hover { background: var(--brand-light); }
.bn-btn-outline-white {
	background: transparent;
	color: #fff;
	padding: 7px var(--s4);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid rgba(255,255,255,0.6);
	font-family: var(--font-body);
	text-decoration: none;
}
.bn-btn-outline-white:hover { background: rgba(255,255,255,0.1); }

/* Search bar */
.bn-explore-search { position: relative; margin-bottom: var(--s5); }
.bn-explore-search-icon {
	position: absolute;
	left: 14px;
	top: 50%;
	transform: translateY(-50%);
	font-size: 18px;
	pointer-events: none;
}
.bn-explore-search input {
	width: 100%;
	border: 2px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s3) var(--s4) var(--s3) 44px;
	font-size: var(--text-base);
	background: var(--surface);
	color: var(--text-1);
	font-family: var(--font-body);
	transition: border-color 0.15s;
}
.bn-explore-search input:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px var(--brand-light);
}
[data-theme="dark"] .bn-explore-search input { background: var(--surface); color: var(--text-1); }

/* Filter chips */
.bn-filter-row { display: flex; gap: var(--s2); margin-bottom: var(--s5); flex-wrap: wrap; }
.bn-filter-chip {
	padding: 6px 14px;
	border-radius: 20px;
	border: 1.5px solid var(--border);
	background: var(--surface);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	color: var(--text-1);
	font-family: var(--font-body);
	transition: border-color 0.1s, color 0.1s;
}
.bn-filter-chip.active { background: var(--brand); border-color: var(--brand); color: #fff; }
.bn-filter-chip:hover:not(.active) { border-color: var(--brand); color: var(--brand); }

/* 2-column layout: grid + sidebar */
.bn-explore-layout {
	display: grid;
	grid-template-columns: 1fr 280px;
	gap: var(--s6);
	align-items: start;
}

/* Masonry grid */
.bn-explore-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 14px;
}

/* Post card */
.bn-explore-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	overflow: hidden;
}
.bn-card-img {
	width: 100%;
	object-fit: cover;
	background: linear-gradient(135deg, var(--brand-light), var(--jetonomy-bg));
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--text-3);
	font-size: var(--text-xs);
}
.bn-card-img.h180 { height: 180px; }
.bn-card-img.h120 { height: 120px; }
.bn-card-img.h220 { height: 220px; }

.bn-card-body  { padding: var(--s3); }
.bn-card-author {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-bottom: var(--s2);
}
.bn-card-author-name { font-weight: 600; font-size: var(--text-xs); color: var(--text-1); }
.bn-card-author-time { font-size: 10px; color: var(--text-3); }

.bn-explore .avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	letter-spacing: 0.02em;
	overflow: hidden;
}
.bn-explore .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bn-explore .avatar.xs  { width: 26px; height: 26px; font-size: 10px; }

.bn-card-text {
	font-size: 12.5px;
	color: var(--text-2);
	line-height: 1.5;
	margin-bottom: var(--s2);
}
.bn-card-hashtag { color: var(--brand); font-weight: 500; text-decoration: none; }
.bn-card-hashtag:hover { text-decoration: underline; }

.bn-card-stats { display: flex; gap: var(--s3); font-size: var(--text-xs); color: var(--text-3); }
.bn-card-stat  { display: flex; align-items: center; gap: 3px; }

/* Sidebar widgets */
.bn-explore-sidebar { display: flex; flex-direction: column; gap: var(--s4); }
.bn-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
}
.bn-widget-title {
	font-size: var(--text-sm);
	font-weight: 700;
	color: var(--text-1);
	margin-bottom: var(--s3);
}

/* Trending tags */
.bn-trending-tag {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 7px 0;
	border-bottom: 1px solid var(--border-soft);
	cursor: pointer;
}
.bn-trending-tag:last-child { border-bottom: none; }
.bn-tag-name  { color: var(--brand); font-weight: 600; font-size: var(--text-sm); text-decoration: none; }
.bn-tag-name:hover { text-decoration: underline; }
.bn-tag-count { color: var(--text-3); font-size: var(--text-xs); }

/* Category grid */
.bn-cat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s2); }
.bn-cat-pill {
	background: var(--bg-subtle);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	font-size: var(--text-xs);
	font-weight: 500;
	cursor: pointer;
	text-align: center;
	color: var(--text-1);
	border: 1px solid var(--border-soft);
}
.bn-cat-pill:hover { background: var(--brand-light); color: var(--brand); }

/* Popular spaces */
.bn-space-entry {
	display: flex;
	align-items: center;
	gap: var(--s2);
	cursor: pointer;
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-space-entry:last-of-type { border-bottom: none; }
.bn-space-icon {
	width: 32px;
	height: 32px;
	border-radius: var(--radius-sm);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-size: var(--text-lg);
}
.bn-space-info  { flex: 1; min-width: 0; }
.bn-space-name  { font-weight: 600; font-size: var(--text-xs); color: var(--text-1); }
.bn-space-meta  { font-size: 10px; color: var(--text-3); }
.bn-btn-join {
	padding: 4px 10px;
	border-radius: 12px;
	border: 1.5px solid var(--brand);
	color: var(--brand);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	background: var(--surface);
	font-family: var(--font-body);
}
.bn-btn-join:hover { background: var(--brand); color: #fff; }

/* ── Dark mode component overrides ── */
[data-theme="dark"] .bn-explore-card { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-widget        { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-card-text     { color: var(--text-2); }
[data-theme="dark"] .bn-filter-chip   { background: var(--surface); color: var(--text-1); border-color: var(--border); }
[data-theme="dark"] .bn-btn-join      { background: var(--surface); }

/* ── Mobile responsive ── */
@media (max-width: 1024px) {
	.bn-explore-shell   { padding: var(--s4) var(--s4); }
	.bn-explore-layout  { grid-template-columns: 1fr 240px; gap: var(--s4); }
	.bn-explore-grid    { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
	.bn-explore-shell   { padding: var(--s3); }
	.bn-explore-layout  { grid-template-columns: 1fr; }
	.bn-explore-sidebar { display: none; }
	.bn-explore-grid    { grid-template-columns: 1fr; }
	.bn-filter-row      { gap: var(--s1); }
	.bn-filter-chip     { font-size: 11px; padding: 5px 10px; }
	.bn-explore-title   { font-size: var(--text-xl); }
}
</style>

<div
	class="bn-explore"
	data-wp-interactive="buddynext/feed"
	data-wp-context='{"scope":"explore","sort":"trending","filter":"all","page":1}'
>
	<div class="bn-explore-shell">

		<!-- Page header -->
		<div class="bn-explore-page-header">
			<h1 class="bn-explore-title">
				&#128269; <?php esc_html_e( 'Explore', 'buddynext' ); ?>
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
						class="bn-btn-white"
					><?php esc_html_e( 'Sign up free', 'buddynext' ); ?></a>
					<a
						href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
						class="bn-btn-outline-white"
					><?php esc_html_e( 'Log in', 'buddynext' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<!-- Search bar -->
		<div class="bn-explore-search" role="search">
			<span class="bn-explore-search-icon" aria-hidden="true">&#128270;</span>
			<label for="bn-explore-search-input" class="screen-reader-text">
				<?php esc_html_e( 'Search the community', 'buddynext' ); ?>
			</label>
			<input
				id="bn-explore-search-input"
				type="search"
				placeholder="<?php esc_attr_e( 'Search posts, people, spaces, hashtags\u2026', 'buddynext' ); ?>"
				autocomplete="off"
				data-wp-on--input="actions.onSearch"
			>
		</div>

		<!-- Filter chips -->
		<div class="bn-filter-row" role="group" aria-label="<?php esc_attr_e( 'Content type filter', 'buddynext' ); ?>">
			<?php
			$filters = [
				'all'    => __( 'All', 'buddynext' ),
				'people' => __( 'People', 'buddynext' ),
				'posts'  => __( 'Posts', 'buddynext' ),
				'spaces' => __( 'Spaces', 'buddynext' ),
				'media'  => __( 'Media', 'buddynext' ),
			];
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

		<!-- 2-column: grid + sidebar -->
		<div class="bn-explore-layout">

			<!-- Main masonry grid -->
			<div class="bn-explore-grid" role="feed" aria-label="<?php esc_attr_e( 'Explore posts', 'buddynext' ); ?>">

				<?php if ( ! empty( $grid_posts ) ) : ?>
					<?php foreach ( $grid_posts as $idx => $card ) : ?>
						<?php
						$card_author_id  = (int) $card->user_id;
						$card_author     = get_userdata( $card_author_id );
						$card_display    = $card_author instanceof WP_User ? $card_author->display_name : __( 'Community Member', 'buddynext' );
						$colour_class    = $avatar_colours[ $card_author_id % count( $avatar_colours ) ];
						$card_parts      = explode( ' ', trim( $card_display ) );
						$card_initials   = strtoupper( substr( $card_parts[0], 0, 1 ) . ( isset( $card_parts[1] ) ? substr( $card_parts[1], 0, 1 ) : '' ) );
						$card_avatar_url = get_avatar_url( $card_author_id, [ 'size' => 52 ] );
						$card_time       = bn_explore_relative_time( $card->created_at );
						$card_excerpt    = bn_excerpt( $card->content );
						$reaction_count  = absint( $card->reaction_count ?? 0 );
						$comment_count   = absint( $card->comment_count ?? 0 );
						$share_count     = absint( $card->share_count ?? 0 );

						// Vary image heights for masonry effect.
						$heights = [ 'h180', 'h120', 'h220' ];
						$img_cls = $heights[ $idx % 3 ];
						$has_img = ( 'photo' === $card->type );
						?>
						<article class="bn-explore-card" data-post-id="<?php echo esc_attr( (string) $card->id ); ?>">
							<?php if ( $has_img ) : ?>
								<div class="bn-card-img <?php echo esc_attr( $img_cls ); ?>" aria-hidden="true">
									&#128247;
								</div>
							<?php endif; ?>
							<div class="bn-card-body">
								<div class="bn-card-author">
									<?php if ( $card_avatar_url ) : ?>
										<img
											src="<?php echo esc_url( $card_avatar_url ); ?>"
											alt="<?php echo esc_attr( $card_display ); ?>"
											class="avatar xs"
											loading="lazy"
											width="26"
											height="26"
										>
									<?php else : ?>
										<div class="avatar xs <?php echo esc_attr( $colour_class ); ?>" aria-hidden="true">
											<?php echo esc_html( $card_initials ); ?>
										</div>
									<?php endif; ?>
									<div>
										<div class="bn-card-author-name"><?php echo esc_html( $card_display ); ?></div>
										<div class="bn-card-author-time"><?php echo esc_html( $card_time ); ?></div>
									</div>
								</div>
								<div class="bn-card-text"><?php echo esc_html( $card_excerpt ); ?></div>
								<div class="bn-card-stats">
									<?php if ( $reaction_count > 0 ) : ?>
										<span class="bn-card-stat">&#10084;&#65039; <?php echo esc_html( (string) $reaction_count ); ?></span>
									<?php endif; ?>
									<?php if ( $comment_count > 0 ) : ?>
										<span class="bn-card-stat">&#128172; <?php echo esc_html( (string) $comment_count ); ?></span>
									<?php endif; ?>
									<?php if ( $share_count > 0 ) : ?>
										<span class="bn-card-stat">&#8599;&#65039; <?php echo esc_html( (string) $share_count ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				<?php else : ?>
					<!-- Empty state — shown when no public posts exist yet -->
					<div style="grid-column:1/-1;text-align:center;padding:var(--s8);color:var(--text-3);">
						<div style="font-size:32px;margin-bottom:var(--s3);">&#128269;</div>
						<div style="font-size:var(--text-base);font-weight:600;color:var(--text-2);">
							<?php esc_html_e( 'Nothing to explore yet', 'buddynext' ); ?>
						</div>
						<div style="font-size:var(--text-sm);margin-top:var(--s2);">
							<?php esc_html_e( 'Be the first to post something.', 'buddynext' ); ?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Sidebar -->
			<aside class="bn-explore-sidebar" aria-label="<?php esc_attr_e( 'Explore sidebar', 'buddynext' ); ?>">

				<!-- Trending topics -->
				<div class="bn-widget">
					<div class="bn-widget-title">&#128293; <?php esc_html_e( 'Trending Topics', 'buddynext' ); ?></div>
					<?php if ( ! empty( $trending_tags ) ) : ?>
						<?php foreach ( $trending_tags as $tag_item ) : ?>
							<div class="bn-trending-tag">
								<a
									class="bn-tag-name"
									href="<?php echo esc_url( home_url( '/community/tag/' . sanitize_title( $tag_item->slug ) . '/' ) ); ?>"
								>#<?php echo esc_html( $tag_item->slug ); ?></a>
								<span class="bn-tag-count">
									<?php
									printf(
										/* translators: %s: number of posts */
										esc_html__( '%s posts', 'buddynext' ),
										esc_html( number_format_i18n( absint( $tag_item->post_count ) ) )
									);
									?>
								</span>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p style="font-size:var(--text-sm);color:var(--text-3);">
							<?php esc_html_e( 'No trending topics yet.', 'buddynext' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Browse categories -->
				<div class="bn-widget">
					<div class="bn-widget-title"><?php esc_html_e( 'Browse Categories', 'buddynext' ); ?></div>
					<div class="bn-cat-grid">
						<?php
						$categories = [
							'&#128187; ' . __( 'Dev', 'buddynext' ),
							'&#127912; ' . __( 'Design', 'buddynext' ),
							'&#128227; ' . __( 'Marketing', 'buddynext' ),
							'&#128640; ' . __( 'Startups', 'buddynext' ),
							'&#129302; ' . __( 'AI', 'buddynext' ),
							'&#128202; ' . __( 'Data', 'buddynext' ),
							'&#127919; ' . __( 'Product', 'buddynext' ),
							'&#128221; ' . __( 'Writing', 'buddynext' ),
						];
						foreach ( $categories as $cat_label ) :
							?>
							<button class="bn-cat-pill" type="button" data-wp-on--click="actions.browseCategory">
								<?php echo esc_html( html_entity_decode( $cat_label, ENT_HTML5, 'UTF-8' ) ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Popular spaces -->
				<div class="bn-widget">
					<div class="bn-widget-title"><?php esc_html_e( 'Popular Spaces', 'buddynext' ); ?></div>
					<?php if ( ! empty( $popular_spaces ) ) : ?>
						<?php foreach ( $popular_spaces as $space ) : ?>
							<div class="bn-space-entry">
								<div class="bn-space-icon" aria-hidden="true">
									<?php echo ! empty( $space->avatar_url ) ? esc_html( $space->avatar_url ) : '&#127968;'; ?>
								</div>
								<div class="bn-space-info">
									<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
									<div class="bn-space-meta">
										<?php
										printf(
											/* translators: %s: member count */
											esc_html__( '%s members', 'buddynext' ),
											esc_html( number_format_i18n( absint( $space->member_count ) ) )
										);
										?>
									</div>
								</div>
								<?php if ( ! $is_guest ) : ?>
									<button
										class="bn-btn-join"
										type="button"
										data-wp-on--click="actions.joinSpace"
										data-space-id="<?php echo esc_attr( (string) $space->id ); ?>"
									><?php esc_html_e( 'Join', 'buddynext' ); ?></button>
								<?php else : ?>
									<a
										class="bn-btn-join"
										href="<?php echo esc_url( wp_registration_url() ); ?>"
									><?php esc_html_e( 'Join', 'buddynext' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p style="font-size:var(--text-sm);color:var(--text-3);">
							<?php esc_html_e( 'No public spaces yet.', 'buddynext' ); ?>
						</p>
					<?php endif; ?>
				</div>

			</aside>
		</div>
	</div>

	<!-- REST config for Interactivity API store -->
	<script type="application/json" id="bn-feed-explore-config">
	<?php
	echo wp_json_encode(
		[
			'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
			'restNonce' => $rest_nonce,
			'userId'    => $current_user_id,
			'isGuest'   => $is_guest,
		]
	);
	?>
	</script>
</div>
