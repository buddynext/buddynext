<?php
/**
 * Member Directory template.
 *
 * Displays a searchable, filterable grid of WordPress users with
 * social-graph context (follow status, mutual connections, online status).
 *
 * Variables expected from the rendering context:
 *   (none required — all data fetched internally)
 *
 * @package BuddyNext
 * @since   0.1.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// ── Query parameters ──────────────────────────────────────────────────────────
$bn_current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_per_page     = 20;
$search_term     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );       // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$orderby_raw     = sanitize_key( $_GET['orderby'] ?? 'registered' );             // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$allowed_sort    = array( 'registered', 'display_name', 'post_count' );
$bn_orderby      = in_array( $orderby_raw, $allowed_sort, true ) ? $orderby_raw : 'registered';
$bn_order        = 'registered' === $bn_orderby ? 'DESC' : 'ASC';

// ── Fetch users ───────────────────────────────────────────────────────────────
$user_query_args = array(
	'number'      => $bn_per_page,
	'paged'       => $bn_current_page,
	'orderby'     => $bn_orderby,
	'order'       => $bn_order,
	'fields'      => 'all',
	'count_total' => true,
);

if ( '' !== $search_term ) {
	$user_query_args['search']         = '*' . $search_term . '*';
	$user_query_args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
}

$user_query  = new WP_User_Query( $user_query_args );
$members     = $user_query->get_results();
$total_users = (int) $user_query->get_total();
$total_pages = (int) ceil( $total_users / $bn_per_page );

// ── Current user context ──────────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Helper: online status (last_active within 5 minutes) ─────────────────────
$online_threshold = time() - 300;

/**
 * Determine if a user is considered online.
 *
 * Uses the BuddyNext activity log if available, falls back to session meta.
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
$bn_is_online = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};

/**
 * Return the two-character initials for a display name.
 *
 * @param string $name Display name.
 * @return string
 */
$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

// Avatar colour palette — cycles deterministically by user ID.
$avatar_colours = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#dc2626', '#d97706' );

/**
 * Pick an avatar background colour for a given user ID.
 *
 * @param int $user_id WordPress user ID.
 * @return string Hex colour string.
 */
$bn_avatar_colour = static function ( int $user_id ) use ( $avatar_colours ): string {
	return $avatar_colours[ $user_id % count( $avatar_colours ) ];
};

/**
 * Return the number of mutual connections between two users.
 *
 * Delegates to the connections service so the query targets bn_connections,
 * not bn_follows (follows and connections are separate social-graph concepts).
 *
 * @param int $user_a First user ID.
 * @param int $user_b Second user ID.
 * @return int
 */
$bn_mutual_count = static function ( int $user_a, int $user_b ): int {
	if ( 0 === $user_a || 0 === $user_b || $user_a === $user_b ) {
		return 0;
	}
	return count( buddynext_service( 'connections' )->mutual_connections( $user_a, $user_b ) );
};

/**
 * Check whether the current user follows a given user.
 *
 * @param int $target_user_id User ID to check.
 * @return bool
 */
$bn_is_following = static function ( int $target_user_id ) use ( $current_user_id ): bool {
	if ( 0 === $current_user_id ) {
		return false;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bn_follows';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE follower_id = %d AND following_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current_user_id,
			$target_user_id
		)
	);
	return '1' === (string) $exists;
};

/**
 * Build a pagination URL preserving current query args.
 *
 * @param int $page_number Target page number.
 * @return string Escaped URL.
 */
$bn_paged_url = static function ( int $page_number ) use ( $search_term, $bn_orderby ): string {
	$args = array( 'paged' => $page_number );
	if ( '' !== $search_term ) {
		$args['s'] = $search_term;
	}
	if ( 'registered' !== $bn_orderby ) {
		$args['orderby'] = $bn_orderby;
	}
	return esc_url( add_query_arg( $args ) );
};

// ── Messages page URL (hoisted — do not call inside member loop) ──────────────
$bn_messages_page = get_page_by_path( 'messages' );
$bn_messages_base = $bn_messages_page ? get_permalink( $bn_messages_page ) : home_url( '/messages/' );

// ── Nonce for interactive actions ─────────────────────────────────────────────
$action_nonce = wp_create_nonce( 'bn_member_action' );
$rest_url     = esc_url( rest_url( 'buddynext/v1/members' ) );
?>
<div
	class="bn-member-directory"
	data-wp-interactive="buddynext/members"
	data-wp-context='{"search":"<?php echo esc_js( $search_term ); ?>","orderby":"<?php echo esc_js( $bn_orderby ); ?>","nonce":"<?php echo esc_js( $action_nonce ); ?>","restUrl":"<?php echo esc_js( rest_url( 'buddynext/v1/members' ) ); ?>"}'
>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap');

:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px;  --text-sm: 13px;  --text-base: 15px;
	--text-lg:  17px;  --text-xl: 20px;  --text-2xl: 24px;
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
	--green:    #059669;  --green-bg:  #ecfdf5;
	--amber:    #d97706;  --amber-bg:  #fffbeb;
	--red:      #dc2626;  --red-bg:    #fef2f2;
	--s1: 4px;  --s2: 8px;   --s3: 12px;  --s4: 16px;
	--s5: 20px; --s6: 24px;  --s8: 32px;
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
}

/* ── Layout ─────────────────────────────────────────────── */
.bn-member-directory {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	line-height: var(--leading-body);
	background: var(--bg-subtle);
	min-height: 100vh;
	padding: var(--s6) var(--s5);
}
.bn-dir-header { margin-bottom: var(--s4); }
.bn-dir-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-dir-sub { font-size: var(--text-sm); color: var(--text-2); }

/* ── Filter bar ─────────────────────────────────────────── */
.bn-filter-bar {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s3) var(--s4);
	margin-bottom: var(--s5);
	display: flex;
	gap: var(--s3);
	align-items: center;
	flex-wrap: wrap;
}
.bn-search-wrap { position: relative; flex: 1; min-width: 200px; }
.bn-search-icon {
	position: absolute;
	left: var(--s3);
	top: 50%;
	transform: translateY(-50%);
	color: var(--text-3);
	font-size: var(--text-base);
	pointer-events: none;
	line-height: 1;
}
.bn-search-input {
	width: 100%;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3) var(--s2) calc(var(--s3) + 20px);
	font-size: var(--text-sm);
	font-family: var(--font-body);
	background: var(--bg);
	color: var(--text-1);
	outline: none;
	transition: border-color 0.15s;
}
.bn-search-input:focus { border-color: var(--brand); }
.bn-filter-select {
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	font-size: var(--text-sm);
	font-family: var(--font-body);
	color: var(--text-1);
	background: var(--bg);
	cursor: pointer;
	outline: none;
}
.bn-filter-select:focus { border-color: var(--brand); }
.bn-view-toggle {
	display: flex;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	overflow: hidden;
}
.bn-view-btn {
	padding: 6px 10px;
	cursor: pointer;
	font-size: var(--text-base);
	color: var(--text-2);
	background: var(--bg);
	border: none;
	line-height: 1;
	transition: background 0.12s, color 0.12s;
}
.bn-view-btn.active,
.bn-view-btn[aria-pressed="true"] {
	background: var(--brand);
	color: #fff;
}
.bn-result-count {
	font-size: var(--text-xs);
	color: var(--text-2);
	white-space: nowrap;
}

/* ── Grid ───────────────────────────────────────────────── */
.bn-members-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: var(--s3);
}
.bn-member-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s5) var(--s4);
	text-align: center;
	position: relative;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.bn-member-card:hover {
	border-color: var(--brand);
	box-shadow: 0 2px 12px rgba(0, 115, 170, 0.1);
}

/* ── Avatar ─────────────────────────────────────────────── */
.bn-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 0 auto var(--s3);
	position: relative;
	width: 64px;
	height: 64px;
	font-size: 22px;
	overflow: visible;
}
.bn-avatar img {
	width: 100%;
	height: 100%;
	border-radius: 50%;
	object-fit: cover;
}
.bn-online-dot {
	position: absolute;
	bottom: 2px;
	right: 2px;
	width: 13px;
	height: 13px;
	background: var(--green);
	border-radius: 50%;
	border: 2px solid var(--surface);
}

/* ── Card content ───────────────────────────────────────── */
.bn-member-name {
	font-weight: 700;
	font-size: var(--text-sm);
	margin-bottom: var(--s1);
	color: var(--text-1);
}
.bn-member-name a {
	color: inherit;
	text-decoration: none;
}
.bn-member-name a:hover { color: var(--brand); }
.bn-member-handle {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-bottom: var(--s1);
}
.bn-member-bio {
	font-size: 12px;
	color: var(--text-2);
	margin-bottom: var(--s2);
	overflow: hidden;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}
.bn-degree-badge {
	display: inline-block;
	padding: 2px var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 700;
	margin-bottom: var(--s2);
}
.bn-deg-1 { background: #dbeafe; color: #1d4ed8; }
.bn-deg-2 { background: #ede9fe; color: #5b21b6; }
.bn-deg-3 { background: var(--bg-subtle); color: var(--text-2); }
[data-theme="dark"] .bn-deg-1 { background: #1e2d4a; color: #93c5fd; }
[data-theme="dark"] .bn-deg-2 { background: #1e1830; color: #a78bfa; }
.bn-mutual-count {
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-bottom: var(--s3);
}
.bn-card-actions {
	display: flex;
	gap: var(--s2);
	justify-content: center;
	flex-wrap: wrap;
}

/* ── Buttons ─────────────────────────────────────────────── */
.bn-btn-follow {
	background: var(--brand);
	color: #fff;
	padding: 6px var(--s3);
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-follow:hover { background: var(--brand-hover); }
.bn-btn-follow.following {
	background: var(--brand-light);
	color: var(--brand);
}
[data-theme="dark"] .bn-btn-follow.following { color: var(--brand); }
.bn-btn-connect {
	background: var(--bg);
	color: var(--brand);
	padding: 6px var(--s3);
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid var(--brand);
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-connect:hover { background: var(--brand-light); }
.bn-btn-message {
	background: var(--bg);
	color: var(--text-2);
	padding: 6px 10px;
	border-radius: 14px;
	font-size: var(--text-base);
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	line-height: 1;
	transition: border-color 0.15s;
	text-decoration: none;
}
.bn-btn-message:hover { border-color: var(--brand); }

/* ── Empty/more card ─────────────────────────────────────── */
.bn-more-card {
	background: var(--bg-subtle);
	border: 2px dashed var(--border);
	border-radius: var(--radius-lg);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-direction: column;
	gap: var(--s2);
	min-height: 240px;
	padding: var(--s5);
}
.bn-more-icon { font-size: 28px; line-height: 1; }
.bn-more-count { font-size: var(--text-sm); color: var(--text-2); font-weight: 600; }
.bn-more-hint { font-size: 12px; color: var(--text-3); }

/* ── No results ──────────────────────────────────────────── */
.bn-no-results {
	grid-column: 1 / -1;
	text-align: center;
	padding: var(--s8) var(--s5);
	color: var(--text-2);
}
.bn-no-results-icon { font-size: 40px; margin-bottom: var(--s3); }
.bn-no-results-title { font-size: var(--text-lg); font-weight: 700; color: var(--text-1); margin-bottom: var(--s2); }

/* ── Pagination ──────────────────────────────────────────── */
.bn-pagination {
	display: flex;
	justify-content: center;
	gap: var(--s2);
	margin-top: var(--s6);
	flex-wrap: wrap;
}
.bn-page-btn {
	width: 36px;
	height: 36px;
	border-radius: var(--radius-sm);
	border: 1.5px solid var(--border);
	background: var(--surface);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: var(--text-sm);
	font-weight: 600;
	text-decoration: none;
	color: var(--text-1);
	transition: border-color 0.15s, background 0.15s, color 0.15s;
}
.bn-page-btn:hover { border-color: var(--brand); color: var(--brand); }
.bn-page-btn.active,
.bn-page-btn[aria-current="page"] {
	background: var(--brand);
	border-color: var(--brand);
	color: #fff;
}
.bn-page-btn.disabled {
	opacity: 0.4;
	pointer-events: none;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 1024px) {
	.bn-members-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
	.bn-members-grid { grid-template-columns: repeat(2, 1fr); }
	.bn-filter-bar { flex-direction: column; align-items: stretch; }
	.bn-search-wrap { min-width: 0; }
	.bn-filter-select { width: 100%; }
}
@media (max-width: 640px) {
	.bn-member-directory { padding: var(--s4) var(--s3); }
	.bn-members-grid { grid-template-columns: 1fr; gap: var(--s2); }
	.bn-dir-title { font-size: var(--text-xl); }
	.bn-filter-bar { padding: var(--s3); }
}
</style>

<div class="bn-dir-header">
	<h1 class="bn-dir-title"><?php esc_html_e( 'Member Directory', 'buddynext' ); ?></h1>
	<p class="bn-dir-sub"><?php esc_html_e( 'Find and connect with community members', 'buddynext' ); ?></p>
</div>

<form class="bn-filter-bar" method="get" action="" role="search" aria-label="<?php esc_attr_e( 'Filter members', 'buddynext' ); ?>">
	<div class="bn-search-wrap">
		<span class="bn-search-icon" aria-hidden="true">&#128269;</span>
		<input
			class="bn-search-input"
			type="search"
			name="s"
			value="<?php echo esc_attr( $search_term ); ?>"
			placeholder="<?php esc_attr_e( 'Search by name, skills, location&hellip;', 'buddynext' ); ?>"
			aria-label="<?php esc_attr_e( 'Search members', 'buddynext' ); ?>"
		>
	</div>

	<select class="bn-filter-select" name="orderby" aria-label="<?php esc_attr_e( 'Sort members', 'buddynext' ); ?>">
		<option value="registered" <?php selected( $bn_orderby, 'registered' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
		<option value="display_name" <?php selected( $bn_orderby, 'display_name' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
		<option value="post_count" <?php selected( $bn_orderby, 'post_count' ); ?>><?php esc_html_e( 'Most active', 'buddynext' ); ?></option>
	</select>

	<div class="bn-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View layout', 'buddynext' ); ?>">
		<button type="button" class="bn-view-btn active" aria-pressed="true" aria-label="<?php esc_attr_e( 'Grid view', 'buddynext' ); ?>"
			data-wp-on--click="actions.setGridView">&#10799;</button>
		<button type="button" class="bn-view-btn" aria-pressed="false" aria-label="<?php esc_attr_e( 'List view', 'buddynext' ); ?>"
			data-wp-on--click="actions.setListView">&#9776;</button>
	</div>

	<span class="bn-result-count">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: formatted number of community members */
				__( '%s members', 'buddynext' ),
				number_format_i18n( $total_users )
			)
		);
		?>
	</span>
</form>

<div class="bn-members-grid" data-wp-class--bn-list-view="state.isListView" role="list">

	<?php if ( empty( $members ) ) : ?>
		<div class="bn-no-results">
			<div class="bn-no-results-icon">&#128101;</div>
			<div class="bn-no-results-title"><?php esc_html_e( 'No members found', 'buddynext' ); ?></div>
			<p><?php esc_html_e( 'Try a different search term or clear your filters.', 'buddynext' ); ?></p>
		</div>
	<?php else : ?>

		<?php foreach ( $members as $member ) : ?>
			<?php
			$member_id    = (int) $member->ID;
			$display_name = $member->display_name;
			$member_login = $member->user_login;
			$bio          = get_user_meta( $member_id, 'bn_field_bio', true );
			if ( empty( $bio ) ) {
				$bio = get_user_meta( $member_id, 'description', true );
			}
			$profile_url    = get_author_posts_url( $member_id );
			$avatar_html    = get_avatar( $member_id, 64, '', esc_attr( $display_name ), array( 'force_display' => true ) );
			$has_avatar     = false !== strpos( $avatar_html, 'src=' );
			$initials       = $bn_initials( $display_name );
			$bg_colour      = $bn_avatar_colour( $member_id );
			$is_online      = $bn_is_online( $member_id );
			$is_following   = $bn_is_following( $member_id );
			$mutual         = $bn_mutual_count( $current_user_id, $member_id );
			$messages_url   = add_query_arg( array( 'recipient' => $member_id ), $bn_messages_base );
			$follow_nonce   = wp_create_nonce( 'bn_follow_' . $member_id );
			$bn_conn_status = $current_user_id
				? buddynext_service( 'connections' )->status( $current_user_id, $member_id )
				: null;
			?>
			<article class="bn-member-card" role="listitem">

				<div
					class="bn-avatar"
					style="background: <?php echo esc_attr( $bg_colour ); ?>;"
					aria-hidden="true"
				>
					<?php if ( $has_avatar ) : ?>
						<?php
						echo wp_kses(
							$avatar_html,
							array(
								'img' => array(
									'src'      => true,
									'class'    => true,
									'alt'      => true,
									'width'    => true,
									'height'   => true,
									'loading'  => true,
									'decoding' => true,
								),
							)
						);
						?>
					<?php else : ?>
						<?php echo esc_html( $initials ); ?>
					<?php endif; ?>

					<?php if ( $is_online ) : ?>
						<span class="bn-online-dot" title="<?php esc_attr_e( 'Online', 'buddynext' ); ?>"></span>
					<?php endif; ?>
				</div>

				<div class="bn-member-name">
					<a href="<?php echo esc_url( $profile_url ); ?>">
						<?php echo esc_html( $display_name ); ?>
					</a>
				</div>

				<div class="bn-member-handle">@<?php echo esc_html( $member_login ); ?></div>

				<?php if ( ! empty( $bio ) ) : ?>
					<div class="bn-member-bio"><?php echo esc_html( wp_trim_words( $bio, 15 ) ); ?></div>
				<?php endif; ?>

				<?php if ( $mutual > 0 ) : ?>
					<div class="bn-mutual-count">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of mutual connections */
								_n( '%d mutual connection', '%d mutual connections', $mutual, 'buddynext' ),
								$mutual
							)
						);
						?>
					</div>
				<?php endif; ?>

				<div class="bn-card-actions">
					<?php if ( $current_user_id === $member_id ) : ?>
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>" class="bn-btn-connect">
							<?php esc_html_e( 'Edit Profile', 'buddynext' ); ?>
						</a>
					<?php else : ?>
						<button
							type="button"
							class="bn-btn-follow<?php echo $is_following ? ' following' : ''; ?>"
							data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
							data-nonce="<?php echo esc_attr( $follow_nonce ); ?>"
							data-wp-on--click="actions.toggleFollow"
						>
							<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
						</button>

						<?php if ( 'accepted' === $bn_conn_status ) : ?>
							<a
								href="<?php echo esc_url( $messages_url ); ?>"
								class="bn-btn-message"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
							>&#128172;</a>
						<?php elseif ( 'pending' === $bn_conn_status ) : ?>
							<button
								type="button"
								class="bn-btn-connect"
								disabled
								aria-disabled="true"
							>
								<?php esc_html_e( 'Pending', 'buddynext' ); ?>
							</button>
						<?php elseif ( ! $is_following ) : ?>
							<button
								type="button"
								class="bn-btn-connect"
								data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'bn_connect_' . $member_id ) ); ?>"
								data-wp-on--click="actions.sendConnection"
							>
								<?php esc_html_e( 'Connect', 'buddynext' ); ?>
							</button>
						<?php else : ?>
							<a
								href="<?php echo esc_url( $messages_url ); ?>"
								class="bn-btn-message"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
							>&#128172;</a>
						<?php endif; ?>
					<?php endif; ?>
				</div>

			</article>
		<?php endforeach; ?>

	<?php endif; ?>

</div>

<?php if ( $total_pages > 1 ) : ?>
<nav class="bn-pagination" aria-label="<?php esc_attr_e( 'Member directory pages', 'buddynext' ); ?>">

	<a
		href="<?php echo $bn_current_page > 1 ? $bn_paged_url( $bn_current_page - 1 ) : '#'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
		class="bn-page-btn<?php echo 1 === $bn_current_page ? ' disabled' : ''; ?>"
		aria-label="<?php esc_attr_e( 'Previous page', 'buddynext' ); ?>"
		<?php echo 1 === $bn_current_page ? 'aria-disabled="true"' : ''; ?>
	>&#8592;</a>

	<?php
	$window_start = max( 1, $bn_current_page - 2 );
	$window_end   = min( $total_pages, $bn_current_page + 2 );

	if ( $window_start > 1 ) :
		?>
		<a href="<?php echo $bn_paged_url( 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="bn-page-btn">1</a>
		<?php if ( $window_start > 2 ) : ?>
			<span class="bn-page-btn" aria-hidden="true">&hellip;</span>
		<?php endif; ?>
	<?php endif; ?>

	<?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>
		<a
			href="<?php echo $bn_paged_url( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
			class="bn-page-btn<?php echo $p === $bn_current_page ? ' active' : ''; ?>"
			<?php echo $p === $bn_current_page ? 'aria-current="page"' : ''; ?>
		><?php echo esc_html( (string) $p ); ?></a>
	<?php endfor; ?>

	<?php if ( $window_end < $total_pages ) : ?>
		<?php if ( $window_end < $total_pages - 1 ) : ?>
			<span class="bn-page-btn" aria-hidden="true">&hellip;</span>
		<?php endif; ?>
		<a href="<?php echo $bn_paged_url( $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="bn-page-btn">
			<?php echo esc_html( (string) $total_pages ); ?>
		</a>
	<?php endif; ?>

	<a
		href="<?php echo $bn_current_page < $total_pages ? $bn_paged_url( $bn_current_page + 1 ) : '#'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
		class="bn-page-btn<?php echo $bn_current_page >= $total_pages ? ' disabled' : ''; ?>"
		aria-label="<?php esc_attr_e( 'Next page', 'buddynext' ); ?>"
		<?php echo $bn_current_page >= $total_pages ? 'aria-disabled="true"' : ''; ?>
	>&#8594;</a>

</nav>
<?php endif; ?>

</div>
