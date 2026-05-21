<?php
/**
 * Template: Space Home
 *
 * Renders the space hero header (cover, avatar, name, tabs) and the
 * two-column feed + sidebar layout for a single space.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

// ── Access gate: private spaces ───────────────────────────────────────────────

if ( 'open' !== $space->type && ! $is_member && ! current_user_can( 'manage_options' ) ) {
	// Show teaser header only, gate the feed.
	$gate_feed = true;
} else {
	$gate_feed = false;
}

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

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return initials (up to 2 chars) from a display name.
 *
 * @param string $name Full display name.
 * @return string Uppercase initials.
 */
function bn_initials( string $name ): string {
	$parts = array_filter( explode( ' ', trim( $name ) ) );
	if ( count( $parts ) >= 2 ) {
		return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( end( $parts ), 0, 1 ) );
	}
	return strtoupper( mb_substr( $name, 0, 2 ) );
}

/**
 * Return a deterministic avatar background colour based on a user id.
 *
 * @param int $user_id WordPress user ID.
 * @return string CSS hex colour.
 */
function bn_avatar_color( int $user_id ): string {
	$colors = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#d97706' );
	return $colors[ $user_id % count( $colors ) ];
}

/**
 * Human-readable time diff label (e.g. "3h ago").
 *
 * @param string $datetime MySQL datetime string.
 * @return string Localized time diff.
 */
function bn_time_diff( string $datetime ): string {
	return human_time_diff( strtotime( $datetime ), time() ) . ' ' . __( 'ago', 'buddynext' );
}

$active_tab = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : 'feed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$member_count_fmt = number_format_i18n( (int) $space->member_count );

$privacy_label = match ( $space->type ) {
	'open'    => __( 'Open', 'buddynext' ),
	'private' => __( 'Private', 'buddynext' ),
	default   => __( 'Invite-only', 'buddynext' ),
};
$privacy_tone = match ( $space->type ) {
	'open'    => 'info',
	'private' => 'warn',
	default   => 'danger',
};

$bn_current_user = $current_user_id ? get_userdata( $current_user_id ) : null;
$rest_nonce      = wp_create_nonce( 'wp_rest' );

$bn_nav_active = 'spaces';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div class="bn-hub-shell">

<div
	class="bn-space-home"
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

	<!-- Space header -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover">
			<?php if ( ! empty( $space->cover_image_url ) ) : ?>
				<img
					src="<?php echo esc_url( $space->cover_image_url ); ?>"
					alt="<?php echo esc_attr( $space->name ); ?>"
					loading="lazy"
				>
			<?php endif; ?>
		</div>

		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php echo wp_kses_data( bn_space_category_icon( $space->category_slug ?? '' ) ); ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name">
					<?php echo esc_html( $space->name ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span>
				</h1>
				<div class="bn-sh-meta">
					<span><?php buddynext_icon( 'users' ); ?> <?php echo esc_html( $member_count_fmt ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
					<?php if ( ! empty( $space->category_name ) ) : ?>
						<span><?php buddynext_icon( 'hash' ); ?> <?php echo esc_html( $space->category_name ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-sh-actions">
				<?php if ( $is_admin_mod ) : ?>
					<a
						href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ) ); ?>"
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
					><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>
					<a
						href="<?php echo esc_url( buddynext_space_moderation_url( $space->slug ) ); ?>"
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
					><?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Moderation', 'buddynext' ); ?></a>

				<?php elseif ( $is_member ) : ?>
					<button
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
						data-current-state="joined"
						data-wp-on--click="actions.leaveSpace"
						aria-label="<?php esc_attr_e( 'Joined — click to leave', 'buddynext' ); ?>"
					><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>
					<button
						class="bn-btn"
						data-variant="ghost"
						data-size="sm"
						aria-label="<?php esc_attr_e( 'Notifications', 'buddynext' ); ?>"
					><?php buddynext_icon( 'bell' ); ?> <?php esc_html_e( 'Notifications', 'buddynext' ); ?></button>

				<?php elseif ( $is_pending ) : ?>
					<button
						class="bn-btn"
						data-variant="ghost"
						data-size="sm"
						data-current-state="pending"
						data-wp-on--click="actions.cancelJoinRequest"
					><?php esc_html_e( 'Request Pending', 'buddynext' ); ?></button>

				<?php elseif ( 'open' === $space->type ) : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="sm"
						data-current-state="join"
						data-wp-on--click="actions.joinSpace"
					><?php esc_html_e( 'Join Space', 'buddynext' ); ?></button>

				<?php else : ?>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="sm"
						data-current-state="request"
						data-wp-on--click="actions.requestJoin"
					><?php esc_html_e( 'Request to Join', 'buddynext' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<nav class="bn-tabs bn-sh-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Space navigation', 'buddynext' ); ?>">
			<?php
			$bn_nav_tabs = array(
				'feed'    => __( 'Feed', 'buddynext' ),
				'members' => __( 'Members', 'buddynext' ),
				'media'   => __( 'Media', 'buddynext' ),
				'about'   => __( 'About', 'buddynext' ),
			);

			/**
			 * Filters the tab list shown in the space navigation bar.
			 *
			 * Addons can append additional tabs. Each entry is either:
			 *   string  — translated label for an internal ?bn_tab={key} link.
			 *   array   — ['label' => string, 'url' => string] for an external link.
			 *
			 * @param array $tabs     Associative array: tab_key => label|config.
			 * @param int   $space_id BuddyNext space ID.
			 */
			$bn_nav_tabs = apply_filters( 'buddynext_space_tabs', $bn_nav_tabs, $space->id );

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
					class="bn-tab bn-sh-tab"
					role="tab"
					aria-selected="<?php echo $tab_active ? 'true' : 'false'; ?>"
					<?php echo $tab_rel ? 'rel="' . esc_attr( $tab_rel ) . '"' : ''; ?>
				><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>
	</div>

	<!-- Main layout -->
	<div class="bn-sh-layout">

		<!-- Feed column -->
		<main>

			<?php if ( $gate_feed ) : ?>

				<div class="bn-card bn-space-gate">
					<div class="bn-space-gate__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
					<h2 class="bn-space-gate__title"><?php esc_html_e( 'This is a private space', 'buddynext' ); ?></h2>
					<p class="bn-space-gate__lede">
						<?php esc_html_e( 'Join to read posts and participate in discussions.', 'buddynext' ); ?>
					</p>
					<button
						class="bn-btn"
						data-variant="primary"
						data-size="md"
						data-current-state="request"
						data-wp-on--click="actions.requestJoin"
					>
						<?php esc_html_e( 'Request to Join', 'buddynext' ); ?>
					</button>
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
					<div class="bn-pinned">
						<div class="bn-pinned__label"><?php buddynext_icon( 'bookmark' ); ?> <?php esc_html_e( 'Pinned announcement', 'buddynext' ); ?></div>
						<p class="bn-pinned__title"><?php echo esc_html( wp_trim_words( $pinned_post->content ?? '', 12 ) ); ?></p>
						<p class="bn-pinned__meta">
							<?php
							// translators: %s is the author name, %s is the time.
							printf(
								// translators: 1: author display name, 2: time ago label.
								esc_html__( 'Pinned by %1$s &middot; %2$s', 'buddynext' ),
								esc_html( $pinned_post->author_name ?? __( 'Admin', 'buddynext' ) ),
								esc_html( bn_time_diff( $pinned_post->created_at ) )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $feed_posts ) ) : ?>
					<div class="bn-card bn-space-empty">
						<p class="bn-space-empty__title">
							<?php esc_html_e( 'No posts yet', 'buddynext' ); ?>
						</p>
						<p class="bn-space-empty__lede"><?php esc_html_e( 'Be the first to post in this space.', 'buddynext' ); ?></p>
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
						// Fallback: also get media from photo-type posts in this space.
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
					<div class="bn-space-media-grid mvs-activity-media-grid">
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
							<div class="bn-space-media-item mvs-activity-media" data-mvs-media-id="<?php echo esc_attr( (string) $sm->ID ); ?>" data-mvs-src="<?php echo esc_url( (string) $sm_full ); ?>">
								<a href="<?php echo esc_url( (string) ( $sm_full ? $sm_full : $sm_url ) ); ?>" class="mvs-grid-item-link">
									<img src="<?php echo esc_url( (string) $sm_url ); ?>" alt="<?php echo esc_attr( $sm->post_title ); ?>" loading="lazy">
								</a>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="bn-empty-state">
						<?php buddynext_icon( 'camera' ); ?>
						<p><?php esc_html_e( 'No media in this space yet. Share a photo to get started!', 'buddynext' ); ?></p>
					</div>
				<?php endif; ?>

			<?php else : ?>

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

				<?php endif; ?>

			<?php endif; ?>

		</main>

		<!-- Sidebar -->
		<aside aria-label="<?php esc_attr_e( 'Space information', 'buddynext' ); ?>">

			<div class="bn-card bn-sidebar-widget">
				<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'About', 'buddynext' ); ?></h2>
				<?php if ( ! empty( $space->description ) ) : ?>
					<p class="bn-sidebar-about"><?php echo esc_html( $space->description ); ?></p>
				<?php endif; ?>

				<div class="bn-stat-grid bn-sidebar-stats">
					<div class="bn-stat">
						<div class="bn-stat__label"><?php esc_html_e( 'Members', 'buddynext' ); ?></div>
						<div class="bn-stat__value"><?php echo esc_html( $member_count_fmt ); ?></div>
					</div>
					<?php
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$bn_post_count = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE space_id = %d AND status = 'published'",
							$space_id
						)
					);
					?>
					<div class="bn-stat">
						<div class="bn-stat__label"><?php esc_html_e( 'Posts', 'buddynext' ); ?></div>
						<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $bn_post_count ) ); ?></div>
					</div>
				</div>

				<div class="bn-sidebar-meta">
					<?php if ( ! empty( $space->created_at ) ) : ?>
						<div><?php buddynext_icon( 'calendar' ); ?>
						<?php
							// translators: %s is the formatted date.
							printf( esc_html__( 'Created %s', 'buddynext' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $space->created_at ) ) ) );
						?>
							</div>
					<?php endif; ?>
					<div><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></div>
					<?php if ( ! empty( $space->category_name ) ) : ?>
						<div><?php buddynext_icon( 'hash' ); ?> <?php echo esc_html( $space->category_name ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="bn-card bn-sidebar-widget">
				<h2 class="bn-sidebar-widget__title">
					<?php esc_html_e( 'Members', 'buddynext' ); ?>
					<span class="bn-sidebar-widget__count">(<?php echo esc_html( $member_count_fmt ); ?>)</span>
				</h2>

				<?php foreach ( $sidebar_members as $member ) : ?>
					<?php
					$m_uid   = (int) $member->user_id;
					$m_name  = $member->display_name ?? __( 'Member', 'buddynext' );
					$m_color = bn_avatar_color( $m_uid );
					$m_init  = bn_initials( $m_name );
					?>
					<div class="bn-member-row">
						<div
							class="bn-avatar bn-member-row__avatar"
							data-size="sm"
							style="background:<?php echo esc_attr( $m_color ); ?>;"
							aria-label="<?php echo esc_attr( $m_name ); ?>"
						><?php echo esc_html( $m_init ); ?></div>
						<span class="bn-member-row__name">
							<?php echo esc_html( $m_name ); ?>
							<?php if ( 'owner' === $member->role ) : ?>
								<span class="bn-badge" data-tone="paid"><?php esc_html_e( 'Admin', 'buddynext' ); ?></span>
							<?php elseif ( 'moderator' === $member->role ) : ?>
								<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Mod', 'buddynext' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>

				<a
					href="<?php echo esc_url( add_query_arg( 'bn_tab', 'members' ) ); ?>"
					class="bn-sidebar-link"
				><?php esc_html_e( 'See all members', 'buddynext' ); ?> &rarr;</a>
			</div>

			<?php if ( ! empty( $top_contributors ) ) : ?>
				<div class="bn-card bn-sidebar-widget">
					<h2 class="bn-sidebar-widget__title"><?php buddynext_icon( 'award' ); ?> <?php esc_html_e( 'Top Contributors', 'buddynext' ); ?></h2>

					<?php foreach ( $top_contributors as $rank => $contrib ) : ?>
						<?php
						$c_uid   = (int) $contrib->user_id;
						$c_name  = $contrib->display_name ?? __( 'Member', 'buddynext' );
						$c_color = bn_avatar_color( $c_uid );
						$c_init  = bn_initials( $c_name );
						?>
						<div class="bn-top-contrib-row">
							<span class="bn-top-contrib-rank"><?php echo esc_html( (string) ( $rank + 1 ) ); ?></span>
							<div
								class="bn-avatar bn-top-contrib-avatar"
								data-size="xs"
								style="background:<?php echo esc_attr( $c_color ); ?>;"
								aria-label="<?php echo esc_attr( $c_name ); ?>"
							><?php echo esc_html( $c_init ); ?></div>
							<span class="bn-top-contrib-name"><?php echo esc_html( $c_name ); ?></span>
							<span class="bn-top-contrib-count">
								<?php
								// translators: %d is the post count.
								printf( esc_html__( '%d posts', 'buddynext' ), (int) $contrib->post_count );
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</aside>

	</div><!-- /.bn-sh-layout -->

</div><!-- /.bn-space-home -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
