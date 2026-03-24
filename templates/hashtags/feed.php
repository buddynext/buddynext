<?php
/**
 * BuddyNext hashtag feed template.
 *
 * Renders a single hashtag's page: a hero header with stats, a follow toggle,
 * sort tabs, and a list of posts tagged with that hashtag — including native
 * BuddyNext posts and bridged Jetonomy forum threads. The sidebar shows
 * hashtag metadata, related tags and top contributors.
 *
 * URL pattern : /community/tag/{slug}/
 * Overridable : copy to {theme}/buddynext/hashtags/feed.php
 *
 * REST endpoint: GET buddynext/v1/feed?hashtag={slug}&sort=top|latest&cursor=X
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

// Headers are already sent by wp_head() when this template runs — use an
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

// ── Check if current user follows this hashtag ────────────────────────────
$follows_hashtag   = false;
$hashtag_posts     = array();
$related_tags      = array();
$top_contributors  = array();
$posts_table       = $wpdb->prefix . 'bn_posts';
$post_hashtags_tbl = $wpdb->prefix . 'bn_post_hashtags';

if ( ! $hashtag_not_found ) {
	if ( $is_logged_in ) {
		$hashtag_follows_table = $wpdb->prefix . 'bn_hashtag_follows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$follows_hashtag = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$hashtag_follows_table} WHERE user_id = %d AND hashtag_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$current_user_id,
				(int) $hashtag->id
			)
		);
	}

	// ── Feed posts for this hashtag ─────────────────────────────────────────
	$limit = absint( $args['limit'] ?? 10 );

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
			ORDER BY (p.reaction_count + p.comment_count * 2) DESC, p.created_at DESC
			LIMIT %d",
			(int) $hashtag->id,
			$limit
		)
	); // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// ── Related hashtags ─────────────────────────────────────────────────────
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

	// ── Top contributors ─────────────────────────────────────────────────────
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
}

// ── REST nonce ─────────────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Formatting helpers ─────────────────────────────────────────────────────
$avatar_colours = array( 'av-brand', 'av-green', 'av-purple', 'av-orange', 'av-pink', 'av-jt', 'av-mvs' );

/**
 * Format a UTC timestamp as a relative human-readable string.
 *
 * @param string $datetime MySQL datetime.
 * @return string Escaped relative label.
 */
function bn_tag_relative_time( string $datetime ): string {
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
 * Linkify #hashtags in escaped content.
 *
 * @param string $content Escaped content (from wp_kses_post).
 * @return string Content with hashtag anchors.
 */
function bn_tag_linkify( string $content ): string {
	return preg_replace_callback(
		'/#([a-zA-Z0-9_]+)/',
		static function ( array $m ): string {
			$slug = sanitize_title( $m[1] );
			$url  = \BuddyNext\Core\PageRouter::hashtag_feed_url( $slug );
			return '<a href="' . esc_url( $url ) . '" class="bn-hashtag">#' . esc_html( $m[1] ) . '</a>';
		},
		$content
	) ?? $content;
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
.bn-hashtag-feed {
	font-family: var(--font-body);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
}

/* Page shell: two-column */
.bn-hashtag-shell {
	max-width: 1160px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: start;
}
.bn-hashtag-feed-area { min-width: 0; }

/* Avatars */
.bn-hashtag-feed .avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	font-size: var(--text-xs);
	letter-spacing: 0.02em;
	overflow: hidden;
}
.bn-hashtag-feed .avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bn-hashtag-feed .avatar.xs  { width: 28px; height: 28px; font-size: 10px; }
.bn-hashtag-feed .avatar.sm  { width: 34px; height: 34px; }
.av-brand  { background: var(--brand); }
.av-green  { background: var(--green); }
.av-purple { background: #7c3aed; }
.av-orange { background: #ea580c; }
.av-pink   { background: #db2777; }
.av-jt     { background: var(--jetonomy); }
.av-mvs    { background: var(--mvs); }
.av-teal   { background: #0d9488; }
.av-rose   { background: #e11d48; }

/* ── Hashtag hero ── */
.bn-hashtag-hero {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s6) var(--s6) var(--s4);
	margin-bottom: var(--s4);
}
.bn-hashtag-title {
	font-family: var(--font-display);
	font-size: 38px;
	font-weight: 800;
	color: var(--brand);
	letter-spacing: -1px;
	line-height: 1.1;
	margin-bottom: var(--s2);
}
.bn-hashtag-stats {
	font-size: var(--text-base);
	color: var(--text-2);
	margin-bottom: var(--s4);
}
.bn-hashtag-actions {
	display: flex;
	align-items: center;
	gap: var(--s3);
	margin-bottom: var(--s4);
	flex-wrap: wrap;
}
.bn-btn-follow-tag {
	padding: 7px var(--s4);
	border-radius: 20px;
	border: 1.5px solid var(--brand);
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	background: transparent;
	font-family: var(--font-body);
	display: flex;
	align-items: center;
	gap: 5px;
}
.bn-btn-follow-tag:hover        { background: var(--brand-light); }
.bn-btn-follow-tag.following    { background: var(--brand-light); }
.bn-btn-primary {
	background: var(--brand);
	color: #fff;
	border: none;
	border-radius: 20px;
	padding: 7px var(--s4);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	display: flex;
	align-items: center;
	gap: 5px;
	text-decoration: none;
}
.bn-btn-primary:hover { background: var(--brand-hover); }

/* Sort tabs */
.bn-sort-tabs {
	display: flex;
	gap: 2px;
	border-top: 1px solid var(--border-soft);
	padding-top: var(--s3);
	flex-wrap: wrap;
}
.bn-sort-tab {
	padding: var(--s1) var(--s4);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	background: transparent;
	border: none;
	font-family: var(--font-body);
}
.bn-sort-tab:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-sort-tab.active { background: var(--brand-light); color: var(--brand); font-weight: 600; }

/* ── Post card ── */
.bn-tag-post-card {
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
.bn-post-author        { flex: 1; min-width: 0; }
.bn-post-author-name   { font-size: var(--text-base); font-weight: 600; color: var(--text-1); line-height: 1.3; }
.bn-post-author-meta   { font-size: var(--text-xs); color: var(--text-3); margin-top: 1px; display: flex; align-items: center; gap: var(--s1); flex-wrap: wrap; }
.bn-post-more          { color: var(--text-3); font-size: 18px; cursor: pointer; padding: 2px 4px; line-height: 1; background: transparent; border: none; }

.bn-post-body {
	padding: var(--s3) var(--s4);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
}
.bn-hashtag { color: var(--brand); font-weight: 500; text-decoration: none; }
.bn-hashtag:hover { text-decoration: underline; }

/* Reactions */
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
.bn-reaction-chip:hover      { border-color: var(--brand); }
.bn-reaction-chip.mine       { background: var(--brand-light); border-color: var(--brand); color: var(--brand); font-weight: 600; }

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
	background: transparent;
	border: none;
	font-family: var(--font-body);
}
.bn-action-btn:hover { background: var(--bg-subtle); color: var(--text-1); }
.bn-action-btn.liked { color: var(--red); }

/* Jetonomy bridged card */
.bn-tag-post-card.jt-card { border-left: 3px solid var(--jetonomy); }
.bn-jt-source-label {
	display: flex;
	align-items: center;
	gap: var(--s1);
	padding: var(--s2) var(--s4) 0;
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--jetonomy);
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.bn-jt-meta-row {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s1) var(--s4) var(--s2);
	font-size: var(--text-xs);
	color: var(--text-3);
}
.bn-jt-stat       { display: flex; align-items: center; gap: 3px; }
.bn-jt-answered   { color: var(--green); font-weight: 600; }
.bn-jt-tag {
	display: inline-block;
	background: var(--jetonomy-bg);
	color: var(--jetonomy);
	font-size: var(--text-xs);
	font-weight: 600;
	padding: 2px var(--s2);
	border-radius: var(--radius-sm);
	margin-right: var(--s1);
}
.bn-jt-vote-bar {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) var(--s4);
	border-top: 1px solid var(--border-soft);
}
.bn-vote-btn {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: var(--s1) var(--s3);
	border-radius: var(--radius-sm);
	border: 1px solid var(--border);
	background: var(--bg-subtle);
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-2);
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-vote-btn:hover   { border-color: var(--jetonomy); color: var(--jetonomy); }
.bn-vote-btn.upvoted { background: var(--jetonomy-bg); border-color: var(--jetonomy); color: var(--jetonomy); }
.bn-jt-open-link {
	margin-left: auto;
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--jetonomy);
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: 4px;
	text-decoration: none;
}

/* ── Sidebar ── */
.bn-hashtag-sidebar { display: flex; flex-direction: column; gap: var(--s4); }
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

/* About widget */
.bn-about-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: var(--s1) 0;
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-sm);
}
.bn-about-row:last-of-type { border-bottom: none; }
.bn-about-label { color: var(--text-2); }
.bn-about-value { color: var(--text-1); font-weight: 500; }

/* Follow toggle */
.bn-follow-toggle-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: var(--s3);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
}
.bn-follow-toggle-label { font-size: var(--text-sm); font-weight: 600; color: var(--text-1); }
.bn-toggle-switch {
	width: 40px;
	height: 22px;
	background: var(--brand);
	border-radius: 11px;
	position: relative;
	cursor: pointer;
	flex-shrink: 0;
	border: none;
}
.bn-toggle-switch::after {
	content: '';
	position: absolute;
	top: 3px;
	right: 3px;
	width: 16px;
	height: 16px;
	background: #fff;
	border-radius: 50%;
	transition: right 0.15s;
}
.bn-toggle-switch[aria-checked="false"] { background: var(--border); }
.bn-toggle-switch[aria-checked="false"]::after { right: auto; left: 3px; }

/* Related hashtags */
.bn-related-tag-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
	cursor: pointer;
}
.bn-related-tag-row:last-child { border-bottom: none; }
.bn-related-tag-name  { font-size: var(--text-sm); font-weight: 600; color: var(--brand); text-decoration: none; }
.bn-related-tag-name:hover { text-decoration: underline; }
.bn-related-tag-count { font-size: var(--text-xs); color: var(--text-3); }

/* Top contributors */
.bn-member-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-member-row:last-of-type { border-bottom: none; }
.bn-member-info { flex: 1; min-width: 0; }
.bn-member-name { font-size: var(--text-sm); font-weight: 600; color: var(--text-1); line-height: 1.2; }
.bn-member-sub  { font-size: var(--text-xs); color: var(--text-3); }

.bn-widget-see-all {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--brand);
	cursor: pointer;
	margin-top: var(--s3);
	display: block;
	text-decoration: none;
}
.bn-widget-see-all:hover { text-decoration: underline; }

/* Load more */
.bn-load-more {
	text-align: center;
	padding: var(--s4);
	color: var(--text-3);
	font-size: var(--text-sm);
}

/* ── Dark mode ── */
[data-theme="dark"] .bn-hashtag-hero     { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-tag-post-card    { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-widget           { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-toggle-switch    { background: var(--brand); }

/* ── Mobile responsive ── */
@media (max-width: 1024px) {
	.bn-hashtag-shell { padding: var(--s4) var(--s4); grid-template-columns: 1fr 260px; }
}
@media (max-width: 640px) {
	.bn-hashtag-shell          { grid-template-columns: 1fr; padding: var(--s3); gap: var(--s3); }
	.bn-hashtag-sidebar        { display: none; }
	.bn-hashtag-title          { font-size: var(--text-2xl); }
	.bn-hashtag-actions        { gap: var(--s2); }
	.bn-action-btn             { font-size: var(--text-xs); padding: var(--s1) var(--s2); gap: 3px; }
	.bn-post-body              { font-size: var(--text-sm); }
	.bn-sort-tabs              { gap: 2px; }
	.bn-sort-tab               { font-size: var(--text-xs); padding: var(--s1) var(--s2); }
}
</style>

<?php
if ( $hashtag_not_found ) :
	?>
	<div class="bn-hashtag-feed">
		<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:var(--s12) var(--s4);text-align:center;">
			<span style="font-size:48px;margin-bottom:var(--s4);">#</span>
			<h1 style="font-size:var(--text-xl);font-weight:600;color:var(--text-1);margin:0 0 var(--s2);">
				<?php
				echo $hashtag_slug
					? esc_html( sprintf( /* translators: %s: hashtag */ __( '#%s not found', 'buddynext' ), $hashtag_slug ) )
					: esc_html__( 'Hashtag not found', 'buddynext' );
				?>
			</h1>
			<p style="color:var(--text-2);font-size:var(--text-sm);">
				<?php esc_html_e( 'This hashtag does not exist yet. Be the first to use it!', 'buddynext' ); ?>
			</p>
		</div>
	</div>
	<?php
else :

	// ── Build page title for SEO / accessibility ─────────────────────────────
	$page_title = sprintf(
	/* translators: %s: hashtag slug */
		__( '#%s — BuddyNext', 'buddynext' ),
		$hashtag_slug
	);

	// ── First-use date label ──────────────────────────────────────────────────
	$first_used_label = '';
	if ( null !== $hashtag && $hashtag->created_at ) {
		$first_used_label = date_i18n( get_option( 'date_format' ), (int) strtotime( $hashtag->created_at ) );
	}
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
				'sort'      => 'top',
				'tab'       => 'posts',
				'page'      => 1,
				'following' => $follows_hashtag,
			)
		)
	);
	?>
	'
>
	<div class="bn-hashtag-shell">

		<!-- ── Feed column ── -->
		<main class="bn-hashtag-feed-area" id="bn-hashtag-feed-main" role="main">

			<!-- Hashtag hero -->
			<div class="bn-hashtag-hero">
				<h1 class="bn-hashtag-title">#<?php echo esc_html( $hashtag_slug ); ?></h1>
				<div class="bn-hashtag-stats">
					<?php
					printf(
						/* translators: %s: post count */
						esc_html__( '%s posts', 'buddynext' ),
						esc_html( number_format_i18n( absint( $hashtag->post_count ) ) )
					);
					?>
				</div>
				<div class="bn-hashtag-actions">
					<?php if ( $is_logged_in ) : ?>
						<button
							class="bn-btn-follow-tag<?php echo $follows_hashtag ? ' following' : ''; ?>"
							type="button"
							data-wp-on--click="actions.toggleFollowHashtag"
							data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
							aria-pressed="<?php echo $follows_hashtag ? 'true' : 'false'; ?>"
						>
							<?php if ( $follows_hashtag ) : ?>
								<?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Following', 'buddynext' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: %s: hashtag slug */
									esc_html__( 'Follow #%s', 'buddynext' ),
									esc_html( $hashtag_slug )
								);
								?>
							<?php endif; ?>
						</button>
						<button
							class="bn-btn-primary"
							type="button"
							data-wp-on--click="actions.openComposerWithTag"
							data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
						>
							<?php buddynext_icon( 'edit' ); ?> <?php esc_html_e( 'Create post', 'buddynext' ); ?>
						</button>
					<?php else : ?>
						<a
							class="bn-btn-follow-tag"
							href="<?php echo esc_url( wp_registration_url() ); ?>"
						>
							<?php
							printf(
								/* translators: %s: hashtag slug */
								esc_html__( 'Follow #%s', 'buddynext' ),
								esc_html( $hashtag_slug )
							);
							?>
						</a>
					<?php endif; ?>
				</div>
				<div class="bn-sort-tabs" role="group" aria-label="<?php esc_attr_e( 'Sort posts', 'buddynext' ); ?>">
					<button
						class="bn-sort-tab active"
						type="button"
						data-sort="top"
						data-wp-on--click="actions.setSort"
						aria-pressed="true"
					><?php esc_html_e( 'Top', 'buddynext' ); ?></button>
					<button
						class="bn-sort-tab"
						type="button"
						data-sort="latest"
						data-wp-on--click="actions.setSort"
						aria-pressed="false"
					><?php esc_html_e( 'Latest', 'buddynext' ); ?></button>
					<button
						class="bn-sort-tab"
						type="button"
						data-sort="people"
						data-wp-on--click="actions.setSort"
						aria-pressed="false"
					><?php esc_html_e( 'People', 'buddynext' ); ?></button>
				</div>
			</div>

			<!-- ── Posts ── -->
			<?php if ( ! empty( $hashtag_posts ) ) : ?>
				<?php foreach ( $hashtag_posts as $post_row ) : ?>
					<?php
					$post_author_id  = (int) $post_row->user_id;
					$post_author     = get_userdata( $post_author_id );
					$post_display    = $post_author instanceof WP_User ? $post_author->display_name : __( 'Community Member', 'buddynext' );
					$colour_class    = $avatar_colours[ $post_author_id % count( $avatar_colours ) ];
					$post_parts      = explode( ' ', trim( $post_display ) );
					$post_initials   = strtoupper( substr( $post_parts[0], 0, 1 ) . ( isset( $post_parts[1] ) ? substr( $post_parts[1], 0, 1 ) : '' ) );
					$post_avatar_url = get_avatar_url( $post_author_id, array( 'size' => 68 ) );
					$post_time       = bn_tag_relative_time( $post_row->created_at );
					$post_content    = wp_kses_post( $post_row->content );
					$post_content    = bn_tag_linkify( $post_content );
					$reaction_count  = absint( $post_row->reaction_count ?? 0 );
					$comment_count   = absint( $post_row->comment_count ?? 0 );
					?>
					<article class="bn-tag-post-card" data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>">
						<div class="bn-post-header">
							<?php if ( $post_avatar_url ) : ?>
								<img
									src="<?php echo esc_url( $post_avatar_url ); ?>"
									alt="<?php echo esc_attr( $post_display ); ?>"
									class="avatar sm"
									loading="lazy"
									width="34"
									height="34"
								>
							<?php else : ?>
								<div class="avatar sm <?php echo esc_attr( $colour_class ); ?>" aria-hidden="true">
									<?php echo esc_html( $post_initials ); ?>
								</div>
							<?php endif; ?>
							<div class="bn-post-author">
								<div class="bn-post-author-name">
									<a href="<?php echo esc_url( get_author_posts_url( $post_author_id ) ); ?>">
										<?php echo esc_html( $post_display ); ?>
									</a>
								</div>
								<div class="bn-post-author-meta">
									<?php echo esc_html( $post_time ); ?>
								</div>
							</div>
							<button class="bn-post-more" type="button" aria-label="<?php esc_attr_e( 'Post options', 'buddynext' ); ?>"><?php buddynext_icon( 'more-horizontal' ); ?></button>
						</div>

						<div class="bn-post-body">
							<?php echo wp_kses_post( $post_content ); ?>
						</div>

						<div class="bn-post-reactions">
							<button
								class="bn-reaction-chip"
								type="button"
								data-wp-on--click="actions.react"
								data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>"
								data-reaction="heart"
								aria-label="<?php esc_attr_e( 'Like', 'buddynext' ); ?>"
							>
								<?php buddynext_icon( 'heart' ); ?>
								<?php if ( $reaction_count > 0 ) : ?>
									<span><?php echo esc_html( (string) $reaction_count ); ?></span>
								<?php endif; ?>
							</button>
							<button class="bn-reaction-chip" type="button" aria-label="<?php esc_attr_e( 'Add reaction', 'buddynext' ); ?>">+</button>
						</div>

						<div class="bn-post-actions">
							<button class="bn-action-btn" type="button" data-wp-on--click="actions.react" data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>">
								<?php buddynext_icon( 'heart' ); ?> <?php esc_html_e( 'Like', 'buddynext' ); ?>
							</button>
							<button class="bn-action-btn" type="button" data-wp-on--click="actions.openComments" data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>">
								<?php buddynext_icon( 'message-circle' ); ?>
								<?php
								if ( $comment_count > 0 ) {
									printf(
										/* translators: %d: number of comments */
										esc_html__( 'Comment (%d)', 'buddynext' ),
										absint( $comment_count )
									);
								} else {
									esc_html_e( 'Comment', 'buddynext' );
								}
								?>
							</button>
							<button class="bn-action-btn" type="button" data-wp-on--click="actions.share" data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>">
								<?php buddynext_icon( 'share' ); ?> <?php esc_html_e( 'Share', 'buddynext' ); ?>
							</button>
							<button class="bn-action-btn" type="button" data-wp-on--click="actions.bookmark" data-post-id="<?php echo esc_attr( (string) $post_row->id ); ?>">
								<?php buddynext_icon( 'bookmark' ); ?> <?php esc_html_e( 'Save', 'buddynext' ); ?>
							</button>
						</div>
					</article>
				<?php endforeach; ?>
			<?php else : ?>
				<!-- Jetonomy bridged card (shown when no BuddyNext posts exist for this tag yet) -->
				<?php if ( defined( 'JETONOMY_VERSION' ) ) : ?>
					<?php
					$jt_posts_table = $wpdb->prefix . 'jt_posts';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$jt_posts = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT jp.id, jp.user_id, jp.title, jp.reply_count, jp.vote_count, jp.view_count, jp.is_answered FROM {$jt_posts_table} jp WHERE jp.status = 'published' AND jp.content LIKE %s ORDER BY jp.vote_count DESC LIMIT 3", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							'%#' . $wpdb->esc_like( $hashtag_slug ) . '%'
						)
					);
					?>
					<?php if ( ! empty( $jt_posts ) ) : ?>
						<?php foreach ( $jt_posts as $jt_post ) : ?>
							<?php
							$jt_author_id = (int) $jt_post->user_id;
							$jt_author    = get_userdata( $jt_author_id );
							$jt_display   = $jt_author instanceof WP_User ? $jt_author->display_name : __( 'Community Member', 'buddynext' );
							$jt_colour    = $avatar_colours[ $jt_author_id % count( $avatar_colours ) ];
							$jt_parts     = explode( ' ', trim( $jt_display ) );
							$jt_initials  = strtoupper( substr( $jt_parts[0], 0, 1 ) . ( isset( $jt_parts[1] ) ? substr( $jt_parts[1], 0, 1 ) : '' ) );
							$jt_avatar    = get_avatar_url( $jt_author_id, array( 'size' => 68 ) );
							?>
							<article class="bn-tag-post-card jt-card">
								<div class="bn-jt-source-label">
									<?php buddynext_icon( 'message-circle' ); ?> <?php esc_html_e( 'Jetonomy Forums', 'buddynext' ); ?>
								</div>
								<div class="bn-post-header">
									<?php if ( $jt_avatar ) : ?>
										<img
											src="<?php echo esc_url( $jt_avatar ); ?>"
											alt="<?php echo esc_attr( $jt_display ); ?>"
											class="avatar sm"
											loading="lazy"
											width="34"
											height="34"
										>
									<?php else : ?>
										<div class="avatar sm av-jt" aria-hidden="true">
											<?php echo esc_html( $jt_initials ); ?>
										</div>
									<?php endif; ?>
									<div class="bn-post-author">
										<div class="bn-post-author-name"><?php echo esc_html( $jt_display ); ?></div>
										<div class="bn-post-author-meta"><?php esc_html_e( 'Started a discussion', 'buddynext' ); ?></div>
									</div>
									<button class="bn-post-more" type="button" aria-label="<?php esc_attr_e( 'Post options', 'buddynext' ); ?>"><?php buddynext_icon( 'more-horizontal' ); ?></button>
								</div>
								<div class="bn-post-body">
									<span class="bn-jt-tag"><?php esc_html_e( 'Discussion', 'buddynext' ); ?></span>
									<?php echo esc_html( $jt_post->title ); ?>
								</div>
								<div class="bn-jt-meta-row">
									<span class="bn-jt-stat"><?php buddynext_icon( 'message-circle' ); ?> <?php echo esc_html( (string) absint( $jt_post->reply_count ) ); ?> <?php esc_html_e( 'replies', 'buddynext' ); ?></span>
									<span class="bn-jt-stat"><?php buddynext_icon( 'eye' ); ?> <?php echo esc_html( (string) absint( $jt_post->view_count ) ); ?> <?php esc_html_e( 'views', 'buddynext' ); ?></span>
									<?php if ( $jt_post->is_answered ) : ?>
										<span class="bn-jt-stat bn-jt-answered"><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Answered', 'buddynext' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="bn-jt-vote-bar">
									<button class="bn-vote-btn" type="button" data-wp-on--click="actions.voteJt" data-jt-id="<?php echo esc_attr( (string) $jt_post->id ); ?>" data-direction="up">
										<?php buddynext_icon( 'arrow-up' ); ?> <?php echo esc_html( (string) absint( $jt_post->vote_count ) ); ?>
									</button>
									<button class="bn-vote-btn" type="button" data-wp-on--click="actions.voteJt" data-jt-id="<?php echo esc_attr( (string) $jt_post->id ); ?>" data-direction="down">
										<?php buddynext_icon( 'arrow-down' ); ?>
									</button>
									<a class="bn-jt-open-link" href="<?php echo esc_url( home_url( '/forums/post/' . (int) $jt_post->id . '/' ) ); ?>">
										<?php esc_html_e( 'Open in forum', 'buddynext' ); ?> &rarr;
									</a>
								</div>
							</article>
						<?php endforeach; ?>
					<?php else : ?>
						<!-- True empty state -->
						<div class="bn-tag-post-card" style="text-align:center;padding:var(--s8);color:var(--text-3);">
							<div style="font-size:32px;margin-bottom:var(--s3);">#</div>
							<div style="font-size:var(--text-base);font-weight:600;color:var(--text-2);">
								<?php
								printf(
									/* translators: %s: hashtag slug */
									esc_html__( 'No posts with #%s yet', 'buddynext' ),
									esc_html( $hashtag_slug )
								);
								?>
							</div>
							<?php if ( $is_logged_in ) : ?>
								<div style="margin-top:var(--s4);">
									<button
										class="bn-btn-primary"
										type="button"
										data-wp-on--click="actions.openComposerWithTag"
										data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
										style="display:inline-flex;margin:0 auto;"
									>
										<?php buddynext_icon( 'edit' ); ?> <?php esc_html_e( 'Be the first to post', 'buddynext' ); ?>
									</button>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<div class="bn-tag-post-card" style="text-align:center;padding:var(--s8);color:var(--text-3);">
						<div style="font-size:32px;margin-bottom:var(--s3);">#</div>
						<div style="font-size:var(--text-base);font-weight:600;color:var(--text-2);">
							<?php
							printf(
								/* translators: %s: hashtag slug */
								esc_html__( 'No posts with #%s yet', 'buddynext' ),
								esc_html( $hashtag_slug )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="bn-load-more" data-wp-bind--hidden="!state.hasMore" aria-live="polite">
				<?php esc_html_e( 'Loading more posts\u2026', 'buddynext' ); ?>
			</div>
		</main>

		<!-- ── Sidebar ── -->
		<aside class="bn-hashtag-sidebar" aria-label="<?php esc_attr_e( 'Hashtag details sidebar', 'buddynext' ); ?>">

			<!-- About this hashtag -->
			<div class="bn-widget">
				<div class="bn-widget-title">
					<?php
					printf(
						/* translators: %s: hashtag slug */
						esc_html__( 'About #%s', 'buddynext' ),
						esc_html( $hashtag_slug )
					);
					?>
				</div>
				<div class="bn-about-row">
					<span class="bn-about-label"><?php esc_html_e( 'Created by', 'buddynext' ); ?></span>
					<span class="bn-about-value"><?php esc_html_e( 'Community', 'buddynext' ); ?></span>
				</div>
				<div class="bn-about-row">
					<span class="bn-about-label"><?php esc_html_e( 'Posts this month', 'buddynext' ); ?></span>
					<span class="bn-about-value"><?php echo esc_html( number_format_i18n( absint( $hashtag->post_count ) ) ); ?></span>
				</div>

				<?php if ( $first_used_label ) : ?>
					<div class="bn-about-row">
						<span class="bn-about-label"><?php esc_html_e( 'First used', 'buddynext' ); ?></span>
						<span class="bn-about-value"><?php echo esc_html( $first_used_label ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( $is_logged_in ) : ?>
					<div class="bn-follow-toggle-row">
						<span class="bn-follow-toggle-label">
							<?php
							if ( $follows_hashtag ) {
								esc_html_e( 'You follow this hashtag', 'buddynext' );
							} else {
								esc_html_e( 'Follow this hashtag', 'buddynext' );
							}
							?>
						</span>
						<button
							class="bn-toggle-switch"
							type="button"
							role="switch"
							aria-checked="<?php echo $follows_hashtag ? 'true' : 'false'; ?>"
							data-wp-on--click="actions.toggleFollowHashtag"
							data-hashtag="<?php echo esc_attr( $hashtag_slug ); ?>"
							aria-label="
							<?php
							printf(
								/* translators: %s: hashtag slug */
								esc_attr__( 'Follow #%s', 'buddynext' ),
								esc_attr( $hashtag_slug )
							);
							?>
							"
						></button>
					</div>
				<?php endif; ?>
			</div>

			<!-- Related hashtags -->
			<?php if ( ! empty( $related_tags ) ) : ?>
				<div class="bn-widget">
					<div class="bn-widget-title"><?php esc_html_e( 'Related Hashtags', 'buddynext' ); ?></div>
					<?php foreach ( $related_tags as $rel_tag ) : ?>
						<div class="bn-related-tag-row">
							<a
								class="bn-related-tag-name"
								href="<?php echo esc_url( \BuddyNext\Core\PageRouter::hashtag_feed_url( $rel_tag->slug ) ); ?>"
							>#<?php echo esc_html( $rel_tag->slug ); ?></a>
							<span class="bn-related-tag-count">
								<?php
								printf(
									/* translators: %s: post count */
									esc_html__( '%s posts', 'buddynext' ),
									esc_html( number_format_i18n( absint( $rel_tag->post_count ) ) )
								);
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Top contributors -->
			<?php if ( ! empty( $top_contributors ) ) : ?>
				<div class="bn-widget">
					<div class="bn-widget-title"><?php esc_html_e( 'Top Contributors', 'buddynext' ); ?></div>
					<?php foreach ( $top_contributors as $contrib ) : ?>
						<?php
						$contrib_id      = (int) $contrib->user_id;
						$contrib_user    = get_userdata( $contrib_id );
						$contrib_display = $contrib_user instanceof WP_User ? $contrib_user->display_name : __( 'Community Member', 'buddynext' );
						$contrib_colour  = $avatar_colours[ $contrib_id % count( $avatar_colours ) ];
						$contrib_parts   = explode( ' ', trim( $contrib_display ) );
						$contrib_init    = strtoupper( substr( $contrib_parts[0], 0, 1 ) . ( isset( $contrib_parts[1] ) ? substr( $contrib_parts[1], 0, 1 ) : '' ) );
						$contrib_avatar  = get_avatar_url( $contrib_id, array( 'size' => 68 ) );
						?>
						<div class="bn-member-row">
							<?php if ( $contrib_avatar ) : ?>
								<img
									src="<?php echo esc_url( $contrib_avatar ); ?>"
									alt="<?php echo esc_attr( $contrib_display ); ?>"
									class="avatar sm"
									loading="lazy"
									width="34"
									height="34"
								>
							<?php else : ?>
								<div class="avatar sm <?php echo esc_attr( $contrib_colour ); ?>" aria-hidden="true">
									<?php echo esc_html( $contrib_init ); ?>
								</div>
							<?php endif; ?>
							<div class="bn-member-info">
								<div class="bn-member-name"><?php echo esc_html( $contrib_display ); ?></div>
								<div class="bn-member-sub">
									<?php
									printf(
										/* translators: 1: number of posts, 2: hashtag slug */
										esc_html__( '%1$d posts with #%2$s', 'buddynext' ),
										absint( $contrib->post_count ),
										esc_html( $hashtag_slug )
									);
									?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
					<a
						class="bn-widget-see-all"
						href="<?php echo esc_url( add_query_arg( 'hashtag', rawurlencode( $hashtag_slug ), \BuddyNext\Core\PageRouter::people_url() ) ); ?>"
					><?php esc_html_e( 'See all contributors', 'buddynext' ); ?> &rarr;</a>
				</div>
			<?php endif; ?>

		</aside>
	</div>

	<!-- REST config -->
	<script type="application/json" id="bn-hashtag-feed-config">
	<?php
	echo wp_json_encode(
		array(
			'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
			'restNonce' => $rest_nonce,
			'userId'    => $current_user_id,
			'hashtag'   => $hashtag_slug,
			'hashtagId' => (int) $hashtag->id,
			'following' => $follows_hashtag,
		)
	);
	?>
	</script>
</div>
<?php endif; ?>
