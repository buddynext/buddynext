<?php
/**
 * BuddyNext — User Profile View template.
 *
 * Thin composer: resolves the data, gates on permissions, hooks the
 * right-sidebar widgets, then delegates the on-page markup to the hero
 * (`parts/profile-hero.php`, which renders the metric row via the shared
 * `parts/nav-metrics.php`), the shared primary tab bar (`parts/nav-bar.php`,
 * fed by the unified Nav registry), and `parts/profile-tab-panel.php`.
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

$current_user_id = get_current_user_id();
$is_own_profile  = ( $current_user_id === $user_id );

// Whether the viewer may edit this (someone else's) profile — drives the
// "Edit profile" control in the hero's more-options menu. Resolves through the
// role map (buddynext-profile/edit-any), so the Roles & Capabilities toggle
// actually governs it; site admins always pass.
$bn_can_edit_any = ! $is_own_profile && $current_user_id > 0
	&& buddynext_can( $current_user_id, 'buddynext-profile/edit-any' );

if ( ! $is_own_profile && ! current_user_can( 'manage_options' )
	&& ! buddynext_service( 'privacy' )->can_view_profile( $current_user_id, $user_id )
) {
	?>
		<div class="bn-card bn-profile-private bn-empty-state">
			<div class="bn-empty-state__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
			<h2 class="bn-empty-state__title"><?php echo esc_html( $profile_user->display_name ); ?></h2>
			<p class="bn-empty-state__text"><?php esc_html_e( 'This profile is private.', 'buddynext' ); ?></p>
			<?php if ( 0 === $current_user_id ) : ?>
				<p class="bn-empty-state__text"><?php esc_html_e( 'Log in to see if you can view it.', 'buddynext' ); ?></p>
			<?php endif; ?>
		</div>
	<?php
	return;
}

// --- Identity + counts ----------------------------------------------------
$avatar_url   = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
$cover_url    = buddynext_user_cover_url( $user_id );
$display_name = $profile_user->display_name;
$joined       = gmdate( 'M Y', strtotime( $profile_user->user_registered ) );

$bn_follow_svc = buddynext_service( 'follows' );
$bn_conn_svc   = buddynext_service( 'connections' );

// Follower count is the one relationship scalar still read on this surface (the
// reactive Interactivity context, below). The nav metric/tab badges resolve
// their own counts lazily inside the registry providers, so the old per-view
// stat/tab count scalars are gone.
$follower_count = $bn_follow_svc->follower_count( $user_id );

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

	$bn_conn_row         = $bn_conn_svc->pair_row( $current_user_id, $user_id );
	$bn_conn_status      = $bn_conn_row ? (string) $bn_conn_row->status : '';
	$is_connected        = 'accepted' === $bn_conn_status;
	$connection_pending  = 'pending' === $bn_conn_status && (int) $bn_conn_row->requester_id === $current_user_id;
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
		// Repeater entries carry non-field meta alongside their field arrays —
		// ProfileService appends a scalar `_visibility` element (consumed by the
		// edit form). Skip anything that isn't a field array so this lookup never
		// dereferences a string offset. Mirrors the guard in profile/edit.php.
		if ( ! is_array( $f ) || ! isset( $f['field_key'] ) ) {
			continue;
		}
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

// Tab-panel data is no longer fetched here. Each tab's panel is rendered through
// the Nav registry's content seam (PanelRenderer below) by its own `render`
// callable, which self-fetches its rows — so only the ACTIVE tab queries (posts,
// replies, likes, media, discussions, followers/following/connections), never all
// of them on every profile load.

// --- Spaces, interests, completion, presence ------------------------------
// Member's active spaces (id/name/slug/role) via the membership service, shared
// with the right-sidebar widget so both surfaces agree.
$member_spaces = buddynext_service( 'space_members' )->membership_rows( $user_id, 5 );

$interests  = array_filter( array_map( 'trim', explode( ',', $get_fv( 'skills', 'interests' ) ) ) );
$completion = $is_own_profile ? $profile_svc->get_completion_score( $user_id ) : null;

// Profile-strength percentage from the SAME 6 curated tasks the strength
// widget shows (bio, tagline, location, skills, work, linked account) — so the
// mobile hero chip and the desktop sidebar ring agree. Driving the chip off
// get_completion_score() (which scores every flat field) left mobile stuck at
// e.g. 83% with all 6 visible tasks done. Sidebar computes the identical set.
$bn_pf_strength_tasks = array(
	'' !== $bio,
	'' !== $headline,
	'' !== $location,
	! empty( $interests ),
	! empty( $work_entries ),
	! empty( $social_links ),
);
$bn_pf_strength_total = count( $bn_pf_strength_tasks );
$bn_pf_strength_pct   = $bn_pf_strength_total > 0
	? (int) round( ( count( array_filter( $bn_pf_strength_tasks ) ) / $bn_pf_strength_total ) * 100 )
	: 0;
$is_online            = buddynext_service( 'blocks' )->is_user_online( $current_user_id, $user_id );

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

// --- Resolve the unified profile navigation -------------------------------
// One registry → metric row + primary tabs (+ Network sub-nav), gated/ordered/
// deduped for THIS viewer. Core items come from ProfileNav (each tab a clean URL
// + a self-fetching `render`); Discussions/Achievements from their bridges; admin
// reorder/hide from NavOverrides. The About tab registers itself only when there
// is about content (ProfileNav::has_about_content) — no per-view buffering here.
$bn_nav        = buddynext_nav( new \BuddyNext\Nav\NavContext( 'profile', (int) $user_id, (int) $current_user_id ) );
$bn_pf_metrics = $bn_nav->layer( 'metric' );
$bn_pf_primary = $bn_nav->layer( 'primary' );

// Deep-link the active tab from the route action. Valid targets are the resolved
// primary tabs plus the metric panels (followers/following/connections), which
// are reached via the metric pills, not the bar. Falls back to Posts.
$bn_pf_action = (string) get_query_var( 'bn_profile_action', '' );

// Valid deep-link targets: every primary tab, its sub-tabs (Network's
// Connections/…, Portfolio's Jobs/Listings/…), and the metric panels reached via
// the hero pills. Sub-tab slugs MUST be here or a clean /members/x/jobs/ URL
// would silently fall back to Posts.
$bn_pf_tab_slugs = array();
foreach ( $bn_pf_primary as $bn_pf_item ) {
	$bn_pf_tab_slugs[] = $bn_pf_item->id;
	foreach ( $bn_pf_item->children as $bn_pf_child ) {
		$bn_pf_tab_slugs[] = $bn_pf_child->id;
	}
}
foreach ( $bn_pf_metrics as $bn_pf_metric ) {
	$bn_pf_tab_slugs[] = $bn_pf_metric->id;
}

$bn_pf_active_tab = in_array( $bn_pf_action, $bn_pf_tab_slugs, true ) ? $bn_pf_action : 'posts';

// A parent tab (Network, Portfolio) owns no panel of its own — landing on it
// (deep link or default) resolves to its first sub-tab so a real panel shows.
foreach ( $bn_pf_primary as $bn_pf_item ) {
	if ( $bn_pf_item->id === $bn_pf_active_tab && ! empty( $bn_pf_item->children ) ) {
		$bn_pf_active_tab = (string) $bn_pf_item->children[0]->id;
		break;
	}
}

$bn_pf_ctx = array(
	'userId'             => $user_id,
	'profileUserId'      => $user_id,
	'displayName'        => $display_name,
	'peopleUrl'          => \BuddyNext\Core\PageRouter::people_url(),
	'profileBaseUrl'     => \BuddyNext\Core\PageRouter::profile_url( (int) $user_id ),
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
<div class="bn-pf-stack" data-wp-interactive="buddynext/profile"
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
			'strength_pct'        => (int) $bn_pf_strength_pct,
			'is_owner'            => (bool) $is_own_profile,
			'can_edit_any'        => (bool) $bn_can_edit_any,
			'is_online'           => (bool) $is_online,
			'is_following'        => (bool) $is_following,
			'is_connected'        => (bool) $is_connected,
			'connection_pending'  => (bool) $connection_pending,
			'connection_received' => (bool) $connection_received,
			'metric_items'        => $bn_pf_metrics,
		)
	);

	// Primary tab bar (+ one-level sub-nav) via the shared Nav renderer — the
	// same component the space surface uses, fed by the resolved registry.
	buddynext_get_template(
		'parts/nav-bar.php',
		array(
			'items'         => $bn_pf_primary,
			'active'        => $bn_pf_active_tab,
			'tablist_label' => __( 'Profile sections', 'buddynext' ),
		)
	);

	// Tab body — the registry content seam paints ONLY the active panel (the same
	// PanelRenderer the space surface uses). Each tab's `render` self-fetches, so
	// nothing here pre-renders the inactive panels. The active tab is resolved +
	// normalized above (unknown → posts, a Network parent → its first child).
	?>
	<div class="bn-pf-tab-content">
		<?php
		( new \BuddyNext\Nav\PanelRenderer() )->render_panels(
			$bn_nav,
			new \BuddyNext\Nav\NavContext( 'profile', (int) $user_id, (int) $current_user_id ),
			$bn_pf_active_tab
		);
		?>
	</div>
	<?php

	// Report + block-confirm modals: only the non-owner viewer needs them.
	if ( ! $is_own_profile && $current_user_id ) :
		buddynext_get_template( 'partials/report-modal.php', array() );
		buddynext_get_template(
			'partials/block-confirm-modal.php',
			array( 'display_name' => $display_name )
		);
	endif;

	// Share modal: any logged-in viewer can share posts shown in the profile
	// feed, so the modal must be present here too (mirrors home.php and
	// single-post.php). Without it the post Share button's bn-open-share-modal
	// event has no element to bind to and the click does nothing.
	if ( $current_user_id ) :
		buddynext_get_template(
			'partials/share-modal.php',
			array( 'current_user_id' => $current_user_id )
		);
	endif;
	?>

</div><!-- /.bn-pf-stack -->

<?php
/** Fires after the profile main content. @param int $user_id */
do_action( 'buddynext_profile_after', (int) $user_id );
