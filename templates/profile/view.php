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
// AvatarService hooks pre_get_avatar_data: returns custom upload or SVG initials
// data-URI — no Gravatar lookup, works offline.
$avatar_url   = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
$cover_url    = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );
$display_name = $profile_user->display_name;

$joined = gmdate( 'M Y', strtotime( $profile_user->user_registered ) );

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
$is_following        = false;
$is_connected        = false;
$connection_pending  = false;
$connection_received = false;
$is_blocked          = false;
$is_muted            = false;
$degree_badge        = '';

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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$connection_pending = ! $is_connected && (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
			$current_user_id,
			$user_id
		)
	);

	// Viewer received a request from this profile user (pending-received direction).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$connection_received = ! $is_connected && ! $connection_pending && (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_connections
			WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'",
			$user_id,
			$current_user_id
		)
	);

	$degree_badge = $is_connected ? '1st' : ( $is_following ? '2nd' : '3rd+' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_blocked = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_blocks
			 WHERE blocker_id = %d AND blocked_id = %d AND type = 'block'
			 LIMIT 1",
			$current_user_id,
			$user_id
		)
	);

	$is_muted = (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bn_blocks
			 WHERE blocker_id = %d AND blocked_id = %d AND type = 'mute'
			 LIMIT 1",
			$current_user_id,
			$user_id
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// --- Mutual connections count ---------------------------------------------
$mutual_count = 0;
if ( ! $is_own_profile && $current_user_id ) {
	$mutual_count = count( buddynext_service( 'connections' )->mutual_connections( $current_user_id, $user_id ) );
}

// --- Member type badge ---------------------------------------------------
$member_type = buddynext_service( 'member_types' )->get_user_type( $user_id );

// --- Profile data via ProfileService -------------------------------------
$profile_svc  = buddynext_service( 'profiles' );
$profile_data = $profile_svc->get_profile( $user_id, $current_user_id );

// Build group_key → group data lookup.
$group_data = array();
if ( is_array( $profile_data ) ) {
	foreach ( $profile_data['groups'] as $group ) {
		$group_data[ $group['group_key'] ] = $group;
	}
}

// Helper: get a single field value from a flat group.
$get_fv = static function ( string $group_key, string $field_key ) use ( $group_data ): string {
	if ( ! isset( $group_data[ $group_key ]['fields'] ) ) {
		return '';
	}
	foreach ( $group_data[ $group_key ]['fields'] as $field ) {
		if ( $field['field_key'] === $field_key ) {
			return (string) ( $field['value'] ?? '' );
		}
	}
	return '';
};

// Helper: get a field value from a repeater entry array by field_key.
$entry_fv = static function ( array $entry_fields, string $fkey ): string {
	foreach ( $entry_fields as $f ) {
		if ( $f['field_key'] === $fkey ) {
			return (string) ( $f['value'] ?? '' );
		}
	}
	return '';
};

$headline = $get_fv( 'basic_info', 'headline' );
$bio      = $get_fv( 'basic_info', 'bio' );
$location = $get_fv( 'basic_info', 'location' );
$website  = $get_fv( 'basic_info', 'website' );
$pronouns = $get_fv( 'basic_info', 'pronouns' );

// Social links: only show fields that have a value.
$social_link_fields = isset( $group_data['social_links']['fields'] ) ? $group_data['social_links']['fields'] : array();
$social_links       = array_filter(
	$social_link_fields,
	static fn( array $f ): bool => '' !== (string) ( $f['value'] ?? '' )
);

// Repeater groups — filter out blank entries so widget guards work correctly.
$work_entries = array_values(
	array_filter(
		isset( $group_data['work_experience']['entries'] ) ? $group_data['work_experience']['entries'] : array(),
		static function ( array $e ) use ( $entry_fv ): bool {
			return '' !== $entry_fv( $e, 'work_company' ) || '' !== $entry_fv( $e, 'work_title' );
		}
	)
);
$edu_entries  = array_values(
	array_filter(
		isset( $group_data['education']['entries'] ) ? $group_data['education']['entries'] : array(),
		static function ( array $e ) use ( $entry_fv ): bool {
			return '' !== $entry_fv( $e, 'edu_institution' ) || '' !== $entry_fv( $e, 'edu_degree' );
		}
	)
);

// Profile URL slug (safe — never exposes WP login).
$profile_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
if ( '' === $profile_slug ) {
	// Fall back to user_nicename (already URL-safe, matches PageRouter::profile_url()).
	$profile_slug = $profile_user instanceof WP_User ? $profile_user->user_nicename : 'user-' . $user_id;
}

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

// --- Interests — stored as comma-separated in the skills group ------------
$interests_raw = $get_fv( 'skills', 'interests' );
$interests     = array_filter( array_map( 'trim', explode( ',', $interests_raw ) ) );

// --- Profile completion (only fetched for profile owner) ------------------
$completion = null;
if ( $is_own_profile ) {
	$completion = $profile_svc->get_completion_score( $user_id );
}

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
<?php
$bn_nav_active = '';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
if ( $is_own_profile || current_user_can( 'edit_users' ) ) {
	include __DIR__ . '/../partials/profile-actions.php';
}
?>
<style>
/* ── Design tokens ─────────────────────────────────────────────────────── */
:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
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
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 32px;
	font-weight: 700;
	color: #fff;
	font-family: var(--font-body);
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

.bn-more-menu-wrap {
	position: relative;
	display: inline-block;
}
.bn-more-menu {
	display: none;
	position: absolute;
	right: 0;
	top: calc(100% + var(--s1));
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--r-md);
	box-shadow: 0 4px 16px rgba(0,0,0,.10);
	min-width: 160px;
	z-index: 100;
	overflow: hidden;
}
.bn-more-menu-wrap.is-open .bn-more-menu { display: block; }
.bn-more-menu-item {
	display: block;
	width: 100%;
	text-align: left;
	background: none;
	border: none;
	padding: var(--s3) var(--s4);
	font-size: var(--text-sm);
	color: var(--text-1);
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-more-menu-item:hover { background: var(--bg-hover); }
.bn-more-menu-item--danger { color: var(--red); }
.bn-more-menu-item--danger:hover { background: var(--red-bg); }
[data-theme="dark"] .bn-more-menu {
	box-shadow: 0 4px 16px rgba(0,0,0,.35);
}

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

.bn-profile-type-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: var(--r-full, 9999px);
	font-size: var(--text-xs);
	font-weight: 700;
	letter-spacing: 0.02em;
	line-height: 1.6;
	margin-top: var(--s2);
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

/* Repeater entries in sidebar */
.bn-repeater-entry {
	margin-bottom: var(--s4);
	padding-bottom: var(--s4);
	border-bottom: 1px solid var(--border-soft);
}
.bn-repeater-entry:last-child {
	margin-bottom: 0;
	padding-bottom: 0;
	border-bottom: none;
}
.bn-entry-title {
	font-weight: 600;
	font-size: var(--text-sm);
	color: var(--text-1);
	margin-bottom: 2px;
}
.bn-entry-sub {
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-bottom: 2px;
}
.bn-entry-meta {
	font-size: var(--text-xs);
	color: var(--text-3);
	margin-bottom: 4px;
}
.bn-entry-desc {
	font-size: var(--text-xs);
	color: var(--text-2);
	line-height: 1.5;
	margin-top: var(--s1);
}

/* Completion bar widget */
.bn-completion-bar-wrap {
	margin-bottom: var(--s3);
}
.bn-completion-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--s2);
}
.bn-completion-label {
	font-size: var(--text-xs);
	font-weight: 600;
	color: var(--text-1);
}
.bn-completion-pct {
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--brand);
}
.bn-completion-track {
	background: var(--bg-hover);
	border-radius: var(--r-full, 9999px);
	height: 7px;
	overflow: hidden;
}
.bn-completion-fill {
	background: var(--brand);
	height: 100%;
	border-radius: var(--r-full, 9999px);
	transition: width 0.4s ease;
}
.bn-completion-fill.bn-complete { background: var(--green); }

/* Prompt cards */
.bn-prompt-cards {
	margin-top: var(--s3);
	display: flex;
	flex-direction: column;
	gap: var(--s2);
}
.bn-prompt-card {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) var(--s3);
	border-radius: var(--r-md, 8px);
	background: var(--brand-light);
	font-size: var(--text-xs);
	color: var(--brand);
	font-weight: 500;
	text-decoration: none;
	border: 1px solid transparent;
}
.bn-prompt-card:hover {
	background: var(--bg-hover);
	border-color: var(--border);
	color: var(--text-1);
}
.bn-prompt-card-icon {
	font-size: 15px;
	flex-shrink: 0;
}

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
			'userId'             => $user_id,
			'profileUserId'      => $user_id,
			'activeTab'          => 'posts',
			'isFollowing'        => $is_following,
			'isConnected'        => $is_connected,
			'connectionPending'  => $connection_pending,
			'connectionReceived' => $connection_received,
			'showConnect'        => ! $is_connected && ! $connection_pending && ! $connection_received,
			'followerCount'      => $follower_count,
			'restNonce'          => wp_create_nonce( 'wp_rest' ),
			'isBlocked'          => $is_blocked,
			'isMuted'            => $is_muted,
			'moreMenuOpen'       => false,
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
>

	<!-- Cover photo -->
	<div class="bn-cover"
	<?php
	if ( '' !== $cover_url ) :
		?>
		style="background-image:url('<?php echo esc_url( $cover_url ); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
		<?php if ( $is_own_profile ) : ?>
		<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
			class="bn-cover-edit" title="<?php esc_attr_e( 'Edit cover photo', 'buddynext' ); ?>">
			<?php buddynext_icon( 'edit' ); ?> <?php esc_html_e( 'Edit cover', 'buddynext' ); ?>
		</a>
		<?php endif; ?>
	</div>

	<!-- Profile header -->
	<div class="bn-profile-head">

		<!-- Action buttons (top-right) — shown for other users only; owners use the action bar above -->
		<div class="bn-profile-actions">
			<?php if ( ! $is_own_profile && $current_user_id ) : ?>
				<!-- Follow button: visible when NOT following; hidden reactively by Interactivity API -->
				<button class="bn-btn-primary"
					data-wp-on--click="actions.follow"
					data-wp-bind--hidden="context.isFollowing"
					<?php echo $is_following ? 'hidden' : ''; ?>>
					+ <?php esc_html_e( 'Follow', 'buddynext' ); ?>
				</button>
				<!-- Unfollow button: visible when following -->
				<button class="bn-btn-secondary"
					data-wp-on--click="actions.unfollow"
					data-wp-bind--hidden="!context.isFollowing"
					<?php echo $is_following ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Following', 'buddynext' ); ?>
				</button>

				<!-- Connect / Pending / Connected / Accept+Decline states -->
				<button class="bn-btn-secondary"
					data-wp-on--click="actions.connect"
					data-wp-bind--hidden="!context.showConnect"
					<?php echo ( $is_connected || $connection_pending || $connection_received ) ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Connect', 'buddynext' ); ?>
				</button>
				<button class="bn-btn-secondary"
					data-wp-on--click="actions.withdrawRequest"
					data-wp-bind--hidden="!context.connectionPending"
					<?php echo $connection_pending ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Pending', 'buddynext' ); ?>
				</button>
				<span class="bn-connect-received-wrap"
					data-wp-bind--hidden="!context.connectionReceived"
					<?php echo $connection_received ? '' : 'hidden'; ?>>
					<button class="bn-btn-secondary bn-accept"
						data-wp-on--click="actions.acceptRequest">
						<?php esc_html_e( 'Accept', 'buddynext' ); ?>
					</button>
					<button class="bn-btn-secondary bn-decline"
						data-wp-on--click="actions.declineRequest">
						<?php esc_html_e( 'Decline', 'buddynext' ); ?>
					</button>
				</span>
				<button class="bn-btn-secondary"
					data-wp-on--click="actions.disconnectUser"
					data-wp-bind--hidden="!context.isConnected"
					<?php echo $is_connected ? '' : 'hidden'; ?>>
					<?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Connected', 'buddynext' ); ?>
				</button>

				<a href="<?php echo esc_url( add_query_arg( 'with', $user_id, \BuddyNext\Core\PageRouter::messages_url() ) ); ?>"
					class="bn-btn-secondary">
					<?php buddynext_icon( 'message-circle' ); ?> <?php esc_html_e( 'Message', 'buddynext' ); ?>
				</a>
				<!-- More options dropdown -->
				<div class="bn-more-menu-wrap" data-wp-class--is-open="context.moreMenuOpen">
					<button class="bn-btn-icon"
						aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>"
						aria-expanded="false"
						data-wp-on--click="actions.toggleMoreMenu"
						data-wp-bind--aria-expanded="context.moreMenuOpen"><?php buddynext_icon( 'more-horizontal' ); ?></button>
					<div class="bn-more-menu" role="menu">
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.toggleMute"
							data-wp-text="state.muteLabel">
							<?php esc_html_e( 'Mute', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item bn-more-menu-item--danger"
							role="menuitem"
							data-wp-on--click="actions.toggleBlock"
							data-wp-text="state.blockLabel">
							<?php esc_html_e( 'Block', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.reportUser">
							<?php esc_html_e( 'Report', 'buddynext' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Avatar -->
		<div class="bn-avatar-wrap">
			<div class="bn-avatar-lg">
				<img src="<?php echo esc_attr( $avatar_url ); ?>"
					alt="<?php echo esc_attr( $display_name ); ?>"
					width="96"
					height="96"
					loading="eager"
					decoding="async"
					style="width:100%;height:100%;object-fit:cover;border-radius:50%;"
				/>
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

		<?php if ( $member_type ) : ?>
			<div>
				<span
					class="bn-profile-type-badge"
					style="background:<?php echo esc_attr( $member_type['color'] ); ?>;color:<?php echo esc_attr( $member_type['text_color'] ); ?>;"
				><?php echo esc_html( $member_type['name'] ); ?></span>
			</div>
		<?php endif; ?>

		<!-- Handle & headline -->
		<div class="bn-profile-handle">
			@<?php echo esc_html( '' !== $profile_slug ? $profile_slug : 'user-' . $user_id ); ?>
			<?php if ( $pronouns ) : ?>
				&nbsp;(<?php echo esc_html( $pronouns ); ?>)
			<?php endif; ?>
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
					<span><?php buddynext_icon( 'at-sign' ); ?></span>
					<?php echo esc_html( $location ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $website ) : ?>
				<div class="bn-meta-item">
					<span><?php buddynext_icon( 'link' ); ?></span>
					<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						$parsed_host = wp_parse_url( $website, PHP_URL_HOST );
						echo esc_html( $parsed_host ? $parsed_host : $website );
						?>
					</a>
				</div>
			<?php endif; ?>
			<div class="bn-meta-item">
				<span><?php buddynext_icon( 'calendar' ); ?></span>
				<?php
				/* translators: %s: month and year the member joined */
				echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), $joined ) );
				?>
			</div>
			<?php if ( $mutual_count > 0 ) : ?>
				<div class="bn-meta-item">
					<span><?php buddynext_icon( 'users' ); ?></span>
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
				<div class="bn-stat-num" data-wp-text="context.followerCount"><?php echo esc_html( $format_count( $follower_count ) ); ?></div>
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
			<?php
			/**
			 * Extra stat blocks injected by bridge plugins (e.g. Jetonomy discussion count,
			 * WBGamification points). Each entry must be an array with 'label' and 'value' keys.
			 *
			 * @param array[] $extra           Array of ['label' => string, 'value' => string|int].
			 * @param int     $user_id         ID of the profile being viewed.
			 */
			$bn_extra_stats = apply_filters( 'buddynext_profile_extra_data', array(), (int) $user_id );
			foreach ( $bn_extra_stats as $bn_extra_stat ) :
				if ( empty( $bn_extra_stat['label'] ) || ! isset( $bn_extra_stat['value'] ) ) {
					continue;
				}
				?>
				<div class="bn-stat">
					<div class="bn-stat-num"><?php echo esc_html( (string) $bn_extra_stat['value'] ); ?></div>
					<div class="bn-stat-lbl"><?php echo esc_html( $bn_extra_stat['label'] ); ?></div>
				</div>
			<?php endforeach; ?>
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
							<span><?php buddynext_icon( 'heart' ); ?> <?php echo esc_html( (string) $post_row->reaction_count ); ?></span>
							<span><?php buddynext_icon( 'message-circle' ); ?> <?php echo esc_html( (string) $post_row->comment_count ); ?></span>
							<span><?php buddynext_icon( 'share' ); ?> <?php echo esc_html( (string) $post_row->share_count ); ?></span>
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

			<?php if ( $is_own_profile && null !== $completion ) : ?>
				<?php
				$c_pct      = (int) $completion['percent'];
				$c_complete = 100 === $c_pct;
				$edit_url   = esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() );
				?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Profile Strength', 'buddynext' ); ?></div>
				<div class="bn-completion-bar-wrap">
					<div class="bn-completion-header">
						<span class="bn-completion-label">
							<?php
							echo $c_complete
								? esc_html__( 'Complete!', 'buddynext' )
								: esc_html__( 'Profile completion', 'buddynext' );
							?>
						</span>
						<span class="bn-completion-pct"><?php echo esc_html( $c_pct . '%' ); ?></span>
					</div>
					<div class="bn-completion-track">
						<div class="bn-completion-fill<?php echo $c_complete ? ' bn-complete' : ''; ?>"
							style="width:<?php echo esc_attr( $c_pct . '%' ); ?>"></div>
					</div>
				</div>
				<?php if ( ! $c_complete ) : ?>
				<div class="bn-prompt-cards">
					<?php if ( '' === $get_fv( 'basic_info', 'bio' ) ) : ?>
					<a href="<?php echo $edit_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>" class="bn-prompt-card">
						<span class="bn-prompt-card-icon"><?php buddynext_icon( 'edit' ); ?></span>
						<?php esc_html_e( 'Add a bio', 'buddynext' ); ?>
					</a>
					<?php endif; ?>
					<?php if ( empty( $work_entries ) ) : ?>
					<a href="<?php echo $edit_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="bn-prompt-card">
						<span class="bn-prompt-card-icon"><?php buddynext_icon( 'briefcase' ); ?></span>
						<?php esc_html_e( 'Add your work experience', 'buddynext' ); ?>
					</a>
					<?php endif; ?>
					<?php if ( empty( $interests ) ) : ?>
					<a href="<?php echo $edit_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="bn-prompt-card">
						<span class="bn-prompt-card-icon"><?php buddynext_icon( 'layers' ); ?></span>
						<?php esc_html_e( 'Add your skills', 'buddynext' ); ?>
					</a>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $social_links ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Connect', 'buddynext' ); ?></div>
				<?php foreach ( $social_links as $field ) : ?>
					<div class="bn-field-row">
						<span class="bn-field-label"><?php echo esc_html( $field['label'] ); ?></span>
						<span class="bn-field-value">
							<a href="<?php echo esc_url( (string) ( $field['value'] ?? '' ) ); ?>"
								target="_blank" rel="noopener noreferrer me">
								<?php
								$parsed_host = wp_parse_url( (string) ( $field['value'] ?? '' ), PHP_URL_HOST );
								echo esc_html( $parsed_host ? $parsed_host : (string) ( $field['value'] ?? '' ) );
								?>
							</a>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( $work_entries ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Work Experience', 'buddynext' ); ?></div>
				<?php foreach ( $work_entries as $entry_fields ) : ?>
					<?php
					$we_company     = $entry_fv( $entry_fields, 'work_company' );
					$we_title       = $entry_fv( $entry_fields, 'work_title' );
					$we_location    = $entry_fv( $entry_fields, 'work_location' );
					$we_daterange   = $entry_fv( $entry_fields, 'work_daterange' );
					$we_current     = $entry_fv( $entry_fields, 'work_current' );
					$we_description = $entry_fv( $entry_fields, 'work_description' );
					if ( '' === $we_company && '' === $we_title ) {
						continue;
					}
					$we_date_display = '' !== $we_daterange
						? ( '1' === $we_current
							? $we_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
							: $we_daterange )
						: ( '1' === $we_current ? esc_html__( 'Current', 'buddynext' ) : '' );
					?>
					<div class="bn-repeater-entry">
						<?php if ( $we_title ) : ?>
							<div class="bn-entry-title"><?php echo esc_html( $we_title ); ?></div>
						<?php endif; ?>
						<?php if ( $we_company ) : ?>
							<div class="bn-entry-sub"><?php echo esc_html( $we_company ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $we_location ) : ?>
							<div class="bn-entry-sub"><?php echo esc_html( $we_location ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $we_date_display ) : ?>
							<div class="bn-entry-meta"><?php echo wp_kses( $we_date_display, array() ); ?></div>
						<?php endif; ?>
						<?php if ( $we_description ) : ?>
							<div class="bn-entry-desc"><?php echo wp_kses_post( $we_description ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( $edu_entries ) : ?>
			<div class="bn-widget">
				<div class="bn-widget-title"><?php esc_html_e( 'Education', 'buddynext' ); ?></div>
				<?php foreach ( $edu_entries as $entry_fields ) : ?>
					<?php
					$edu_institution = $entry_fv( $entry_fields, 'edu_institution' );
					$edu_degree      = $entry_fv( $entry_fields, 'edu_degree' );
					$edu_field_study = $entry_fv( $entry_fields, 'edu_field' );
					$edu_daterange   = $entry_fv( $entry_fields, 'edu_daterange' );
					$edu_current     = $entry_fv( $entry_fields, 'edu_current' );
					if ( '' === $edu_institution ) {
						continue;
					}
					$edu_degree_line  = implode( ', ', array_filter( array( $edu_degree, $edu_field_study ) ) );
					$edu_date_display = '' !== $edu_daterange
						? ( '1' === $edu_current
							? $edu_daterange . ' &ndash; ' . esc_html__( 'Present', 'buddynext' )
							: $edu_daterange )
						: ( '1' === $edu_current ? esc_html__( 'Current', 'buddynext' ) : '' );
					?>
					<div class="bn-repeater-entry">
						<div class="bn-entry-title"><?php echo esc_html( $edu_institution ); ?></div>
						<?php if ( $edu_degree_line ) : ?>
							<div class="bn-entry-sub"><?php echo esc_html( $edu_degree_line ); ?></div>
						<?php endif; ?>
						<?php if ( '' !== $edu_date_display ) : ?>
							<div class="bn-entry-meta"><?php echo wp_kses( $edu_date_display, array() ); ?></div>
						<?php endif; ?>
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
							<?php buddynext_icon( 'home' ); ?>
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
