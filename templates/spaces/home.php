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

if ( ! function_exists( 'bn_sh_initials' ) ) {
	/**
	 * Return initials (up to 2 chars) from a display name.
	 *
	 * @param string $name Full display name.
	 * @return string Uppercase initials.
	 */
	function bn_sh_initials( string $name ): string {
		$parts = array_filter( explode( ' ', trim( $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( end( $parts ), 0, 1 ) );
		}
		return strtoupper( mb_substr( $name, 0, 2 ) );
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

if ( ! function_exists( 'bn_sh_time_diff' ) ) {
	/**
	 * Human-readable time diff label (e.g. "3h ago").
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string Localized time diff.
	 */
	function bn_sh_time_diff( string $datetime ): string {
		return human_time_diff( strtotime( $datetime ), time() ) . ' ' . __( 'ago', 'buddynext' );
	}
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
$is_invited   = $membership && 'invited' === $membership->status;
$is_guest     = ( 0 === (int) $current_user_id );

// Posting permission (Permissions panel → "Who can post"): members | mods | owner.
// A site admin, or any member whose role meets the configured threshold, may post.
// This drives whether the composer is rendered in the feed panel; the REST
// endpoint enforces the same rule server-side so the gate is not bypassable.
$bn_member_role  = ( $membership && 'active' === $membership->status ) ? (string) $membership->role : '';
$bn_who_can_post = (string) get_option( 'bn_space_' . $space_id . '_who_can_post', 'members' );
$bn_role_rank    = array(
	'member'    => 1,
	'moderator' => 2,
	'owner'     => 3,
);
$bn_required_rank = array(
	'members' => 1,
	'mods'    => 2,
	'owner'   => 3,
);
$bn_can_post = $is_member && (
	current_user_can( 'manage_options' )
	|| ( $bn_role_rank[ $bn_member_role ] ?? 0 ) >= ( $bn_required_rank[ $bn_who_can_post ] ?? 1 )
);

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
				AND ( p.scheduled_at IS NULL OR p.scheduled_at <= UTC_TIMESTAMP() )
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
				AND ( p.scheduled_at IS NULL OR p.scheduled_at <= UTC_TIMESTAMP() )
			ORDER BY p.created_at DESC
			LIMIT 20",
			$space_id
		),
		ARRAY_A
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
			AND ( p.scheduled_at IS NULL OR p.scheduled_at <= UTC_TIMESTAMP() )
		GROUP BY p.user_id
		ORDER BY post_count DESC
		LIMIT 3",
		$space_id
	)
);

// ── Counts for stat strip + tabs ──────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$bn_post_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND status = 'published' AND ( scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP() )",
		$space_id
	)
);

// Media tab count — posts in this space carrying at least one media attachment.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$bn_media_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
		 WHERE space_id = %d AND status = 'published' AND ( scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP() )
		   AND media_ids IS NOT NULL AND media_ids != '[]' AND media_ids != ''",
		$space_id
	)
);

// Moderation tab count — reports against content in this space that
// still need a decision. `pending` is the queued state; `escalated` is
// also still actionable (admin review). Resolved only when the viewer
// is admin/mod; everyone else gets 0 so the count chip never leaks
// the queue size to non-moderators.
$bn_mod_count = 0;
// Pending join requests waiting on an owner/mod decision. Surfaced alongside
// reports so the Moderation tab badge and summary reflect everything the
// moderator still needs to action — otherwise join requests are invisible at
// the space level (only reachable by opening the full queue and switching tabs).
$bn_pending_count = 0;
if ( ! empty( $is_admin_mod ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_mod_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports
			 WHERE space_id = %d AND status IN ( 'pending', 'escalated' )",
			$space_id
		)
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_pending_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members
			 WHERE space_id = %d AND status = 'pending'",
			$space_id
		)
	);
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$bn_active_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND status = 'published' AND ( scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP() ) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
		$space_id
	)
);

$active_tab       = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$member_count_fmt = number_format_i18n( (int) $space->member_count );

$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) $space->type );
$privacy_tone  = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) $space->type );

$bn_current_user = $current_user_id ? get_userdata( $current_user_id ) : null;
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// Per-space notification preference for the current user.
$bn_notif_pref = 'all';
if ( $is_member ) {
	$bn_notif_pref = ( new \BuddyNext\Spaces\SpaceMemberService() )->get_notification_pref( $space_id, $current_user_id );
}

// Members and moderation tabs require fetched data when active.
$bn_full_members = array();
$bn_tab_lookup   = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'members' === $bn_tab_lookup && ! $gate_feed ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_full_members = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT sm.user_id, sm.role, u.display_name, u.user_login
			 FROM {$wpdb->prefix}bn_space_members sm
			 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
			 WHERE sm.space_id = %d AND sm.status = 'active'
			 ORDER BY FIELD( sm.role, 'owner', 'moderator', 'member' ), sm.joined_at ASC
			 LIMIT 100",
			$space_id
		)
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
					printf( esc_html__( 'Created %s', 'buddynext' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bn_s['space']->created_at ) ) ) );
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
						$bn_mod_init  = bn_sh_initials( $bn_mod_name );
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
					$bn_init  = bn_sh_initials( $bn_mname );
					?>
					<li class="bn-sh-side-member">
						<span class="bn-avatar bn-sh-side-member__avatar"
							data-size="sm"
							style="background:<?php echo esc_attr( bn_sh_avatar_color( $bn_uid ) ); ?>;color:#fff;"
							aria-hidden="true"
						><?php echo esc_html( $bn_init ); ?></span>
						<span class="bn-sh-side-member__name">
							<?php echo esc_html( $bn_mname ); ?>
						</span>
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
					$bn_cinit = bn_sh_initials( $bn_cname );
					?>
					<li class="bn-sh-side-member">
						<span class="bn-sh-side-member__rank"><?php echo esc_html( (string) ( $bn_rank + 1 ) ); ?></span>
						<span class="bn-avatar bn-sh-side-member__avatar"
							data-size="sm"
							style="background:<?php echo esc_attr( bn_sh_avatar_color( $bn_cuid ) ); ?>;color:#fff;"
							aria-hidden="true"
						><?php echo esc_html( $bn_cinit ); ?></span>
						<span class="bn-sh-side-member__name"><?php echo esc_html( $bn_cname ); ?></span>
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

if ( $is_admin_mod ) {
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
	$bn_hero_stats = array(
		array(
			'label' => __( 'Members', 'buddynext' ),
			'value' => $member_count_fmt,
			'icon'  => 'users',
		),
		array(
			'label' => __( 'Posts', 'buddynext' ),
			'value' => number_format_i18n( $bn_post_count ),
			'icon'  => 'message-circle',
		),
		array(
			'label' => __( 'Active 7d', 'buddynext' ),
			'value' => number_format_i18n( $bn_active_count ),
			'icon'  => 'activity',
		),
		array(
			'label' => __( 'Created', 'buddynext' ),
			'value' => ! empty( $space->created_at ) ? date_i18n( 'M Y', strtotime( $space->created_at ) ) : '—',
			'icon'  => 'calendar',
		),
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
						data-variant="ghost"
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
			// lives in mvs_media_index and renders through MediaRenderer.
			$space_media_ids = array();
			if ( \BuddyNext\Media\MediaClient::available() ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$bn_space_media_rows = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT media_ids FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND media_ids IS NOT NULL AND media_ids != '' AND status = 'published' AND ( scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP() ) ORDER BY created_at DESC LIMIT 60",
						$space_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( $bn_space_media_rows as $bn_json ) {
					$bn_decoded = json_decode( (string) $bn_json, true );
					if ( is_array( $bn_decoded ) ) {
						foreach ( $bn_decoded as $bn_mid ) {
							$space_media_ids[] = absint( $bn_mid );
						}
					}
				}
				$space_media_ids = array_slice( array_values( array_unique( array_filter( $space_media_ids ) ) ), 0, 24 );
			}
			?>
			<?php if ( ! empty( $space_media_ids ) ) : ?>
				<?php echo \BuddyNext\Media\MediaRenderer::gallery( $space_media_ids ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
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

		<?php elseif ( 'moderation' === $active_tab && $is_admin_mod ) : ?>

			<div class="bn-card bn-sh-moderation">
				<header>
					<h2><?php esc_html_e( 'Moderation', 'buddynext' ); ?></h2>
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

</div>
<?php
/**
 * Fires after the space-home inner content.
 *
 * @param int $space_id Current space ID.
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_space_home_after', $space_id, $current_user_id );
