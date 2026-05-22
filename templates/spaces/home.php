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

// Access gate: private spaces.
$gate_feed = ( 'open' !== $space->type && ! $is_member && ! current_user_can( 'manage_options' ) );

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
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND status = 'published'",
		$space_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$bn_active_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND status = 'published' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
		$space_id
	)
);

$active_tab       = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$member_count_fmt = number_format_i18n( (int) $space->member_count );

$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) $space->type );
$privacy_tone = match ( $space->type ) {
	'open'    => 'info',
	'private' => 'warn',
	default   => 'danger',
};

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

		// Card 1: About.
		ob_start();
		if ( ! empty( $bn_s['space']->description ) ) :
			?>
			<p class="bn-sh-side-text"><?php echo esc_html( $bn_s['space']->description ); ?></p>
			<?php
		endif;
		?>
		<div class="bn-sh-side-stats">
			<div class="bn-sh-side-stat">
				<span class="bn-sh-side-stat__num"><?php echo esc_html( $bn_s['member_count_fmt'] ); ?></span>
				<span class="bn-sh-side-stat__label"><?php esc_html_e( 'Members', 'buddynext' ); ?></span>
			</div>
			<div class="bn-sh-side-stat">
				<span class="bn-sh-side-stat__num"><?php echo esc_html( number_format_i18n( $bn_s['post_count'] ) ); ?></span>
				<span class="bn-sh-side-stat__label"><?php esc_html_e( 'Posts', 'buddynext' ); ?></span>
			</div>
		</div>
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

		// Card 2: Members preview.
		if ( ! empty( $bn_s['sidebar_members'] ) ) {
			ob_start();
			?>
			<ul class="bn-sh-side-members">
				<?php foreach ( $bn_s['sidebar_members'] as $bn_m ) : ?>
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
							<?php if ( 'owner' === $bn_m->role ) : ?>
								<span class="bn-badge" data-tone="paid"><?php esc_html_e( 'Admin', 'buddynext' ); ?></span>
							<?php elseif ( 'moderator' === $bn_m->role ) : ?>
								<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Mod', 'buddynext' ); ?></span>
							<?php endif; ?>
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

$bn_nav_tabs = array(
	'feed'    => __( 'Feed', 'buddynext' ),
	'members' => __( 'Members', 'buddynext' ),
	'media'   => __( 'Media', 'buddynext' ),
	'about'   => __( 'About', 'buddynext' ),
);

if ( $is_admin_mod ) {
	$bn_nav_tabs['moderation'] = __( 'Moderation', 'buddynext' );
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
	<section class="bn-sh-hero">
		<div class="bn-sh-hero__cover"<?php echo empty( $space->cover_image_url ) ? '' : ' style="background-image:url(' . esc_url( $space->cover_image_url ) . ');background-size:cover;background-position:center;"'; ?>>
			<span class="bn-sh-hero__cover-tone" aria-hidden="true"></span>
		</div>

		<div class="bn-sh-hero__head">
			<div class="bn-sh-hero__emblem" aria-hidden="true">
				<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
			</div>

			<div class="bn-sh-hero__info">
				<h1 class="bn-sh-hero__name">
					<?php echo esc_html( $space->name ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span>
				</h1>
				<?php if ( ! empty( $space->category_name ) ) : ?>
					<div class="bn-sh-hero__handle">
						<?php buddynext_icon( 'hash' ); ?>
						<?php echo esc_html( $space->category_name ); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="bn-sh-hero__actions" data-space-id="<?php echo esc_attr( (string) $space_id ); ?>">
				<?php if ( $is_member ) : ?>
					<div class="bn-sh-notif" data-bn-notif-popover>
						<button
							type="button"
							class="bn-btn"
							data-variant="ghost"
							data-size="sm"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-label="<?php esc_attr_e( 'Notification preferences', 'buddynext' ); ?>"
							data-bn-notif-trigger
							data-wp-on--click="actions.toggleNotifPopover"
						><?php buddynext_icon( 'bell' ); ?></button>
						<ul class="bn-sh-notif__list" role="listbox" hidden data-bn-notif-list>
							<?php
							$bn_notif_options = array(
								'all'      => __( 'All activity', 'buddynext' ),
								'mentions' => __( 'Mentions only', 'buddynext' ),
								'none'     => __( 'None', 'buddynext' ),
							);
							foreach ( $bn_notif_options as $bn_pref_val => $bn_pref_label ) :
								?>
								<li>
									<button
										type="button"
										class="bn-sh-notif__option"
										role="option"
										aria-selected="<?php echo ( $bn_notif_pref === $bn_pref_val ) ? 'true' : 'false'; ?>"
										data-bn-notif-pref="<?php echo esc_attr( $bn_pref_val ); ?>"
										data-wp-on--click="actions.setNotificationPref"
									><?php echo esc_html( $bn_pref_label ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $is_admin_mod ) : ?>
					<button
						type="button"
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
						data-wp-on--click="actions.openInviteModal"
					><?php buddynext_icon( 'user-plus' ); ?> <?php esc_html_e( 'Invite', 'buddynext' ); ?></button>
					<a
						href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ) ); ?>"
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
					><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>

				<?php elseif ( $is_member ) : ?>
					<button
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
						data-current-state="joined"
						data-wp-on--click="actions.leaveSpace"
						aria-label="<?php esc_attr_e( 'Joined - click to leave', 'buddynext' ); ?>"
					><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>

				<?php elseif ( $is_pending ) : ?>
					<button
						class="bn-btn"
						data-variant="ghost"
						data-size="sm"
						data-current-state="pending"
						data-wp-on--click="actions.cancelJoinRequest"
					><?php esc_html_e( 'Request pending', 'buddynext' ); ?></button>

				<?php elseif ( 'open' === $space->type ) : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="sm"
						data-current-state="join"
						data-wp-on--click="actions.joinSpace"
					><?php esc_html_e( 'Join space', 'buddynext' ); ?></button>

				<?php else : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="sm"
						data-current-state="request"
						data-wp-on--click="actions.requestJoin"
					><?php esc_html_e( 'Request to join', 'buddynext' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<?php
		// 4-tile stat strip composed via parts/stat-strip.php (Members / Posts / Active / Created).
		$bn_stats = array(
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
			'parts/stat-strip.php',
			array(
				'stats'   => $bn_stats,
				'classes' => array( 'bn-sh-hero__stats' ),
			)
		);
		?>

		<nav class="bn-tabs bn-sh-hero__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Space navigation', 'buddynext' ); ?>">
			<?php
			foreach ( $bn_nav_tabs as $tab_key => $tab_data ) :
				if ( is_array( $tab_data ) ) {
					// External-link tab injected by an addon.
					$tab_label  = $tab_data['label'] ?? $tab_key;
					$tab_url    = $tab_data['url'] ?? '#';
					$tab_active = false;
					$tab_rel    = 'noopener';
				} else {
					$tab_label  = $tab_data;
					$tab_url    = add_query_arg( 'bn_tab', $tab_key );
					$tab_active = ( $active_tab === $tab_key );
					$tab_rel    = '';
				}
				?>
				<a
					href="<?php echo esc_url( $tab_url ); ?>"
					class="bn-tab<?php echo $tab_active ? ' is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $tab_active ? 'true' : 'false'; ?>"
					<?php echo $tab_rel ? 'rel="' . esc_attr( $tab_rel ) . '"' : ''; ?>
				><span class="bn-tab__label"><?php echo esc_html( $tab_label ); ?></span></a>
			<?php endforeach; ?>
		</nav>
	</section>

	<!-- Tab body -->
	<div class="bn-sh-body">
		<?php if ( $gate_feed ) : ?>

			<div class="bn-card bn-sh-gate">
				<div class="bn-sh-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
				<h2 class="bn-sh-gate__title"><?php esc_html_e( 'This is a private space', 'buddynext' ); ?></h2>
				<p class="bn-sh-gate__lede">
					<?php esc_html_e( 'Join to read posts and participate in discussions.', 'buddynext' ); ?>
				</p>
				<button
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					data-current-state="request"
					data-wp-on--click="actions.requestJoin"
				>
					<?php esc_html_e( 'Request to join', 'buddynext' ); ?>
				</button>
			</div>

		<?php elseif ( 'media' === $active_tab ) : ?>

			<?php
			// Media tab — show all MVS media uploaded in this space.
			$space_media = array();
			if ( class_exists( 'WPMediaVerse\Core\Plugin' ) && post_type_exists( 'mvs_media' ) ) {
				$space_media = get_posts(
					array(
						'post_type'   => 'mvs_media',
						'numberposts' => 24,
						'post_status' => 'publish',
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => '_mvs_space_id',
								'value' => $space_id,
							),
						),
					)
				);
				// Fallback: media from photo-type posts in this space.
				if ( empty( $space_media ) ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$photo_ids_raw = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT media_ids FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND type = 'photo' AND media_ids IS NOT NULL AND media_ids != '' AND status = 'published' ORDER BY created_at DESC LIMIT 24",
							$space_id
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$all_ids = array();
					foreach ( $photo_ids_raw as $json_str ) {
						$decoded = json_decode( $json_str, true );
						if ( is_array( $decoded ) ) {
							$all_ids = array_merge( $all_ids, $decoded );
						}
					}
					if ( $all_ids ) {
						$space_media = get_posts(
							array(
								'post_type'   => 'attachment',
								'post__in'    => array_slice( array_map( 'absint', $all_ids ), 0, 24 ),
								'post_status' => 'inherit',
							)
						);
					}
				}
			}
			?>
			<?php if ( $space_media ) : ?>
				<div class="bn-sh-media-grid mvs-activity-media-grid">
					<?php foreach ( $space_media as $sm ) : ?>
						<?php
						$sm_url = get_post_meta( $sm->ID, '_mvs_file_url', true );
						if ( ! $sm_url ) {
							$sm_url = wp_get_attachment_image_url( $sm->ID, 'medium' );
						}
						if ( ! $sm_url ) {
							$sm_url = wp_get_attachment_url( $sm->ID );
						}
						$sm_full = wp_get_attachment_url( $sm->ID );
						?>
						<div class="bn-sh-media-item mvs-activity-media" data-mvs-media-id="<?php echo esc_attr( (string) $sm->ID ); ?>" data-mvs-src="<?php echo esc_url( (string) $sm_full ); ?>">
							<a href="<?php echo esc_url( (string) ( $sm_full ? $sm_full : $sm_url ) ); ?>" class="mvs-grid-item-link">
								<img src="<?php echo esc_url( (string) $sm_url ); ?>" alt="<?php echo esc_attr( $sm->post_title ); ?>" loading="lazy">
							</a>
						</div>
					<?php endforeach; ?>
				</div>
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

			<div class="bn-card bn-sh-members">
				<header class="bn-sh-members__head">
					<h2 class="bn-sh-members__title"><?php esc_html_e( 'Members', 'buddynext' ); ?></h2>
					<p class="bn-sh-members__count">
						<?php
						printf(
							/* translators: %s: formatted member count. */
							esc_html( _n( '%s member', '%s members', (int) $space->member_count, 'buddynext' ) ),
							esc_html( $member_count_fmt )
						);
						?>
					</p>
				</header>

				<?php if ( empty( $bn_full_members ) ) : ?>
					<p class="bn-sh-members__empty"><?php esc_html_e( 'No members yet.', 'buddynext' ); ?></p>
				<?php else : ?>
					<ul class="bn-sh-members__grid" role="list">
						<?php foreach ( $bn_full_members as $bn_fm ) : ?>
							<?php
							$bn_fm_uid    = (int) $bn_fm->user_id;
							$bn_fm_name   = $bn_fm->display_name ?: $bn_fm->user_login;
							$bn_fm_avatar = get_avatar_url( $bn_fm_uid, array( 'size' => 80 ) );
							$bn_fm_role   = in_array( $bn_fm->role, array( 'owner', 'moderator', 'member' ), true ) ? $bn_fm->role : 'member';
							$bn_role_tone = match ( $bn_fm_role ) {
								'owner'     => 'accent',
								'moderator' => 'info',
								default     => 'default',
							};
							$bn_role_label = match ( $bn_fm_role ) {
								'owner'     => __( 'Owner', 'buddynext' ),
								'moderator' => __( 'Moderator', 'buddynext' ),
								default     => __( 'Member', 'buddynext' ),
							};
							$bn_fm_profile = function_exists( 'buddynext_member_url' )
								? buddynext_member_url( $bn_fm_uid )
								: get_author_posts_url( $bn_fm_uid );
	?>
							<li class="bn-sh-members__card" role="listitem">
								<a href="<?php echo esc_url( $bn_fm_profile ); ?>" class="bn-sh-members__avatar-link">
									<span class="bn-avatar" data-size="md" aria-hidden="true">
										<?php if ( $bn_fm_avatar ) : ?>
											<img src="<?php echo esc_url( $bn_fm_avatar ); ?>" alt="" loading="lazy">
										<?php else : ?>
											<?php echo esc_html( strtoupper( mb_substr( $bn_fm_name, 0, 1 ) ) ); ?>
										<?php endif; ?>
									</span>
								</a>
								<div class="bn-sh-members__info">
									<a href="<?php echo esc_url( $bn_fm_profile ); ?>" class="bn-sh-members__name">
										<?php echo esc_html( $bn_fm_name ); ?>
									</a>
									<span class="bn-badge" data-tone="<?php echo esc_attr( $bn_role_tone ); ?>"><?php echo esc_html( $bn_role_label ); ?></span>
								</div>
								<?php if ( $current_user_id && $current_user_id !== $bn_fm_uid ) : ?>
									<div class="bn-sh-members__actions">
										<?php
										if ( function_exists( 'buddynext_follow_button' ) ) {
											buddynext_follow_button( $bn_fm_uid );
										}
										?>
									</div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

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
			</div>

		<?php elseif ( 'about' === $active_tab ) : ?>

			<?php
			// About tab — description, rules, categories, metadata. Rules are
			// rendered from the bn_spaces.rules column (one per line). Empty
			// sections (no rules, no category) collapse so we don't render
			// orphan headings.
			$bn_about_rules_raw = isset( $space->rules ) ? (string) $space->rules : '';
			$bn_about_rules     = array();
			if ( '' !== trim( $bn_about_rules_raw ) ) {
				foreach ( preg_split( "/\r\n|\n|\r/", $bn_about_rules_raw ) as $bn_rule_line ) {
					$bn_rule_line = trim( $bn_rule_line );
					if ( '' !== $bn_rule_line ) {
						$bn_about_rules[] = $bn_rule_line;
					}
				}
			}
			?>
			<div class="bn-card bn-sh-about">
				<h2 class="bn-sh-about__title"><?php esc_html_e( 'About', 'buddynext' ); ?></h2>
				<?php if ( ! empty( $space->description ) ) : ?>
					<div class="bn-sh-about__desc"><?php echo wp_kses_post( wpautop( $space->description ) ); ?></div>
				<?php else : ?>
					<p class="bn-sh-about__desc"><?php esc_html_e( 'No description yet.', 'buddynext' ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $bn_about_rules ) ) : ?>
					<section class="bn-sh-about__rules">
						<h3 class="bn-sh-about__section-title"><?php esc_html_e( 'House rules', 'buddynext' ); ?></h3>
						<ol class="bn-sh-about__rules-list">
							<?php foreach ( $bn_about_rules as $bn_rule ) : ?>
								<li><?php echo esc_html( $bn_rule ); ?></li>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endif; ?>

				<?php if ( ! empty( $space->category_name ) && ! empty( $space->category_slug ) ) : ?>
					<section class="bn-sh-about__categories">
						<h3 class="bn-sh-about__section-title"><?php esc_html_e( 'Category', 'buddynext' ); ?></h3>
						<div class="bn-sh-about__cat-chips">
							<a
								href="<?php echo esc_url( add_query_arg( 'bn_cat', $space->category_slug, \BuddyNext\Core\PageRouter::spaces_url() ) ); ?>"
								class="bn-tab bn-sd-chip"
							>
								<span class="bn-sd-chip__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ) ); ?></span>
								<?php echo esc_html( $space->category_name ); ?>
							</a>
						</div>
					</section>
				<?php endif; ?>

				<dl class="bn-sh-about__meta">
					<div>
						<dt><?php esc_html_e( 'Visibility', 'buddynext' ); ?></dt>
						<dd><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></dd>
					</div>
					<?php if ( ! empty( $space->created_at ) ) : ?>
						<div>
							<dt><?php esc_html_e( 'Created', 'buddynext' ); ?></dt>
							<dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $space->created_at ) ) ); ?></dd>
						</div>
					<?php endif; ?>
					<div>
						<dt><?php esc_html_e( 'Members', 'buddynext' ); ?></dt>
						<dd><?php echo esc_html( $member_count_fmt ); ?></dd>
					</div>
				</dl>
			</div>

		<?php else : ?>

			<?php if ( $is_member && $bn_current_user ) : ?>
				<?php
				buddynext_get_template(
					'partials/composer.php',
					array(
						'space_id'        => $space_id,
						'current_user_id' => $current_user_id,
					)
				);
				?>
			<?php endif; ?>

			<?php if ( $pinned_post ) : ?>
				<div class="bn-card bn-sh-pinned">
					<div class="bn-sh-pinned__label">
						<?php buddynext_icon( 'bookmark' ); ?>
						<?php esc_html_e( 'Pinned announcement', 'buddynext' ); ?>
					</div>
					<p class="bn-sh-pinned__title"><?php echo esc_html( wp_trim_words( $pinned_post->content ?? '', 24 ) ); ?></p>
					<p class="bn-sh-pinned__meta">
						<?php
						// translators: 1: author display name, 2: time ago label.
						printf(
							/* translators: 1: author display name, 2: time ago label. */
							esc_html__( 'Pinned by %1$s · %2$s', 'buddynext' ),
							esc_html( $pinned_post->author_name ?? __( 'Admin', 'buddynext' ) ),
							esc_html( bn_sh_time_diff( $pinned_post->created_at ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $feed_posts ) ) : ?>
				<?php
				buddynext_get_template(
					'parts/empty-state.php',
					array(
						'icon'  => 'message-circle',
						'title' => __( 'No posts yet', 'buddynext' ),
						'body'  => __( 'Be the first to post in this space.', 'buddynext' ),
					)
				);
				?>
			<?php else : ?>
				<div class="bn-sh-feed" role="feed" aria-label="<?php esc_attr_e( 'Space feed', 'buddynext' ); ?>">
					<?php
					foreach ( $feed_posts as $post_arr ) {
						if ( isset( $post_arr['media_ids'] ) && is_string( $post_arr['media_ids'] ) ) {
							$post_arr['media_ids'] = json_decode( $post_arr['media_ids'], true );
						}
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $post_arr,
								'current_user_id' => $current_user_id,
								'context'         => 'space',
							)
						);
					}
					?>
				</div>
			<?php endif; ?>

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
