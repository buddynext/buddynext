<?php
/**
 * BuddyNext — Member directory inner template (v2 design system).
 *
 * Renders inside the shell main column (`<main class="bn-app__main">`,
 * see `templates/shell/hub-shell.php`). This inner template does NOT
 * own the rail, the page chrome, or the 2-column grid —
 * the shell handles all of that. Sidebar widgets (online-now, role
 * counts) are registered on the `buddynext_right_sidebar` action; the
 * shell auto-renders the right column when callbacks are present.
 *
 * Canonical layout: `docs/v2 Plans/v2/member-directory.html` plus the
 * 9-rule contract in `docs/v2 Plans/TEMPLATE-REFACTOR-PLAN.md`.
 *
 * Composition: `parts/section-head.php` for the page heading,
 * `parts/filter-strip.php` for tabs + search + sorts,
 * `blocks/member-card.php` per member in `.bn-md-grid`,
 * `parts/pagination.php` for paging,
 * `parts/sidebar-card.php` widgets via the right-sidebar action.
 *
 * Overridable: copy to `{theme}/buddynext/directory/members.php`.
 *
 * REST endpoint: GET buddynext/v1/members.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

// ── Query parameters ──────────────────────────────────────────────────────
$bn_current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_per_page     = 20;
$search_term     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$orderby_raw     = sanitize_key( $_GET['orderby'] ?? 'registered' );                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$relation_raw    = sanitize_key( $_GET['relation'] ?? 'all' );                      // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Accept type slug from the pretty URL rewrite (/members/{slug}/) or a ?type= query arg.
$type_slug_filter = sanitize_key( (string) get_query_var( 'bn_member_type', '' ) );
if ( '' === $type_slug_filter ) {
	$type_slug_filter = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

$allowed_sort = array( 'registered', 'display_name', 'post_count' );
$bn_orderby   = in_array( $orderby_raw, $allowed_sort, true ) ? $orderby_raw : 'registered';
$bn_order     = 'registered' === $bn_orderby ? 'DESC' : 'ASC';

$allowed_relations = array( 'all', 'following', 'connections' );
$bn_relation       = in_array( $relation_raw, $allowed_relations, true ) ? $relation_raw : 'all';

// ── Current user context ──────────────────────────────────────────────────
$current_user_id = get_current_user_id();

// ── Member types for directory pill tabs and card badges ──────────────────
$all_types_raw = buddynext_service( 'member_types' )->get_all();
$dir_types     = array_values( array_filter( $all_types_raw, static fn( $t ) => ! empty( $t['show_in_dir'] ) ) );
// Flat slug → type data map for O(1) card badge lookup inside the member loop.
$type_map = array();
foreach ( $all_types_raw as $t ) {
	$type_map[ (string) $t['slug'] ] = $t;
}
unset( $all_types_raw, $t );

// ── Fetch users ───────────────────────────────────────────────────────────
// Resolve user IDs to exclude: active suspensions + shadow-banned users.
global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$bn_dir_suspended_ids = $wpdb->get_col(
	"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
	 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())"
);

$bn_dir_shadow_banned_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta}
		 WHERE meta_key = %s AND meta_value = '1'",
		'bn_shadow_banned'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$bn_dir_excluded_ids = array_unique(
	array_map( 'intval', array_merge( $bn_dir_suspended_ids, $bn_dir_shadow_banned_ids ) )
);

$user_query_args = array(
	'number'      => $bn_per_page,
	'paged'       => $bn_current_page,
	'orderby'     => $bn_orderby,
	'order'       => $bn_order,
	'fields'      => 'all',
	'count_total' => true,
);

if ( ! empty( $bn_dir_excluded_ids ) ) {
	$user_query_args['exclude'] = $bn_dir_excluded_ids;
}

if ( '' !== $search_term ) {
	$user_query_args['search']         = '*' . $search_term . '*';
	$user_query_args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
}

// Relation filter (Following / Connections) — only relevant when logged in.
if ( $current_user_id > 0 && 'all' !== $bn_relation ) {
	if ( 'following' === $bn_relation ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$relation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
				$current_user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// connections() returns a flat list of accepted peer user IDs.
		$relation_ids = buddynext_service( 'connections' )->connections( $current_user_id, 500, 0 );
	}
	$relation_ids = array_map( 'intval', (array) $relation_ids );
	if ( empty( $relation_ids ) ) {
		// Force zero results when the relation set is empty.
		$user_query_args['include'] = array( 0 );
	} else {
		$user_query_args['include'] = $relation_ids;
	}
}

// Filter by member type via denormalised usermeta (write-through cache — no JOIN needed).
if ( '' !== $type_slug_filter ) {
	$user_query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array(
			'key'     => 'bn_member_type',
			'value'   => $type_slug_filter,
			'compare' => '=',
		),
	);
}

$user_query  = new WP_User_Query( $user_query_args );
$members     = $user_query->get_results();
$total_users = (int) $user_query->get_total();
$total_pages = (int) ceil( $total_users / max( 1, $bn_per_page ) );

// ── Helpers ───────────────────────────────────────────────────────────────
$online_threshold = time() - 300;

/**
 * Determine if a user is considered online (last_active within 5 minutes).
 *
 * @param int $user_id WordPress user ID.
 * @return bool
 */
$bn_is_online = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};

/**
 * Return the two-character initials for a display name.
 *
 * @param string $name Display name.
 * @return string
 */
$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

/**
 * Return the number of mutual connections between two users.
 *
 * @param int $user_a First user ID.
 * @param int $user_b Second user ID.
 * @return int
 */
$bn_mutual_count = static function ( int $user_a, int $user_b ): int {
	if ( 0 === $user_a || 0 === $user_b || $user_a === $user_b ) {
		return 0;
	}
	return count( buddynext_service( 'connections' )->mutual_connections( $user_a, $user_b ) );
};

/**
 * Check whether the current user follows a given user.
 *
 * @param int $target_user_id User ID to check.
 * @return bool
 */
$bn_is_following = static function ( int $target_user_id ) use ( $current_user_id ): bool {
	if ( 0 === $current_user_id ) {
		return false;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'bn_follows';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE follower_id = %d AND following_id = %d LIMIT 1",
			$current_user_id,
			$target_user_id
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return '1' === (string) $exists;
};

/**
 * Build a relation-tab URL preserving current search/sort.
 *
 * @param string $relation Relation slug.
 * @return string Escaped URL.
 */
$bn_relation_url = static function ( string $relation ) use ( $search_term, $bn_orderby ): string {
	$args = array();
	if ( 'all' !== $relation ) {
		$args['relation'] = $relation;
	}
	if ( '' !== $search_term ) {
		$args['s'] = $search_term;
	}
	if ( 'registered' !== $bn_orderby ) {
		$args['orderby'] = $bn_orderby;
	}
	return esc_url( add_query_arg( $args, remove_query_arg( array( 'relation', 'paged' ) ) ) );
};

// ── Page URLs ─────────────────────────────────────────────────────────────
$bn_messages_base = PageRouter::messages_url();

// ── Avatar tone palette — cycles deterministically by user ID ─────────────
$bn_avatar_tones = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

// ── Interactivity context ─────────────────────────────────────────────────
$action_nonce         = wp_create_nonce( 'bn_member_action' );
$bn_directory_context = wp_json_encode(
	array(
		'search'   => $search_term,
		'orderby'  => $bn_orderby,
		'relation' => $bn_relation,
		'nonce'    => $action_nonce,
		'restUrl'  => esc_url_raw( rest_url( 'buddynext/v1/members' ) ),
	)
);
if ( false === $bn_directory_context ) {
	$bn_directory_context = '{}';
}

// ── Sidebar widgets — hooked into the shell's right-sidebar slot ──────────
// Online-now widget: top 6 users with bn_last_active within the threshold.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$bn_online_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT u.ID, u.display_name, u.user_login
		   FROM {$wpdb->users} u
		   JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
		  WHERE um.meta_key = %s
			AND CAST(um.meta_value AS UNSIGNED) >= %d
		  ORDER BY CAST(um.meta_value AS UNSIGNED) DESC
		  LIMIT %d",
		'bn_last_active',
		$online_threshold,
		6
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( ! empty( $bn_online_rows ) ) {
	$bn_online_count = (int) count( $bn_online_rows );
	add_action(
		'buddynext_right_sidebar',
		static function () use ( $bn_online_rows, $bn_avatar_tones, $bn_initials, $bn_online_count ) {
			ob_start();
			?>
			<ul class="bn-md-sidebar-list">
				<?php foreach ( $bn_online_rows as $bn_row ) : ?>
					<?php
					$bn_row_id    = (int) $bn_row->ID;
					$bn_row_name  = (string) $bn_row->display_name;
					$bn_row_login = (string) $bn_row->user_login;
					$bn_row_url   = PageRouter::profile_url( $bn_row_id );
					$bn_row_av    = (string) get_avatar_url( $bn_row_id, array( 'size' => 56 ) );
					$bn_row_tone  = $bn_avatar_tones[ $bn_row_id % count( $bn_avatar_tones ) ];
					?>
					<li class="bn-md-sidebar-item">
						<a class="bn-md-sidebar-item__link" href="<?php echo esc_url( $bn_row_url ); ?>">
							<span
								class="bn-avatar"
								data-size="sm"
								data-presence="online"
								data-tone="<?php echo esc_attr( $bn_row_tone ); ?>"
							>
								<?php if ( '' !== $bn_row_av ) : ?>
									<img
										src="<?php echo esc_url( $bn_row_av ); ?>"
										alt=""
										width="28"
										height="28"
										loading="lazy"
										decoding="async"
									>
								<?php else : ?>
									<?php echo esc_html( $bn_initials( $bn_row_name ) ); ?>
								<?php endif; ?>
							</span>
							<span class="bn-md-sidebar-item__text">
								<span class="bn-md-sidebar-item__name"><?php echo esc_html( $bn_row_name ); ?></span>
								<span class="bn-md-sidebar-item__handle">@<?php echo esc_html( $bn_row_login ); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_body = (string) ob_get_clean();
			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'         => 'online-now',
					'title'      => sprintf(
						/* translators: %s: number of online members */
						__( 'Online now (%s)', 'buddynext' ),
						number_format_i18n( $bn_online_count )
					),
					'title_icon' => 'users',
					'body_html'  => $bn_body,
				)
			);
		}
	);
}

// Member-type counts widget.
if ( ! empty( $dir_types ) ) {
	add_action(
		'buddynext_right_sidebar',
		static function () use ( $dir_types, $type_slug_filter ) {
			ob_start();
			?>
			<ul class="bn-md-sidebar-list bn-md-sidebar-list--rows">
				<?php
				// "All" row.
				$bn_all_url    = PageRouter::people_url();
				$bn_all_active = ( '' === $type_slug_filter );
				?>
				<li class="bn-md-sidebar-row">
					<a
						class="bn-md-sidebar-row__link<?php echo $bn_all_active ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $bn_all_url ); ?>"
						<?php echo $bn_all_active ? 'aria-current="page"' : ''; ?>
					>
						<span class="bn-md-sidebar-row__label"><?php esc_html_e( 'All members', 'buddynext' ); ?></span>
					</a>
				</li>
				<?php foreach ( $dir_types as $bn_type ) : ?>
					<?php
					$bn_type_slug   = (string) $bn_type['slug'];
					$bn_type_name   = (string) $bn_type['name'];
					$bn_type_count  = isset( $bn_type['count'] ) ? (int) $bn_type['count'] : 0;
					$bn_type_url    = PageRouter::member_type_url( $bn_type_slug );
					$bn_type_active = ( $bn_type_slug === $type_slug_filter );
					?>
					<li class="bn-md-sidebar-row">
						<a
							class="bn-md-sidebar-row__link<?php echo $bn_type_active ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( $bn_type_url ); ?>"
							<?php echo $bn_type_active ? 'aria-current="page"' : ''; ?>
						>
							<span class="bn-md-sidebar-row__label"><?php echo esc_html( $bn_type_name ); ?></span>
							<?php if ( $bn_type_count > 0 ) : ?>
								<span class="bn-md-sidebar-row__count"><?php echo esc_html( number_format_i18n( $bn_type_count ) ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_body = (string) ob_get_clean();
			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'         => 'by-role',
					'title'      => __( 'By type', 'buddynext' ),
					'title_icon' => 'tag',
					'body_html'  => $bn_body,
				)
			);
		}
	);
}

/**
 * Fires before the members directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_members_before', $current_user_id );

// Section-head meta + actions slot HTML.
$bn_head_subtitle = sprintf(
	/* translators: %s: formatted member count */
	__( '%s members in the community', 'buddynext' ),
	number_format_i18n( $total_users )
);

$bn_head_actions = '';
if ( $current_user_id > 0 ) {
	$bn_head_actions = sprintf(
		'<a class="bn-btn" data-variant="secondary" data-size="md" href="%1$s"><span>%2$s</span></a>',
		esc_url( admin_url( 'profile.php' ) ),
		esc_html__( 'Edit profile', 'buddynext' )
	);
}

// Filter-strip tabs (relation filter) — logged-in only.
$bn_strip_tabs = array();
if ( $current_user_id > 0 ) {
	$bn_strip_tabs = array(
		array(
			'key'   => 'all',
			'label' => __( 'All members', 'buddynext' ),
			'href'  => $bn_relation_url( 'all' ),
		),
		array(
			'key'   => 'following',
			'label' => __( 'Following', 'buddynext' ),
			'href'  => $bn_relation_url( 'following' ),
		),
		array(
			'key'   => 'connections',
			'label' => __( 'Connections', 'buddynext' ),
			'href'  => $bn_relation_url( 'connections' ),
		),
	);
}

$bn_strip_hidden = array();
if ( 'all' !== $bn_relation ) {
	$bn_strip_hidden['relation'] = $bn_relation;
}
if ( '' !== $type_slug_filter ) {
	$bn_strip_hidden['type'] = $type_slug_filter;
}
?>
<div
	class="bn-member-directory bn-md-stack"
	data-wp-interactive="buddynext/members"
	data-wp-context="<?php echo esc_attr( (string) $bn_directory_context ); ?>"
>

	<?php
	buddynext_get_template(
		'parts/section-head.php',
		array(
			'title'         => __( 'Members', 'buddynext' ),
			'subtitle'      => $bn_head_subtitle,
			'heading_level' => 'h1',
			'actions_html'  => $bn_head_actions,
		)
	);
	?>

	<?php
	buddynext_get_template(
		'parts/filter-strip.php',
		array(
			'tabs'    => $bn_strip_tabs,
			'active'  => $bn_relation,
			'search'  => array(
				'name'        => 's',
				'value'       => $search_term,
				'placeholder' => __( 'Search by name, skills, location…', 'buddynext' ),
				'aria_label'  => __( 'Search members', 'buddynext' ),
			),
			'selects' => array(
				array(
					'name'       => 'orderby',
					'value'      => $bn_orderby,
					'aria_label' => __( 'Sort members', 'buddynext' ),
					'options'    => array(
						'registered'   => __( 'Newest first', 'buddynext' ),
						'display_name' => __( 'Alphabetical', 'buddynext' ),
						'post_count'   => __( 'Most active', 'buddynext' ),
					),
				),
			),
			'hidden'  => $bn_strip_hidden,
			'classes' => array( 'bn-md-strip' ),
		)
	);
	?>

	<?php if ( empty( $members ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'users',
				'title' => __( 'No members found', 'buddynext' ),
				'body'  => __( 'Try a different search term or clear your filters.', 'buddynext' ),
			)
		);
		?>
	<?php else : ?>

		<div class="bn-md-grid" role="list">
			<?php foreach ( $members as $member ) : ?>
				<?php
				$member_id    = (int) $member->ID;
				$display_name = (string) $member->display_name;
				$member_login = (string) $member->user_login;
				$bio          = (string) get_user_meta( $member_id, 'bn_field_bio', true );
				if ( '' === $bio ) {
					$bio = (string) get_user_meta( $member_id, 'description', true );
				}
				$profile_url      = PageRouter::profile_url( $member_id );
				$avatar_url       = (string) get_avatar_url( $member_id, array( 'size' => 96 ) );
				$is_online        = $bn_is_online( $member_id );
				$is_following     = $bn_is_following( $member_id );
				$mutual           = $bn_mutual_count( $current_user_id, $member_id );
				$member_type_slug = (string) get_user_meta( $member_id, 'bn_member_type', true );
				$member_type_data = '' !== $member_type_slug ? ( $type_map[ $member_type_slug ] ?? null ) : null;
				$messages_url     = add_query_arg( array( 'recipient' => $member_id ), $bn_messages_base );
				$follow_nonce     = wp_create_nonce( 'bn_follow_' . $member_id );
				$connect_nonce    = wp_create_nonce( 'bn_connect_' . $member_id );
				$bn_conn_status   = $current_user_id > 0
					? buddynext_service( 'connections' )->status( $current_user_id, $member_id )
					: null;
				$bn_avatar_tone   = $bn_avatar_tones[ $member_id % count( $bn_avatar_tones ) ];
				$bn_presence_attr = $is_online ? 'online' : 'offline';
				$bn_initials_text = $bn_initials( $display_name );
				?>
				<article
					class="bn-card bn-md-card"
					data-interactive
					role="listitem"
					data-user-id="<?php echo esc_attr( (string) $member_id ); ?>"
				>

					<a href="<?php echo esc_url( $profile_url ); ?>" class="bn-md-card__avatar-link" tabindex="-1" aria-hidden="true">
						<span
							class="bn-avatar bn-md-card__avatar"
							data-size="xl"
							data-presence="<?php echo esc_attr( $bn_presence_attr ); ?>"
							data-tone="<?php echo esc_attr( $bn_avatar_tone ); ?>"
						>
							<?php if ( '' !== $avatar_url ) : ?>
								<img
									src="<?php echo esc_url( $avatar_url ); ?>"
									alt=""
									width="72"
									height="72"
									loading="lazy"
									decoding="async"
								>
							<?php else : ?>
								<?php echo esc_html( $bn_initials_text ); ?>
							<?php endif; ?>
						</span>
					</a>

					<h3 class="bn-md-card__name">
						<a href="<?php echo esc_url( $profile_url ); ?>">
							<?php echo esc_html( $display_name ); ?>
						</a>
					</h3>

					<p class="bn-md-card__handle">@<?php echo esc_html( $member_login ); ?></p>

					<?php if ( null !== $member_type_data ) : ?>
						<span class="bn-badge bn-md-card__type" data-tone="accent">
							<?php echo esc_html( (string) $member_type_data['name'] ); ?>
						</span>
					<?php endif; ?>

					<?php if ( '' !== $bio ) : ?>
						<p class="bn-md-card__bio"><?php echo esc_html( wp_trim_words( $bio, 18 ) ); ?></p>
					<?php endif; ?>

					<?php if ( $mutual > 0 ) : ?>
						<p class="bn-md-card__mutual">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of mutual connections */
									_n( '%d mutual connection', '%d mutual connections', $mutual, 'buddynext' ),
									$mutual
								)
							);
							?>
						</p>
					<?php endif; ?>

					<div class="bn-md-card__actions">
						<?php if ( 0 === $current_user_id ) : ?>
							<a
								class="bn-btn"
								data-variant="primary"
								data-size="sm"
								href="<?php echo esc_url( wp_login_url( $profile_url ) ); ?>"
							>
								<?php esc_html_e( 'View profile', 'buddynext' ); ?>
							</a>
						<?php elseif ( $current_user_id === $member_id ) : ?>
							<a
								class="bn-btn"
								data-variant="secondary"
								data-size="sm"
								href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"
							>
								<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
							</a>
						<?php else : ?>
							<button
								type="button"
								class="bn-btn bn-md-card__follow"
								data-variant="<?php echo $is_following ? 'secondary' : 'primary'; ?>"
								data-size="sm"
								data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
								data-nonce="<?php echo esc_attr( $follow_nonce ); ?>"
								data-action="<?php echo $is_following ? 'unfollow' : 'follow'; ?>"
								data-label-follow="<?php esc_attr_e( 'Follow', 'buddynext' ); ?>"
								data-label-following="<?php esc_attr_e( 'Following', 'buddynext' ); ?>"
								data-wp-on--click="actions.toggleFollow"
							>
								<?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
							</button>

							<?php if ( 'accepted' === $bn_conn_status ) : ?>
								<a
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									href="<?php echo esc_url( $messages_url ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
								>
									<?php buddynext_icon( 'message-circle' ); ?>
								</a>
							<?php elseif ( 'pending' === $bn_conn_status ) : ?>
								<button
									type="button"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									disabled
									aria-disabled="true"
								>
									<?php esc_html_e( 'Pending', 'buddynext' ); ?>
								</button>
							<?php elseif ( ! $is_following ) : ?>
								<button
									type="button"
									class="bn-btn bn-md-card__connect"
									data-variant="secondary"
									data-size="sm"
									data-member-id="<?php echo esc_attr( (string) $member_id ); ?>"
									data-nonce="<?php echo esc_attr( $connect_nonce ); ?>"
									data-label-pending="<?php esc_attr_e( 'Pending', 'buddynext' ); ?>"
									data-wp-on--click="actions.sendConnection"
								>
									<?php esc_html_e( 'Connect', 'buddynext' ); ?>
								</button>
							<?php else : ?>
								<a
									class="bn-btn"
									data-variant="ghost"
									data-size="sm"
									href="<?php echo esc_url( $messages_url ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'Message %s', 'buddynext' ), $display_name ) ); ?>"
								>
									<?php buddynext_icon( 'message-circle' ); ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
					</div>

				</article>
			<?php endforeach; ?>
		</div>

		<?php
		buddynext_get_template(
			'parts/pagination.php',
			array(
				'current'    => $bn_current_page,
				'total'      => $total_pages,
				'aria_label' => __( 'Member directory pages', 'buddynext' ),
			)
		);
		?>

	<?php endif; ?>

</div>
<?php
/**
 * Fires after the members directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_members_after', $current_user_id );
