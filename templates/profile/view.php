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

// --- Social graph state (viewer vs. this profile) -------------------------
$is_following        = false;
$is_connected        = false;
$connection_pending  = false;
$connection_received = false;
$is_blocked          = false;
$is_muted            = false;
$degree_badge        = '';

if ( ! $is_own_profile && $current_user_id ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$is_following        = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d", $current_user_id, $user_id ) );
	$is_connected        = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_connections WHERE ( ( requester_id = %d AND recipient_id = %d ) OR ( requester_id = %d AND recipient_id = %d ) ) AND status = 'accepted'", $current_user_id, $user_id, $user_id, $current_user_id ) );
	$connection_pending  = ! $is_connected && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_connections WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'", $current_user_id, $user_id ) );
	$connection_received = ! $is_connected && ! $connection_pending && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_connections WHERE requester_id = %d AND recipient_id = %d AND status = 'pending'", $user_id, $current_user_id ) );
	$degree_badge        = $is_connected ? '1st' : ( $is_following ? '2nd' : '3rd+' );
	$is_blocked          = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d AND blocked_id = %d AND type = 'block' LIMIT 1", $current_user_id, $user_id ) );
	$is_muted            = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d AND blocked_id = %d AND type = 'mute' LIMIT 1", $current_user_id, $user_id ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
$user_likes   = $wpdb->get_results( $wpdb->prepare( "SELECT r.emoji, r.created_at, r.object_id, p.content, p.type, p.user_id AS post_author_id, u.display_name AS post_author_name FROM {$wpdb->prefix}bn_reactions r INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id AND r.object_type = 'post' INNER JOIN {$wpdb->users} u ON u.ID = p.user_id WHERE r.user_id = %d ORDER BY r.created_at DESC LIMIT 20", $user_id ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$user_media = array();
if ( class_exists( 'WPMediaVerse\Core\Plugin' ) && post_type_exists( 'mvs_media' ) ) {
	$user_media = get_posts(
		array(
			'post_type'   => 'mvs_media',
			'author'      => $user_id,
			'numberposts' => 24,
			'post_status' => 'publish',
		)
	);
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
$last_active  = (int) get_user_meta( $user_id, 'bn_last_active', true );
$is_online    = $last_active && ( time() - $last_active ) < 300;
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

// Owner action bar: rendered ONLY when the viewer is the profile owner.
if ( $is_own_profile ) {
	buddynext_get_template(
		'partials/profile-actions.php',
		array(
			'user_id'        => (int) $user_id,
			'is_own_profile' => true,
		)
	);
}

// --- Stat-strip descriptors (passed through hero → stats-strip part) ------
$bn_pf_stats = array(
	array(
		'slug'        => 'posts',
		'label'       => __( 'Posts', 'buddynext' ),
		'value'       => $format_count( $post_count ),
		'wp_on_click' => 'actions.setTab',
		'data_tab'    => 'posts',
		'aria_label'  => __( 'Show posts', 'buddynext' ),
	),
	array(
		'slug'    => 'followers',
		'label'   => __( 'Followers', 'buddynext' ),
		'value'   => $format_count( $follower_count ),
		'href'    => \BuddyNext\Core\PageRouter::followers_url( (int) $user_id ),
		'wp_text' => 'context.followerCount',
	),
	array(
		'slug'  => 'following',
		'label' => __( 'Following', 'buddynext' ),
		'value' => $format_count( $following_count ),
		'href'  => \BuddyNext\Core\PageRouter::following_url( (int) $user_id ),
	),
	array(
		'slug'  => 'connections',
		'label' => __( 'Connections', 'buddynext' ),
		'value' => $format_count( $connection_count ),
		'href'  => \BuddyNext\Core\PageRouter::connections_url( (int) $user_id ),
	),
);

// --- Tab descriptors (filterable via buddynext_part_profile_tab_bar_args) -
$bn_pf_tabs = array(
	array(
		'slug'  => 'posts',
		'label' => __( 'Posts', 'buddynext' ),
		'count' => $format_count( $post_count ),
	),
	array(
		'slug'  => 'replies',
		'label' => __( 'Replies', 'buddynext' ),
	),
	array(
		'slug'  => 'media',
		'label' => __( 'Media', 'buddynext' ),
	),
	array(
		'slug'  => 'likes',
		'label' => __( 'Likes', 'buddynext' ),
	),
);
if ( $has_jt_tab ) {
	$bn_pf_tabs[] = array(
		'slug'  => 'discussions',
		'label' => __( 'Discussions', 'buddynext' ),
	);
}
$bn_pf_ctx = array(
	'userId'             => $user_id,
	'profileUserId'      => $user_id,
	'displayName'        => $display_name,
	'peopleUrl'          => \BuddyNext\Core\PageRouter::people_url(),
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

	/**
	 * Fires after the hero card and before the tab bar. Pro plugins hook
	 * here to inject widgets (e.g. "Who viewed your profile"). Retained for
	 * backwards-compat; new listeners should use `buddynext_part_profile_hero_after`.
	 *
	 * @since 1.1.0
	 * @param int $user_id         Profile being viewed.
	 * @param int $current_user_id Current viewer (0 when anonymous).
	 */
	do_action( 'buddynext_profile_view_after_hero', (int) $user_id, (int) $current_user_id );

	buddynext_get_template(
		'partials/profile-about-cards.php',
		array(
			'work_entries' => $work_entries,
			'edu_entries'  => $edu_entries,
			'interests'    => $interests,
			'entry_fv'     => $entry_fv,
		)
	);

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
			'active_tab'       => 'posts',
			'profile_user_id'  => (int) $user_id,
			'viewer_id'        => (int) $current_user_id,
			'is_owner'         => (bool) $is_own_profile,
			'display_name'     => (string) $display_name,
			'recent_posts'     => is_array( $recent_posts ) ? $recent_posts : array(),
			'user_replies'     => is_array( $user_replies ) ? $user_replies : array(),
			'user_media'       => is_array( $user_media ) ? $user_media : array(),
			'user_likes'       => is_array( $user_likes ) ? $user_likes : array(),
			'jt_discussions'   => is_array( $jt_discussions ) ? $jt_discussions : array(),
			'show_discussions' => (bool) $show_discussions,
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
