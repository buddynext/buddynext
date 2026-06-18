<?php
/**
 * Template: Space Home (v2 inner).
 *
 * Renders the space hero (cover + identity + stats + actions) + tab nav
 * (Feed / Members / Media / About) + tab body, inside the shell main
 * column (`<main class="bn-app__main">` — see templates/shell/hub-shell.php).
 * This inner template does NOT own the rail or the
 * 2-column page grid. Sidebar widgets (about, members, top contributors)
 * are registered on the `buddynext_right_sidebar` action; the shell
 * auto-renders the right column when callbacks are present.
 *
 * v2 prototype: docs/v2 Plans/v2/space-home.html.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * Overridable: copy to {theme}/buddynext/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bn_space_category_icon' ) ) {
	/**
	 * Return inline SVG for a space category slug.
	 *
	 * @param string|null $cat_slug Category slug.
	 * @return string SVG markup.
	 */
	function bn_space_category_icon( ?string $cat_slug ): string {
		$map  = array(
			'technology'  => 'cpu',
			'design'      => 'image',
			'marketing'   => 'megaphone',
			'startups'    => 'rocket',
			'ai-ml'       => 'cpu',
			'data'        => 'bar-chart',
			'product'     => 'target',
			'writing'     => 'edit',
			'open-source' => 'globe',
			'business'    => 'briefcase',
			'creative'    => 'star',
		);
		$slug = $map[ (string) $cat_slug ] ?? 'home';
		return buddynext_get_icon( $slug );
	}
}

if ( ! function_exists( 'bn_sh_avatar_color' ) ) {
	/**
	 * Return a deterministic avatar background colour based on a user id.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string CSS hex colour.
	 */
	function bn_sh_avatar_color( int $user_id ): string {
		$colors = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#d97706' );
		return $colors[ $user_id % count( $colors ) ];
	}
}


// ── Services ──────────────────────────────────────────────────────────────────

$bn_space_service  = new \BuddyNext\Spaces\SpaceService();
$bn_member_service = new \BuddyNext\Spaces\SpaceMemberService();
$bn_feed_service   = buddynext_service( 'feed' );

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

$bn_space_arr = $bn_space_service->get( $space_id );

if ( null === $bn_space_arr ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// Parts (hero, about, members, feed, sidebar) read $space as an object and need
// the category name/slug the bare space row does not carry. Resolve the category
// through its owning service, then expose the space as an object so the shared
// parts keep their existing property access untouched.
$bn_category_name = '';
$bn_category_slug = '';
if ( ! empty( $bn_space_arr['category_id'] ) ) {
	$bn_category = ( new \BuddyNext\Spaces\SpaceCategoryService() )->get_by_id( (int) $bn_space_arr['category_id'] );
	if ( is_array( $bn_category ) ) {
		$bn_category_name = (string) ( $bn_category['name'] ?? '' );
		$bn_category_slug = (string) ( $bn_category['slug'] ?? '' );
	}
}
$bn_space_arr['category_name'] = $bn_category_name;
$bn_space_arr['category_slug'] = $bn_category_slug;

$space = (object) $bn_space_arr;

$current_user_id = get_current_user_id();

// ── Current user's membership ─────────────────────────────────────────────────

$bn_member_role_now   = $current_user_id ? $bn_member_service->get_role( $space_id, $current_user_id ) : null;
$bn_member_status_now = $current_user_id ? $bn_member_service->get_status( $space_id, $current_user_id ) : null;

$membership = ( null !== $bn_member_status_now )
	? (object) array(
		'role'   => (string) $bn_member_role_now,
		'status' => (string) $bn_member_status_now,
	)
	: null;

$is_member    = $membership && 'active' === $membership->status;
$is_admin_mod = $membership && 'active' === $membership->status && in_array( $membership->role, array( 'owner', 'moderator' ), true );

// Whether the viewer may moderate THIS space. Resolves through the role map
// (buddynext-spaces/moderate) so the Roles & Capabilities toggle governs it:
// space owners/moderators, role-granted members, and site admins all pass.
// Drives the moderation tab/counts/panel below — the moderation page itself
// (templates/spaces/moderation.php) already uses this same capability, so the
// tab and the page now agree instead of the tab being hidden by a role check.
$can_moderate = $current_user_id > 0
	&& buddynext_can( $current_user_id, 'buddynext-spaces/moderate', array( 'space_id' => $space_id ) );
$is_pending   = $membership && 'pending' === $membership->status;
$is_invited   = $membership && 'invited' === $membership->status;
$is_guest     = ( 0 === (int) $current_user_id );

// Posting permission (Permissions panel → "Who can post"): members | mods | owner.
// A site admin, or any member whose role meets the configured threshold, may post.
// This drives whether the composer is rendered in the feed panel; the rank rule
// lives in SpacePostGuard::can_post(), which the REST create path enforces too,
// so the visible composer and the server gate stay in lockstep.
$bn_can_post = $is_member && \BuddyNext\Spaces\SpacePostGuard::can_post( $space_id, $current_user_id );

// An archived space is read-only: no composer for anyone (mirrors the
// PostService/CommentService/join guards). A banner explains the state.
$bn_space_archived = ! empty( $space->is_archived );
if ( $bn_space_archived ) {
	$bn_can_post = false;
}

// Secret spaces are leak-proof: a logged-out visitor (or any non-member who
// isn't a site admin) reaches the canonical 404 surface so we never confirm
// the slug exists. Mirrors the visibility gate enforced by
// SpaceService::search() and the directory's `type != 'secret'` filter.
if ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_hidden_from_non_members( (string) $space->type ) && ! $is_member && ! current_user_can( 'manage_options' ) ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	include get_404_template();
	exit;
}

// Access gate: private + secret feeds. Open spaces never gate the feed, but
// guests still see a "Join to participate" CTA instead of the composer.
$gate_feed = ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->content_requires_membership( (string) $space->type ) && ! $is_member && ! current_user_can( 'manage_options' ) );

// ── Fetch posts for the feed ──────────────────────────────────────────────────
// All post data flows through FeedService, which hydrates each row via
// PostService::hydrate() — the same path the space-feed REST controller uses.

$feed_posts  = array();
$pinned_post = null;

if ( ! $gate_feed ) {
	// Pinned announcement (hydrated post array). The feed panel renders it as an
	// object and shows the author's name, which hydrate() does not carry, so we
	// enrich a single display_name onto the object before handing it over.
	$bn_pinned_arr = $bn_feed_service->space_pinned_post( $space_id );
	if ( is_array( $bn_pinned_arr ) ) {
		$bn_pinned_author       = get_userdata( (int) ( $bn_pinned_arr['user_id'] ?? 0 ) );
		$bn_pinned_arr['author_name'] = $bn_pinned_author ? $bn_pinned_author->display_name : __( 'Admin', 'buddynext' );
		$pinned_post            = (object) $bn_pinned_arr;
	}

	// Regular feed posts (hydrated arrays; pinned post excluded by FeedService).
	$bn_space_feed = $bn_feed_service->space_feed( $space_id, $current_user_id, null, 20 );
	$feed_posts    = array_values(
		array_filter(
			(array) ( $bn_space_feed['items'] ?? array() ),
			static function ( $bn_p ) {
				// The pinned post leads as its own card above the feed, so drop it
				// from the regular list to avoid showing it twice.
				return empty( $bn_p['is_pinned'] );
			}
		)
	);
}

// ── Fetch sidebar members ─────────────────────────────────────────────────────
// Owners/moderators always lead the sidebar; fetch them in full (they are few)
// and a capped preview of regular members. Each row is exposed as an object so
// the sidebar markup keeps its existing property access.
$bn_to_objects = static function ( array $rows ): array {
	return array_map(
		static function ( array $r ): object {
			// space-members-panel falls back to user_login when display_name is
			// empty; the service carries user_nicename, so mirror it across.
			$r['user_login'] = $r['user_login'] ?? ( $r['user_nicename'] ?? '' );
			return (object) $r;
		},
		$rows
	);
};

$bn_mods = array_merge(
	$bn_member_service->get_members( $space_id, $current_user_id, 0, 0, array( 'role' => 'owner' ) ),
	$bn_member_service->get_members( $space_id, $current_user_id, 0, 0, array( 'role' => 'moderator' ) )
);
$bn_regulars     = $bn_member_service->get_members( $space_id, $current_user_id, 10, 0, array( 'role' => 'member' ) );
$sidebar_members = $bn_to_objects( array_merge( $bn_mods, $bn_regulars ) );

// ── Top contributors ──────────────────────────────────────────────────────────

$top_contributors = $bn_to_objects( $bn_space_service->top_contributors( $space_id, 3 ) );

// ── Counts for stat strip + tabs ──────────────────────────────────────────────

$bn_post_count  = $bn_feed_service->space_post_count( $space_id );
// Media tab count — posts in this space carrying at least one media attachment.
$bn_media_count = $bn_feed_service->space_media_post_count( $space_id );

// Moderation tab counts — open reports + pending join requests for this space.
// Resolved only when the viewer may moderate; everyone else gets 0 so the count
// chip never leaks the queue size to non-moderators.
$bn_mod_count     = 0;
$bn_pending_count = 0;
if ( $can_moderate ) {
	$bn_mod_count     = buddynext_service( 'moderation' )->count_open_reports_for_space( $space_id );
	$bn_pending_count = $bn_member_service->count_pending_requests( $space_id );
}

$active_tab       = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$member_count_fmt = number_format_i18n( (int) $space->member_count );

$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) $space->type );
$privacy_tone  = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) $space->type );

$bn_current_user = $current_user_id ? get_userdata( $current_user_id ) : null;
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// Per-space notification preference for the current user.
$bn_notif_pref = 'all';
if ( $is_member ) {
	$bn_notif_pref = $bn_member_service->get_notification_pref( $space_id, $current_user_id );
}

// Members tab requires the full roster when active. Exposed as objects so the
// members panel keeps its existing property access.
$bn_full_members = array();
$bn_tab_lookup   = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'members' === $bn_tab_lookup && ! $gate_feed ) {
	$bn_full_members = $bn_to_objects(
		$bn_member_service->get_members( $space_id, $current_user_id, 100, 0 )
	);
}

// ── Right sidebar widgets ────────────────────────────────────────────────────
// Registered on the shared hub-shell action. The shell detects via has_action()
// after the inner buffer flushes and renders the right column.
$bn_sidebar_args = array(
	'space'            => $space,
	'space_id'         => $space_id,
	'sidebar_members'  => $sidebar_members,
	'top_contributors' => $top_contributors,
	'member_count_fmt' => $member_count_fmt,
	'post_count'       => $bn_post_count,
	'privacy_label'    => $privacy_label,
	'privacy_tone'     => $privacy_tone,
);

add_action(
	'buddynext_right_sidebar',
	static function () use ( $bn_sidebar_args ) {
		$bn_s = $bn_sidebar_args;

		// Card 1: About. Qualitative context only (description + type +
		// created + category). The Members / Posts counts live in the hero
		// stat strip — repeating the numbers here is duplication, so this
		// card carries what the strip does not.
		ob_start();
		if ( ! empty( $bn_s['space']->description ) ) :
			?>
			<p class="bn-sh-side-text"><?php echo esc_html( $bn_s['space']->description ); ?></p>
			<?php
		endif;
		?>
		<div class="bn-sh-side-meta">
			<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_s['privacy_tone'] ); ?>"><?php echo esc_html( $bn_s['privacy_label'] ); ?></span>
			<?php if ( ! empty( $bn_s['space']->created_at ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'calendar' ); ?>
					<?php
					// translators: %s is the formatted date.
					printf( esc_html__( 'Created %s', 'buddynext' ), buddynext_date_local( (string) $bn_s['space']->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_date_local() returns esc_html()'d output.
					?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $bn_s['space']->category_name ) ) : ?>
				<span class="bn-sh-side-meta__row">
					<?php buddynext_icon( 'hash' ); ?>
					<?php echo esc_html( $bn_s['space']->category_name ); ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
		$bn_about_html = (string) ob_get_clean();

		buddynext_get_template(
			'parts/sidebar-card.php',
			array(
				'id'         => 'space-about',
				'title'      => __( 'About this space', 'buddynext' ),
				'title_icon' => 'info',
				'body_html'  => $bn_about_html,
			)
		);

		// Split the role-ordered preview into moderators (owner + moderator)
			// and regular members so the two cards complement each other
			// instead of repeating mods. owner/moderator always lead the
			// LIMIT-10 set, so this needs no extra query.
			$bn_side_all = (array) $bn_s['sidebar_members'];
			$bn_mods     = array_values(
				array_filter(
					$bn_side_all,
					static function ( $m ) {
						return in_array( $m->role ?? '', array( 'owner', 'moderator' ), true );
					}
				)
			);
			$bn_regulars = array_values(
				array_filter(
					$bn_side_all,
					static function ( $m ) {
						return 'member' === ( $m->role ?? '' );
					}
				)
			);

			// Card 2: Moderators. DMs are owned by WPMediaVerse, so only offer
			// the Message action when that dependency is present (same signal
			// the messages hub uses); otherwise the row links to the profile.
		if ( ! empty( $bn_mods ) ) {
			$bn_msgs_on = \BuddyNext\Messages\MessagesData::available();
			ob_start();
			?>
				<ul class="bn-sh-side-members">
				<?php foreach ( $bn_mods as $bn_mod ) : ?>
						<?php
						$bn_mod_uid   = (int) $bn_mod->user_id;
						$bn_mod_name  = $bn_mod->display_name ?? __( 'Member', 'buddynext' );
						$bn_mod_init  = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_mod_name );
						$bn_mod_url   = \BuddyNext\Core\PageRouter::profile_url( $bn_mod_uid );
						$bn_mod_owner = 'owner' === $bn_mod->role;
						?>
						<li class="bn-sh-side-member bn-sh-side-mod">
							<a class="bn-sh-side-mod__id" href="<?php echo esc_url( $bn_mod_url ); ?>">
								<span class="bn-avatar bn-sh-side-member__avatar"
									data-size="sm"
									style="background:<?php echo esc_attr( bn_sh_avatar_color( $bn_mod_uid ) ); ?>;color:#fff;"
									aria-hidden="true"
								><?php echo esc_html( $bn_mod_init ); ?></span>
								<span class="bn-sh-side-member__name">
									<?php echo esc_html( $bn_mod_name ); ?>
									<span class="bn-badge" data-tone="<?php echo $bn_mod_owner ? 'paid' : 'accent'; ?>">
										<?php echo $bn_mod_owner ? esc_html__( 'Admin', 'buddynext' ) : esc_html__( 'Mod', 'buddynext' ); ?>
									</span>
								</span>
							</a>
							<?php if ( $bn_msgs_on ) : ?>
								<a
									class="bn-btn bn-btn--sm bn-btn--ghost bn-sh-side-mod__msg"
									href="<?php echo esc_url( add_query_arg( 'recipient', $bn_mod_uid, home_url( '/messages/' ) ) ); ?>"
									aria-label="
									<?php
									/* translators: %s: moderator display name */
									echo esc_attr( sprintf( __( 'Message %s', 'buddynext' ), $bn_mod_name ) );
									?>
									"
								><?php buddynext_icon( 'mail' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$bn_mods_html = (string) ob_get_clean();

				buddynext_get_template(
					'parts/sidebar-card.php',
					array(
						'id'         => 'space-moderators',
						'title'      => _n( 'Moderator', 'Moderators', count( $bn_mods ), 'buddynext' ),
						'title_icon' => 'shield',
						'body_html'  => $bn_mods_html,
					)
				);
		}

			// Card 3: Members preview (regular members only — mods sit in the card above).
		if ( ! empty( $bn_regulars ) ) {
			ob_start();
			?>
			<ul class="bn-sh-side-members">
				<?php foreach ( $bn_regulars as $bn_m ) : ?>
					<?php
					$bn_uid   = (int) $bn_m->user_id;
					$bn_mname = $bn_m->display_name ?? __( 'Member', 'buddynext' );
					$bn_init  = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_mname );
					$bn_murl  = \BuddyNext\Core\PageRouter::profile_url( $bn_uid );
					?>
					<li class="bn-sh-side-member">
						<a class="bn-sh-side-member__id" href="<?php echo esc_url( $bn_murl ); ?>">
							<span class="bn-avatar bn-sh-side-member__avatar"
								data-size="sm"
								style="background:<?php echo esc_attr( bn_sh_avatar_color( $bn_uid ) ); ?>;color:#fff;"
								aria-hidden="true"
							><?php echo esc_html( $bn_init ); ?></span>
							<span class="bn-sh-side-member__name">
								<?php echo esc_html( $bn_mname ); ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_members_html = (string) ob_get_clean();

			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'            => 'space-members',
					'title'         => __( 'Members', 'buddynext' ),
					'title_icon'    => 'users',
					'body_html'     => $bn_members_html,
					'see_all_url'   => add_query_arg( 'bn_tab', 'members' ),
					'see_all_label' => __( 'See all members', 'buddynext' ),
				)
			);
		}

		// Card 3: Top contributors.
		if ( ! empty( $bn_s['top_contributors'] ) ) {
			ob_start();
			?>
			<ul class="bn-sh-side-members">
				<?php foreach ( $bn_s['top_contributors'] as $bn_rank => $bn_c ) : ?>
					<?php
					$bn_cuid  = (int) $bn_c->user_id;
					$bn_cname = $bn_c->display_name ?? __( 'Member', 'buddynext' );
					$bn_cinit = \BuddyNext\Profile\AvatarService::initials_for( (string) $bn_cname );
					$bn_curl  = \BuddyNext\Core\PageRouter::profile_url( $bn_cuid );
					?>
					<li class="bn-sh-side-member">
						<span class="bn-sh-side-member__rank"><?php echo esc_html( (string) ( $bn_rank + 1 ) ); ?></span>
						<a class="bn-sh-side-member__id" href="<?php echo esc_url( $bn_curl ); ?>">
							<span class="bn-avatar bn-sh-side-member__avatar"
								data-size="sm"
								style="background:<?php echo esc_attr( bn_sh_avatar_color( $bn_cuid ) ); ?>;color:#fff;"
								aria-hidden="true"
							><?php echo esc_html( $bn_cinit ); ?></span>
							<span class="bn-sh-side-member__name"><?php echo esc_html( $bn_cname ); ?></span>
						</a>
						<span class="bn-sh-side-member__count">
							<?php
							// translators: %d: post count.
							printf( esc_html( _n( '%d post', '%d posts', (int) $bn_c->post_count, 'buddynext' ) ), (int) $bn_c->post_count );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_contrib_html = (string) ob_get_clean();

			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'         => 'space-contributors',
					'title'      => __( 'Top contributors', 'buddynext' ),
					'title_icon' => 'award',
					'body_html'  => $bn_contrib_html,
				)
			);
		}
	}
);

/**
 * Fires before the space-home inner content.
 *
 * @param int $space_id Current space ID.
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_space_home_before', $space_id, $current_user_id );

// ── Render ───────────────────────────────────────────────────────────────────

// Tab entries use the array shape so the count chip (v2 prototype pattern)
// surfaces under each label. `count` is the integer rendered inside
// `<span class="bn-tab__count">` by `parts/space-tab-bar.php`.
// Media tab only when WPMediaVerse is active AND the space owner enabled it
// (Settings > Integrations > "WPMediaVerse Media"). Mirrors the option the
// settings page writes/reads, bn_space_{id}_mvs_media_tab (default off).
$bn_media_tab_on = \BuddyNext\Media\MediaClient::available() && (bool) get_option( 'bn_space_' . $space->id . '_mvs_media_tab', 0 );

$bn_nav_tabs = array(
	'feed'    => array(
		'label' => __( 'Feed', 'buddynext' ),
		'count' => (int) $bn_post_count,
	),
	'members' => array(
		'label' => __( 'Members', 'buddynext' ),
		'count' => (int) $space->member_count,
	),
);

if ( $bn_media_tab_on ) {
	$bn_nav_tabs['media'] = array(
		'label' => __( 'Media', 'buddynext' ),
		'count' => (int) $bn_media_count,
	);
} elseif ( 'media' === $active_tab ) {
	// Direct ?bn_tab=media URL while the media tab is disabled — fall back to Feed
	// so the gallery body branch never renders for a hidden tab.
	$active_tab = 'feed';
}

$bn_nav_tabs['about'] = array(
	'label' => __( 'About', 'buddynext' ),
);

if ( $can_moderate ) {
	$bn_nav_tabs['moderation'] = array(
		'label' => __( 'Moderation', 'buddynext' ),
		'count' => (int) $bn_mod_count + (int) $bn_pending_count,
	);
}

/**
 * Filters the tab list shown in the space navigation bar.
 *
 * @param array $tabs     Associative array: tab_key => label|config.
 * @param int   $space_id BuddyNext space ID.
 */
$bn_nav_tabs = apply_filters( 'buddynext_space_tabs', $bn_nav_tabs, $space->id );
?>
<div class="bn-sh-stack"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'restNonce' => $rest_nonce,
				'restUrl'   => rest_url( 'buddynext/v1' ),
			)
		)
	);
	?>
	'
>

	<!-- Hero -->
	<?php
	// Header stats. Counts at zero are not shown — an empty "0 Posts" promotes
	// emptiness and adds no information. Visibility (Open/Private) is already
	// rendered as a badge next to the space name, so it is not repeated here.
	// "Active 7d" was dropped: it measured only members who posted in 7 days,
	// which has no real-world precedent and reads 0 for healthy lurking spaces.
	$bn_hero_stats = array();

	if ( (int) $space->member_count > 0 ) {
		$bn_hero_stats[] = array(
			'label' => __( 'Members', 'buddynext' ),
			'value' => $member_count_fmt,
			'icon'  => 'users',
		);
	}

	if ( $bn_post_count > 0 ) {
		$bn_hero_stats[] = array(
			'label' => __( 'Posts', 'buddynext' ),
			'value' => number_format_i18n( $bn_post_count ),
			'icon'  => 'message-circle',
		);
	}

	$bn_hero_stats[] = array(
		'label' => __( 'Created', 'buddynext' ),
		'value' => ! empty( $space->created_at ) ? buddynext_date_local( (string) $space->created_at, 'M Y' ) : '—',
		'icon'  => 'calendar',
	);
	buddynext_get_template(
		'parts/space-hero.php',
		array(
			'space'           => $space,
			'space_id'        => $space_id,
			'current_user_id' => $current_user_id,
			'is_member'       => $is_member,
			'is_owner'        => $is_admin_mod,
			'is_pending'      => $is_pending,
			'is_invited'      => $is_invited,
			'is_guest'        => $is_guest,
			'privacy_label'   => $privacy_label,
			'privacy_tone'    => $privacy_tone,
			'notif_pref'      => $bn_notif_pref,
			'stats'           => $bn_hero_stats,
			'active_tab'      => $active_tab,
			'tabs'            => $bn_nav_tabs,
		)
	);
	?>

	<?php if ( $is_invited ) : ?>
		<!-- Pending space invitation for the current user -->
		<div class="bn-card bn-sh-invite" role="region" aria-label="<?php esc_attr_e( 'Space invitation', 'buddynext' ); ?>">
			<div class="bn-sh-invite__text">
				<span class="bn-sh-invite__icon" aria-hidden="true"><?php buddynext_icon( 'bell' ); ?></span>
				<span><?php esc_html_e( "You've been invited to join this space.", 'buddynext' ); ?></span>
			</div>
			<div class="bn-sh-invite__actions">
				<button class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.acceptInvite">
					<?php esc_html_e( 'Accept', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.declineInvite">
					<?php esc_html_e( 'Decline', 'buddynext' ); ?>
				</button>
			</div>
		</div>
	<?php endif; ?>

	<!-- Tab body -->
	<div class="bn-sh-body">
		<?php if ( $gate_feed ) : ?>

			<div class="bn-card bn-sh-gate">
				<div class="bn-sh-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
				<h2 class="bn-sh-gate__title"><?php esc_html_e( 'This is a private space', 'buddynext' ); ?></h2>
				<p class="bn-sh-gate__lede">
					<?php
					echo $is_invited
						? esc_html__( 'Accept the invitation above to read posts and participate.', 'buddynext' )
						: esc_html__( 'Join to read posts and participate in discussions.', 'buddynext' );
					?>
				</p>
				<?php if ( $is_invited ) : ?>
					<?php // The invitation banner above owns Accept/Decline; the gate shows no join CTA. ?>
				<?php elseif ( $is_guest ) : ?>
					<a
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $space->slug ) ) ); ?>"
						class="bn-btn"
						data-variant="primary"
						data-size="md"
					>
						<?php esc_html_e( 'Log in to request access', 'buddynext' ); ?>
					</a>
				<?php elseif ( $is_pending ) : ?>
					<button
						class="bn-btn"
						data-variant="secondary"
						data-size="md"
						data-current-state="pending"
						data-wp-on--click="actions.cancelJoinRequest"
					>
						<?php esc_html_e( 'Request pending', 'buddynext' ); ?>
					</button>
				<?php else : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="md"
						data-current-state="request"
						data-wp-on--click="actions.requestJoin"
					>
						<?php esc_html_e( 'Request to join', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			</div>

		<?php elseif ( 'media' === $active_tab ) : ?>

			<?php
			// Media tab — media shared in this space, gathered from the space's
			// own posts (BuddyNext owns the post↔media linkage) and resolved
			// BN-native. No WP attachments, no dropped mvs_media CPT — all media
			// lives in mvs_media_index and renders through MediaRenderer. The
			// flatten/de-dup/cap pipeline lives in FeedService::space_media_ids().
			$space_media_ids = \BuddyNext\Media\MediaClient::available()
				? $bn_feed_service->space_media_ids( $space_id, 24 )
				: array();
			?>
			<?php if ( ! empty( $space_media_ids ) ) : ?>
				<?php echo \BuddyNext\Media\MediaRenderer::gallery( $space_media_ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer::gallery() returns escaped markup. ?>
			<?php else : ?>
				<?php
				buddynext_get_template(
					'parts/empty-state.php',
					array(
						'icon'  => 'camera',
						'title' => __( 'No media in this space yet', 'buddynext' ),
						'body'  => __( 'Share a photo to get started.', 'buddynext' ),
					)
				);
				?>
			<?php endif; ?>

		<?php elseif ( 'members' === $active_tab && ! $gate_feed ) : ?>

			<?php
			$bn_member_filter = isset( $_GET['bn_role'] ) ? sanitize_key( wp_unslash( $_GET['bn_role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			buddynext_get_template(
				'parts/space-members-panel.php',
				array(
					'space'            => $space,
					'members'          => $bn_full_members,
					'top_contributors' => $top_contributors,
					'viewer_id'        => $current_user_id,
					'member_count_fmt' => $member_count_fmt,
					'active_role'      => $bn_member_filter,
				)
			);
			?>

		<?php elseif ( 'moderation' === $active_tab && $can_moderate ) : ?>

			<div class="bn-card bn-sh-moderation">
				<header>
					<h2 class="bn-sh-moderation__title"><?php esc_html_e( 'Moderation', 'buddynext' ); ?></h2>
					<p>
						<?php esc_html_e( 'Manage pending join requests and reported posts.', 'buddynext' ); ?>
						<a href="<?php echo esc_url( buddynext_space_moderation_url( $space->slug ) ); ?>" class="bn-link">
							<?php esc_html_e( 'Open full moderation queue', 'buddynext' ); ?>
						</a>
					</p>
				</header>
				<div class="bn-sh-moderation__stats">
					<a class="bn-sh-moderation__stat" href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'pending', buddynext_space_moderation_url( $space->slug ) ) ); ?>">
						<span class="bn-sh-moderation__stat-num"><?php echo esc_html( number_format_i18n( (int) $bn_pending_count ) ); ?></span>
						<span class="bn-sh-moderation__stat-label"><?php esc_html_e( 'Pending join requests', 'buddynext' ); ?></span>
					</a>
					<a class="bn-sh-moderation__stat" href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'reports', buddynext_space_moderation_url( $space->slug ) ) ); ?>">
						<span class="bn-sh-moderation__stat-num"><?php echo esc_html( number_format_i18n( (int) $bn_mod_count ) ); ?></span>
						<span class="bn-sh-moderation__stat-label"><?php esc_html_e( 'Reported posts', 'buddynext' ); ?></span>
					</a>
				</div>
			</div>

		<?php elseif ( 'about' === $active_tab ) : ?>

			<?php
			buddynext_get_template(
				'parts/space-about-panel.php',
				array(
					'space' => $space,
					'meta'  => array(
						'privacy_label'    => $privacy_label,
						'privacy_tone'     => $privacy_tone,
						'member_count_fmt' => $member_count_fmt,
					),
				)
			);
			?>

		<?php else : ?>

			<?php if ( $bn_space_archived ) : ?>
				<div class="bn-notice" role="status">
					<?php esc_html_e( 'This space is archived. You can still read past activity, but new posts, comments, and joins are disabled.', 'buddynext' ); ?>
				</div>
			<?php endif; ?>

			<?php
			buddynext_get_template(
				'parts/space-feed-panel.php',
				array(
					'space'        => $space,
					'space_id'     => $space_id,
					'viewer_id'    => $current_user_id,
					'is_member'    => $is_member,
					'can_post'     => $bn_can_post,
					'is_guest'     => $is_guest,
					'is_pending'   => $is_pending,
					'posts'        => $feed_posts,
					'pinned_post'  => $pinned_post,
					'current_user' => $bn_current_user,
				)
			);
			?>

		<?php endif; ?>
	</div>

	<?php
	// Share modal — the space feed renders post cards whose Share action
	// dispatches bn-open-share-modal, but without this include there is no modal
	// island to receive it, so Share did nothing inside a space.
	buddynext_get_template(
		'partials/share-modal.php',
		array( 'current_user_id' => $current_user_id )
	);
	?>

</div>
<?php
/**
 * Fires after the space-home inner content.
 *
 * @param int $space_id Current space ID.
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_space_home_after', $space_id, $current_user_id );
