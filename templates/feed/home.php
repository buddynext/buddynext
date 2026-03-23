<?php
/**
 * BuddyNext home feed template.
 *
 * Renders a personalized activity feed for the logged-in viewer:
 *   - Posts from users the viewer follows (bn_follows)
 *   - Posts from spaces the viewer has joined (bn_space_members, status='active')
 *   - The viewer's own posts
 *   - Shadow-banned users' posts are excluded (usermeta: bn_shadow_banned = 1)
 *   - Results ordered by created_at DESC, paginated 12 per page
 *
 * Guests see the Explore feed instead (handled by [buddynext_activity] shortcode).
 *
 * Overridable: copy to {theme}/buddynext/feed/home.php
 *
 * REST endpoints used by interactive actions:
 *   POST buddynext/v1/feed       — create post
 *   GET  buddynext/v1/feed       — load more posts
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

global $wpdb;

// ── Viewer context ─────────────────────────────────────────────────────────────
$viewer_id = get_current_user_id();
$is_guest  = ( 0 === $viewer_id );

// ── Cursor-based pagination ─────────────────────────────────────────────────────
// Cursor encodes the last seen post's created_at + id (via FeedService::encode_cursor).
// First page: no cursor. Next page: cursor from the previous response's next_cursor value.
$bn_per_page   = 12;
$bn_raw_cursor = isset( $_GET['after'] ) ? sanitize_text_field( wp_unslash( $_GET['after'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_cursor     = ( '' !== (string) $bn_raw_cursor && null !== $bn_raw_cursor ) ? $bn_raw_cursor : null;

// ── Feed posts query ───────────────────────────────────────────────────────────
$posts_table   = $wpdb->prefix . 'bn_posts';
$follows_table = $wpdb->prefix . 'bn_follows';
$spaces_table  = $wpdb->prefix . 'bn_space_members';

if ( $is_guest ) {
	// Guests: show latest public posts via cursor pagination.
	// Build cursor WHERE fragment inline — guests do not go through FeedService.
	$guest_cursor_where  = '';
	$guest_cursor_params = array();
	if ( null !== $bn_cursor ) {
		$raw_decoded = base64_decode( $bn_cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false !== $raw_decoded ) {
			$cursor_parts = explode( '|', $raw_decoded, 2 );
			if ( 2 === count( $cursor_parts ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$guest_cursor_where  = 'AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))';
				$guest_cursor_params = array( $cursor_parts[0], $cursor_parts[0], (int) $cursor_parts[1] );
			}
		}
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$feed_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.id, p.user_id, p.content, p.type, p.privacy, p.space_id,
			        p.created_at, p.reaction_count, p.comment_count, p.share_count,
			        p.is_pinned, p.is_announcement, p.content_warning, p.content_warning_type,
			        p.link_url, p.edited_at, p.updated_at
			 FROM {$posts_table} p
			 WHERE p.status = 'published'
			   AND p.privacy = 'public'
			   AND p.user_id NOT IN (
			       SELECT user_id FROM {$wpdb->usermeta}
			       WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			   )
			   {$guest_cursor_where}
			 ORDER BY p.created_at DESC, p.id DESC
			 LIMIT %d",
			...array_merge( $guest_cursor_params, array( $bn_per_page + 1 ) )
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	$guest_has_more = count( (array) $feed_rows ) > $bn_per_page;
	if ( $guest_has_more ) {
		$feed_rows = array_slice( (array) $feed_rows, 0, $bn_per_page );
	}
	$feed_posts     = (array) $feed_rows;
	$bn_next_cursor = null;
	if ( $guest_has_more && ! empty( $feed_rows ) ) {
		$last_row       = end( $feed_rows );
		$bn_next_cursor = base64_encode( $last_row['created_at'] . '|' . $last_row['id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
} else {
	// Authenticated: delegate entirely to FeedService for cursor pagination.
	$feed_service   = buddynext_service( 'feed' );
	$feed_result    = $feed_service->home_feed( $viewer_id, $bn_cursor, $bn_per_page );
	$feed_posts     = $feed_result['items'];
	$bn_next_cursor = $feed_result['next_cursor'];
}

// ── Sidebar: suggested follows (users viewer does not yet follow) ──────────────
$suggested_users = array();
if ( ! $is_guest ) {
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$suggested_users = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT u.ID, u.display_name, u.user_nicename
			 FROM {$wpdb->users} u
			 WHERE u.ID != %d
			   AND u.ID NOT IN (
			       SELECT following_id FROM {$follows_table} WHERE follower_id = %d
			   )
			   AND u.ID NOT IN (
			       SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			   )
			 ORDER BY RAND()
			 LIMIT 4",
			$viewer_id,
			$viewer_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Sidebar: active spaces (spaces the viewer has joined) ──────────────────────
$member_spaces = array();
if ( ! $is_guest ) {
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$member_spaces = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.id, s.name, s.avatar_url, s.member_count
			 FROM {$wpdb->prefix}bn_spaces s
			 INNER JOIN {$spaces_table} sm ON sm.space_id = s.id
			 WHERE sm.user_id = %d AND sm.status = 'active'
			 ORDER BY s.member_count DESC
			 LIMIT 4",
			$viewer_id
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Current viewer data ────────────────────────────────────────────────────────
$viewer        = $is_guest ? null : get_userdata( $viewer_id );
$viewer_name   = $viewer ? $viewer->display_name : '';
$viewer_avatar = $viewer ? get_avatar_url( $viewer_id, array( 'size' => 40 ) ) : '';

// ── REST nonce for interactive actions ────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Avatar colour palette (deterministic by user ID) ──────────────────────────
$bn_avatar_colours = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );

/**
 * Return initials from a display name.
 *
 * @param string $name Display name.
 * @return string Up to two uppercase characters.
 */
if ( ! function_exists( 'bn_home_initials' ) ) {
	/**
	 * Return initials from a display name.
	 *
	 * @param string $name Display name.
	 * @return string Up to two uppercase characters.
	 */
	function bn_home_initials( string $name ): string {
		$parts = array_filter( explode( ' ', trim( $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( substr( (string) reset( $parts ), 0, 1 ) . substr( (string) end( $parts ), 0, 1 ) );
		}
		return strtoupper( substr( $name, 0, 2 ) );
	}
}

/**
 * Format a UTC timestamp as a human-readable relative time label.
 *
 * @param string $datetime MySQL datetime string (UTC).
 * @return string Escaped relative time string.
 */
if ( ! function_exists( 'bn_home_relative_time' ) ) {
	/**
	 * Format a UTC timestamp as a human-readable relative time label.
	 *
	 * @param string $datetime MySQL datetime string (UTC).
	 * @return string Escaped relative time string.
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
}
?>
<?php
$bn_nav_active = 'feed';
require __DIR__ . '/../partials/nav.php';
?>
<style>
/* ── BuddyNext design tokens ── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px;  --text-sm:   13px;  --text-base: 15px;
	--text-lg:  17px;  --text-xl:   20px;  --text-2xl:  24px;
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
	--s1: 4px;  --s2: 8px;   --s3: 12px;  --s4: 16px;
	--s5: 20px; --s6: 24px;  --s8: 32px;
	--radius-sm: 6px;  --radius: 10px;  --radius-lg: 14px;
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

/* ── Base ── */
.bn-home-feed {
	font-family: var(--font-body);
	font-size: var(--text-base);
	line-height: var(--leading-body);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
	min-height: 100vh;
}

/* ── Page shell — 2-col layout ── */
.bn-home-shell {
	max-width: 1160px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: start;
}
.bn-home-feed-area { min-width: 0; }

/* ── Feed tabs ── */
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
	background: var(--surface);
	border-top: none;
	border-bottom: none;
	border-left: none;
	font-family: var(--font-body);
	transition: background 0.12s, color 0.12s;
}
.bn-feed-tab:last-child { border-right: none; }
.bn-feed-tab.active { background: var(--brand); color: #fff; font-weight: 600; }
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
.bn-composer-input {
	flex: 1;
	background: var(--bg-subtle);
	border: 1.5px solid var(--border);
	border-radius: 24px;
	padding: var(--s2) var(--s4);
	font-size: var(--text-base);
	color: var(--text-3);
	cursor: text;
	font-family: var(--font-body);
	transition: border-color 0.15s, box-shadow 0.15s;
	min-height: 40px;
	display: flex;
	align-items: center;
}
.bn-composer-input:focus-within {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px var(--brand-light);
}
.bn-composer-textarea {
	width: 100%;
	border: none;
	background: transparent;
	font-size: var(--text-base);
	color: var(--text-1);
	font-family: var(--font-body);
	resize: none;
	outline: none;
	min-height: 60px;
	line-height: 1.6;
}
.bn-composer-textarea::placeholder { color: var(--text-3); }
.bn-composer-toolbar {
	display: flex;
	gap: var(--s1);
	align-items: center;
	margin-top: var(--s3);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
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
	background: transparent;
	border: none;
	font-family: var(--font-body);
	transition: background 0.12s, color 0.12s;
}
.bn-toolbar-btn:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-btn-post {
	margin-left: auto;
	background: var(--brand);
	color: #fff;
	border: none;
	border-radius: 20px;
	padding: 7px var(--s4);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-post:hover { background: var(--brand-hover); }
.bn-btn-post:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

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
.bn-post-author { flex: 1; min-width: 0; }
.bn-post-author-name {
	font-size: var(--text-base);
	font-weight: 600;
	color: var(--text-1);
	line-height: 1.3;
	text-decoration: none;
}
.bn-post-author-name:hover { color: var(--brand); }
.bn-post-author-meta {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: 1px;
	display: flex;
	align-items: center;
	gap: var(--s1);
	flex-wrap: wrap;
}
.bn-space-badge { color: var(--brand); font-weight: 500; }
.bn-post-more {
	color: var(--text-3);
	font-size: 18px;
	cursor: pointer;
	padding: 2px var(--s1);
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
.bn-hashtag { color: var(--brand); font-weight: 500; text-decoration: none; }
.bn-hashtag:hover { text-decoration: underline; }
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
	transition: border-color 0.1s;
}
.bn-reaction-chip:hover { border-color: var(--brand); }
.bn-reaction-chip.mine { background: var(--brand-light); border-color: var(--brand); color: var(--brand); font-weight: 600; }
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
	transition: background 0.12s, color 0.12s;
}
.bn-action-btn:hover { background: var(--bg-subtle); color: var(--text-1); }

/* ── Empty state ── */
.bn-empty-state {
	text-align: center;
	padding: var(--s8) var(--s6);
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	color: var(--text-3);
}
.bn-empty-icon { font-size: 40px; margin-bottom: var(--s3); }
.bn-empty-title {
	font-family: var(--font-display);
	font-size: var(--text-lg);
	font-weight: 700;
	color: var(--text-2);
	margin-bottom: var(--s2);
}
.bn-empty-text { font-size: var(--text-sm); line-height: 1.6; }
.bn-empty-cta {
	display: inline-block;
	margin-top: var(--s4);
	background: var(--brand);
	color: #fff;
	padding: var(--s2) var(--s5);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 600;
	text-decoration: none;
	transition: background 0.15s;
}
.bn-empty-cta:hover { background: var(--brand-hover); color: #fff; }

/* ── Avatar ── */
.bn-avatar {
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
.bn-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bn-avatar.sm { width: 34px; height: 34px; font-size: var(--text-xs); }
.bn-avatar.xs { width: 28px; height: 28px; font-size: 10px; }

/* ── Load more ── */
.bn-load-more-wrap {
	display: flex;
	justify-content: center;
	margin-top: var(--s5);
}
.bn-load-more-btn {
	padding: var(--s2) var(--s6);
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	background: var(--surface);
	cursor: pointer;
	font-family: var(--font-body);
	transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.bn-load-more-btn:hover { border-color: var(--brand); color: var(--brand); background: var(--brand-light); }

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
.bn-source-tag {
	font-size: 10px;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 4px;
	letter-spacing: 0.04em;
}

/* People-to-follow widget */
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
.bn-member-name a { color: inherit; text-decoration: none; }
.bn-member-name a:hover { color: var(--brand); }
.bn-member-sub { font-size: var(--text-xs); color: var(--text-3); }
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
	transition: background 0.12s, color 0.12s;
}
.bn-btn-follow:hover { background: var(--brand); color: #fff; }

/* Spaces widget */
.bn-space-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
	text-decoration: none;
	color: inherit;
}
.bn-space-row:last-of-type { border-bottom: none; }
.bn-space-row:hover .bn-space-name { color: var(--brand); }
.bn-space-icon-box {
	width: 36px;
	height: 36px;
	border-radius: var(--radius-sm);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: var(--text-lg);
	flex-shrink: 0;
	overflow: hidden;
}
.bn-space-icon-box img { width: 100%; height: 100%; object-fit: cover; border-radius: var(--radius-sm); }
.bn-space-info { flex: 1; min-width: 0; }
.bn-space-name { font-size: var(--text-sm); font-weight: 600; color: var(--text-1); transition: color 0.1s; }
.bn-space-meta { font-size: var(--text-xs); color: var(--text-3); }
.bn-widget-see-all {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--brand);
	cursor: pointer;
	margin-top: var(--s2);
	display: block;
	text-decoration: none;
}
.bn-widget-see-all:hover { text-decoration: underline; }

/* ── Guest join banner ── */
.bn-guest-banner {
	background: linear-gradient(135deg, var(--brand), var(--brand-hover));
	color: #fff;
	border-radius: var(--radius-lg);
	padding: var(--s5);
	margin-bottom: var(--s5);
}
.bn-guest-banner h2 {
	font-family: var(--font-display);
	font-size: var(--text-lg);
	font-weight: 700;
	margin-bottom: var(--s1);
}
.bn-guest-banner p { font-size: var(--text-sm); opacity: 0.85; margin-bottom: var(--s4); line-height: 1.6; }
.bn-banner-btns { display: flex; gap: var(--s2); flex-wrap: wrap; }
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
	text-decoration: none;
}
.bn-btn-white:hover { opacity: 0.9; }
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

/* ── Dark mode overrides ── */
[data-theme="dark"] .bn-post-card       { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-composer        { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-widget          { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-composer-input  { background: var(--bg-hover); border-color: var(--border); }
[data-theme="dark"] .bn-btn-follow      { background: var(--surface); }
[data-theme="dark"] .bn-feed-tabs       { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-feed-tab        { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .bn-reaction-chip   { background: var(--bg-hover); border-color: var(--border); }

/* ── Mobile responsive ── */
@media (max-width: 1024px) {
	.bn-home-shell { padding: var(--s4); grid-template-columns: 1fr 260px; gap: var(--s4); }
}
@media (max-width: 640px) {
	.bn-home-shell { padding: var(--s3); grid-template-columns: 1fr; }
	.bn-home-sidebar { display: none; }
	.bn-feed-tab { font-size: var(--text-xs); padding: var(--s2); }
	.bn-composer { padding: var(--s3); }
	.bn-post-header { padding: var(--s3) var(--s3) 0; }
	.bn-post-body { padding: var(--s2) var(--s3); }
	.bn-post-reactions { padding: 0 var(--s3) var(--s2); }
	.bn-action-btn { font-size: var(--text-xs); gap: 4px; padding: var(--s2); }
}
</style>

<div
	class="bn-home-feed"
	data-wp-interactive="buddynext/feed"
	data-wp-context='{"scope":"home","nextCursor":<?php echo wp_json_encode( $bn_next_cursor ); ?>,"viewerId":<?php echo absint( $viewer_id ); ?>,"restNonce":"<?php echo esc_js( $rest_nonce ); ?>","restUrl":"<?php echo esc_js( rest_url( 'buddynext/v1' ) ); ?>"}'
>
<div class="bn-home-shell">

	<!-- ── Feed column ── -->
	<main class="bn-home-feed-area" id="bn-feed-main">

		<?php if ( $is_guest ) : ?>
			<!-- Guest banner -->
			<div class="bn-guest-banner" role="banner">
				<h2><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h2>
				<p><?php esc_html_e( "You're browsing as a guest. Create an account to post, follow people, and join spaces.", 'buddynext' ); ?></p>
				<div class="bn-banner-btns">
					<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="bn-btn-white">
						<?php esc_html_e( 'Sign up free', 'buddynext' ); ?>
					</a>
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="bn-btn-outline-white">
						<?php esc_html_e( 'Log in', 'buddynext' ); ?>
					</a>
				</div>
			</div>
		<?php endif; ?>

		<!-- Feed tabs -->
		<div class="bn-feed-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Feed filter tabs', 'buddynext' ); ?>">
			<button class="bn-feed-tab active" role="tab" aria-selected="true"
				data-wp-on--click="actions.setScope" data-scope="home">
				<?php esc_html_e( 'Following', 'buddynext' ); ?>
			</button>
			<button class="bn-feed-tab" role="tab" aria-selected="false"
				data-wp-on--click="actions.setScope" data-scope="spaces">
				<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
			</button>
			<button class="bn-feed-tab" role="tab" aria-selected="false"
				data-wp-on--click="actions.setScope" data-scope="hashtags">
				<?php esc_html_e( 'Hashtags', 'buddynext' ); ?>
			</button>
			<button class="bn-feed-tab" role="tab" aria-selected="false"
				data-wp-on--click="actions.setScope" data-scope="explore">
				<?php esc_html_e( 'All', 'buddynext' ); ?>
			</button>
		</div>

		<?php if ( ! $is_guest ) : ?>
			<!-- Composer -->
			<div class="bn-composer" role="form" aria-label="<?php esc_attr_e( 'Create a post', 'buddynext' ); ?>">
				<div class="bn-composer-row">
					<?php if ( $viewer_avatar ) : ?>
						<img
							src="<?php echo esc_url( $viewer_avatar ); ?>"
							alt="<?php echo esc_attr( $viewer_name ); ?>"
							class="bn-avatar sm"
							width="34"
							height="34"
							loading="lazy"
						>
					<?php else : ?>
						<div
							class="bn-avatar sm"
							style="background:<?php echo esc_attr( $bn_avatar_colours[ $viewer_id % count( $bn_avatar_colours ) ] ); ?>;"
							aria-hidden="true"
						><?php echo esc_html( bn_home_initials( $viewer_name ) ); ?></div>
					<?php endif; ?>
					<div class="bn-composer-input" id="bn-composer-wrap">
						<textarea
							class="bn-composer-textarea"
							id="bn-composer-text"
							name="content"
							rows="1"
							placeholder="
							<?php
								/* translators: %s: the viewer's first name */
								printf( esc_attr__( "What's on your mind, %s?", 'buddynext' ), esc_attr( explode( ' ', $viewer_name )[0] ) );
							?>
							"
							data-wp-on--input="actions.onComposerInput"
							maxlength="5000"
							aria-label="<?php esc_attr_e( 'Post content', 'buddynext' ); ?>"
						></textarea>
					</div>
				</div>
				<div class="bn-composer-toolbar">
					<button type="button" class="bn-toolbar-btn" data-wp-on--click="actions.openMediaPicker">
						&#128248; <?php esc_html_e( 'Photo', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-toolbar-btn" data-wp-on--click="actions.openLinkAttach">
						&#128279; <?php esc_html_e( 'Link', 'buddynext' ); ?>
					</button>
					<button type="button" class="bn-toolbar-btn" data-wp-on--click="actions.openPollCreator">
						&#128202; <?php esc_html_e( 'Poll', 'buddynext' ); ?>
					</button>
					<button
						type="button"
						class="bn-btn-post"
						data-wp-on--click="actions.submitPost"
						data-wp-bind--disabled="!state.composerHasContent"
					><?php esc_html_e( 'Post', 'buddynext' ); ?></button>
				</div>
			</div>
		<?php endif; ?>

		<!-- Feed posts -->
		<div
			class="bn-feed-list"
			role="feed"
			aria-label="<?php esc_attr_e( 'Activity feed', 'buddynext' ); ?>"
			aria-busy="false"
		>
		<?php if ( ! empty( $feed_posts ) ) : ?>
			<?php foreach ( $feed_posts as $feed_post ) : ?>
				<?php
				// Both paths (guest ARRAY_A rows + FeedService hydrated arrays) return arrays.
				$fp           = is_array( $feed_post ) ? $feed_post : (array) $feed_post;
				$partial_post = array(
					'id'                   => (int) ( $fp['id'] ?? 0 ),
					'user_id'              => (int) ( $fp['user_id'] ?? 0 ),
					'type'                 => $fp['type'] ?? 'text',
					'content'              => $fp['content'] ?? '',
					'privacy'              => $fp['privacy'] ?? 'public',
					'space_id'             => isset( $fp['space_id'] ) ? (int) $fp['space_id'] : null,
					'media_ids'            => is_array( $fp['media_ids'] ?? null ) ? $fp['media_ids'] : array(),
					'link_url'             => $fp['link_url'] ?? null,
					'link_meta'            => is_array( $fp['link_meta'] ?? null ) ? $fp['link_meta'] : array(),
					'poll_options'         => is_array( $fp['poll_options'] ?? null ) ? $fp['poll_options'] : array(),
					'is_pinned'            => (int) ( $fp['is_pinned'] ?? 0 ),
					'is_announcement'      => (int) ( $fp['is_announcement'] ?? 0 ),
					'content_warning'      => ! empty( $fp['content_warning'] ),
					'content_warning_type' => $fp['content_warning_type'] ?? null,
					'reaction_count'       => absint( $fp['reaction_count'] ?? 0 ),
					'comment_count'        => absint( $fp['comment_count'] ?? 0 ),
					'share_count'          => absint( $fp['share_count'] ?? 0 ),
					'edited_at'            => $fp['edited_at'] ?? null,
					'created_at'           => $fp['created_at'] ?? '',
					'updated_at'           => $fp['updated_at'] ?? null,
				);
				?>
				<?php
				buddynext_get_template(
					'partials/post-card',
					array(
						'post'            => $partial_post,
						'current_user_id' => $viewer_id,
						'context'         => 'home',
					)
				);
				?>
			<?php endforeach; ?>
		<?php else : ?>
			<!-- Empty feed state -->
			<div class="bn-empty-state">
				<div class="bn-empty-icon" aria-hidden="true">&#127760;</div>
				<div class="bn-empty-title">
					<?php esc_html_e( 'Your feed is empty', 'buddynext' ); ?>
				</div>
				<p class="bn-empty-text">
					<?php esc_html_e( 'Follow people, join spaces, and start conversations to fill your feed.', 'buddynext' ); ?>
				</p>
				<a href="<?php echo esc_url( PageRouter::explore_url() ); ?>" class="bn-empty-cta">
					<?php esc_html_e( 'Explore the community', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>
		</div><!-- .bn-feed-list -->

		<?php if ( null !== $bn_next_cursor ) : ?>
			<div class="bn-load-more-wrap">
				<button
					type="button"
					class="bn-load-more-btn"
					data-cursor="<?php echo esc_attr( $bn_next_cursor ); ?>"
					data-wp-on--click="actions.loadMore"
				><?php esc_html_e( 'Load more', 'buddynext' ); ?></button>
			</div>
		<?php endif; ?>

	</main>

	<!-- ── Sidebar ── -->
	<aside class="bn-home-sidebar" aria-label="<?php esc_attr_e( 'Community sidebar', 'buddynext' ); ?>">

		<?php if ( ! $is_guest && ! empty( $suggested_users ) ) : ?>
			<!-- People to follow -->
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'People to Follow', 'buddynext' ); ?></div>
				<?php foreach ( $suggested_users as $suggestion ) : ?>
					<?php
					$sug_id     = (int) $suggestion->ID;
					$sug_name   = $suggestion->display_name;
					$sug_avatar = get_avatar_url( $sug_id, array( 'size' => 68 ) );
					$sug_colour = $bn_avatar_colours[ $sug_id % count( $bn_avatar_colours ) ];
					$sug_inits  = bn_home_initials( $sug_name );
					?>
					<div class="bn-member-row">
						<?php if ( $sug_avatar ) : ?>
							<img
								src="<?php echo esc_url( $sug_avatar ); ?>"
								alt="<?php echo esc_attr( $sug_name ); ?>"
								class="bn-avatar sm"
								width="34"
								height="34"
								loading="lazy"
							>
						<?php else : ?>
							<div class="bn-avatar sm" style="background:<?php echo esc_attr( $sug_colour ); ?>;" aria-hidden="true">
								<?php echo esc_html( $sug_inits ); ?>
							</div>
						<?php endif; ?>
						<div class="bn-member-info">
							<div class="bn-member-name">
								<a href="<?php echo esc_url( PageRouter::profile_url( $sug_id ) ); ?>">
									<?php echo esc_html( $sug_name ); ?>
								</a>
							</div>
						</div>
						<button
							type="button"
							class="bn-btn-follow"
							data-user-id="<?php echo absint( $sug_id ); ?>"
							data-wp-on--click="actions.followUser"
						><?php esc_html_e( 'Follow', 'buddynext' ); ?></button>
					</div>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( PageRouter::people_url() ); ?>" class="bn-widget-see-all">
					<?php esc_html_e( 'See all members', 'buddynext' ); ?> &#8594;
				</a>
			</div>
		<?php endif; ?>

		<?php if ( ! $is_guest && ! empty( $member_spaces ) ) : ?>
			<!-- Active Spaces -->
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Your Spaces', 'buddynext' ); ?></div>
				<?php foreach ( $member_spaces as $sp ) : ?>
					<a
						href="<?php echo esc_url( PageRouter::space_url( (int) $sp->id ) ); ?>"
						class="bn-space-row"
					>
						<div class="bn-space-icon-box">
							<?php if ( ! empty( $sp->avatar_url ) ) : ?>
								<img
									src="<?php echo esc_url( $sp->avatar_url ); ?>"
									alt=""
									loading="lazy"
									width="36"
									height="36"
								>
							<?php else : ?>
								&#127970;
							<?php endif; ?>
						</div>
						<div class="bn-space-info">
							<div class="bn-space-name"><?php echo esc_html( $sp->name ); ?></div>
							<div class="bn-space-meta">
								<?php
								/* translators: %s: formatted member count */
								printf( esc_html__( '%s members', 'buddynext' ), esc_html( number_format_i18n( (int) $sp->member_count ) ) );
								?>
							</div>
						</div>
					</a>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( PageRouter::spaces_url() ); ?>" class="bn-widget-see-all">
					<?php esc_html_e( 'Browse all spaces', 'buddynext' ); ?> &#8594;
				</a>
			</div>
		<?php endif; ?>

		<?php if ( $is_guest ) : ?>
			<!-- Guest sidebar: prompt to join -->
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Join BuddyNext', 'buddynext' ); ?></div>
				<p style="font-size:var(--text-sm);color:var(--text-2);margin-bottom:var(--s3);line-height:1.6;">
					<?php esc_html_e( 'Create an account to connect with members, join spaces, and share ideas.', 'buddynext' ); ?>
				</p>
				<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="bn-empty-cta" style="display:block;text-align:center;">
					<?php esc_html_e( 'Sign up free', 'buddynext' ); ?>
				</a>
			</div>
		<?php endif; ?>

	</aside>

</div><!-- .bn-home-shell -->
</div><!-- .bn-home-feed -->
