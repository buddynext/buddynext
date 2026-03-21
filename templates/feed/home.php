<?php
/**
 * Home feed template.
 *
 * Displays the personalised activity feed for authenticated users.
 * Pulls posts from bn_posts ordered by the follow graph via FeedService.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Authentication gate ──────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

if ( 0 === $current_user_id ) {
	?>
	<div class="bn-home-feed">
		<div class="bn-feed-shell">
			<div class="bn-auth-gate">
				<div class="bn-auth-gate-icon" aria-hidden="true">&#128274;</div>
				<h2 class="bn-auth-gate-title">
					<?php esc_html_e( 'Sign in to see your feed', 'buddynext' ); ?>
				</h2>
				<p class="bn-auth-gate-sub">
					<?php esc_html_e( 'Your personalised feed of posts, people, and spaces is waiting.', 'buddynext' ); ?>
				</p>
				<a
					class="bn-auth-gate-btn"
					href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
				><?php esc_html_e( 'Sign in', 'buddynext' ); ?></a>
				<a
					class="bn-auth-gate-register"
					href="<?php echo esc_url( wp_registration_url() ); ?>"
				><?php esc_html_e( 'Create an account', 'buddynext' ); ?></a>
			</div>
		</div>
	</div>
	<?php
	return;
}

// ── Current user data ────────────────────────────────────────────────────────
$bn_current_user    = get_userdata( $current_user_id );
$display_name       = $bn_current_user instanceof WP_User ? $bn_current_user->display_name : __( 'there', 'buddynext' );
$current_avatar_url = get_avatar_url( $current_user_id, array( 'size' => 68 ) );
$name_parts         = explode( ' ', trim( $display_name ) );
$current_initials   = strtoupper( substr( $name_parts[0], 0, 1 ) . ( isset( $name_parts[1] ) ? substr( $name_parts[1], 0, 1 ) : '' ) );

// ── Pagination ───────────────────────────────────────────────────────────────
$bn_page = max( 1, absint( isset( $_GET['bn_page'] ) ? $_GET['bn_page'] : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Feed data via FeedService ─────────────────────────────────────────────────
$feed_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'feed' ) : null;

$bn_posts   = array();
$feed_error = '';
$total      = 0;

if ( null !== $feed_service && method_exists( $feed_service, 'get_feed' ) ) {
	$feed_result = $feed_service->get_feed(
		$current_user_id,
		array(
			'per_page' => 20,
			'page'     => $bn_page,
		)
	);

	if ( is_wp_error( $feed_result ) ) {
		$feed_error = $feed_result->get_error_message();
	} elseif ( is_array( $feed_result ) ) {
		$bn_posts = isset( $feed_result['posts'] ) ? (array) $feed_result['posts'] : $feed_result;
		$total    = isset( $feed_result['total'] ) ? absint( $feed_result['total'] ) : count( $bn_posts );
	}
} else {
	// Fallback: direct DB query when FeedService is not yet registered.
	$posts_table = $wpdb->prefix . 'bn_posts';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.id, p.user_id, p.content, p.post_type, p.visibility, p.created_at, p.reaction_count, p.comment_count, p.share_count FROM {$posts_table} p WHERE p.status = %s ORDER BY p.created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'published',
			20,
			( $bn_page - 1 ) * 20
		)
	);

	if ( is_array( $raw ) ) {
		foreach ( $raw as $row ) {
			$bn_posts[] = (array) $row;
		}
	}
}

// ── Trending hashtags via HashtagService or direct DB ────────────────────────
$hashtag_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'hashtags' ) : null;
$trending_tags   = array();

if ( null !== $hashtag_service && method_exists( $hashtag_service, 'get_trending' ) ) {
	$raw_tags = $hashtag_service->get_trending( 5 );
	if ( is_array( $raw_tags ) ) {
		$trending_tags = $raw_tags;
	}
} else {
	$hashtags_table = $wpdb->prefix . 'bn_hashtags';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$db_tags = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT slug, post_count FROM {$hashtags_table} WHERE post_count > 0 ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			5
		)
	);

	if ( is_array( $db_tags ) ) {
		$trending_tags = $db_tags;
	}
}

// ── Suggested people (friend-of-friend heuristic, direct DB) ─────────────────
$follows_table = $wpdb->prefix . 'bn_follows';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$suggested_users = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT DISTINCT f2.following_id AS user_id FROM {$follows_table} f1 INNER JOIN {$follows_table} f2 ON f1.following_id = f2.follower_id WHERE f1.follower_id = %d AND f2.following_id != %d AND f2.following_id NOT IN ( SELECT following_id FROM {$follows_table} WHERE follower_id = %d ) ORDER BY RAND() LIMIT 3", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current_user_id,
		$current_user_id,
		$current_user_id
	)
);

// ── REST nonce ────────────────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Avatar colour palette (deterministic by user ID) ──────────────────────────
$avatar_colours = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-jt', 'av-mvs' );

// ── Pagination math ───────────────────────────────────────────────────────────
$bn_per_page = 20;
$next_page   = $bn_page + 1;
$has_more    = ( $total > 0 ) ? ( $bn_page * $bn_per_page < $total ) : ( count( $bn_posts ) === $bn_per_page );

/**
 * Format a UTC timestamp as a human-readable relative time label.
 *
 * @param string $datetime MySQL datetime string.
 * @return string Escaped, translated relative time.
 */
function bn_home_relative_time( string $datetime ): string {
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
?>
<style>
/* ── BuddyNext design tokens ── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:   11px;
	--text-sm:   13px;
	--text-base: 15px;
	--text-lg:   17px;
	--text-xl:   20px;
	--text-2xl:  24px;
	--text-3xl:  30px;
	--leading-tight:  1.25;
	--leading-normal: 1.5;
	--leading-body:   1.7;
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
	--jetonomy:        #5b21b6;
	--jetonomy-bg:     #f5f3ff;
	--jetonomy-border: #ddd6fe;
	--mvs:             #0f766e;
	--mvs-bg:          #f0fdf9;
	--mvs-border:      #99f6e4;
	--green:    #059669;  --green-bg:  #ecfdf5;
	--amber:    #d97706;  --amber-bg:  #fffbeb;
	--red:      #dc2626;  --red-bg:    #fef2f2;
	--s1: 4px;  --s2: 8px;  --s3: 12px; --s4: 16px;
	--s5: 20px; --s6: 24px; --s8: 32px; --s10: 40px; --s12: 48px;
	--r-sm: 4px;  --r-md: 8px;  --r-lg: 12px;  --r-xl: 16px;  --r-full: 9999px;
	/* Legacy radius aliases (match explore.php) */
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
	--jetonomy:    #a78bfa;  --jetonomy-bg: #1e1830;
	--mvs:         #34d399;  --mvs-bg:      #0d2420;
	--green:       #34d399;  --green-bg:    #0d2420;
	--amber:       #fbbf24;  --amber-bg:    #2a2000;
	--red:         #f87171;  --red-bg:      #2d0f0f;
}

/* ── Component styles ── */
.bn-home-feed {
	font-family: var(--font-body);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
}

/* Auth gate */
.bn-feed-shell {
	max-width: 1160px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
}
.bn-auth-gate {
	max-width: 420px;
	margin: var(--s12) auto;
	text-align: center;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s8) var(--s6);
}
.bn-auth-gate-icon { font-size: 40px; margin-bottom: var(--s4); }
.bn-auth-gate-title {
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	color: var(--text-1);
	margin-bottom: var(--s2);
}
.bn-auth-gate-sub {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s5);
	line-height: 1.6;
}
.bn-auth-gate-btn {
	display: inline-block;
	background: var(--brand);
	color: #fff;
	border-radius: var(--r-full);
	padding: 10px var(--s6);
	font-size: var(--text-sm);
	font-weight: 700;
	text-decoration: none;
	margin-right: var(--s2);
}
.bn-auth-gate-btn:hover { background: var(--brand-hover); }
.bn-auth-gate-register {
	display: inline-block;
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 600;
	text-decoration: none;
}
.bn-auth-gate-register:hover { text-decoration: underline; }

/* Page layout — 2 columns */
.bn-home-shell {
	max-width: 1160px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: start;
}

/* ── Feed column ── */
.bn-feed-area { min-width: 0; }

/* Feed tabs */
.bn-feed-tabs {
	display: flex;
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
	margin-bottom: var(--s4);
}
.bn-feed-tab {
	flex: 1;
	text-align: center;
	padding: var(--s2) var(--s3);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-right: 1px solid var(--border);
	background: none;
	border-top: none;
	border-bottom: none;
	font-family: var(--font-body);
}
.bn-feed-tab:last-child { border-right: none; }
.bn-feed-tab.active    { background: var(--brand); color: #fff; font-weight: 600; }
.bn-feed-tab:hover:not(.active) { background: var(--bg-hover); color: var(--text-1); }

/* ── Composer ── */
.bn-composer {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
	margin-bottom: var(--s3);
}
.bn-composer-row {
	display: flex;
	gap: var(--s3);
	align-items: center;
}
.bn-composer-input-wrap { flex: 1; }
.bn-composer-textarea {
	width: 100%;
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: 24px;
	padding: var(--s2) var(--s4);
	font-size: var(--text-base);
	color: var(--text-1);
	font-family: var(--font-body);
	resize: none;
	min-height: 44px;
	line-height: var(--leading-body);
	transition: border-color 0.15s, box-shadow 0.15s;
}
.bn-composer-textarea::placeholder { color: var(--text-3); }
.bn-composer-textarea:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px var(--brand-light);
}
.bn-composer-toolbar {
	display: flex;
	gap: var(--s1);
	align-items: center;
	margin-top: var(--s3);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
	flex-wrap: wrap;
}
.bn-toolbar-btn {
	display: flex;
	align-items: center;
	gap: 5px;
	padding: var(--s1) var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	color: var(--text-2);
	cursor: pointer;
	background: none;
	border: none;
	font-family: var(--font-body);
}
.bn-toolbar-btn:hover { background: var(--bg-hover); color: var(--text-1); }

.bn-composer-meta {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-left: auto;
}
.bn-privacy-select {
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 5px var(--s2);
	font-size: var(--text-xs);
	color: var(--text-2);
	font-family: var(--font-body);
	cursor: pointer;
}
.bn-privacy-select:focus {
	outline: none;
	border-color: var(--brand);
}
.bn-btn-post {
	background: var(--brand);
	color: #fff;
	border: none;
	border-radius: var(--r-full);
	padding: 7px var(--s4);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-btn-post:hover { background: var(--brand-hover); }
.bn-btn-post:disabled { opacity: 0.5; cursor: not-allowed; }

/* ── Post card ── */
.bn-post-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	margin-bottom: var(--s3);
	overflow: hidden;
}
.bn-post-header {
	display: flex;
	align-items: flex-start;
	gap: var(--s3);
	padding: var(--s4) var(--s4) 0;
}
.bn-post-author      { flex: 1; min-width: 0; }
.bn-post-author-name {
	font-size: var(--text-base);
	font-weight: 600;
	color: var(--text-1);
	line-height: 1.3;
}
.bn-post-author-meta {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: 1px;
	display: flex;
	align-items: center;
	gap: var(--s1);
	flex-wrap: wrap;
}
.bn-visibility-badge { color: var(--brand); font-weight: 500; }
.bn-post-more {
	color: var(--text-3);
	font-size: 18px;
	cursor: pointer;
	padding: 2px 4px;
	line-height: 1;
	background: none;
	border: none;
	border-radius: var(--radius-sm);
}
.bn-post-more:hover { background: var(--bg-hover); color: var(--text-1); }

.bn-post-body {
	padding: var(--s3) var(--s4);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
}
.bn-hashtag { color: var(--brand); font-weight: 500; }

/* Reaction chips */
.bn-post-reactions {
	display: flex;
	gap: var(--s1);
	padding: 0 var(--s4) var(--s2);
	flex-wrap: wrap;
}
.bn-reaction-chip {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 3px var(--s2);
	border-radius: 12px;
	border: 1px solid var(--border);
	font-size: var(--text-xs);
	cursor: pointer;
	background: var(--bg-subtle);
	color: var(--text-2);
	font-family: var(--font-body);
}
.bn-reaction-chip:hover { border-color: var(--brand); }
.bn-reaction-chip.mine {
	background: var(--brand-light);
	border-color: var(--brand);
	color: var(--brand);
	font-weight: 600;
}

/* Action bar */
.bn-post-actions {
	display: flex;
	border-top: 1px solid var(--border-soft);
}
.bn-action-btn {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: var(--s2) var(--s3);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	background: none;
	border: none;
	font-family: var(--font-body);
}
.bn-action-btn:hover { background: var(--bg-subtle); color: var(--text-1); }
.bn-action-btn.liked { color: var(--red); }
.bn-action-btn.bookmarked { color: var(--amber); }

/* Avatar — reuse same pattern as explore.php */
.bn-home-feed .avatar {
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
.bn-home-feed .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bn-home-feed .avatar.xs  { width: 28px; height: 28px; font-size: 10px; }
.bn-home-feed .avatar.sm  { width: 34px; height: 34px; font-size: var(--text-xs); }
.av-brand  { background: var(--brand); }
.av-green  { background: var(--green); }
.av-purple { background: #7c3aed; }
.av-orange { background: #ea580c; }
.av-pink   { background: #db2777; }
.av-jt     { background: var(--jetonomy); }
.av-mvs    { background: var(--mvs); }

/* ── Sidebar ── */
.bn-home-sidebar { display: flex; flex-direction: column; gap: var(--s4); }
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
	display: flex;
	align-items: center;
	gap: var(--s2);
}
.bn-widget-source-tag {
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 4px;
	letter-spacing: 0.04em;
}
.bn-jt-source-tag  { background: var(--jetonomy-bg); color: var(--jetonomy); }
.bn-mvs-source-tag { background: var(--mvs-bg);      color: var(--mvs); }

/* Suggested people */
.bn-member-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-member-row:last-of-type { border-bottom: none; }
.bn-member-info    { flex: 1; min-width: 0; }
.bn-member-name    { font-size: var(--text-sm); font-weight: 600; color: var(--text-1); line-height: 1.2; }
.bn-member-sub     { font-size: var(--text-xs); color: var(--text-3); }
.bn-btn-follow {
	padding: 4px 12px;
	border-radius: 14px;
	border: 1.5px solid var(--brand);
	color: var(--brand);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	background: var(--bg);
	white-space: nowrap;
	font-family: var(--font-body);
}
.bn-btn-follow:hover { background: var(--brand); color: #fff; }

/* Trending hashtags */
.bn-trending-tag {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 7px 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-trending-tag:last-child { border-bottom: none; }
.bn-tag-name  { color: var(--brand); font-weight: 600; font-size: var(--text-sm); text-decoration: none; }
.bn-tag-name:hover { text-decoration: underline; }
.bn-tag-count { color: var(--text-3); font-size: var(--text-xs); }

/* Widget see-all link */
.bn-widget-see-all {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--brand);
	text-decoration: none;
	margin-top: var(--s2);
	display: block;
}
.bn-widget-see-all:hover { text-decoration: underline; }

/* Load more */
.bn-load-more-zone {
	text-align: center;
	padding: var(--s4) 0;
}
.bn-load-more-btn {
	display: inline-block;
	padding: 9px var(--s6);
	border: 1.5px solid var(--border);
	border-radius: var(--r-full);
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-2);
	background: var(--surface);
	cursor: pointer;
	text-decoration: none;
	font-family: var(--font-body);
}
.bn-load-more-btn:hover { border-color: var(--brand); color: var(--brand); }

/* Error notice */
.bn-feed-error {
	background: var(--red-bg);
	border: 1px solid var(--red);
	border-radius: var(--radius);
	padding: var(--s3) var(--s4);
	color: var(--red);
	font-size: var(--text-sm);
	margin-bottom: var(--s4);
}

/* Empty state */
.bn-feed-empty {
	text-align: center;
	padding: var(--s12) var(--s8);
	color: var(--text-3);
}
.bn-feed-empty-icon  { font-size: 36px; margin-bottom: var(--s3); }
.bn-feed-empty-title { font-size: var(--text-base); font-weight: 600; color: var(--text-2); }
.bn-feed-empty-sub   { font-size: var(--text-sm); margin-top: var(--s2); }

/* ── Dark mode component overrides ── */
[data-theme="dark"] .bn-post-card      { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-widget         { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-composer       { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-composer-textarea { background: var(--bg-hover); color: var(--text-1); }
[data-theme="dark"] .bn-feed-tabs      { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-feed-tab       { background: var(--surface); color: var(--text-2); border-color: var(--border); }
[data-theme="dark"] .bn-btn-follow     { background: var(--surface); }
[data-theme="dark"] .bn-load-more-btn  { background: var(--surface); }

/* ── Mobile responsive ── */
@media (max-width: 1024px) {
	.bn-home-shell    { padding: var(--s4); grid-template-columns: 1fr 240px; gap: var(--s4); }
}
@media (max-width: 640px) {
	.bn-home-shell         { padding: var(--s3); grid-template-columns: 1fr; }
	.bn-home-sidebar       { display: none; }
	.bn-feed-shell         { padding: var(--s3); }
	.bn-composer-toolbar   { gap: var(--s1); }
	.bn-toolbar-btn        { font-size: 11px; padding: var(--s1); }
	.bn-post-author-name   { font-size: var(--text-sm); }
	.bn-post-body          { font-size: var(--text-sm); }
	.bn-action-btn         { font-size: 11px; gap: 3px; padding: var(--s1) var(--s2); }
	.bn-widget-title       { font-size: var(--text-xs); }
}
</style>

<div
	class="bn-home-feed"
	data-wp-interactive="buddynext/feed"
	data-wp-context='{"scope":"home","page":<?php echo absint( $bn_page ); ?>,"composerOpen":false,"composerContent":"","composerPrivacy":"public","submitting":false}'
>
	<div class="bn-home-shell">

		<!-- ── Feed column ── -->
		<main class="bn-feed-area" id="bn-home-feed-main">

			<!-- Feed tabs -->
			<div
				class="bn-feed-tabs"
				role="tablist"
				aria-label="<?php esc_attr_e( 'Feed filter', 'buddynext' ); ?>"
			>
				<button
					class="bn-feed-tab active"
					type="button"
					role="tab"
					aria-selected="true"
					data-wp-on--click="actions.setFeedTab"
					data-tab="following"
				><?php esc_html_e( 'Following', 'buddynext' ); ?></button>
				<button
					class="bn-feed-tab"
					type="button"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setFeedTab"
					data-tab="spaces"
				><?php esc_html_e( 'Spaces', 'buddynext' ); ?></button>
				<button
					class="bn-feed-tab"
					type="button"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setFeedTab"
					data-tab="hashtags"
				><?php esc_html_e( 'Hashtags', 'buddynext' ); ?></button>
				<button
					class="bn-feed-tab"
					type="button"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setFeedTab"
					data-tab="all"
				><?php esc_html_e( 'All', 'buddynext' ); ?></button>
			</div>

			<!-- ── Composer ── -->
			<div
				class="bn-composer"
				role="region"
				aria-label="<?php esc_attr_e( 'Create a post', 'buddynext' ); ?>"
			>
				<div class="bn-composer-row">
					<?php if ( $current_avatar_url ) : ?>
						<img
							src="<?php echo esc_url( $current_avatar_url ); ?>"
							alt="<?php echo esc_attr( $display_name ); ?>"
							class="avatar sm"
							loading="lazy"
							width="34"
							height="34"
						>
					<?php else : ?>
						<?php
						$colour_idx = absint( $current_user_id ) % count( $avatar_colours );
						?>
						<div
							class="avatar sm <?php echo esc_attr( $avatar_colours[ $colour_idx ] ); ?>"
							aria-hidden="true"
						><?php echo esc_html( $current_initials ); ?></div>
					<?php endif; ?>

					<div class="bn-composer-input-wrap">
						<label for="bn-composer-textarea" class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: user display name */
								esc_html__( "What's on your mind, %s?", 'buddynext' ),
								esc_html( $display_name )
							);
							?>
						</label>
						<textarea
							id="bn-composer-textarea"
							class="bn-composer-textarea"
							rows="1"
							placeholder="
							<?php
							printf(
								/* translators: %s: user display name */
								esc_attr__( "What's on your mind, %s?", 'buddynext' ),
								esc_attr( $display_name )
							);
							?>
							"
							aria-label="<?php esc_attr_e( 'Post composer', 'buddynext' ); ?>"
							data-wp-bind--value="context.composerContent"
							data-wp-on--input="actions.onComposerInput"
							data-wp-on--focus="actions.openComposer"
						></textarea>
					</div>
				</div>

				<div class="bn-composer-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Post attachment options', 'buddynext' ); ?>">
					<button
						class="bn-toolbar-btn"
						type="button"
						aria-label="<?php esc_attr_e( 'Add photo', 'buddynext' ); ?>"
						data-wp-on--click="actions.attachPhoto"
					>&#128247; <?php esc_html_e( 'Photo', 'buddynext' ); ?></button>

					<button
						class="bn-toolbar-btn"
						type="button"
						aria-label="<?php esc_attr_e( 'Add video', 'buddynext' ); ?>"
						data-wp-on--click="actions.attachVideo"
					>&#127916; <?php esc_html_e( 'Video', 'buddynext' ); ?></button>

					<button
						class="bn-toolbar-btn"
						type="button"
						aria-label="<?php esc_attr_e( 'Add link preview', 'buddynext' ); ?>"
						data-wp-on--click="actions.attachLink"
					>&#128279; <?php esc_html_e( 'Link', 'buddynext' ); ?></button>

					<button
						class="bn-toolbar-btn"
						type="button"
						aria-label="<?php esc_attr_e( 'Create poll', 'buddynext' ); ?>"
						data-wp-on--click="actions.attachPoll"
					>&#128202; <?php esc_html_e( 'Poll', 'buddynext' ); ?></button>

					<div class="bn-composer-meta">
						<label for="bn-composer-privacy" class="screen-reader-text">
							<?php esc_html_e( 'Post visibility', 'buddynext' ); ?>
						</label>
						<select
							id="bn-composer-privacy"
							class="bn-privacy-select"
							aria-label="<?php esc_attr_e( 'Post visibility', 'buddynext' ); ?>"
							data-wp-on--change="actions.setPrivacy"
						>
							<option value="public"><?php esc_html_e( 'Everyone', 'buddynext' ); ?></option>
							<option value="followers"><?php esc_html_e( 'Followers', 'buddynext' ); ?></option>
							<option value="connections"><?php esc_html_e( 'Connections', 'buddynext' ); ?></option>
						</select>

						<button
							class="bn-btn-post"
							type="button"
							data-wp-on--click="actions.submitPost"
							data-wp-bind--disabled="context.submitting"
							aria-label="<?php esc_attr_e( 'Publish post', 'buddynext' ); ?>"
						><?php esc_html_e( 'Post', 'buddynext' ); ?></button>
					</div>
				</div>
			</div>

			<!-- ── Feed error ── -->
			<?php if ( '' !== $feed_error ) : ?>
				<div class="bn-feed-error" role="alert">
					<?php echo esc_html( $feed_error ); ?>
				</div>
			<?php endif; ?>

			<!-- ── Post cards ── -->
			<?php if ( ! empty( $bn_posts ) ) : ?>

				<?php foreach ( $bn_posts as $post_item ) : ?>
					<?php
					// Normalise: support both array and object rows.
					$bn_post_id      = absint( is_array( $post_item ) ? ( $post_item['id'] ?? 0 ) : ( $post_item->id ?? 0 ) );
					$post_user_id    = absint( is_array( $post_item ) ? ( $post_item['user_id'] ?? 0 ) : ( $post_item->user_id ?? 0 ) );
					$post_content    = is_array( $post_item ) ? ( $post_item['content'] ?? '' ) : ( $post_item->content ?? '' );
					$post_visibility = is_array( $post_item ) ? ( $post_item['visibility'] ?? 'public' ) : ( $post_item->visibility ?? 'public' );
					$post_created    = is_array( $post_item ) ? ( $post_item['created_at'] ?? '' ) : ( $post_item->created_at ?? '' );
					$post_reactions  = absint( is_array( $post_item ) ? ( $post_item['reaction_count'] ?? 0 ) : ( $post_item->reaction_count ?? 0 ) );
					$post_comments   = absint( is_array( $post_item ) ? ( $post_item['comment_count'] ?? 0 ) : ( $post_item->comment_count ?? 0 ) );
					$post_shares     = absint( is_array( $post_item ) ? ( $post_item['share_count'] ?? 0 ) : ( $post_item->share_count ?? 0 ) );

					$post_author     = get_userdata( $post_user_id );
					$author_name     = $post_author instanceof WP_User ? $post_author->display_name : __( 'Community Member', 'buddynext' );
					$author_parts    = explode( ' ', trim( $author_name ) );
					$author_initials = strtoupper( substr( $author_parts[0], 0, 1 ) . ( isset( $author_parts[1] ) ? substr( $author_parts[1], 0, 1 ) : '' ) );
					$author_avatar   = get_avatar_url( $post_user_id, array( 'size' => 68 ) );
					$author_colour   = $avatar_colours[ $post_user_id % count( $avatar_colours ) ];
					$post_time       = '' !== $post_created ? bn_home_relative_time( $post_created ) : esc_html__( 'recently', 'buddynext' );

					$visibility_label = '';
					if ( 'public' === $post_visibility ) {
						$visibility_label = esc_html__( 'Public', 'buddynext' );
					} elseif ( 'followers' === $post_visibility ) {
						$visibility_label = esc_html__( 'Followers', 'buddynext' );
					} elseif ( 'connections' === $post_visibility ) {
						$visibility_label = esc_html__( 'Connections', 'buddynext' );
					}
					?>
					<article
						class="bn-post-card"
						data-post-id="<?php echo esc_attr( (string) $bn_post_id ); ?>"
						data-wp-context='{"postId":<?php echo absint( $bn_post_id ); ?>,"liked":false,"bookmarked":false}'
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: author name */ __( 'Post by %s', 'buddynext' ), $author_name ) ); ?>"
					>
						<div class="bn-post-header">
							<?php if ( $author_avatar ) : ?>
								<img
									src="<?php echo esc_url( $author_avatar ); ?>"
									alt="<?php echo esc_attr( $author_name ); ?>"
									class="avatar sm"
									loading="lazy"
									width="34"
									height="34"
								>
							<?php else : ?>
								<div class="avatar sm <?php echo esc_attr( $author_colour ); ?>" aria-hidden="true">
									<?php echo esc_html( $author_initials ); ?>
								</div>
							<?php endif; ?>

							<div class="bn-post-author">
								<div class="bn-post-author-name"><?php echo esc_html( $author_name ); ?></div>
								<div class="bn-post-author-meta">
									<span><?php echo esc_html( $post_time ); ?></span>
									<?php if ( '' !== $visibility_label ) : ?>
										<span aria-hidden="true">&middot;</span>
										<span class="bn-visibility-badge"><?php echo esc_html( $visibility_label ); ?></span>
									<?php endif; ?>
								</div>
							</div>

							<button
								class="bn-post-more"
								type="button"
								aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>"
								data-wp-on--click="actions.openPostMenu"
							>&#8943;</button>
						</div>

						<div class="bn-post-body">
							<?php echo wp_kses_post( $post_content ); ?>
						</div>

						<!-- Reaction chips -->
						<?php if ( $post_reactions > 0 || $post_comments > 0 || $post_shares > 0 ) : ?>
							<div class="bn-post-reactions" aria-label="<?php esc_attr_e( 'Reactions', 'buddynext' ); ?>">
								<?php if ( $post_reactions > 0 ) : ?>
									<button
										class="bn-reaction-chip"
										type="button"
										data-wp-on--click="actions.reactToPost"
										data-reaction="like"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %d: count */ _n( '%d reaction', '%d reactions', $post_reactions, 'buddynext' ), $post_reactions ) ); ?>"
									>&#10084;&#65039; <?php echo esc_html( number_format_i18n( $post_reactions ) ); ?></button>
								<?php endif; ?>
								<button
									class="bn-reaction-chip"
									type="button"
									data-wp-on--click="actions.openReactionPicker"
									aria-label="<?php esc_attr_e( 'Add reaction', 'buddynext' ); ?>"
								>+</button>
							</div>
						<?php endif; ?>

						<!-- Action bar -->
						<div class="bn-post-actions" role="group" aria-label="<?php esc_attr_e( 'Post actions', 'buddynext' ); ?>">
							<button
								class="bn-action-btn"
								type="button"
								data-wp-on--click="actions.likePost"
								data-wp-class--liked="context.liked"
								aria-label="<?php esc_attr_e( 'Like this post', 'buddynext' ); ?>"
							>&#10084;&#65039; <?php esc_html_e( 'Like', 'buddynext' ); ?></button>

							<button
								class="bn-action-btn"
								type="button"
								data-wp-on--click="actions.openComments"
								aria-label="
								<?php
								echo esc_attr(
									sprintf(
										/* translators: %d: comment count */
										_n( 'View %d comment', 'View %d comments', $post_comments, 'buddynext' ),
										$post_comments
									)
								);
								?>
								"
							>&#128172; 
							<?php
							if ( $post_comments > 0 ) {
								printf(
									/* translators: %s: comment count */
									esc_html__( 'Comment (%s)', 'buddynext' ),
									esc_html( number_format_i18n( $post_comments ) )
								);
							} else {
								esc_html_e( 'Comment', 'buddynext' );
							}
							?>
							</button>

							<button
								class="bn-action-btn"
								type="button"
								data-wp-on--click="actions.sharePost"
								aria-label="<?php esc_attr_e( 'Share this post', 'buddynext' ); ?>"
							>&#8599;&#65039; 
							<?php
							if ( $post_shares > 0 ) {
								printf(
									/* translators: %s: share count */
									esc_html__( 'Share (%s)', 'buddynext' ),
									esc_html( number_format_i18n( $post_shares ) )
								);
							} else {
								esc_html_e( 'Share', 'buddynext' );
							}
							?>
							</button>

							<button
								class="bn-action-btn"
								type="button"
								data-wp-on--click="actions.bookmarkPost"
								data-wp-class--bookmarked="context.bookmarked"
								aria-label="<?php esc_attr_e( 'Save this post', 'buddynext' ); ?>"
							>&#128278; <?php esc_html_e( 'Save', 'buddynext' ); ?></button>
						</div>
					</article>
				<?php endforeach; ?>

			<?php else : ?>
				<!-- Empty feed state -->
				<div class="bn-feed-empty" role="status">
					<div class="bn-feed-empty-icon" aria-hidden="true">&#128247;</div>
					<div class="bn-feed-empty-title">
						<?php esc_html_e( 'Your feed is quiet for now', 'buddynext' ); ?>
					</div>
					<div class="bn-feed-empty-sub">
						<?php esc_html_e( 'Follow people or join spaces to see posts here.', 'buddynext' ); ?>
					</div>
				</div>
			<?php endif; ?>

			<!-- ── Load more / pagination ── -->
			<?php if ( $has_more ) : ?>
				<div class="bn-load-more-zone">
					<a
						class="bn-load-more-btn"
						href="<?php echo esc_url( add_query_arg( 'bn_page', $next_page ) ); ?>"
						data-wp-on--click="actions.loadMore"
						aria-label="<?php esc_attr_e( 'Load more posts', 'buddynext' ); ?>"
					><?php esc_html_e( 'Load more', 'buddynext' ); ?></a>
				</div>
			<?php endif; ?>

		</main>

		<!-- ── Sidebar ── -->
		<aside
			class="bn-home-sidebar"
			aria-label="<?php esc_attr_e( 'Feed sidebar', 'buddynext' ); ?>"
		>

			<!-- Suggested people -->
			<div class="bn-widget">
				<div class="bn-widget-title">&#128101; <?php esc_html_e( 'People to Follow', 'buddynext' ); ?></div>

				<?php if ( ! empty( $suggested_users ) ) : ?>
					<?php foreach ( $suggested_users as $suggestion ) : ?>
						<?php
						$sug_id     = absint( is_array( $suggestion ) ? ( $suggestion['user_id'] ?? 0 ) : ( $suggestion->user_id ?? 0 ) );
						$sug_data   = get_userdata( $sug_id );
						$sug_name   = $sug_data instanceof WP_User ? $sug_data->display_name : __( 'Member', 'buddynext' );
						$sug_parts  = explode( ' ', trim( $sug_name ) );
						$sug_init   = strtoupper( substr( $sug_parts[0], 0, 1 ) . ( isset( $sug_parts[1] ) ? substr( $sug_parts[1], 0, 1 ) : '' ) );
						$sug_avatar = get_avatar_url( $sug_id, array( 'size' => 68 ) );
						$sug_colour = $avatar_colours[ $sug_id % count( $avatar_colours ) ];
						?>
						<div class="bn-member-row">
							<?php if ( $sug_avatar ) : ?>
								<img
									src="<?php echo esc_url( $sug_avatar ); ?>"
									alt="<?php echo esc_attr( $sug_name ); ?>"
									class="avatar sm"
									loading="lazy"
									width="34"
									height="34"
								>
							<?php else : ?>
								<div class="avatar sm <?php echo esc_attr( $sug_colour ); ?>" aria-hidden="true">
									<?php echo esc_html( $sug_init ); ?>
								</div>
							<?php endif; ?>
							<div class="bn-member-info">
								<div class="bn-member-name"><?php echo esc_html( $sug_name ); ?></div>
								<div class="bn-member-sub"><?php esc_html_e( 'Suggested for you', 'buddynext' ); ?></div>
							</div>
							<button
								class="bn-btn-follow"
								type="button"
								data-wp-on--click="actions.followUser"
								data-user-id="<?php echo esc_attr( (string) $sug_id ); ?>"
							><?php esc_html_e( 'Follow', 'buddynext' ); ?></button>
						</div>
					<?php endforeach; ?>

				<?php else : ?>
					<!-- Fallback static suggestions when follows table is empty -->
					<?php
					$static_suggestions = array(
						array(
							'colour'   => 'av-orange',
							'initials' => 'LM',
							'name'     => 'Lena Martinez',
							'sub'      => __( '3 mutual connections', 'buddynext' ),
						),
						array(
							'colour'   => 'av-green',
							'initials' => 'DR',
							'name'     => 'David Reyes',
							'sub'      => __( 'Follows you', 'buddynext' ),
						),
						array(
							'colour'   => 'av-purple',
							'initials' => 'JP',
							'name'     => 'Julia Park',
							'sub'      => __( '5 mutual connections', 'buddynext' ),
						),
					);
					foreach ( $static_suggestions as $static_sug ) :
						?>
						<div class="bn-member-row">
							<div class="avatar sm <?php echo esc_attr( $static_sug['colour'] ); ?>" aria-hidden="true">
								<?php echo esc_html( $static_sug['initials'] ); ?>
							</div>
							<div class="bn-member-info">
								<div class="bn-member-name"><?php echo esc_html( $static_sug['name'] ); ?></div>
								<div class="bn-member-sub"><?php echo esc_html( $static_sug['sub'] ); ?></div>
							</div>
							<button
								class="bn-btn-follow"
								type="button"
								data-wp-on--click="actions.followUser"
								data-user-id="0"
							><?php esc_html_e( 'Follow', 'buddynext' ); ?></button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<a
					class="bn-widget-see-all"
					href="<?php echo esc_url( home_url( '/community/members/' ) ); ?>"
				><?php esc_html_e( 'See all suggestions &rarr;', 'buddynext' ); ?></a>
			</div>

			<!-- Trending hashtags -->
			<div class="bn-widget">
				<div class="bn-widget-title">&#128293; <?php esc_html_e( 'Trending Hashtags', 'buddynext' ); ?></div>

				<?php if ( ! empty( $trending_tags ) ) : ?>
					<?php foreach ( $trending_tags as $tag_item ) : ?>
						<?php
						$tag_slug  = is_array( $tag_item ) ? ( $tag_item['slug'] ?? '' ) : ( $tag_item->slug ?? '' );
						$tag_count = absint( is_array( $tag_item ) ? ( $tag_item['post_count'] ?? 0 ) : ( $tag_item->post_count ?? 0 ) );
						?>
						<div class="bn-trending-tag">
							<a
								class="bn-tag-name"
								href="<?php echo esc_url( home_url( '/community/tag/' . sanitize_title( $tag_slug ) . '/' ) ); ?>"
							>#<?php echo esc_html( $tag_slug ); ?></a>
							<span class="bn-tag-count">
								<?php
								printf(
									/* translators: %s: number of posts */
									esc_html__( '%s posts', 'buddynext' ),
									esc_html( number_format_i18n( $tag_count ) )
								);
								?>
							</span>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p style="font-size:var(--text-sm);color:var(--text-3);">
						<?php esc_html_e( 'No trending hashtags yet.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>

				<a
					class="bn-widget-see-all"
					href="<?php echo esc_url( home_url( '/community/hashtags/' ) ); ?>"
				><?php esc_html_e( 'Browse all hashtags &rarr;', 'buddynext' ); ?></a>
			</div>

		</aside>
	</div>

	<!-- REST config for Interactivity API store -->
	<script type="application/json" id="bn-feed-home-config">
	<?php
	echo wp_json_encode(
		array(
			'restUrl'     => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
			'restNonce'   => $rest_nonce,
			'userId'      => $current_user_id,
			'displayName' => $display_name,
			'page'        => $bn_page,
		)
	);
	?>
	</script>
</div>
