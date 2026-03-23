<?php
/**
 * BuddyNext home feed template.
 *
 * Personalised activity feed for the logged-in user.  Shows posts from
 * followed accounts and the viewer's own posts, ordered by recency.
 * Guests are redirected to the auth page.
 *
 * Features: post composer, announcement banner, cursor pagination,
 * trending-hashtags sidebar, suggested-spaces sidebar, dark mode, mobile.
 *
 * Overridable: copy to {theme}/buddynext/feed/home.php
 *
 * REST endpoint: GET buddynext/v1/feed?scope=home
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

// ── Guest gate ─────────────────────────────────────────────────────────────
$current_user_id = get_current_user_id();
if ( 0 === $current_user_id ) {
	$auth_url = PageRouter::auth_url();
	wp_safe_redirect( $auth_url );
	exit;
}

global $wpdb;

$posts_table     = $wpdb->prefix . 'bn_posts';
$follows_table   = $wpdb->prefix . 'bn_follows';
$hashtags_table  = $wpdb->prefix . 'bn_hashtags';
$spaces_table    = $wpdb->prefix . 'bn_spaces';
$space_mem_table = $wpdb->prefix . 'bn_space_members';
$user_meta_table = $wpdb->usermeta;

$bn_per_page = 15;
$cursor      = isset( $_GET['cursor'] ) ? absint( $_GET['cursor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Suspended / shadow-banned exclusion ────────────────────────────────────
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$suspended_ids = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_suspended' AND meta_value = '1'"
);
$shadow_ids    = $wpdb->get_col(
	"SELECT user_id FROM {$user_meta_table} WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$excluded_ids = array_unique(
	array_filter(
		array_map( 'intval', array_merge( $suspended_ids ?? array(), $shadow_ids ?? array() ) ),
		fn( int $id ) => $id !== $current_user_id // Viewer can always see their own posts.
	)
);

$exclusion_sql = '';
if ( ! empty( $excluded_ids ) ) {
	$placeholders  = implode( ',', array_fill( 0, count( $excluded_ids ), '%d' ) );
	$exclusion_sql = $wpdb->prepare( " AND p.user_id NOT IN ({$placeholders})", $excluded_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Pinned announcement ─────────────────────────────────────────────────────
// Matches FeedService::active_announcement() — uses bn_announcement_dismissals,
// not usermeta, so REST dismiss endpoint and PHP render are consistent.
$dismissals_table = $wpdb->prefix . 'bn_announcement_dismissals';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$announcement = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT p.id, p.user_id, p.content, p.created_at
		   FROM {$posts_table} p
		  WHERE p.type = 'announcement'
		    AND p.is_announcement = 1
		    AND p.status = 'published'
		    AND (p.site_pin_expires_at IS NULL OR p.site_pin_expires_at > NOW())
		    AND NOT EXISTS (
		          SELECT 1 FROM {$dismissals_table} d
		           WHERE d.post_id = p.id AND d.user_id = %d
		        )
		  ORDER BY p.created_at DESC
		  LIMIT %d",
		$current_user_id,
		1
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$show_announcement = null !== $announcement;

// ── Home feed posts ─────────────────────────────────────────────────────────
// Include own posts + posts from followed users.
$cursor_sql = $cursor > 0 ? $wpdb->prepare( ' AND p.id < %d', $cursor ) : '';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$feed_posts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT p.id, p.user_id, p.content, p.type, p.privacy,
		        p.media_ids, p.link_url, p.link_meta,
		        p.is_pinned, p.is_announcement, p.content_warning, p.content_warning_type,
		        p.reaction_count, p.comment_count, p.share_count,
		        p.edited_at, p.created_at, p.updated_at
		   FROM {$posts_table} p
		  WHERE p.status = 'published'
		    AND (
		          p.user_id = %d
		       OR p.user_id IN (
		            SELECT f.following_id
		              FROM {$follows_table} f
		             WHERE f.follower_id = %d
		          )
		        )
		    {$exclusion_sql}
		    {$cursor_sql}
		  ORDER BY p.created_at DESC
		  LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current_user_id,
		$current_user_id,
		$bn_per_page + 1
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$has_more = count( $feed_posts ) > $bn_per_page;
if ( $has_more ) {
	array_pop( $feed_posts );
}
$next_cursor = $has_more && ! empty( $feed_posts ) ? (int) end( $feed_posts )->id : 0;

// ── Trending hashtags sidebar ───────────────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$trending_tags = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT slug, post_count FROM {$hashtags_table} WHERE post_count > 0 ORDER BY post_count DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		8
	)
);

// ── Suggested spaces sidebar ─────────────────────────────────────────────────
// Open spaces the user has not yet joined.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$suggested_spaces = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT s.id, s.name, s.avatar_url, s.member_count
		   FROM {$spaces_table} s
		  WHERE s.type = 'open'
		    AND NOT EXISTS (
		          SELECT 1 FROM {$space_mem_table} m
		           WHERE m.space_id = s.id AND m.user_id = %d AND m.status = 'active'
		        )
		  ORDER BY s.member_count DESC
		  LIMIT %d",
		$current_user_id,
		4
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// ── REST nonce ──────────────────────────────────────────────────────────────
$rest_nonce      = wp_create_nonce( 'wp_rest' );
$explore_url     = PageRouter::explore_url();
$bn_current_user = get_userdata( $current_user_id );
$display_name    = $bn_current_user ? esc_attr( $bn_current_user->display_name ) : '';

/**
 * Format a UTC timestamp as a human-readable relative time label.
 *
 * @param string $datetime MySQL datetime string.
 * @return string Escaped, translated relative time.
 */
if ( ! function_exists( 'bn_home_relative_time' ) ) {
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
	--text-xs:   11px;
	--text-sm:   13px;
	--text-base: 15px;
	--text-lg:   17px;
	--text-xl:   20px;
	--text-2xl:  24px;
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
	--green:       #059669;
	--green-bg:    #ecfdf5;
	--amber:       #d97706;
	--amber-bg:    #fffbeb;
	--red:         #dc2626;
	--red-bg:      #fef2f2;
	--s1:  4px;  --s2: 8px;   --s3: 12px;  --s4: 16px;
	--s5:  20px; --s6: 24px;  --s8: 32px;  --s10: 40px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
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
	--green:       #34d399;
	--green-bg:    #0d2420;
	--amber:       #fbbf24;
	--amber-bg:    #2a2000;
	--red:         #f87171;
	--red-bg:      #2d0f0f;
}

.bn-home {
	font-family: var(--font-body);
	font-size:   var(--text-base);
	line-height: var(--leading-body);
	color:       var(--text-1);
	background:  var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
}
.bn-home-shell {
	max-width: 1100px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: start;
}

/* ── Feed tabs ─────────────────────────────────────────────────────────── */
.bn-feed-tabs {
	display: flex;
	gap: 0;
	border-bottom: 1px solid var(--border);
	margin-bottom: var(--s4);
	background: var(--surface);
	border-radius: var(--radius) var(--radius) 0 0;
	overflow: hidden;
}
.bn-feed-tab {
	padding: var(--s3) var(--s5);
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-2);
	text-decoration: none;
	border-bottom: 2px solid transparent;
	margin-bottom: -1px;
	transition: color .15s, border-color .15s;
}
.bn-feed-tab:hover { color: var(--text-1); }
.bn-feed-tab--active {
	color: var(--brand);
	border-bottom-color: var(--brand);
}

/* ── Announcement banner ─────────────────────────────────────────────── */
.bn-announcement {
	display: flex;
	align-items: flex-start;
	gap: var(--s3);
	background: var(--brand-light);
	border: 1px solid var(--brand);
	border-radius: var(--radius);
	padding: var(--s4);
	margin-bottom: var(--s4);
	font-size: var(--text-sm);
	color: var(--text-1);
}
.bn-announcement__icon { font-size: 18px; flex-shrink: 0; }
.bn-announcement__body { flex: 1; }
.bn-announcement__dismiss {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--text-3);
	padding: 0;
	font-size: 18px;
	line-height: 1;
	flex-shrink: 0;
}
.bn-announcement__dismiss:hover { color: var(--text-1); }

/* ── Post composer ─────────────────────────────────────────────────────── */
.bn-composer {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s4);
	margin-bottom: var(--s4);
}
.bn-composer__top {
	display: flex;
	align-items: center;
	gap: var(--s3);
}
.bn-composer__avatar {
	width: 38px;
	height: 38px;
	border-radius: 50%;
	background: var(--brand-light);
	color: var(--brand);
	font-size: var(--text-sm);
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	overflow: hidden;
}
.bn-composer__avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-composer__input {
	flex: 1;
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: 20px;
	padding: var(--s2) var(--s4);
	font-size: var(--text-base);
	color: var(--text-2);
	cursor: pointer;
	user-select: none;
	transition: background .15s, border-color .15s;
}
.bn-composer__input:hover {
	background: var(--bg-hover);
	border-color: var(--brand);
	color: var(--text-1);
}
.bn-composer__actions {
	display: flex;
	gap: var(--s2);
	margin-top: var(--s3);
	padding-top: var(--s3);
	border-top: 1px solid var(--border-soft);
}
.bn-composer__action-btn {
	display: flex;
	align-items: center;
	gap: var(--s1);
	background: none;
	border: none;
	cursor: pointer;
	font-size: var(--text-sm);
	color: var(--text-2);
	padding: var(--s1) var(--s2);
	border-radius: var(--radius-sm);
	transition: background .15s, color .15s;
}
.bn-composer__action-btn:hover {
	background: var(--bg-hover);
	color: var(--text-1);
}
.bn-composer__action-icon { font-size: 16px; }

/* ── Feed list ──────────────────────────────────────────────────────────── */
.bn-feed-list { display: flex; flex-direction: column; gap: var(--s4); }

/* ── Empty state ─────────────────────────────────────────────────────────── */
.bn-feed-empty {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s10);
	text-align: center;
	color: var(--text-2);
}
.bn-feed-empty__icon { font-size: 40px; margin-bottom: var(--s4); }
.bn-feed-empty__title {
	font-size: var(--text-lg);
	font-weight: 700;
	color: var(--text-1);
	margin-bottom: var(--s2);
}
.bn-feed-empty__text { font-size: var(--text-sm); margin-bottom: var(--s5); }
.bn-feed-empty__cta {
	display: inline-block;
	background: var(--brand);
	color: #fff;
	border-radius: var(--radius);
	padding: var(--s2) var(--s5);
	font-size: var(--text-sm);
	font-weight: 600;
	text-decoration: none;
	transition: background .15s;
}
.bn-feed-empty__cta:hover { background: var(--brand-hover); color: #fff; }

/* ── Load-more button ───────────────────────────────────────────────────── */
.bn-load-more {
	text-align: center;
	padding: var(--s5) 0;
}
.bn-load-more__btn {
	display: inline-flex;
	align-items: center;
	gap: var(--s2);
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s2) var(--s6);
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-1);
	cursor: pointer;
	text-decoration: none;
	transition: background .15s, border-color .15s;
}
.bn-load-more__btn:hover {
	background: var(--bg-hover);
	border-color: var(--brand);
	color: var(--brand);
}

/* ── Sidebar ─────────────────────────────────────────────────────────────── */
.bn-home-sidebar { display: flex; flex-direction: column; gap: var(--s5); }
.bn-sidebar-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}
.bn-sidebar-card__header {
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-sm);
	font-weight: 700;
	color: var(--text-1);
	letter-spacing: .3px;
}
.bn-sidebar-card__body { padding: var(--s3) var(--s4); }

/* Trending hashtags */
.bn-hashtag-list { list-style: none; margin: 0; padding: 0; }
.bn-hashtag-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: var(--s2) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-hashtag-item:last-child { border-bottom: none; }
.bn-hashtag-item__link {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--brand);
	text-decoration: none;
}
.bn-hashtag-item__link:hover { text-decoration: underline; }
.bn-hashtag-item__count { font-size: var(--text-xs); color: var(--text-3); }

/* Suggested spaces */
.bn-space-list { list-style: none; margin: 0; padding: 0; }
.bn-space-item {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s3) 0;
	border-bottom: 1px solid var(--border-soft);
}
.bn-space-item:last-child { border-bottom: none; }
.bn-space-item__avatar {
	width: 36px;
	height: 36px;
	border-radius: var(--radius-sm);
	object-fit: cover;
	background: var(--brand-light);
	flex-shrink: 0;
}
.bn-space-item__info { flex: 1; min-width: 0; }
.bn-space-item__name {
	font-size: var(--text-sm);
	font-weight: 600;
	color: var(--text-1);
	text-decoration: none;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	display: block;
}
.bn-space-item__name:hover { color: var(--brand); }
.bn-space-item__count { font-size: var(--text-xs); color: var(--text-3); }
.bn-space-item__join {
	background: var(--brand-light);
	border: 1px solid var(--brand);
	border-radius: var(--radius-sm);
	color: var(--brand);
	font-size: var(--text-xs);
	font-weight: 600;
	padding: 3px 10px;
	cursor: pointer;
	transition: background .15s;
	flex-shrink: 0;
}
.bn-space-item__join:hover { background: var(--brand); color: #fff; }

/* ── Mobile ≤640px ───────────────────────────────────────────────────────── */
@media (max-width: 640px) {
	.bn-home-shell {
		grid-template-columns: 1fr;
		padding: 0 var(--s3) var(--s8);
		gap: var(--s4);
	}
	.bn-home-sidebar { display: none; }
	.bn-feed-tabs { border-radius: 0; }
	.bn-composer { border-radius: 0; border-left: none; border-right: none; }
}
</style>

<div class="bn-home" data-bn-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>" data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>">
	<div class="bn-home-shell">

		<!-- ── Main feed column ──────────────────────────────────────────── -->
		<main class="bn-home-main" role="main">

			<!-- Feed tabs -->
			<div class="bn-feed-tabs" role="tablist">
				<a href="<?php echo esc_url( PageRouter::activity_url() ); ?>"
					class="bn-feed-tab bn-feed-tab--active"
					role="tab" aria-selected="true">
					<?php esc_html_e( 'Home', 'buddynext' ); ?>
				</a>
				<a href="<?php echo esc_url( $explore_url ); ?>"
					class="bn-feed-tab"
					role="tab" aria-selected="false">
					<?php esc_html_e( 'Explore', 'buddynext' ); ?>
				</a>
			</div>

			<?php if ( $show_announcement && $announcement ) : ?>
				<div class="bn-announcement"
					data-wp-interactive="buddynext/announcement"
					data-wp-context='{"announcementId":<?php echo (int) $announcement->id; ?>}'>
					<span class="bn-announcement__icon" aria-hidden="true">📢</span>
					<div class="bn-announcement__body">
						<?php echo wp_kses_post( $announcement->content ); ?>
					</div>
					<button
						class="bn-announcement__dismiss"
						data-wp-on--click="actions.dismiss"
						aria-label="<?php esc_attr_e( 'Dismiss announcement', 'buddynext' ); ?>">
						&times;
					</button>
				</div>
			<?php endif; ?>

			<!-- Post composer -->
			<div class="bn-composer"
				data-wp-interactive="buddynext/post-composer"
				data-wp-context='<?php echo esc_attr( wp_json_encode( array( "restUrl" => rest_url( "buddynext/v1" ), "restNonce" => $rest_nonce, "composerOpen" => false, "composerType" => "text", "privacy" => "public", "content" => "", "submitting" => false ) ) ); ?>'>
				<div class="bn-composer__top">
					<div class="bn-composer__avatar" aria-hidden="true">
						<?php
						$avatar_src = get_avatar_url( $current_user_id, array( 'size' => 76 ) );
						if ( $avatar_src ) {
							echo '<img src="' . esc_url( $avatar_src ) . '" alt="" width="38" height="38">';
						} else {
							echo esc_html( mb_substr( $display_name, 0, 1 ) );
						}
						?>
					</div>
					<div class="bn-composer__input"
						role="button"
						tabindex="0"
						data-wp-interactive="buddynext/post-composer"
						data-wp-on--click="actions.open"
						data-wp-on--keydown="actions.openOnEnter">
						<?php esc_html_e( "What's on your mind?", 'buddynext' ); ?>
					</div>
				</div>
				<div class="bn-composer__actions">
					<button class="bn-composer__action-btn"
							data-wp-interactive="buddynext/post-composer"
							data-wp-on--click="actions.openPhoto"
							type="button">
						<span class="bn-composer__action-icon" aria-hidden="true">🖼️</span>
						<?php esc_html_e( 'Photo', 'buddynext' ); ?>
					</button>
					<button class="bn-composer__action-btn"
							data-wp-interactive="buddynext/post-composer"
							data-wp-on--click="actions.openPoll"
							type="button">
						<span class="bn-composer__action-icon" aria-hidden="true">📊</span>
						<?php esc_html_e( 'Poll', 'buddynext' ); ?>
					</button>
					<button class="bn-composer__action-btn"
							data-wp-interactive="buddynext/post-composer"
							data-wp-on--click="actions.openLink"
							type="button">
						<span class="bn-composer__action-icon" aria-hidden="true">🔗</span>
						<?php esc_html_e( 'Link', 'buddynext' ); ?>
					</button>
				</div>
			</div>

			<!-- Feed posts -->
			<?php if ( ! empty( $feed_posts ) ) : ?>
				<div class="bn-feed-list" role="feed" aria-label="<?php esc_attr_e( 'Home feed', 'buddynext' ); ?>">
					<?php foreach ( $feed_posts as $row ) : ?>
						<?php
						$home_post = array(
							'id'                   => (int) $row->id,
							'user_id'              => (int) $row->user_id,
							'type'                 => $row->type ?? 'text',
							'content'              => $row->content ?? '',
							'privacy'              => $row->privacy ?? 'public',
							'space_id'             => null,
							'media_ids'            => $row->media_ids ?? null,
							'link_url'             => $row->link_url ?? null,
							'link_meta'            => $row->link_meta ?? null,
							'poll_options'         => array(),
							'is_pinned'            => (int) ( $row->is_pinned ?? 0 ),
							'is_announcement'      => (int) ( $row->is_announcement ?? 0 ),
							'content_warning'      => (bool) ( $row->content_warning ?? false ),
							'content_warning_type' => $row->content_warning_type ?? null,
							'reaction_count'       => absint( $row->reaction_count ?? 0 ),
							'comment_count'        => absint( $row->comment_count ?? 0 ),
							'share_count'          => absint( $row->share_count ?? 0 ),
							'edited_at'            => $row->edited_at ?? null,
							'created_at'           => $row->created_at ?? '',
							'updated_at'           => $row->updated_at ?? null,
						);
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $home_post,
								'current_user_id' => $current_user_id,
								'context'         => 'home',
							)
						);
						?>
					<?php endforeach; ?>
				</div>

				<?php if ( $has_more && $next_cursor > 0 ) : ?>
					<div class="bn-load-more">
						<a href="<?php echo esc_url( add_query_arg( 'cursor', $next_cursor, PageRouter::activity_url() ) ); ?>"
							class="bn-load-more__btn">
							<?php esc_html_e( 'Load more', 'buddynext' ); ?>
						</a>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<div class="bn-feed-empty" role="status">
					<div class="bn-feed-empty__icon" aria-hidden="true">👋</div>
					<div class="bn-feed-empty__title">
						<?php esc_html_e( 'Your feed is empty', 'buddynext' ); ?>
					</div>
					<p class="bn-feed-empty__text">
						<?php esc_html_e( 'Follow members or join spaces to start seeing posts here.', 'buddynext' ); ?>
					</p>
					<a href="<?php echo esc_url( PageRouter::people_url() ); ?>" class="bn-feed-empty__cta">
						<?php esc_html_e( 'Discover Members', 'buddynext' ); ?>
					</a>
				</div>
			<?php endif; ?>

		</main>

		<!-- ── Sidebar ──────────────────────────────────────────────────── -->
		<aside class="bn-home-sidebar" aria-label="<?php esc_attr_e( 'Community sidebar', 'buddynext' ); ?>">

			<?php if ( ! empty( $trending_tags ) ) : ?>
				<div class="bn-sidebar-card">
					<div class="bn-sidebar-card__header">
						<?php esc_html_e( 'Trending Topics', 'buddynext' ); ?>
					</div>
					<div class="bn-sidebar-card__body">
						<ul class="bn-hashtag-list">
							<?php foreach ( $trending_tags as $bn_tag ) : ?>
								<li class="bn-hashtag-item">
									<a href="<?php echo esc_url( PageRouter::hashtag_feed_url( $bn_tag->slug ) ); ?>"
										class="bn-hashtag-item__link">
										#<?php echo esc_html( $bn_tag->slug ); ?>
									</a>
									<span class="bn-hashtag-item__count">
										<?php
										/* translators: %d: post count */
										echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $bn_tag->post_count, 'buddynext' ), (int) $bn_tag->post_count ) );
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $suggested_spaces ) ) : ?>
				<div class="bn-sidebar-card">
					<div class="bn-sidebar-card__header">
						<?php esc_html_e( 'Suggested Spaces', 'buddynext' ); ?>
					</div>
					<div class="bn-sidebar-card__body">
						<ul class="bn-space-list">
							<?php foreach ( $suggested_spaces as $space ) : ?>
								<li class="bn-space-item">
									<?php if ( ! empty( $space->avatar_url ) ) : ?>
										<img class="bn-space-item__avatar"
											src="<?php echo esc_url( $space->avatar_url ); ?>"
											alt="<?php echo esc_attr( $space->name ); ?>"
											width="36" height="36">
									<?php else : ?>
										<div class="bn-space-item__avatar" aria-hidden="true"></div>
									<?php endif; ?>
									<div class="bn-space-item__info">
										<a href="<?php echo esc_url( PageRouter::space_url( $space->id ) ); ?>"
											class="bn-space-item__name">
											<?php echo esc_html( $space->name ); ?>
										</a>
										<div class="bn-space-item__count">
											<?php
											/* translators: %d: member count */
											echo esc_html( sprintf( _n( '%d member', '%d members', (int) $space->member_count, 'buddynext' ), (int) $space->member_count ) );
											?>
										</div>
									</div>
									<button
										class="bn-space-item__join"
										type="button"
										data-wp-interactive="buddynext/spaces"
										data-wp-context='{"spaceId":<?php echo (int) $space->id; ?>}'
										data-wp-on--click="actions.join"
										data-nonce="<?php echo esc_attr( $rest_nonce ); ?>">
										<?php esc_html_e( 'Join', 'buddynext' ); ?>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

		</aside>

	</div><!-- .bn-home-shell -->
</div><!-- .bn-home -->
