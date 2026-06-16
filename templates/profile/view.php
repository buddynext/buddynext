<?php
/**
 * BuddyNext — User Profile View template.
 *
 * Thin composer: resolves the data, gates on permissions, hooks the
 * right-sidebar widgets, then delegates the on-page markup to four
 * template parts (`parts/profile-hero.php`, `parts/profile-stats-strip.php`,
 * `parts/profile-tab-bar.php`, `parts/profile-tab-panel.php`).
 *
 * Context variables expected (set by PageRouter before include):
 *   $user_id  int  The ID of the profile being viewed.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

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

if ( ! $is_own_profile && ! current_user_can( 'manage_options' )
	&& ! buddynext_service( 'privacy' )->can_view_profile( $current_user_id, $user_id )
) {
	?>
	<div class="bn-profile-private"><p><?php esc_html_e( 'This profile is private.', 'buddynext' ); ?></p></div>
	<?php
	return;
}

// --- Identity + counts ----------------------------------------------------
$avatar_url   = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
$cover_url    = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );
$display_name = $profile_user->display_name;
$joined       = gmdate( 'M Y', strtotime( $profile_user->user_registered ) );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$follower_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d", $user_id ) );
$following_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d", $user_id ) );
$connection_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'", $user_id, $user_id ) );
$post_count       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'", $user_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// --- Social-graph member lists for the in-page Followers / Following /
// Connections tabs (rendered inside the same profile shell, not as separate
// bare pages). Capped for the panel; the count chip shows the true total.
$bn_pf_ids_to_users   = static function ( array $ids ): array {
	return array_values( array_filter( array_map( static fn( $id ) => get_userdata( (int) $id ), $ids ) ) );
};
$bn_follow_svc        = buddynext_service( 'follows' );
$bn_conn_svc          = buddynext_service( 'connections' );
$follower_users       = $bn_pf_ids_to_users( array_slice( $bn_follow_svc->followers( $user_id ), 0, 60 ) );
$following_users      = $bn_pf_ids_to_users( array_slice( $bn_follow_svc->following( $user_id ), 0, 60 ) );
$connection_users     = $bn_pf_ids_to_users( $bn_conn_svc->connections( $user_id, 60, 0 ) );
$pending_follow_users = $is_own_profile ? $bn_pf_ids_to_users( $bn_follow_svc->pending_followers( $user_id ) ) : array();

// --- Social graph state (viewer vs. this profile) -------------------------
$is_following        = false;
$is_connected        = false;
$connection_pending  = false;
$connection_received = false;
$is_blocked          = false;
$is_muted            = false;
$is_restricted       = false;
$degree_badge        = '';

if ( ! $is_own_profile && $current_user_id ) {
	// Relationship state (viewer → this profile). Previously seven separate
	// uncached $wpdb->get_var() round-trips; now three cache-backed service
	// calls: one for the follow edge, one connection row (direction-aware, so
	// pending-sent vs pending-received is resolved without a second query),
	// and one batched block/mute/restrict lookup. See HIGH-02 / HIGH-05.
	$is_following = buddynext_service( 'follows' )->is_following( $current_user_id, $user_id );

	$bn_conn_row        = $bn_conn_svc->pair_row( $current_user_id, $user_id );
	$bn_conn_status     = $bn_conn_row ? (string) $bn_conn_row->status : '';
	$is_connected       = 'accepted' === $bn_conn_status;
	$connection_pending = 'pending' === $bn_conn_status && (int) $bn_conn_row->requester_id === $current_user_id;
	$connection_received = 'pending' === $bn_conn_status && (int) $bn_conn_row->requester_id === $user_id;

	$bn_block_state = buddynext_service( 'blocks' )->directed_block_types( $current_user_id, $user_id );
	$is_blocked     = $bn_block_state['block'];
	$is_muted       = $bn_block_state['mute'];
	$is_restricted  = $bn_block_state['restrict'];

	// LinkedIn-style degree badge — uses the connections service so the
	// "2nd-degree" label only fires when there's an actual mutual
	// connection, not just a follow. ConnectionService::connection_degree
	// returns 1 (direct), 2 (shared mutual), or 3 (no shared mutual).
	$degree       = buddynext_service( 'connections' )->connection_degree( $current_user_id, $user_id );
	$degree_badge = 1 === $degree ? '1st' : ( 2 === $degree ? '2nd' : '3rd+' );
}

$mutual_count = ( ! $is_own_profile && $current_user_id ) ? count( buddynext_service( 'connections' )->mutual_connections( $current_user_id, $user_id ) ) : 0;
$member_type  = buddynext_service( 'member_types' )->get_user_type( $user_id );

// --- Profile field data via ProfileService -------------------------------
$profile_svc  = buddynext_service( 'profiles' );
$profile_data = $profile_svc->get_profile( $user_id, $current_user_id );

$group_data = array();
if ( is_array( $profile_data ) ) {
	foreach ( $profile_data['groups'] as $group ) {
		$group_data[ $group['group_key'] ] = $group;
	}
}

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

$social_link_fields = isset( $group_data['social_links']['fields'] ) ? $group_data['social_links']['fields'] : array();
$social_links       = array_filter( $social_link_fields, static fn( array $f ): bool => '' !== (string) ( $f['value'] ?? '' ) );

$work_entries = array_values( array_filter( isset( $group_data['work_experience']['entries'] ) ? $group_data['work_experience']['entries'] : array(), static fn( array $e ): bool => '' !== $entry_fv( $e, 'work_company' ) || '' !== $entry_fv( $e, 'work_title' ) ) );
$edu_entries  = array_values( array_filter( isset( $group_data['education']['entries'] ) ? $group_data['education']['entries'] : array(), static fn( array $e ): bool => '' !== $entry_fv( $e, 'edu_institution' ) || '' !== $entry_fv( $e, 'edu_degree' ) ) );

$profile_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
if ( '' === $profile_slug ) {
	$profile_slug = $profile_user instanceof WP_User ? $profile_user->user_nicename : 'user-' . $user_id;
}

// --- Tab-panel data sets --------------------------------------------------
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_posts = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, user_id, content, privacy, media_ids, reaction_count, comment_count, share_count, is_pinned, is_announcement, content_warning, content_warning_type, shared_post_id, link_meta, created_at FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published' ORDER BY created_at DESC LIMIT 10", $user_id ), ARRAY_A );
$user_replies = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.content, c.created_at, c.object_id, p.content AS post_content, p.type AS post_type, u.display_name AS post_author_name FROM {$wpdb->prefix}bn_comments c INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id AND c.object_type = 'post' INNER JOIN {$wpdb->users} u ON u.ID = p.user_id WHERE c.user_id = %d ORDER BY c.created_at DESC LIMIT 20", $user_id ) );
$user_likes   = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.type, p.user_id, p.content, p.privacy, p.media_ids, p.reaction_count, p.comment_count, p.share_count, p.is_pinned, p.is_announcement, p.content_warning, p.content_warning_type, p.shared_post_id, p.link_meta, p.created_at FROM {$wpdb->prefix}bn_reactions r INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id AND r.object_type = 'post' WHERE r.user_id = %d AND p.status = 'published' ORDER BY r.created_at DESC LIMIT 20", $user_id ), ARRAY_A );

// True totals for the tab-bar count chips (limited result sets above only
// surface the most-recent N rows for rendering — we want the full counts
// for the badges so the UI matches what a deep-scroll would reveal).
$reply_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE user_id = %d AND object_type = 'post'", $user_id ) );
$like_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions WHERE user_id = %d AND object_type = 'post'", $user_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Profile media gallery — resolved from WPMediaVerse at the API level (its
// media live in mvs_media_index, not wp_posts). $user_media holds ordered
// media ids; the panel renders them BN-native via MediaRenderer::gallery().
// Privacy (hide private from non-owners) is enforced inside the engine query.
$user_media  = array();
$media_count = 0;
if ( \BuddyNext\Media\MediaClient::available() ) {
	$bn_media_viewer = get_current_user_id();
	$user_media      = \BuddyNext\Media\Galleries::user_media_ids( $user_id, $bn_media_viewer, 24, 0 );
	$media_count     = \BuddyNext\Media\Galleries::user_media_count( $user_id, $bn_media_viewer );
}

$jt_discussions   = array();
$show_discussions = class_exists( 'Jetonomy\Models\Post' );
$has_jt_tab       = class_exists( 'Jetonomy\Jetonomy' );
if ( $show_discussions ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$jt_discussions = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at, s.title AS space_name, s.slug AS space_slug FROM {$wpdb->prefix}jt_posts p LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id WHERE p.author_id = %d AND p.status = 'publish' ORDER BY p.created_at DESC LIMIT 20", $user_id ) );
}

// --- Spaces, interests, completion, presence ------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$member_spaces = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.name, sm.role FROM {$wpdb->prefix}bn_spaces s INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id WHERE sm.user_id = %d AND sm.status = 'active' ORDER BY sm.joined_at DESC LIMIT 5", $user_id ) );

$interests    = array_filter( array_map( 'trim', explode( ',', $get_fv( 'skills', 'interests' ) ) ) );
$completion   = $is_own_profile ? $profile_svc->get_completion_score( $user_id ) : null;
$is_online    = buddynext_service( 'blocks' )->is_user_online( $current_user_id, $user_id );
$format_count = static fn( int $n ): string => $n >= 1000 ? round( $n / 1000, 1 ) . 'k' : (string) $n;

// --- Sidebar widget hook (partial holds the markup) -----------------------
$bn_pf_sidebar_args = compact( 'is_own_profile', 'completion', 'social_links', 'work_entries', 'edu_entries', 'interests', 'member_spaces', 'get_fv', 'entry_fv' );
add_action(
	'buddynext_right_sidebar',
	static function () use ( $bn_pf_sidebar_args ): void {
		buddynext_get_template( 'partials/profile-right-sidebar.php', $bn_pf_sidebar_args );
	}
);

/**
 * Fires before the profile main content.
 *
 * @param int $user_id Profile being viewed.
 */
do_action( 'buddynext_profile_before', (int) $user_id );

// Owner edit affordances live ON the hero itself (Edit profile + Share in the
// action cluster, Edit cover on the cover, and the Edit-avatar badge on the
// avatar) — so the previous standalone Edit Profile / Avatar / Cover toolbar
// was a redundant duplicate and has been removed.

// --- 7-day deltas for the stat-tile delta chips (v2 prototype pattern) ----
// Each delta is a count of new rows in the trailing 7 days. Rendered as
// `+N` next to the stat value when > 0. All four COUNT queries are
// index-only scans on (user_id, created_at) and run once per profile view.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$post_delta_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published' AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
// status='approved' so pending follow-requests (S2 private-account
// gate) don't bump the absolute count, keeping this delta consistent
// with FollowService::follower_count / following_count.
$follower_delta_7d   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d AND status = 'approved' AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
$following_delta_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND status = 'approved' AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
$connection_delta_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted' AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id, $user_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Format a 7-day "+N this week" growth chip. Rendered only for a genuine
// PARTIAL gain (0 < n < total): when every item is new this week (n === total,
// e.g. a brand-new account or freshly-seeded data) the chip would just repeat
// the count — "2 Followers +2" — so it is suppressed. Negative deltas (un-
// follows) carry no created_at semantic, so only positive deltas are shown.
$bn_delta_chip = static fn( int $n, int $total ): array => ( $n > 0 && $n < $total )
	? array(
		'delta' => '+' . $n,
		'trend' => 'up',
	)
	: array();

// --- Stat-strip descriptors (passed through hero → stats-strip part) ------
$bn_pf_stats = array(
	array_merge(
		array(
			'slug'        => 'posts',
			'label'       => __( 'Posts', 'buddynext' ),
			'value'       => $format_count( $post_count ),
			'wp_on_click' => 'actions.setTab',
			'data_tab'    => 'posts',
			'aria_label'  => __( 'Show posts', 'buddynext' ),
		),
		$bn_delta_chip( $post_delta_7d, $post_count )
	),
	array_merge(
		array(
			'slug'        => 'followers',
			'label'       => __( 'Followers', 'buddynext' ),
			'value'       => $format_count( $follower_count ),
			'href'        => \BuddyNext\Core\PageRouter::followers_url( (int) $user_id ),
			'wp_on_click' => 'actions.setTab',
			'data_tab'    => 'followers',
			'wp_text'     => 'context.followerCount',
		),
		$bn_delta_chip( $follower_delta_7d, $follower_count )
	),
	array_merge(
		array(
			'slug'        => 'following',
			'label'       => __( 'Following', 'buddynext' ),
			'value'       => $format_count( $following_count ),
			'href'        => \BuddyNext\Core\PageRouter::following_url( (int) $user_id ),
			'wp_on_click' => 'actions.setTab',
			'data_tab'    => 'following',
		),
		$bn_delta_chip( $following_delta_7d, $following_count )
	),
	array_merge(
		array(
			'slug'        => 'connections',
			'label'       => __( 'Connections', 'buddynext' ),
			'value'       => $format_count( $connection_count ),
			'href'        => \BuddyNext\Core\PageRouter::connections_url( (int) $user_id ),
			'wp_on_click' => 'actions.setTab',
			'data_tab'    => 'connections',
		),
		$bn_delta_chip( $connection_delta_7d, $connection_count )
	),
);

// --- Tab descriptors (filterable via buddynext_part_profile_tab_bar_args) -
// Build tab descriptors. Count chips only render when > 0 — empty tabs
// don't surface a `0` badge (matches the v2 prototype tab-counter pattern).
$bn_tab_count_for = static fn( int $n ): string => $n > 0 ? $format_count( $n ) : '';
$bn_pf_tabs       = array(
	array(
		'slug'  => 'posts',
		'label' => __( 'Posts', 'buddynext' ),
		'count' => $bn_tab_count_for( $post_count ),
	),
	array(
		'slug'  => 'replies',
		'label' => __( 'Replies', 'buddynext' ),
		'count' => $bn_tab_count_for( $reply_count ),
	),
	array(
		'slug'  => 'media',
		'label' => __( 'Media', 'buddynext' ),
		'count' => $bn_tab_count_for( $media_count ),
	),
	array(
		'slug'  => 'likes',
		'label' => __( 'Likes', 'buddynext' ),
		'count' => $bn_tab_count_for( $like_count ),
	),
	array(
		'slug'  => 'followers',
		'label' => __( 'Followers', 'buddynext' ),
		'count' => $bn_tab_count_for( $follower_count ),
	),
	array(
		'slug'  => 'following',
		'label' => __( 'Following', 'buddynext' ),
		'count' => $bn_tab_count_for( $following_count ),
	),
	array(
		'slug'  => 'connections',
		'label' => __( 'Connections', 'buddynext' ),
		'count' => $bn_tab_count_for( $connection_count ),
	),
);
if ( $has_jt_tab ) {
	// Count chip mirrors every other tab (show when > 0). The Discussions panel
	// renders the same fetched set (capped at 20), so the badge matches its body.
	$bn_pf_tabs[] = array(
		'slug'  => 'discussions',
		'label' => __( 'Discussions', 'buddynext' ),
		'count' => $bn_tab_count_for( count( $jt_discussions ) ),
	);
}
// Deep-link the active tab from the route action so /members/{slug}/media/
// opens the Media tab. Falls back to Posts for actions without a tab.
$bn_pf_action     = (string) get_query_var( 'bn_profile_action', '' );
$bn_pf_tab_slugs  = array_column( $bn_pf_tabs, 'slug' );
$bn_pf_active_tab = in_array( $bn_pf_action, $bn_pf_tab_slugs, true ) ? $bn_pf_action : 'posts';

$bn_pf_ctx = array(
	'userId'             => $user_id,
	'profileUserId'      => $user_id,
	'displayName'        => $display_name,
	'peopleUrl'          => \BuddyNext\Core\PageRouter::people_url(),
	'profileBaseUrl'     => \BuddyNext\Core\PageRouter::profile_url( (int) $user_id ),
	'activeTab'          => $bn_pf_active_tab,
	'isFollowing'        => $is_following,
	'isConnected'        => $is_connected,
	'connectionPending'  => $connection_pending,
	'connectionReceived' => $connection_received,
	'showConnect'        => ! $is_connected && ! $connection_pending && ! $connection_received,
	'followerCount'      => $follower_count,
	'restNonce'          => wp_create_nonce( 'wp_rest' ),
	'isBlocked'          => $is_blocked,
	'isMuted'            => $is_muted,
	'isRestricted'       => $is_restricted,
	'moreMenuOpen'       => false,
	'shareMenuOpen'      => false,
	'reportOpen'         => false,
	'reportReason'       => 'spam',
	'reportNotes'        => '',
	'reportSubmitting'   => false,
	'blockConfirmOpen'   => false,
	'blockSubmitting'    => false,
);
?>
<div class="bn-pf-stack" data-wp-interactive="buddynext/profile" data-wp-init="callbacks.initView"
	<?php echo wp_interactivity_data_wp_context( $bn_pf_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
>

	<?php
	buddynext_get_template(
		'parts/profile-hero.php',
		array(
			'profile_user_id'     => (int) $user_id,
			'viewer_id'           => (int) $current_user_id,
			'display_name'        => (string) $display_name,
			'username'            => (string) $profile_slug,
			'avatar_url'          => (string) $avatar_url,
			'cover_url'           => (string) $cover_url,
			'bio'                 => (string) $bio,
			'headline'            => (string) $headline,
			'pronouns'            => (string) $pronouns,
			'location'            => (string) $location,
			'website'             => (string) $website,
			'joined'              => (string) $joined,
			'mutual_count'        => (int) $mutual_count,
			'degree_badge'        => (string) $degree_badge,
			'member_type'         => is_array( $member_type ) ? $member_type : array(),
			'social_links'        => is_array( $social_links ) ? $social_links : array(),
			'is_owner'            => (bool) $is_own_profile,
			'is_online'           => (bool) $is_online,
			'is_following'        => (bool) $is_following,
			'is_connected'        => (bool) $is_connected,
			'connection_pending'  => (bool) $connection_pending,
			'connection_received' => (bool) $connection_received,
			'stats'               => $bn_pf_stats,
		)
	);

	// Buffer the about-cards + generic detail sections and move them into a
	// dedicated "About" tab below, instead of stacking this metadata above the
	// tab bar where it sat on top of EVERY tab (Posts, Replies, Media, …).
	ob_start();

	buddynext_get_template(
		'partials/profile-about-cards.php',
		array(
			'work_entries' => $work_entries,
			'edu_entries'  => $edu_entries,
			'interests'    => $interests,
			'entry_fv'     => $entry_fv,
		)
	);

	/*
	 * ── Generic profile-field renderer ─────────────────────────────────────
	 *
	 * Every admin-defined field — including custom field types the curated
	 * hero/about-cards above don't know about — is rendered here through the
	 * single field-type engine (contracts.field_type_engine), so any type
	 * displays correctly (chips for multi, <a> for url/email/tel, formatted
	 * date, swatch for color, …). ProfileService::get_profile has ALREADY
	 * applied per-field visibility for the viewer, so anything present here is
	 * something this viewer is allowed to see — no extra gating needed.
	 *
	 * Keys/groups the hero + about-cards already surface prominently are
	 * skipped to avoid visible duplication; everything else renders below.
	 */
	$bn_pf_hero_keys   = array( 'headline', 'bio', 'pronouns', 'location', 'website' );
	$bn_pf_skip_groups = array( 'work_experience', 'education', 'social_links' );

	$bn_pf_detail_sections = array();
	foreach ( (array) ( $profile_data['groups'] ?? array() ) as $bn_pf_group ) {
		$bn_pf_gkey  = isset( $bn_pf_group['group_key'] ) ? (string) $bn_pf_group['group_key'] : '';
		$bn_pf_gtype = isset( $bn_pf_group['type'] ) ? (string) $bn_pf_group['type'] : 'flat';

		if ( '' === $bn_pf_gkey || in_array( $bn_pf_gkey, $bn_pf_skip_groups, true ) ) {
			continue;
		}

		// Repeater groups: render each entry's fields via the engine.
		if ( 'repeater' === $bn_pf_gtype ) {
			$bn_pf_entries = isset( $bn_pf_group['entries'] ) && is_array( $bn_pf_group['entries'] ) ? $bn_pf_group['entries'] : array();
			$bn_pf_rows    = '';
			foreach ( $bn_pf_entries as $bn_pf_entry ) {
				if ( ! is_array( $bn_pf_entry ) ) {
					continue;
				}
				$bn_pf_entry_rows = '';
				foreach ( $bn_pf_entry as $bn_pf_field ) {
					if ( ! is_array( $bn_pf_field ) || empty( $bn_pf_field['field_key'] ) ) {
						continue;
					}
					$bn_pf_val = (string) ( $bn_pf_field['value'] ?? '' );
					if ( '' === $bn_pf_val ) {
						continue;
					}
					$bn_pf_label       = isset( $bn_pf_field['label'] ) ? (string) $bn_pf_field['label'] : '';
					$bn_pf_display     = \BuddyNext\Profile\FieldType::render_display( $bn_pf_field, $bn_pf_field['value'] ?? '' );
					$bn_pf_entry_rows .= '<div class="bn-pf-detail"><dt class="bn-pf-detail__label">' . esc_html( $bn_pf_label ) . '</dt><dd class="bn-pf-detail__value">' . $bn_pf_display . '</dd></div>';
				}
				if ( '' !== $bn_pf_entry_rows ) {
					$bn_pf_rows .= '<dl class="bn-pf-detail-list bn-pf-detail-entry">' . $bn_pf_entry_rows . '</dl>';
				}
			}
			if ( '' !== $bn_pf_rows ) {
				$bn_pf_detail_sections[] = array(
					'label' => isset( $bn_pf_group['label'] ) ? (string) $bn_pf_group['label'] : ucwords( str_replace( '_', ' ', $bn_pf_gkey ) ),
					'html'  => $bn_pf_rows,
				);
			}
			continue;
		}

		// Flat group: render every field value via the engine.
		$bn_pf_fields = isset( $bn_pf_group['fields'] ) && is_array( $bn_pf_group['fields'] ) ? $bn_pf_group['fields'] : array();
		$bn_pf_rows   = '';
		foreach ( $bn_pf_fields as $bn_pf_field ) {
			if ( ! is_array( $bn_pf_field ) || empty( $bn_pf_field['field_key'] ) ) {
				continue;
			}
			$bn_pf_fkey = (string) $bn_pf_field['field_key'];
			if ( 'basic_info' === $bn_pf_gkey && in_array( $bn_pf_fkey, $bn_pf_hero_keys, true ) ) {
				continue;
			}
			$bn_pf_val = (string) ( $bn_pf_field['value'] ?? '' );
			if ( '' === $bn_pf_val ) {
				continue;
			}
			$bn_pf_label   = isset( $bn_pf_field['label'] ) ? (string) $bn_pf_field['label'] : ucwords( str_replace( '_', ' ', $bn_pf_fkey ) );
			$bn_pf_display = \BuddyNext\Profile\FieldType::render_display( $bn_pf_field, $bn_pf_field['value'] ?? '' );
			$bn_pf_rows   .= '<div class="bn-pf-detail"><dt class="bn-pf-detail__label">' . esc_html( $bn_pf_label ) . '</dt><dd class="bn-pf-detail__value">' . $bn_pf_display . '</dd></div>';
		}
		if ( '' !== $bn_pf_rows ) {
			$bn_pf_detail_sections[] = array(
				'label' => isset( $bn_pf_group['label'] ) ? (string) $bn_pf_group['label'] : ucwords( str_replace( '_', ' ', $bn_pf_gkey ) ),
				'html'  => '<dl class="bn-pf-detail-list">' . $bn_pf_rows . '</dl>',
			);
		}
	}

	foreach ( $bn_pf_detail_sections as $bn_pf_section ) :
		?>
		<section class="bn-card bn-pf-about-card bn-pf-detail-card">
			<header class="bn-pf-about-card__header">
				<h2 class="bn-pf-about-card__title"><?php echo esc_html( (string) $bn_pf_section['label'] ); ?></h2>
			</header>
			<?php
			// Detail rows are assembled from FieldType::render_display output,
			// which is escaped per the field_type_engine contract, plus
			// esc_html() labels.
			echo $bn_pf_section['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</section>
		<?php
	endforeach;

	// Pull the buffered about content; when it has anything, surface a dedicated
	// "About" tab right after Posts (the Facebook/LinkedIn placement) so the
	// metadata is one click away instead of permanently above every tab.
	$bn_pf_about_html = trim( (string) ob_get_clean() );
	if ( '' !== $bn_pf_about_html ) {
		array_splice(
			$bn_pf_tabs,
			1,
			0,
			array(
				array(
					'slug'  => 'about',
					'label' => __( 'About', 'buddynext' ),
				),
			)
		);
	}

	buddynext_get_template(
		'parts/profile-tab-bar.php',
		array(
			'profile_user_id' => (int) $user_id,
			'viewer_id'       => (int) $current_user_id,
			'active_tab'      => 'posts',
			'tabs'            => $bn_pf_tabs,
		)
	);

	buddynext_get_template(
		'parts/profile-tab-panel.php',
		array(
			'active_tab'           => 'posts',
			'about_html'           => $bn_pf_about_html,
			'profile_user_id'      => (int) $user_id,
			'viewer_id'            => (int) $current_user_id,
			'is_owner'             => (bool) $is_own_profile,
			'display_name'         => (string) $display_name,
			'recent_posts'         => is_array( $recent_posts ) ? $recent_posts : array(),
			'user_replies'         => is_array( $user_replies ) ? $user_replies : array(),
			'user_media'           => is_array( $user_media ) ? $user_media : array(),
			'user_likes'           => is_array( $user_likes ) ? $user_likes : array(),
			'jt_discussions'       => is_array( $jt_discussions ) ? $jt_discussions : array(),
			'show_discussions'     => (bool) $show_discussions,
			'follower_users'       => $follower_users,
			'following_users'      => $following_users,
			'connection_users'     => $connection_users,
			'pending_follow_users' => $pending_follow_users,
		)
	);

	// Report + block-confirm modals: only the non-owner viewer needs them.
	if ( ! $is_own_profile && $current_user_id ) :
		buddynext_get_template( 'partials/report-modal.php', array() );
		buddynext_get_template(
			'partials/block-confirm-modal.php',
			array( 'display_name' => $display_name )
		);
	endif;
	?>

</div><!-- /.bn-pf-stack -->

<?php
/** Fires after the profile main content. @param int $user_id */
do_action( 'buddynext_profile_after', (int) $user_id );
