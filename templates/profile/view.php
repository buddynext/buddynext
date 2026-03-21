<?php
/**
 * BuddyNext — User Profile View template.
 *
 * Context variables expected (set by the shortcode/block before include):
 *   $user_id  int  The ID of the profile being viewed.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

// Guard: require a valid user ID.
if ( empty( $user_id ) || (int) $user_id <= 0 ) {
	return;
}

$profile_user = get_userdata( $user_id );
if ( ! $profile_user ) {
	return;
}

global $wpdb;

$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

if ( ! $is_own_profile && ! current_user_can( 'manage_options' ) ) {
	$privacy_svc = buddynext_service( 'privacy' );
	if ( ! $privacy_svc->can_view_profile( $current_user_id, $user_id ) ) {
		?>
		<div class="bn-profile-private">
			<p><?php esc_html_e( 'This profile is private.', 'buddynext' ); ?></p>
		</div>
		<?php
		return;
	}
}

// --- Avatar & display name ------------------------------------------------
$avatar_url        = get_avatar_url( $user_id, array( 'size' => 96 ) );
$display_name      = $profile_user->display_name;
$profile_login     = $profile_user->user_login;
$profile_email_raw = $profile_user->user_email;

// Initials fallback for generated avatar.
$name_parts = explode( ' ', $display_name );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
	$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
$initials = $initials ? $initials : mb_strtoupper( mb_substr( $profile_login, 0, 2 ) );

// --- Profile meta from user meta ------------------------------------------
$headline = (string) get_user_meta( $user_id, 'bn_headline', true );
$bio      = (string) get_user_meta( $user_id, 'bn_bio', true );
$location = (string) get_user_meta( $user_id, 'bn_location', true );
$website  = (string) get_user_meta( $user_id, 'bn_website', true );
$joined   = gmdate( 'M Y', strtotime( $profile_user->user_registered ) );

// --- Stats ----------------------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$follower_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$following_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$connection_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections
		WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'",
		$user_id,
		$user_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$post_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
		$user_id
	)
);

// --- Social graph state (viewer vs. this profile) -------------------------
$is_following = false;
$is_connected = false;
$is_blocked   = false;
$degree_badge = '';

if ( ! $is_own_profile && $current_user_id ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_following = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_follows
			WHERE follower_id = %d AND following_id = %d",
			$current_user_id,
			$user_id
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_connected = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE ( ( requester_id = %d AND recipient_id = %d )
			     OR ( requester_id = %d AND recipient_id = %d ) )
			AND status = 'accepted'",
			$current_user_id,
			$user_id,
			$user_id,
			$current_user_id
		)
	);

	$degree_badge = $is_connected ? '1st' : ( $is_following ? '2nd' : '3rd+' );
}

// --- Mutual connections count ---------------------------------------------
$mutual_count = 0;
if ( ! $is_own_profile && $current_user_id ) {
	$mutual_count = count( buddynext_service( 'connections' )->mutual_connections( $current_user_id, $user_id ) );
}

// --- Custom profile fields ------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$profile_fields = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT f.id, f.label, f.type, v.value
		FROM {$wpdb->prefix}bn_profile_fields f
		INNER JOIN {$wpdb->prefix}bn_profile_values v
		  ON v.field_id = f.id AND v.user_id = %d
		ORDER BY f.sort_order ASC",
		$user_id
	)
);

// --- Recent posts (tab: Posts default) ------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_posts = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, content, reaction_count, comment_count, share_count, created_at
		FROM {$wpdb->prefix}bn_posts
		WHERE user_id = %d AND status = 'published'
		ORDER BY created_at DESC
		LIMIT 10",
		$user_id
	)
);

// --- Spaces the user is a member of ---------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$member_spaces = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT s.id, s.name, sm.role
		FROM {$wpdb->prefix}bn_spaces s
		INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id
		WHERE sm.user_id = %d AND sm.status = 'active'
		ORDER BY sm.joined_at DESC
		LIMIT 5",
		$user_id
	)
);

// --- Interests (from profile field id 'interests' or user meta) -----------
$interests_raw = (string) get_user_meta( $user_id, 'bn_interests', true );
$interests     = array_filter( array_map( 'trim', explode( ',', $interests_raw ) ) );

// --- Online indicator -----------------------------------------------------
$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
$is_online   = $last_active && ( time() - $last_active ) < 300;

// --- Number formatter helper ----------------------------------------------
$format_count = static function ( int $n ): string {
	if ( $n >= 1000 ) {
		return round( $n / 1000, 1 ) . 'k';
	}
	return (string) $n;
};
?>
<style>
/* ── Design tokens ─────────────────────────────────────────────────────── */
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

/* ── Profile page ────────────────────────────────────────────────────── */
.bn-profile-wrap {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	-webkit-font-smoothing: antialiased;
}

/* Cover */
.bn-cover {
	height: 200px;
	background: linear-gradient(135deg, #1d4ed8 0%, #7c3aed 50%, #db2777 100%);
	position: relative;
}
.bn-cover-edit {
	position: absolute;
	bottom: var(--s3);
	right: var(--s3);
	background: rgba(0,0,0,0.52);
	color: #fff;
	padding: 6px var(--s3);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 5px;
	border: none;
}
.bn-cover-edit:hover { background: rgba(0,0,0,0.72); color: #fff; }

/* Profile head */
.bn-profile-head {
	background: var(--surface);
	border-bottom: 1px solid var(--border);
	padding: 0 var(--s8) var(--s5);
	position: relative;
}

.bn-avatar-wrap {
	position: relative;
	display: inline-block;
	margin-top: -48px;
	margin-bottom: var(--s3);
}
.bn-avatar-lg {
	width: 96px;
	height: 96px;
	border-radius: 50%;
	background: var(--brand);
	color: #fff;
	font-family: var(--font-display);
	font-size: 28px;
	font-weight: 800;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 4px solid var(--bg);
	box-shadow: 0 2px 8px rgba(0,0,0,0.15);
	overflow: hidden;
}
.bn-avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
.bn-avatar-online {
	position: absolute;
	bottom: 6px;
	right: 6px;
	width: 18px;
	height: 18px;
	background: var(--green);
	border-radius: 50%;
	border: 2px solid var(--bg);
}

/* Actions row */
.bn-profile-actions {
	position: absolute;
	top: var(--s5);
	right: var(--s8);
	display: flex;
	gap: var(--s2);
	flex-wrap: wrap;
}
.bn-btn-primary {
	background: var(--brand);
	color: #fff;
	padding: 8px var(--s5);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 5px;
}
.bn-btn-primary:hover { background: var(--brand-hover); color: #fff; }
.bn-btn-secondary {
	background: var(--surface);
	color: var(--text-1);
	padding: 8px var(--s4);
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	text-decoration: none;
	display: inline-flex;
	align-items: center;
	gap: 5px;
}
.bn-btn-secondary:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-btn-icon {
	background: var(--surface);
	color: var(--text-2);
	padding: 8px 10px;
	border-radius: 20px;
	font-size: var(--text-base);
	cursor: pointer;
	border: 1.5px solid var(--border);
	font-family: var(--font-body);
	line-height: 1;
}
.bn-btn-icon:hover { background: var(--bg-hover); }

.bn-degree-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px var(--s2);
	border-radius: 10px;
	font-size: var(--text-xs);
	font-weight: 700;
	background: var(--brand-light);
	color: var(--brand);
	margin-left: var(--s2);
	vertical-align: middle;
}

.bn-profile-name {
	font-family: var(--font-display);
	font-size: 22px;
	font-weight: 800;
	color: var(--text-1);
	margin-bottom: 3px;
	line-height: 1.25;
}
.bn-profile-handle {
	color: var(--text-2);
	font-size: var(--text-sm);
	margin-bottom: var(--s2);
}
.bn-profile-bio {
	color: var(--text-1);
	font-size: var(--text-sm);
	line-height: 1.65;
	max-width: 540px;
	margin-bottom: var(--s3);
}

.bn-profile-meta {
	display: flex;
	gap: var(--s4);
	flex-wrap: wrap;
	margin-bottom: var(--s4);
}
.bn-meta-item {
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: var(--text-xs);
	color: var(--text-2);
}
.bn-meta-item a { color: var(--brand); text-decoration: none; }
.bn-meta-item a:hover { text-decoration: underline; }

.bn-stats-row {
	display: flex;
	gap: var(--s5);
	flex-wrap: wrap;
}
.bn-stat { text-align: center; cursor: pointer; }
.bn-stat:hover .bn-stat-num { color: var(--brand); }
.bn-stat-num {
	font-family: var(--font-display);
	font-size: 18px;
	font-weight: 800;
	color: var(--text-1);
	line-height: 1.2;
}
.bn-stat-lbl {
	font-size: var(--text-xs);
	color: var(--text-2);
}

/* Main layout */
.bn-profile-layout {
	max-width: 960px;
	margin: 0 auto;
	padding: var(--s6) var(--s5);
	display: grid;
	grid-template-columns: 1fr 280px;
	gap: var(--s6);
	align-items: start;
}

/* Profile tabs */
.bn-profile-tabs {
	display: flex;
	background: var(--surface);
	border-radius: var(--radius);
	border: 1px solid var(--border);
	overflow: hidden;
	margin-bottom: var(--s4);
}
.bn-ptab {
	flex: 1;
	text-align: center;
	padding: 11px;
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-right: 1px solid var(--border);
	transition: background 0.15s, color 0.15s;
}
.bn-ptab:last-child { border-right: none; }
.bn-ptab.active,
.bn-ptab[aria-selected="true"] {
	background: var(--brand);
	color: #fff;
	font-weight: 600;
}
.bn-ptab:not(.active):hover { background: var(--bg-hover); color: var(--text-1); }

/* Post card */
.bn-post-card {
	background: var(--surface);
	border-radius: var(--radius);
	border: 1px solid var(--border);
	margin-bottom: var(--s3);
	padding: var(--s4);
}
.bn-post-text {
	font-size: var(--text-sm);
	color: var(--text-1);
	line-height: 1.65;
	margin-bottom: var(--s2);
}
.bn-hashtag { color: var(--brand); }
.bn-post-stats {
	display: flex;
	gap: var(--s3);
	font-size: var(--text-xs);
	color: var(--text-2);
	padding-top: var(--s2);
	border-top: 1px solid var(--border-soft);
	align-items: center;
}
.bn-post-age { margin-left: auto; color: var(--text-3); }

/* Empty state */
.bn-empty-state {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s8);
	text-align: center;
	color: var(--text-3);
	font-size: var(--text-sm);
}

/* Sidebar widgets */
.bn-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s4);
	margin-bottom: var(--s4);
}
.bn-widget-title {
	font-weight: 700;
	font-size: var(--text-sm);
	margin-bottom: var(--s3);
	color: var(--text-1);
}
.bn-field-row {
	display: flex;
	gap: var(--s2);
	margin-bottom: var(--s2);
	font-size: var(--text-xs);
}
.bn-field-label { color: var(--text-2); font-weight: 500; min-width: 90px; flex-shrink: 0; }
.bn-field-value { color: var(--text-1); word-break: break-word; }
.bn-field-value a { color: var(--brand); text-decoration: none; }
.bn-field-value a:hover { text-decoration: underline; }

.bn-skill-chips { display: flex; flex-wrap: wrap; gap: var(--s1); }
.bn-skill-chip {
	background: var(--brand-light);
	color: var(--brand);
	border-radius: 12px;
	padding: 3px 10px;
	font-size: var(--text-xs);
	font-weight: 600;
}

.bn-space-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	margin-bottom: var(--s2);
}
.bn-space-icon {
	width: 32px;
	height: 32px;
	border-radius: var(--radius-sm);
	background: var(--brand-light);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 15px;
	flex-shrink: 0;
}
.bn-space-name { font-weight: 600; font-size: var(--text-xs); }
.bn-space-role { font-size: 11px; color: var(--text-2); }

/* ── Mobile ── */
@media (max-width: 640px) {
	.bn-cover { height: 140px; }
	.bn-profile-head { padding: 0 var(--s4) var(--s4); }
	.bn-profile-actions {
	position: static;
	margin-top: var(--s3);
	flex-wrap: wrap;
	justify-content: flex-start;
	}
	.bn-profile-layout {
	grid-template-columns: 1fr;
	padding: var(--s4);
	gap: var(--s4);
	}
	.bn-profile-tabs { font-size: var(--text-xs); }
	.bn-stats-row { gap: var(--s4); }
	.bn-avatar-lg { width: 72px; height: 72px; font-size: 22px; }
	.bn-avatar-wrap { margin-top: -36px; }
}
</style>

<div class="bn-profile-wrap"
	data-wp-interactive="buddynext/profile"
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_interactivity_data_wp_context(
		array(
			'userId'      => $user_id,
			'activeTab'   => 'posts',
			'isFollowing' => $is_following,
			'isConnected' => $is_connected,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<!-- Cover photo -->
	<div class="bn-cover">
		<?php if ( $is_own_profile ) : ?>
		<a href="<?php echo esc_url( get_edit_profile_url( $user_id ) ); ?>"
			class="bn-cover-edit" title="<?php esc_attr_e( 'Edit cover photo', 'buddynext' ); ?>">
			&#9998; <?php esc_html_e( 'Edit cover', 'buddynext' ); ?>
		</a>
		<?php endif; ?>
	</div>

	<!-- Profile header -->
	<div class="bn-profile-head">

		<!-- Action buttons (top-right) -->
		<div class="bn-profile-actions">
			<?php if ( $is_own_profile ) : ?>
				<a href="<?php echo esc_url( get_edit_profile_url( $user_id ) ); ?>"
					class="bn-btn-secondary">
					&#9998; <?php esc_html_e( 'Edit Profile', 'buddynext' ); ?>
				</a>
			<?php elseif ( $current_user_id ) : ?>
				<?php if ( $is_following ) : ?>
					<button class="bn-btn-secondary"
						data-wp-on--click="actions.unfollow"
						data-wp-text="state.followLabel">
						<?php esc_html_e( 'Following', 'buddynext' ); ?>
					</button>
				<?php else : ?>
					<button class="bn-btn-primary"
						data-wp-on--click="actions.follow">
						+ <?php esc_html_e( 'Follow', 'buddynext' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( ! $is_connected ) : ?>
					<button class="bn-btn-secondary"
						data-wp-on--click="actions.connect">
						<?php esc_html_e( 'Connect', 'buddynext' ); ?>
					</button>
				<?php endif; ?>

				<a href="<?php echo esc_url( home_url( '/messages/?with=' . $user_id ) ); ?>"
					class="bn-btn-secondary">
					&#128172; <?php esc_html_e( 'Message', 'buddynext' ); ?>
				</a>
				<button class="bn-btn-icon" aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>">&#8943;</button>
			<?php endif; ?>
		</div>

		<!-- Avatar -->
		<div class="bn-avatar-wrap">
			<div class="bn-avatar-lg">
				<?php if ( $avatar_url ) : ?>
					<img src="<?php echo esc_url( $avatar_url ); ?>"
						alt="<?php echo esc_attr( $display_name ); ?>" />
				<?php else : ?>
					<?php echo esc_html( $initials ); ?>
				<?php endif; ?>
			</div>
			<?php if ( $is_online ) : ?>
				<div class="bn-avatar-online" title="<?php esc_attr_e( 'Online', 'buddynext' ); ?>"></div>
			<?php endif; ?>
		</div>

		<!-- Name -->
		<div class="bn-profile-name">
			<?php echo esc_html( $display_name ); ?>
			<?php if ( $degree_badge ) : ?>
				<span class="bn-degree-badge"><?php echo esc_html( $degree_badge ); ?></span>
			<?php endif; ?>
		</div>

		<!-- Handle & headline -->
		<div class="bn-profile-handle">
			@<?php echo esc_html( $profile_login ); ?>
			<?php if ( $headline ) : ?>
				&nbsp;&middot;&nbsp;<?php echo esc_html( $headline ); ?>
			<?php endif; ?>
		</div>

		<!-- Bio -->
		<?php if ( $bio ) : ?>
			<div class="bn-profile-bio"><?php echo wp_kses_post( $bio ); ?></div>
		<?php endif; ?>

		<!-- Meta row -->
		<div class="bn-profile-meta">
			<?php if ( $location ) : ?>
				<div class="bn-meta-item">
					<span>&#128205;</span>
					<?php echo esc_html( $location ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $website ) : ?>
				<div class="bn-meta-item">
					<span>&#128279;</span>
					<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						$parsed_host = wp_parse_url( $website, PHP_URL_HOST );
						echo esc_html( $parsed_host ? $parsed_host : $website );
						?>
					</a>
				</div>
			<?php endif; ?>
			<div class="bn-meta-item">
				<span>&#128197;</span>
				<?php
				/* translators: %s: month and year the member joined */
				echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), $joined ) );
				?>
			</div>
			<?php if ( $mutual_count > 0 ) : ?>
				<div class="bn-meta-item">
					<span>&#128101;</span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of mutual connections */
							_n( '%d mutual connection', '%d mutual connections', $mutual_count, 'buddynext' ),
							$mutual_count
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Stats -->
		<div class="bn-stats-row">
			<div class="bn-stat">
				<div class="bn-stat-num"><?php echo esc_html( $format_count( $post_count ) ); ?></div>
				<div class="bn-stat-lbl"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat-num"><?php echo esc_html( $format_count( $follower_count ) ); ?></div>
				<div class="bn-stat-lbl"><?php esc_html_e( 'Followers', 'buddynext' ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat-num"><?php echo esc_html( $format_count( $following_count ) ); ?></div>
				<div class="bn-stat-lbl"><?php esc_html_e( 'Following', 'buddynext' ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat-num"><?php echo esc_html( $format_count( $connection_count ) ); ?></div>
				<div class="bn-stat-lbl"><?php esc_html_e( 'Connections', 'buddynext' ); ?></div>
			</div>
		</div>

	</div><!-- /bn-profile-head -->

	<!-- Main two-column layout -->
	<div class="bn-profile-layout">

		<!-- Left: posts feed -->
		<div>
			<!-- Tab bar -->
			<div class="bn-profile-tabs" role="tablist">
				<div class="bn-ptab active"
					role="tab"
					aria-selected="true"
					data-wp-on--click="actions.setTab"
					data-tab="posts">
					<?php esc_html_e( 'Posts', 'buddynext' ); ?>
				</div>
				<div class="bn-ptab"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setTab"
					data-tab="replies">
					<?php esc_html_e( 'Replies', 'buddynext' ); ?>
				</div>
				<div class="bn-ptab"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setTab"
					data-tab="media">
					<?php esc_html_e( 'Media', 'buddynext' ); ?>
				</div>
				<div class="bn-ptab"
					role="tab"
					aria-selected="false"
					data-wp-on--click="actions.setTab"
					data-tab="likes">
					<?php esc_html_e( 'Likes', 'buddynext' ); ?>
				</div>
			</div>

			<!-- Posts list -->
			<?php if ( $recent_posts ) : ?>
				<?php foreach ( $recent_posts as $post_row ) : ?>
					<div class="bn-post-card">
						<div class="bn-post-text">
							<?php echo wp_kses_post( $post_row->content ); ?>
						</div>
						<div class="bn-post-stats">
							<span>&#10084;&#65039; <?php echo esc_html( (string) $post_row->reaction_count ); ?></span>
							<span>&#128172; <?php echo esc_html( (string) $post_row->comment_count ); ?></span>
							<span>&#8599;&#65039; <?php echo esc_html( (string) $post_row->share_count ); ?></span>
							<span class="bn-post-age">
								<?php echo esc_html( human_time_diff( strtotime( $post_row->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="bn-empty-state">
					<?php
					echo esc_html(
						$is_own_profile
							? __( 'You have not posted anything yet.', 'buddynext' )
							: sprintf(
								/* translators: %s: member display name */
								__( '%s has not posted anything yet.', 'buddynext' ),
								$display_name
							)
					);
					?>
				</div>
			<?php endif; ?>
		</div><!-- /left column -->

		<!-- Right: sidebar widgets -->
		<aside>

			<?php if ( $profile_fields ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Profile Details', 'buddynext' ); ?></div>
				<?php foreach ( $profile_fields as $field ) : ?>
					<div class="bn-field-row">
						<span class="bn-field-label"><?php echo esc_html( $field->label ); ?></span>
						<span class="bn-field-value">
							<?php if ( 'url' === $field->type ) : ?>
								<a href="<?php echo esc_url( $field->value ); ?>"
									target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $field->value ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $field->value ); ?>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( $interests ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Interests', 'buddynext' ); ?></div>
				<div class="bn-skill-chips">
					<?php foreach ( $interests as $interest ) : ?>
						<span class="bn-skill-chip"><?php echo esc_html( $interest ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( $member_spaces ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Member of', 'buddynext' ); ?></div>
				<?php foreach ( $member_spaces as $space ) : ?>
					<div class="bn-space-row">
						<div class="bn-space-icon">
							&#127968;
						</div>
						<div>
							<div class="bn-space-name"><?php echo esc_html( $space->name ); ?></div>
							<div class="bn-space-role"><?php echo esc_html( ucfirst( (string) $space->role ) ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

		</aside>

	</div><!-- /bn-profile-layout -->

</div><!-- /bn-profile-wrap -->
