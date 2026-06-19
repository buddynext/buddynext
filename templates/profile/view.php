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

$bn_post_svc = buddynext_service( 'post_service' );

// --- Social-graph member lists for the in-page Followers / Following /
// Connections tabs (rendered inside the same profile shell, not as separate
// bare pages). Capped for the panel; the count chip shows the true total.
$bn_pf_ids_to_users   = static function ( array $ids ): array {
	return array_values( array_filter( array_map( static fn( $id ) => get_userdata( (int) $id ), $ids ) ) );
};
$follower_users       = $bn_pf_ids_to_users( array_slice( $bn_follow_svc->followers( $user_id ), 0, 60 ) );
$following_users      = $bn_pf_ids_to_users( array_slice( $bn_follow_svc->following( $user_id ), 0, 60 ) );
$connection_users     = $bn_pf_ids_to_users( $bn_conn_svc->connections( $user_id, 60, 0 ) );
$pending_follow_users = $is_own_profile ? $bn_pf_ids_to_users( $bn_follow_svc->pending_followers( $user_id ) ) : array();
// Incoming connection (friend) requests — owner-only; the requester accepts/declines
// from the Connections tab. Mirrors the pending-follow-requests inbox.
$pending_connection_users = $is_own_profile ? $bn_pf_ids_to_users( $bn_conn_svc->pending_received( $user_id, 60, 0 ) ) : array();

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
// All rows come from the service layer (same methods the REST controllers call)
// — recent posts through the privacy-aware profile feed (canonically hydrated),
// replies/likes through PostService, so the panels never touch the DB directly.
//
// Recent posts: the profile feed applies the private-account gate + per-post
// privacy, then hydrates each row through PostService::hydrate(). For a
// non-permitted viewer it returns an empty set, so the Posts panel shows its
// existing empty-state copy.
$bn_feed_svc  = buddynext_service( 'feed' );
$recent_posts = $bn_feed_svc->profile_feed( $user_id, $current_user_id, null, 10 )['items'];

// Replies: service rows are associative; the Replies panel reads them as objects
// (->object_id / ->content / ->post_author_name), so re-cast to objects here
// (the panel markup is shared and stays untouched).
$user_replies = array_map(
	static fn( array $r ): object => (object) $r,
	$bn_post_svc->user_replies( $user_id, 20 )
);

// Likes: already hydrated arrays (post-card consumes arrays).
$user_likes = $bn_post_svc->user_liked_posts( $user_id, 20 );

// Scheduled posts are private to the author, so the panel + its data are owner-only.
$scheduled_posts = array();
if ( $is_own_profile ) {
	$scheduled_posts = $bn_post_svc->user_scheduled_posts( $user_id, 20 );
}

// Profile media gallery — resolved from WPMediaVerse at the API level (its
// media live in mvs_media_index, not wp_posts). $user_media holds ordered
// media ids; the panel renders them BN-native via MediaRenderer::gallery().
// Privacy (hide private from non-owners) is enforced inside the engine query.
$user_media = array();
if ( \BuddyNext\Media\MediaClient::available() ) {
	$bn_media_viewer = get_current_user_id();
	$user_media      = \BuddyNext\Media\Galleries::user_media_ids( $user_id, $bn_media_viewer, 24, 0 );
}

// Jetonomy discussions — the bridge owns all jt_* table access, so the template
// never queries the partner's tables directly.
$jt_discussions   = array();
$show_discussions = class_exists( 'Jetonomy\Models\Post' );
if ( $show_discussions ) {
	$jt_discussions = ( new \BuddyNext\Bridges\JetonomyBridge() )->user_discussions( $user_id, 20 );
}

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

// --- About content (buffered up-front) ------------------------------------
// The "About" section (curated about-cards + every other admin-defined field via
// the generic field-type engine) is buffered here, BEFORE the nav resolves, so
// an "About" tab can be registered only when there is content to show. The
// captured HTML is handed to the tab panel below — its echo order is unchanged.
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
 * displays correctly. ProfileService::get_profile has ALREADY applied
 * per-field visibility for the viewer, so anything present here is allowed.
 *
 * Keys/groups the hero + about-cards already surface prominently are skipped
 * to avoid visible duplication; everything else renders below.
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
		// Detail rows are assembled from FieldType::render_display output, which is
		// escaped per the field_type_engine contract, plus esc_html() labels.
		echo $bn_pf_section['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>
	<?php
endforeach;

$bn_pf_about_html = trim( (string) ob_get_clean() );

// --- Resolve the unified profile navigation -------------------------------
// One registry → metric row + primary tabs (+ sub-nav), gated/ordered/deduped
// for THIS viewer. Core items come from ProfileNav; Discussions/Achievements
// from their bridges; admin reorder/hide from NavOverrides — all via the
// registry, so the rendered nav is consistent everywhere.
//
// The content-dependent "About" tab is registered here (only when there is
// about content), demonstrating the public extension seam.
if ( '' !== $bn_pf_about_html ) {
	add_filter(
		'buddynext_nav_items',
		static function ( array $items, \BuddyNext\Nav\NavContext $ctx ): array {
			if ( 'profile' === $ctx->surface ) {
				$items[] = array(
					'id'       => 'about',
					'surface'  => 'profile',
					'layer'    => 'primary',
					'label'    => __( 'About', 'buddynext' ),
					'tab'      => 'about',
					'priority' => 12,
					'after'    => 'posts',
				);
			}
			return $items;
		},
		10,
		2
	);
}

$bn_nav         = buddynext_nav( new \BuddyNext\Nav\NavContext( 'profile', (int) $user_id, (int) $current_user_id ) );
$bn_pf_metrics  = $bn_nav->layer( 'metric' );
$bn_pf_primary  = $bn_nav->layer( 'primary' );

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

	buddynext_get_template(
		'parts/profile-tab-panel.php',
		array(
			'active_tab'               => $bn_pf_active_tab,
			'about_html'               => $bn_pf_about_html,
			'profile_user_id'          => (int) $user_id,
			'viewer_id'                => (int) $current_user_id,
			'is_owner'                 => (bool) $is_own_profile,
			'display_name'             => (string) $display_name,
			'recent_posts'             => is_array( $recent_posts ) ? $recent_posts : array(),
			'scheduled_posts'          => is_array( $scheduled_posts ) ? $scheduled_posts : array(),
			'user_replies'             => is_array( $user_replies ) ? $user_replies : array(),
			'user_media'               => is_array( $user_media ) ? $user_media : array(),
			'user_likes'               => is_array( $user_likes ) ? $user_likes : array(),
			'jt_discussions'           => is_array( $jt_discussions ) ? $jt_discussions : array(),
			'show_discussions'         => (bool) $show_discussions,
			'follower_users'           => $follower_users,
			'following_users'          => $following_users,
			'connection_users'         => $connection_users,
			'pending_follow_users'     => $pending_follow_users,
			'pending_connection_users' => $pending_connection_users,
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
