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
$follow_pending      = false;
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

	// Following a PRIVATE account stores the row as status='pending' until the
	// owner approves it (FollowService::follow). The profile header had no third
	// state for that — only Follow / Following — so it had no way to say
	// "Requested", which is the state the member is actually in. Connections
	// already model this ($connection_pending, just below); follows now match.
	$follow_pending = ! $is_following
		&& method_exists( buddynext_service( 'follows' ), 'has_pending_request' )
		&& buddynext_service( 'follows' )->has_pending_request( $current_user_id, $user_id );

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

$mutual_count = ( ! $is_own_profile && $current_user_id ) ? buddynext_service( 'connections' )->mutual_count( $current_user_id, $user_id ) : 0;
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

/**
 * A field's value as a person should READ it, not as it is stored.
 *
 * The hero is the one surface that used to bypass the field-type engine: it took
 * `$field['value']` verbatim and echoed it, so any type storing something other
 * than its own display text leaked raw storage under the member's name — the Pro
 * Location map type printed its whole JSON payload there. Resolving through
 * FieldType::display_text() puts the hero on the same type-aware path the About
 * panel has always used, for every type at once rather than one at a time.
 */
$get_fv = static function ( string $group_key, string $field_key ) use ( $group_data ): string {
	if ( ! isset( $group_data[ $group_key ]['fields'] ) ) {
		return '';
	}
	foreach ( $group_data[ $group_key ]['fields'] as $field ) {
		if ( $field['field_key'] === $field_key ) {
			return \BuddyNext\Profile\FieldType::display_text( $field, $field['value'] ?? '' );
		}
	}
	return '';
};

$entry_fv = static function ( array $entry_fields, string $fkey ): string {
	foreach ( $entry_fields as $f ) {
		// Defensive: entries are packed field-array lists (per-entry privacy
		// travels in the group's parallel entry_visibility array), but skip any
		// non-field element so this lookup never dereferences a string offset.
		// Mirrors the guard in profile/edit.php.
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
$pronouns = $get_fv( 'basic_info', 'pronouns' );

// The hero meta row is data-driven (show_in_header, in sort_order) instead of a
// hardcoded location + website. Build the ordered {key,label,type,value} list,
// resolving each flagged field by key across EVERY group (a header field need not
// live in basic_info) and through FieldType::display_text() so a map/date/etc.
// shows its human value, not raw storage. Empties are dropped so the row carries
// only fields the member actually filled.
$bn_find_field = static function ( string $field_key ) use ( $group_data ): ?array {
	foreach ( $group_data as $bn_g ) {
		if ( empty( $bn_g['fields'] ) || ! is_array( $bn_g['fields'] ) ) {
			continue;
		}
		foreach ( $bn_g['fields'] as $bn_f ) {
			if ( is_array( $bn_f ) && ( $bn_f['field_key'] ?? '' ) === $field_key ) {
				return $bn_f;
			}
		}
	}
	return null;
};
$hero_meta     = array();
foreach ( \BuddyNext\Profile\ProfileService::hero_meta_field_keys() as $bn_hk ) {
	$bn_hf = $bn_find_field( (string) $bn_hk );
	if ( null === $bn_hf ) {
		continue;
	}
	$bn_hv = \BuddyNext\Profile\FieldType::display_text( $bn_hf, $bn_hf['value'] ?? '' );
	if ( '' === trim( $bn_hv ) ) {
		continue;
	}
	$hero_meta[] = array(
		'key'   => (string) $bn_hk,
		'label' => (string) ( $bn_hf['label'] ?? '' ),
		'type'  => (string) ( $bn_hf['type'] ?? 'text' ),
		'value' => $bn_hv,
	);
}

$social_link_fields = isset( $group_data['social_links']['fields'] ) ? $group_data['social_links']['fields'] : array();
$social_links       = array_filter( $social_link_fields, static fn( array $f ): bool => '' !== (string) ( $f['value'] ?? '' ) );

// An entry is "present" if ANY of its fields carries a value — never a fixed set
// of keys. The old work_company||work_title / edu_institution||edu_degree probe
// made every entry vanish the moment the admin deleted that one field, even when
// the title, dates, location or description were still filled in.
$entry_has_value = static fn( array $e ): bool => (bool) array_filter(
	$e,
	static fn( $f ): bool => is_array( $f ) && '' !== trim( (string) ( $f['value'] ?? '' ) )
);

$work_entries = array_values( array_filter( isset( $group_data['work_experience']['entries'] ) ? $group_data['work_experience']['entries'] : array(), $entry_has_value ) );
$edu_entries  = array_values( array_filter( isset( $group_data['education']['entries'] ) ? $group_data['education']['entries'] : array(), $entry_has_value ) );

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

$skills     = array_filter( array_map( 'trim', explode( ',', $get_fv( 'skills', 'skills' ) ) ) );
$completion = $is_own_profile ? $profile_svc->get_completion_score( $user_id ) : null;

// Interests — the member's picked space categories from the system
// category_multiselect field. Sourced from get_profile()'s output above, so
// per-entry visibility is already applied (viewers who may not see the field
// get an empty list). Each chip deep-links to the spaces directory filtered
// to that category; deleted categories drop out silently.
$bn_pf_interest_ids = array();
foreach ( (array) ( $group_data['interests']['fields'] ?? array() ) as $bn_pf_int_field ) {
	if ( is_array( $bn_pf_int_field ) && 'interests' === (string) ( $bn_pf_int_field['field_key'] ?? '' ) ) {
		$bn_pf_interest_ids = array_values( array_filter( array_map( 'absint', (array) ( $bn_pf_int_field['value'] ?? array() ) ) ) );
		break;
	}
}
$interest_chips = array();
if ( ! empty( $bn_pf_interest_ids ) ) {
	$bn_pf_cat_names = \BuddyNext\Profile\FieldType::category_options();
	$bn_pf_cat_links = \BuddyNext\Profile\FieldType::category_directory_links();
	foreach ( $bn_pf_interest_ids as $bn_pf_cat_id ) {
		if ( ! isset( $bn_pf_cat_names[ (string) $bn_pf_cat_id ] ) ) {
			continue;
		}
		$interest_chips[] = array(
			'name' => (string) $bn_pf_cat_names[ (string) $bn_pf_cat_id ],
			'url'  => (string) ( $bn_pf_cat_links[ (string) $bn_pf_cat_id ] ?? '' ),
		);
	}
}

// Profile-strength tasks: the SAME curated set drives the mobile hero chip and
// the desktop sidebar ring/checklist — so both surfaces always agree. The
// canonical builder is ProfileService::get_strength() (existence-filtered,
// change-gated buddynext_profile_strength_changed hook for reward systems);
// the already-loaded owner-scoped profile data is passed through so the
// service does not reload it. Strength is an own-profile concept — for
// visitors the widget never renders, so skip the computation entirely.
$bn_pf_strength       = $is_own_profile
	? $profile_svc->get_strength( $user_id, is_array( $profile_data ) ? $profile_data : null )
	: array(
		'tasks'   => array(),
		'done'    => 0,
		'total'   => 0,
		'percent' => 0,
	);
$bn_pf_strength_tasks = $bn_pf_strength['tasks'];
$bn_pf_strength_total = (int) $bn_pf_strength['total'];
$bn_pf_strength_pct   = (int) $bn_pf_strength['percent'];
$strength_tasks       = $bn_pf_strength_tasks;
$is_online            = buddynext_service( 'blocks' )->is_user_online( $current_user_id, $user_id );

// --- Sidebar widget surface (ProfileSidebarProvider reads this context) ---
// Set BEFORE the shell renders the right column (see templates/shell/right-sidebar.php),
// same pattern as every other surface (feed/space/search/etc.) — the registry
// reads it via Surface::current() and ProfileSidebarProvider via Surface::context().
$bn_pf_sidebar_args = compact( 'is_own_profile', 'completion', 'social_links', 'work_entries', 'edu_entries', 'skills', 'interest_chips', 'member_spaces', 'get_fv', 'entry_fv', 'strength_tasks' );
\BuddyNext\Sidebar\Surface::set( 'profile', $bn_pf_sidebar_args );

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
	'followPending'      => $follow_pending,
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
	data-wp-on-document--click="actions.closeMenusOnOutside"
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
			'hero_meta'           => $hero_meta,
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
			'follow_pending'      => (bool) $follow_pending,
			'is_connected'        => (bool) $is_connected,
			'connection_pending'  => (bool) $connection_pending,
			'connection_received' => (bool) $connection_received,
			'metric_items'        => $bn_pf_metrics,
		)
	);

	// Account standing banner — strikes / suspension (+ shadow-ban for moderators).
	// Privileged: only the owner (their own sanctions) or a moderator sees it, via
	// the single ModerationService::account_status_for() source shared with the REST
	// profile response. The partial renders nothing for a clean account.
	$bn_mod_service    = buddynext_service( 'moderation' );
	$bn_account_status = ( $bn_mod_service instanceof \BuddyNext\Moderation\ModerationService )
		? $bn_mod_service->account_status_for( (int) $user_id, (int) $current_user_id )
		: null;
	if ( is_array( $bn_account_status ) ) {
		buddynext_get_template(
			'parts/profile-account-status.php',
			array(
				'status'  => $bn_account_status,
				'is_self' => (bool) $is_own_profile,
			)
		);
	}

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
