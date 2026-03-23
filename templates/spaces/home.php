<?php
/**
 * Template: Space Home
 *
 * Renders the space hero header (cover, avatar, name, tabs) and the
 * two-column feed + sidebar layout for a single space.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT s.*, c.name AS category_name, c.slug AS category_slug
		FROM {$wpdb->prefix}bn_spaces s
		LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
		WHERE s.id = %d
		LIMIT 1",
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();

// ── Current user's membership ─────────────────────────────────────────────────

if ( $current_user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$membership = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT role, status FROM {$wpdb->prefix}bn_space_members
			WHERE space_id = %d AND user_id = %d LIMIT 1",
			$space_id,
			$current_user_id
		)
	);
} else {
	$membership = null;
}

$is_member    = $membership && 'active' === $membership->status;
$is_admin_mod = $membership && 'active' === $membership->status && in_array( $membership->role, array( 'owner', 'moderator' ), true );
$is_pending   = $membership && 'pending' === $membership->status;

// ── Access gate: private spaces ───────────────────────────────────────────────

if ( 'open' !== $space->type && ! $is_member && ! current_user_can( 'manage_options' ) ) {
	// Show teaser header only, gate the feed.
	$gate_feed = true;
} else {
	$gate_feed = false;
}

// ── Fetch posts for the feed ──────────────────────────────────────────────────

$feed_posts  = array();
$pinned_post = null;

if ( ! $gate_feed ) {
	// Pinned announcement.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$pinned_post = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT p.*, u.display_name AS author_name, um.meta_value AS author_avatar_url
			FROM {$wpdb->prefix}bn_posts p
			INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
			LEFT JOIN {$wpdb->usermeta} um ON um.user_id = p.user_id AND um.meta_key = 'buddynext_avatar_url'
			WHERE p.space_id = %d AND p.is_pinned = 1 AND p.status = 'published'
			ORDER BY p.created_at DESC LIMIT 1",
			$space_id
		)
	);

	// Regular feed posts.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$feed_posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.*, u.display_name AS author_name,
			( SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions r WHERE r.object_type = 'post' AND r.object_id = p.id ) AS reaction_count,
			( SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments cm WHERE cm.object_type = 'post' AND cm.object_id = p.id ) AS comment_count,
			sm.role AS author_role
			FROM {$wpdb->prefix}bn_posts p
			INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
			LEFT JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = p.space_id AND sm.user_id = p.user_id
			WHERE p.space_id = %d AND p.is_pinned = 0 AND p.status = 'published'
			ORDER BY p.created_at DESC
			LIMIT 20",
			$space_id
		)
	);
}

// ── Fetch sidebar members ─────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$sidebar_members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.role, sm.user_id, u.display_name
		FROM {$wpdb->prefix}bn_space_members sm
		INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
		WHERE sm.space_id = %d AND sm.status = 'active'
		ORDER BY FIELD( sm.role, 'owner', 'moderator', 'member' ), sm.joined_at ASC
		LIMIT 10",
		$space_id
	)
);

// ── Top contributors ──────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$top_contributors = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT p.user_id, u.display_name, COUNT(*) AS post_count
		FROM {$wpdb->prefix}bn_posts p
		INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
		WHERE p.space_id = %d AND p.status = 'published'
		GROUP BY p.user_id
		ORDER BY post_count DESC
		LIMIT 3",
		$space_id
	)
);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return initials (up to 2 chars) from a display name.
 *
 * @param string $name Full display name.
 * @return string Uppercase initials.
 */
function bn_initials( string $name ): string {
	$parts = array_filter( explode( ' ', trim( $name ) ) );
	if ( count( $parts ) >= 2 ) {
		return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( end( $parts ), 0, 1 ) );
	}
	return strtoupper( mb_substr( $name, 0, 2 ) );
}

/**
 * Return a deterministic avatar background colour based on a user id.
 *
 * @param int $user_id WordPress user ID.
 * @return string CSS hex colour.
 */
function bn_avatar_color( int $user_id ): string {
	$colors = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#d97706' );
	return $colors[ $user_id % count( $colors ) ];
}

/**
 * Human-readable time diff label (e.g. "3h ago").
 *
 * @param string $datetime MySQL datetime string.
 * @return string Localized time diff.
 */
function bn_time_diff( string $datetime ): string {
	return human_time_diff( strtotime( $datetime ), time() ) . ' ' . __( 'ago', 'buddynext' );
}

$active_tab = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$member_count_fmt = number_format_i18n( (int) $space->member_count );

$privacy_label = match ( $space->type ) {
	'open'    => __( 'Open', 'buddynext' ),
	'private' => __( 'Private', 'buddynext' ),
	default   => __( 'Invite-only', 'buddynext' ),
};

$bn_current_user = $current_user_id ? get_userdata( $current_user_id ) : null;

?>
<style>
<?php /* phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline CSS token block */ ?>
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs: 11px; --text-sm: 13px; --text-base: 15px;
	--text-lg: 17px; --text-xl: 20px; --text-2xl: 24px;
	--leading-body: 1.7;
	--bg: #ffffff; --bg-subtle: #f8f8f7; --bg-hover: #f1f1f0;
	--surface: #ffffff; --border: #e8e8e5; --border-soft: #f1f1ee;
	--text-1: #37352f; --text-2: #787774; --text-3: #aeaca8;
	--brand: #0073aa; --brand-light: #e8f4fb; --brand-hover: #005f8e;
	--green: #059669; --green-bg: #ecfdf5;
	--amber: #d97706; --amber-bg: #fffbeb;
	--red: #dc2626; --red-bg: #fef2f2;
	--s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 20px;
	--s6: 24px; --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg: #191919; --bg-subtle: #202020; --bg-hover: #2a2a2a;
	--surface: #252525; --border: #333330; --border-soft: #2c2c2a;
	--text-1: #e8e8e6; --text-2: #9b9b97; --text-3: #6b6b67;
	--brand: #4dabdb; --brand-light: #1a2e3a; --brand-hover: #5fbfe8;
}

.bn-space-home {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
}

/* ── Space header ── */
.bn-sh-header {
	background: var(--surface);
	border-bottom: 1px solid var(--border);
}
.bn-sh-cover {
	height: 160px;
	background: linear-gradient(135deg, #1d4ed8, var(--brand));
	position: relative;
	overflow: hidden;
}
.bn-sh-cover img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
.bn-sh-inner {
	padding: 0 var(--s8) var(--s4);
	display: flex;
	align-items: flex-end;
	gap: var(--s4);
}
.bn-sh-avatar {
	width: 80px;
	height: 80px;
	border-radius: var(--radius-lg);
	background: var(--surface);
	margin-top: -40px;
	border: 4px solid var(--surface);
	box-shadow: 0 2px 8px rgba(0,0,0,0.12);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 32px;
	flex-shrink: 0;
}
.bn-sh-info {
	flex: 1;
	padding-top: var(--s2);
	min-width: 0;
}
.bn-sh-name {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-sh-meta {
	font-size: var(--text-xs);
	color: var(--text-2);
	display: flex;
	gap: var(--s3);
	flex-wrap: wrap;
}
.bn-sh-actions {
	display: flex;
	gap: var(--s2);
	padding-top: var(--s4);
	align-items: center;
	flex-wrap: wrap;
}

/* ── Buttons ── */
.bn-btn-primary {
	background: var(--brand);
	color: #fff;
	border: none;
	padding: 8px 20px;
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	transition: background 0.15s;
	text-decoration: none;
}
.bn-btn-primary:hover { background: var(--brand-hover); }

.bn-btn-secondary {
	background: var(--surface);
	color: var(--text-1);
	border: 1.5px solid var(--border);
	padding: 8px var(--s4);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	text-decoration: none;
}
.bn-btn-secondary:hover { border-color: var(--brand); color: var(--brand); }

/* ── Space tabs ── */
.bn-sh-tabs {
	display: flex;
	padding: 0 var(--s8);
	border-top: 1px solid var(--border-soft);
	overflow-x: auto;
	gap: 0;
}
.bn-sh-tab {
	padding: var(--s3) var(--s4);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-bottom: 2px solid transparent;
	white-space: nowrap;
	text-decoration: none;
	display: block;
}
.bn-sh-tab:hover { color: var(--text-1); }
.bn-sh-tab--active {
	color: var(--brand);
	border-bottom-color: var(--brand);
	font-weight: 600;
}

/* ── Layout ── */
.bn-sh-layout {
	max-width: 1060px;
	margin: 0 auto;
	padding: var(--s6) var(--s5);
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s6);
	align-items: flex-start;
}

/* ── Composer ── */
.bn-composer {
	background: var(--surface);
	border-radius: var(--radius);
	border: 1px solid var(--border);
	padding: 14px var(--s4);
	margin-bottom: 14px;
}
.bn-composer__row {
	display: flex;
	gap: var(--s2);
	align-items: center;
}
.bn-composer__avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: var(--text-xs);
	flex-shrink: 0;
}
.bn-composer__input {
	flex: 1;
	border: 1.5px solid var(--border);
	border-radius: 20px;
	padding: 9px 14px;
	font-size: var(--text-sm);
	color: var(--text-2);
	background: var(--bg-subtle);
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-composer__input:focus {
	outline: none;
	border-color: var(--brand);
}
.bn-composer__btn {
	background: var(--brand);
	color: #fff;
	border: none;
	padding: 8px var(--s4);
	border-radius: var(--radius);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
}

/* ── Pinned card ── */
.bn-pinned {
	background: var(--amber-bg);
	border: 1px solid #fde68a;
	border-radius: var(--radius);
	padding: var(--s3) 14px;
	margin-bottom: var(--s2);
}
.bn-pinned__label {
	font-size: 10px;
	font-weight: 700;
	color: #92400e;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	margin-bottom: var(--s1);
}
.bn-pinned__title {
	font-weight: 600;
	font-size: var(--text-sm);
	margin-bottom: var(--s1);
}
.bn-pinned__body {
	font-size: var(--text-xs);
	color: var(--text-2);
	line-height: 1.5;
}
.bn-pinned__meta {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-top: var(--s1);
}

/* ── Post card ── */
.bn-post-card {
	background: var(--surface);
	border-radius: var(--radius);
	border: 1px solid var(--border);
	margin-bottom: var(--s3);
	padding: 14px var(--s4);
}
.bn-post-card__header {
	display: flex;
	gap: var(--s2);
	align-items: flex-start;
	margin-bottom: var(--s2);
}
.bn-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.bn-avatar--sm {
	width: 36px;
	height: 36px;
	font-size: var(--text-xs);
}
.bn-post-card__author {
	font-weight: 600;
	font-size: var(--text-sm);
}
.bn-post-card__role {
	display: inline-block;
	font-size: 10px;
	background: var(--amber-bg);
	color: #92400e;
	padding: 1px 6px;
	border-radius: var(--radius-sm);
	font-weight: 600;
	margin-left: var(--s1);
}
.bn-post-card__time {
	font-size: var(--text-xs);
	color: var(--text-3);
}
.bn-post-card__menu {
	margin-left: auto;
	color: var(--text-3);
	cursor: pointer;
	padding: 4px;
}
.bn-post-card__text {
	font-size: var(--text-sm);
	color: var(--text-2);
	line-height: 1.6;
	margin-bottom: var(--s2);
}
.bn-post-card__link-preview {
	background: var(--bg-subtle);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	font-size: var(--text-xs);
	border-left: 3px solid var(--brand);
	margin-bottom: var(--s2);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.bn-post-card__stats {
	display: flex;
	gap: var(--s4);
	font-size: var(--text-xs);
	color: var(--text-2);
	padding-top: var(--s2);
	border-top: 1px solid var(--border-soft);
}
.bn-post-card__stat {
	display: flex;
	align-items: center;
	gap: var(--s1);
	cursor: pointer;
}
.bn-post-card__stat:hover { color: var(--brand); }

/* ── Gate ── */
.bn-space-gate {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s8);
	text-align: center;
}
.bn-space-gate__icon { font-size: 40px; margin-bottom: var(--s3); }
.bn-space-gate__title {
	font-size: var(--text-lg);
	font-weight: 700;
	margin-bottom: var(--s2);
}

/* ── Sidebar ── */
.bn-sidebar-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s4);
	margin-bottom: 14px;
}
.bn-sidebar-widget__title {
	font-weight: 700;
	font-size: var(--text-sm);
	margin-bottom: var(--s3);
}
.bn-sidebar-about {
	font-size: 12.5px;
	color: var(--text-2);
	line-height: 1.6;
	margin-bottom: var(--s2);
}
.bn-sidebar-meta {
	font-size: var(--text-xs);
	color: var(--text-2);
	display: flex;
	flex-direction: column;
	gap: var(--s1);
}
.bn-member-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-bottom: var(--s2);
	font-size: var(--text-xs);
}
.bn-member-row__avatar {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	flex-shrink: 0;
}
.bn-member-row__name { font-weight: 600; flex: 1; }
.bn-member-role-badge {
	font-size: 10px;
	padding: 1px 5px;
	border-radius: var(--radius-sm);
}
.bn-member-role-badge--admin {
	background: var(--amber-bg);
	color: #92400e;
}
.bn-member-role-badge--mod {
	background: #e0e7ff;
	color: #3730a3;
}
.bn-sidebar-link {
	font-size: var(--text-xs);
	color: var(--brand);
	font-weight: 600;
	margin-top: var(--s1);
	cursor: pointer;
	text-decoration: none;
}
.bn-sidebar-link:hover { text-decoration: underline; }
.bn-top-contrib-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	font-size: var(--text-xs);
	margin-bottom: var(--s1);
}
.bn-top-contrib-rank {
	font-weight: 700;
	color: var(--text-3);
	width: 16px;
}
.bn-top-contrib-count { color: var(--text-3); }

/* ── Responsive ── */
@media (max-width: 1024px) {
	.bn-sh-layout { grid-template-columns: 1fr; }
	.bn-sh-inner { padding: 0 var(--s4) var(--s4); }
	.bn-sh-tabs { padding: 0 var(--s4); }
}
@media (max-width: 640px) {
	.bn-sh-cover { height: 100px; }
	.bn-sh-avatar { width: 60px; height: 60px; font-size: 24px; margin-top: -30px; }
	.bn-sh-name { font-size: var(--text-xl); }
	.bn-sh-inner { flex-direction: column; align-items: flex-start; gap: var(--s2); padding: 0 var(--s3) var(--s3); }
	.bn-sh-actions { gap: var(--s1); }
	.bn-sh-layout { padding: var(--s3); }
	.bn-sh-tabs { padding: 0 var(--s3); }
}
<?php /* phpcs:enable */ ?>
</style>

<div
	class="bn-space-home"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>

	<!-- Space header -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover">
			<?php if ( ! empty( $space->cover_image_url ) ) : ?>
				<img
					src="<?php echo esc_url( $space->cover_image_url ); ?>"
					alt="<?php echo esc_attr( $space->name ); ?>"
					loading="lazy"
				>
			<?php endif; ?>
		</div>

		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name"><?php echo esc_html( $space->name ); ?></h1>
				<div class="bn-sh-meta">
					<span>&#x1F465; <?php echo esc_html( $member_count_fmt ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
					<?php if ( ! empty( $space->category_name ) ) : ?>
						<span>&#x1F3F7;&#xFE0F; <?php echo esc_html( $space->category_name ); ?></span>
					<?php endif; ?>
					<span><?php echo esc_html( $privacy_label ); ?></span>
				</div>
			</div>

			<div class="bn-sh-actions">
				<?php if ( $is_admin_mod ) : ?>
					<a
						href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ) ); ?>"
						class="bn-btn-secondary"
					>&#x2699;&#xFE0F; <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>
					<a
						href="<?php echo esc_url( buddynext_space_moderation_url( $space->slug ) ); ?>"
						class="bn-btn-secondary"
					>&#x1F6E1;&#xFE0F; <?php esc_html_e( 'Moderation', 'buddynext' ); ?></a>

				<?php elseif ( $is_member ) : ?>
					<button
						class="bn-btn-primary"
						data-wp-on--click="actions.leaveSpace"
					>&#x2713; <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>
					<button class="bn-btn-secondary">&#x1F514; <?php esc_html_e( 'Notifications', 'buddynext' ); ?></button>

				<?php elseif ( $is_pending ) : ?>
					<button
						class="bn-btn-secondary"
						data-wp-on--click="actions.cancelJoinRequest"
					><?php esc_html_e( 'Request Pending', 'buddynext' ); ?></button>

				<?php elseif ( 'public' === $space->type ) : ?>
					<button
						class="bn-btn-primary"
						data-wp-on--click="actions.joinSpace"
					><?php esc_html_e( 'Join Space', 'buddynext' ); ?></button>

				<?php else : ?>
					<button
						class="bn-btn-primary"
						data-wp-on--click="actions.requestJoin"
					><?php esc_html_e( 'Request to Join', 'buddynext' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<nav class="bn-sh-tabs" aria-label="<?php esc_attr_e( 'Space navigation', 'buddynext' ); ?>">
			<?php
			$bn_nav_tabs = array(
				'feed'    => __( 'Feed', 'buddynext' ),
				'members' => __( 'Members', 'buddynext' ),
				'about'   => __( 'About', 'buddynext' ),
			);
			foreach ( $bn_nav_tabs as $tab_key => $tab_label ) :
				$tab_url = add_query_arg( 'bn_tab', $tab_key );
				?>
				<a
					href="<?php echo esc_url( $tab_url ); ?>"
					class="bn-sh-tab<?php echo ( $active_tab === $tab_key ) ? ' bn-sh-tab--active' : ''; ?>"
					aria-current="<?php echo ( $active_tab === $tab_key ) ? 'page' : 'false'; ?>"
				><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>
	</div>

	<!-- Main layout -->
	<div class="bn-sh-layout">

		<!-- Feed column -->
		<main>

			<?php if ( $gate_feed ) : ?>

				<div class="bn-space-gate">
					<div class="bn-space-gate__icon">&#x1F512;</div>
					<h2 class="bn-space-gate__title"><?php esc_html_e( 'This is a private space', 'buddynext' ); ?></h2>
					<p style="color:var(--text-2);font-size:var(--text-sm);margin-bottom:var(--s4);">
						<?php esc_html_e( 'Join to read posts and participate in discussions.', 'buddynext' ); ?>
					</p>
					<button class="bn-btn-primary" data-wp-on--click="actions.requestJoin">
						<?php esc_html_e( 'Request to Join', 'buddynext' ); ?>
					</button>
				</div>

			<?php else : ?>

				<?php if ( $is_member && $bn_current_user ) : ?>
					<div class="bn-composer">
						<div class="bn-composer__row">
							<div
								class="bn-composer__avatar"
								style="background:<?php echo esc_attr( bn_avatar_color( $current_user_id ) ); ?>;"
							><?php echo esc_html( bn_initials( $bn_current_user->display_name ) ); ?></div>
							<input
								type="text"
								class="bn-composer__input"
								placeholder="
								<?php
								// translators: %s is the space name.
								echo esc_attr( sprintf( __( 'Share something with %s\u{2026}', 'buddynext' ), $space->name ) );
								?>
								"
								data-wp-on--focus="actions.openComposer"
								readonly
							>
							<button class="bn-composer__btn" data-wp-on--click="actions.openComposer">
								<?php esc_html_e( 'Post', 'buddynext' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $pinned_post ) : ?>
					<div class="bn-pinned">
						<div class="bn-pinned__label">&#x1F4CC; <?php esc_html_e( 'Pinned announcement', 'buddynext' ); ?></div>
						<p class="bn-pinned__title"><?php echo esc_html( wp_trim_words( $pinned_post->content ?? '', 12 ) ); ?></p>
						<p class="bn-pinned__meta">
							<?php
							// translators: %s is the author name, %s is the time.
							printf(
								// translators: 1: author display name, 2: time ago label.
								esc_html__( 'Pinned by %1$s &middot; %2$s', 'buddynext' ),
								esc_html( $pinned_post->author_name ?? __( 'Admin', 'buddynext' ) ),
								esc_html( bn_time_diff( $pinned_post->created_at ) )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $feed_posts ) ) : ?>
					<div style="text-align:center;padding:var(--s8);color:var(--text-2);">
						<p style="font-size:var(--text-lg);font-weight:700;color:var(--text-1);margin-bottom:var(--s2);">
							<?php esc_html_e( 'No posts yet', 'buddynext' ); ?>
						</p>
						<p><?php esc_html_e( 'Be the first to post in this space.', 'buddynext' ); ?></p>
					</div>

				<?php else : ?>

					<?php foreach ( $feed_posts as $bn_post ) : ?>
						<?php
						$post_user_id    = (int) $bn_post->user_id;
						$author_name     = $bn_post->author_name ?? __( 'Member', 'buddynext' );
						$author_initials = bn_initials( $author_name );
						$author_color    = bn_avatar_color( $post_user_id );
						$reaction_count  = (int) ( $bn_post->reaction_count ?? 0 );
						$comment_count   = (int) ( $bn_post->comment_count ?? 0 );
						$post_time       = isset( $bn_post->created_at ) ? bn_time_diff( $bn_post->created_at ) : '';
						$author_role     = $bn_post->author_role ?? '';
						?>
						<article class="bn-post-card">
							<div class="bn-post-card__header">
								<div
									class="bn-avatar bn-avatar--sm"
									style="background:<?php echo esc_attr( $author_color ); ?>;"
									aria-label="<?php echo esc_attr( $author_name ); ?>"
								><?php echo esc_html( $author_initials ); ?></div>

								<div>
									<span class="bn-post-card__author"><?php echo esc_html( $author_name ); ?></span>
									<?php if ( in_array( $author_role, array( 'owner', 'moderator' ), true ) ) : ?>
										<span class="bn-post-card__role"><?php echo esc_html( ucfirst( $author_role ) ); ?></span>
									<?php endif; ?>
									<div class="bn-post-card__time"><?php echo esc_html( $post_time ); ?></div>
								</div>

								<button
									class="bn-post-card__menu"
									aria-label="<?php esc_attr_e( 'Post options', 'buddynext' ); ?>"
									data-wp-on--click="actions.openPostMenu"
									data-post-id="<?php echo esc_attr( (string) $bn_post->id ); ?>"
								>&#x22EF;</button>
							</div>

							<p class="bn-post-card__text"><?php echo esc_html( $bn_post->content ?? '' ); ?></p>

							<?php if ( ! empty( $bn_post->link_url ) ) : ?>
								<div class="bn-post-card__link-preview">
									&#x1F517; <?php echo esc_html( esc_url( $bn_post->link_url ) ); ?>
								</div>
							<?php endif; ?>

							<div class="bn-post-card__stats">
								<button
									class="bn-post-card__stat"
									data-wp-on--click="actions.toggleReaction"
									data-post-id="<?php echo esc_attr( (string) $bn_post->id ); ?>"
									aria-label="<?php esc_attr_e( 'React to post', 'buddynext' ); ?>"
								>&#x2764;&#xFE0F; <?php echo esc_html( (string) $reaction_count ); ?></button>

								<button
									class="bn-post-card__stat"
									data-wp-on--click="actions.viewComments"
									data-post-id="<?php echo esc_attr( (string) $bn_post->id ); ?>"
									aria-label="<?php esc_attr_e( 'View comments', 'buddynext' ); ?>"
								>&#x1F4AC; <?php echo esc_html( (string) $comment_count ); ?> <?php esc_html_e( 'comments', 'buddynext' ); ?></button>

								<button class="bn-post-card__stat" data-wp-on--click="actions.sharePost" data-post-id="<?php echo esc_attr( (string) $bn_post->id ); ?>">
									&#x2197;&#xFE0F; <?php esc_html_e( 'Share', 'buddynext' ); ?>
								</button>
							</div>
						</article>
					<?php endforeach; ?>

				<?php endif; ?>

			<?php endif; ?>

		</main>

		<!-- Sidebar -->
		<aside aria-label="<?php esc_attr_e( 'Space information', 'buddynext' ); ?>">

			<div class="bn-sidebar-widget">
				<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'About', 'buddynext' ); ?></h2>
				<?php if ( ! empty( $space->description ) ) : ?>
					<p class="bn-sidebar-about"><?php echo esc_html( $space->description ); ?></p>
				<?php endif; ?>
				<div class="bn-sidebar-meta">
					<?php if ( ! empty( $space->created_at ) ) : ?>
						<div>&#x1F4C5; 
						<?php
							// translators: %s is the formatted date.
							printf( esc_html__( 'Created %s', 'buddynext' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $space->created_at ) ) ) );
						?>
							</div>
					<?php endif; ?>
					<div><?php echo esc_html( $privacy_label ); ?></div>
					<?php if ( ! empty( $space->category_name ) ) : ?>
						<div>&#x1F3F7;&#xFE0F; <?php echo esc_html( $space->category_name ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-sidebar-widget">
				<h2 class="bn-sidebar-widget__title">
					<?php esc_html_e( 'Members', 'buddynext' ); ?>
					<span style="font-size:var(--text-xs);color:var(--text-3);font-weight:400;">(<?php echo esc_html( $member_count_fmt ); ?>)</span>
				</h2>

				<?php foreach ( $sidebar_members as $member ) : ?>
					<?php
					$m_uid   = (int) $member->user_id;
					$m_name  = $member->display_name ?? __( 'Member', 'buddynext' );
					$m_color = bn_avatar_color( $m_uid );
					$m_init  = bn_initials( $m_name );
					?>
					<div class="bn-member-row">
						<div
							class="bn-member-row__avatar"
							style="background:<?php echo esc_attr( $m_color ); ?>;"
							aria-label="<?php echo esc_attr( $m_name ); ?>"
						><?php echo esc_html( $m_init ); ?></div>
						<span class="bn-member-row__name">
							<?php echo esc_html( $m_name ); ?>
							<?php if ( 'owner' === $member->role ) : ?>
								<span class="bn-member-role-badge bn-member-role-badge--admin"><?php esc_html_e( 'Admin', 'buddynext' ); ?></span>
							<?php elseif ( 'moderator' === $member->role ) : ?>
								<span class="bn-member-role-badge bn-member-role-badge--mod"><?php esc_html_e( 'Mod', 'buddynext' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>

				<a
					href="<?php echo esc_url( add_query_arg( 'bn_tab', 'members' ) ); ?>"
					class="bn-sidebar-link"
				><?php esc_html_e( 'See all members', 'buddynext' ); ?> &rarr;</a>
			</div>

			<?php if ( ! empty( $top_contributors ) ) : ?>
				<div class="bn-sidebar-widget">
					<h2 class="bn-sidebar-widget__title">&#x1F3C6; <?php esc_html_e( 'Top Contributors', 'buddynext' ); ?></h2>

					<?php foreach ( $top_contributors as $rank => $contrib ) : ?>
						<?php
						$c_uid   = (int) $contrib->user_id;
						$c_name  = $contrib->display_name ?? __( 'Member', 'buddynext' );
						$c_color = bn_avatar_color( $c_uid );
						$c_init  = bn_initials( $c_name );
						?>
						<div class="bn-top-contrib-row">
							<span class="bn-top-contrib-rank"><?php echo esc_html( (string) ( $rank + 1 ) ); ?></span>
							<div
								class="bn-member-row__avatar"
								style="background:<?php echo esc_attr( $c_color ); ?>;width:24px;height:24px;font-size:9px;"
								aria-label="<?php echo esc_attr( $c_name ); ?>"
							><?php echo esc_html( $c_init ); ?></div>
							<span style="flex:1;font-weight:600;font-size:var(--text-xs);"><?php echo esc_html( $c_name ); ?></span>
							<span class="bn-top-contrib-count">
								<?php
								// translators: %d is the post count.
								printf( esc_html__( '%d posts', 'buddynext' ), (int) $contrib->post_count );
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</aside>

	</div><!-- /.bn-sh-layout -->

</div><!-- /.bn-space-home -->
