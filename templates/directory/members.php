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
 * Production wiring (Social Graph row 4):
 *   - Reactive filter bar (no Apply submit). 250 ms debounced search,
 *     instant sort + relation tab changes, member-type pill row.
 *   - Loading skeleton during fetch, empty state when zero results,
 *     error state with a retry CTA on REST failure.
 *   - Follow / Connect / Connection accept-decline buttons run through
 *     the buddynext/members store with optimistic UI, REST round-trip,
 *     toast on success, rollback + danger toast on failure.
 *   - Per-card kebab menu surfaces Mute / Block / Report wired to the
 *     same buddynext/members store; Block + Report reuse the existing
 *     modal partials so the experience matches profile/view.
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

// Site title for the document title override.
$bn_site_name = (string) get_bloginfo( 'name' );
add_filter(
	'document_title_parts',
	static function ( array $parts ) use ( $bn_site_name ): array {
		$parts['title'] = __( 'Members', 'buddynext' );
		if ( '' !== $bn_site_name ) {
			$parts['site'] = $bn_site_name;
		}
		return $parts;
	},
	20
);

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

$bn_is_online = static function ( int $user_id ) use ( $online_threshold ): bool {
	$last_active = (int) get_user_meta( $user_id, 'bn_last_active', true );
	return $last_active >= $online_threshold;
};

$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

$bn_mutual_count = static function ( int $user_a, int $user_b ): int {
	if ( 0 === $user_a || 0 === $user_b || $user_a === $user_b ) {
		return 0;
	}
	return count( buddynext_service( 'connections' )->mutual_connections( $user_a, $user_b ) );
};

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

// ── Page URLs ─────────────────────────────────────────────────────────────
$bn_messages_base = PageRouter::messages_url();

// ── Avatar tone palette — cycles deterministically by user ID ─────────────
$bn_avatar_tones = array( 'accent', 'success', 'jetonomy', 'media', 'events', 'warn', 'danger', 'info' );

// ── Interactivity context ─────────────────────────────────────────────────
$bn_rest_nonce = wp_create_nonce( 'wp_rest' );

// Map sort keys (UI) to REST API sort values.
$bn_rest_sort_map = array(
	'registered'   => 'newest',
	'display_name' => 'alphabetical',
	'post_count'   => 'most_active',
);
$bn_initial_sort  = $bn_rest_sort_map[ $bn_orderby ] ?? 'newest';

$bn_directory_context = wp_json_encode(
	array(
		'search'           => $search_term,
		'sort'             => $bn_initial_sort,
		'relation'         => $bn_relation,
		'memberType'       => $type_slug_filter,
		'restNonce'        => $bn_rest_nonce,
		'restUrl'          => esc_url_raw( rest_url( 'buddynext/v1' ) ),
		'loading'          => false,
		'searching'        => false,
		'error'            => '',
		'hasError'         => false,
		'isEmpty'          => empty( $members ),
		'totalLabel'       => '',
		'peopleUrl'        => PageRouter::people_url(),
		// Cross-surface modal state (block / report).
		'blockTargetId'    => 0,
		'blockTargetName'  => '',
		'blockConfirmOpen' => false,
		'blockSubmitting'  => false,
		'reportOpen'       => false,
		'reportTargetType' => 'user',
		'reportTargetId'   => 0,
		'reportReason'     => 'spam',
		'reportNotes'      => '',
		'reportSubmitting' => false,
		// Active row state shared by toast copy helpers.
		'lastActorName'    => '',
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

// Relation tabs — reactive, no full-page reload.
$bn_relation_tabs = array();
if ( $current_user_id > 0 ) {
	$bn_relation_tabs = array(
		array(
			'key'   => 'all',
			'label' => __( 'All members', 'buddynext' ),
		),
		array(
			'key'   => 'following',
			'label' => __( 'Following', 'buddynext' ),
		),
		array(
			'key'   => 'connections',
			'label' => __( 'Connections', 'buddynext' ),
		),
	);
}
?>
<div
	class="bn-member-directory bn-md-stack"
	data-wp-interactive="buddynext/members"
	data-wp-context="<?php echo esc_attr( (string) $bn_directory_context ); ?>"
	data-wp-init="callbacks.init"
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

	<?php if ( ! empty( $dir_types ) ) : ?>
		<nav class="bn-md-pill-row" aria-label="<?php esc_attr_e( 'Filter by member type', 'buddynext' ); ?>">
			<?php
			$bn_all_active = ( '' === $type_slug_filter );
			?>
			<button
				type="button"
				class="bn-md-pill<?php echo $bn_all_active ? ' is-active' : ''; ?>"
				data-type-slug=""
				aria-pressed="<?php echo $bn_all_active ? 'true' : 'false'; ?>"
				data-wp-on--click="actions.selectMemberType"
				data-wp-bind--aria-pressed="state.allPillPressed"
				data-wp-bind--class="state.allPillClass"
			>
				<span class="bn-md-pill__label"><?php esc_html_e( 'All members', 'buddynext' ); ?></span>
			</button>
			<?php
			foreach ( $dir_types as $bn_type ) :
				$bn_pill_slug   = (string) $bn_type['slug'];
				$bn_pill_name   = (string) $bn_type['name'];
				$bn_pill_count  = isset( $bn_type['count'] ) ? (int) $bn_type['count'] : 0;
				$bn_pill_active = ( $bn_pill_slug === $type_slug_filter );
				?>
				<button
					type="button"
					class="bn-md-pill<?php echo $bn_pill_active ? ' is-active' : ''; ?>"
					data-type-slug="<?php echo esc_attr( $bn_pill_slug ); ?>"
					aria-pressed="<?php echo $bn_pill_active ? 'true' : 'false'; ?>"
					data-wp-on--click="actions.selectMemberType"
				>
					<span class="bn-md-pill__label"><?php echo esc_html( $bn_pill_name ); ?></span>
					<?php if ( $bn_pill_count > 0 ) : ?>
						<span class="bn-md-pill__count"><?php echo esc_html( number_format_i18n( $bn_pill_count ) ); ?></span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="bn-md-strip bn-filter-strip">
		<?php if ( ! empty( $bn_relation_tabs ) ) : ?>
			<div class="bn-tabs" role="tablist">
				<?php
				foreach ( $bn_relation_tabs as $bn_rt ) :
					$bn_rt_key    = (string) $bn_rt['key'];
					$bn_rt_label  = (string) $bn_rt['label'];
					$bn_rt_active = ( $bn_rt_key === $bn_relation );
					?>
					<button
						type="button"
						class="bn-tab<?php echo $bn_rt_active ? ' is-active' : ''; ?>"
						role="tab"
						aria-selected="<?php echo $bn_rt_active ? 'true' : 'false'; ?>"
						data-relation="<?php echo esc_attr( $bn_rt_key ); ?>"
						data-wp-on--click="actions.selectRelation"
					>
						<span class="bn-tab__label"><?php echo esc_html( $bn_rt_label ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="bn-md-strip__form" role="search">
			<label class="bn-md-strip__search">
				<span class="screen-reader-text"><?php esc_html_e( 'Search members', 'buddynext' ); ?></span>
				<input
					type="search"
					class="bn-input bn-md-strip__search-input"
					name="s"
					value="<?php echo esc_attr( $search_term ); ?>"
					placeholder="<?php esc_attr_e( 'Search by name, skills, location…', 'buddynext' ); ?>"
					aria-label="<?php esc_attr_e( 'Search members', 'buddynext' ); ?>"
					data-wp-on--input="actions.handleSearchInput"
				>
				<span
					class="bn-md-strip__searching"
					aria-hidden="true"
					data-wp-bind--hidden="!state.searching"
					hidden
				><?php esc_html_e( 'Searching…', 'buddynext' ); ?></span>
			</label>

			<select
				class="bn-select bn-md-strip__sort"
				aria-label="<?php esc_attr_e( 'Sort members', 'buddynext' ); ?>"
				data-wp-on--change="actions.selectSort"
			>
				<option value="newest" <?php selected( $bn_initial_sort, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
				<option value="alphabetical" <?php selected( $bn_initial_sort, 'alphabetical' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
				<option value="most_active" <?php selected( $bn_initial_sort, 'most_active' ); ?>><?php esc_html_e( 'Most active', 'buddynext' ); ?></option>
				<option value="online" <?php selected( $bn_initial_sort, 'online' ); ?>><?php esc_html_e( 'Online now', 'buddynext' ); ?></option>
			</select>
		</div>
	</div>

	<div
		class="bn-md-skeleton"
		aria-hidden="true"
		data-wp-bind--hidden="!state.loading"
		hidden
	>
		<?php for ( $bn_sk = 0; $bn_sk < 6; $bn_sk++ ) : ?>
			<div class="bn-md-skeleton__card">
				<span class="bn-md-skeleton__avatar"></span>
				<span class="bn-md-skeleton__line bn-md-skeleton__line--lg"></span>
				<span class="bn-md-skeleton__line bn-md-skeleton__line--sm"></span>
				<span class="bn-md-skeleton__line"></span>
				<span class="bn-md-skeleton__actions"></span>
			</div>
		<?php endfor; ?>
	</div>

	<div
		class="bn-md-error"
		role="alert"
		data-wp-bind--hidden="!state.hasError"
		hidden
	>
		<p class="bn-md-error__copy" data-wp-text="context.error">
			<?php esc_html_e( 'Could not load members.', 'buddynext' ); ?>
		</p>
		<button
			type="button"
			class="bn-btn"
			data-variant="secondary"
			data-size="sm"
			data-wp-on--click="actions.retry"
		>
			<?php esc_html_e( 'Retry', 'buddynext' ); ?>
		</button>
	</div>

	<div
		class="bn-md-empty"
		data-wp-bind--hidden="!state.showEmpty"
		<?php echo empty( $members ) ? '' : 'hidden'; ?>
	>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'users',
				'title' => __( 'No members match your filters', 'buddynext' ),
				'body'  => __( 'Try widening your filters or clearing the search term.', 'buddynext' ),
			)
		);
		?>
		<div class="bn-md-empty__actions">
			<button
				type="button"
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				data-wp-on--click="actions.resetFilters"
			><?php esc_html_e( 'Reset filters', 'buddynext' ); ?></button>
		</div>
	</div>

	<div
		class="bn-md-grid"
		role="list"
		data-wp-bind--hidden="state.gridHidden"
		<?php echo empty( $members ) ? 'hidden' : ''; ?>
	>
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
			$bn_conn_status   = $current_user_id > 0
				? buddynext_service( 'connections' )->status( $current_user_id, $member_id )
				: null;
			$bn_avatar_tone   = $bn_avatar_tones[ $member_id % count( $bn_avatar_tones ) ];
			$bn_presence_attr = $is_online ? 'online' : 'offline';
			$bn_initials_text = $bn_initials( $display_name );

			// Resolve direction-aware connection state for the 5-state Connect button.
			$bn_conn_state = 'none';
			if ( 'accepted' === $bn_conn_status ) {
				$bn_conn_state = 'accepted';
			} elseif ( 'pending' === $bn_conn_status && $current_user_id > 0 ) {
				$sent_ids      = buddynext_service( 'connections' )->pending_sent( $current_user_id );
				$bn_conn_state = in_array( $member_id, $sent_ids, true ) ? 'pending-sent' : 'pending-received';
			}

			$bn_card_ctx = wp_json_encode(
				array(
					'userId'      => $member_id,
					'displayName' => $display_name,
					'isFollowing' => $is_following,
					'connection'  => $bn_conn_state,
					'menuOpen'    => false,
					'isMuted'     => $current_user_id > 0
						? buddynext_service( 'blocks' )->is_muted( $current_user_id, $member_id )
						: false,
				)
			);
			if ( false === $bn_card_ctx ) {
				$bn_card_ctx = '{}';
			}
			?>
			<article
				class="bn-card bn-md-card"
				data-interactive
				role="listitem"
				data-user-id="<?php echo esc_attr( (string) $member_id ); ?>"
				data-wp-context="<?php echo esc_attr( (string) $bn_card_ctx ); ?>"
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
							data-size="sm"
							data-wp-bind--data-variant="state.cardFollowVariant"
							data-wp-bind--data-state="state.cardFollowState"
							data-wp-text="state.cardFollowLabel"
							data-wp-on--click="actions.toggleFollow"
						><?php echo $is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?></button>

						<?php // Connection control — 5-state, reactive. ?>
						<button
							type="button"
							class="bn-btn bn-md-card__connect-primary"
							data-size="sm"
							data-wp-bind--hidden="!state.cardShowConnect"
							data-wp-bind--data-variant="state.cardConnectVariant"
							data-wp-bind--data-state="state.cardConnectState"
							data-wp-text="state.cardConnectLabel"
							data-wp-on--click="actions.toggleConnection"
							<?php echo in_array( $bn_conn_state, array( 'none', 'pending-sent', 'accepted' ), true ) ? '' : 'hidden'; ?>
						>
						<?php
						if ( 'accepted' === $bn_conn_state ) {
							esc_html_e( 'Connected', 'buddynext' );
						} elseif ( 'pending-sent' === $bn_conn_state ) {
							esc_html_e( 'Requested', 'buddynext' );
						} else {
							esc_html_e( 'Connect', 'buddynext' );
						}
						?>
						</button>

						<span
							class="bn-md-card__connect-decide"
							data-wp-bind--hidden="!state.cardShowReceived"
							<?php echo 'pending-received' === $bn_conn_state ? '' : 'hidden'; ?>
						>
							<button
								type="button"
								class="bn-btn"
								data-variant="primary"
								data-size="sm"
								data-wp-on--click="actions.acceptConnection"
							><?php esc_html_e( 'Accept', 'buddynext' ); ?></button>
							<button
								type="button"
								class="bn-btn"
								data-variant="ghost"
								data-size="sm"
								data-wp-on--click="actions.declineConnection"
							><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
						</span>

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
						<?php endif; ?>

						<?php // Kebab menu — Mute / Block / Report. ?>
						<div class="bn-md-card__menu-wrap">
							<button
								type="button"
								class="bn-md-card__menu"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'More actions for %s', 'buddynext' ), $display_name ) ); ?>"
								aria-haspopup="true"
								aria-expanded="false"
								data-wp-on--click="actions.toggleCardMenu"
								data-wp-bind--aria-expanded="state.cardMenuExpanded"
							><?php buddynext_icon( 'more-horizontal' ); ?></button>
							<div
								class="bn-md-card__menu-pop"
								role="menu"
								data-wp-bind--hidden="!state.cardMenuOpen"
								hidden
							>
								<button
									type="button"
									class="bn-md-card__menu-item"
									role="menuitem"
									data-wp-on--click="actions.toggleMute"
									data-wp-text="state.cardMuteLabel"
								><?php echo esc_html( $current_user_id > 0 && buddynext_service( 'blocks' )->is_muted( $current_user_id, $member_id ) ? __( 'Unmute', 'buddynext' ) : __( 'Mute', 'buddynext' ) ); ?></button>
								<button
									type="button"
									class="bn-md-card__menu-item bn-md-card__menu-item--danger"
									role="menuitem"
									data-wp-on--click="actions.openBlock"
								><?php esc_html_e( 'Block', 'buddynext' ); ?></button>
								<button
									type="button"
									class="bn-md-card__menu-item bn-md-card__menu-item--danger"
									role="menuitem"
									data-wp-on--click="actions.openReport"
								><?php esc_html_e( 'Report', 'buddynext' ); ?></button>
							</div>
						</div>
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
</div>

<?php
// Cross-surface modals — opened imperatively by the directory kebab menu.
// Rendered OUTSIDE the `data-wp-interactive="buddynext/members"` element so
// stray `data-wp-bind` directives from the partials are inert. The
// directory store opens / closes them by toggling the [hidden] attribute.
$bn_report_reasons = array(
	'spam'           => __( 'Spam', 'buddynext' ),
	'harassment'     => __( 'Harassment or hate speech', 'buddynext' ),
	'misinformation' => __( 'Misinformation', 'buddynext' ),
	'inappropriate'  => __( 'Inappropriate content', 'buddynext' ),
	'fake'           => __( 'Fake account', 'buddynext' ),
	'impersonation'  => __( 'Impersonation', 'buddynext' ),
	'other'          => __( 'Something else', 'buddynext' ),
);
?>
<div
	class="bn-modal-backdrop bn-pf-block-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-md-block-title"
	hidden
>
	<div class="bn-modal__panel" data-tone="danger" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-md-block-title"><?php esc_html_e( 'Block this member?', 'buddynext' ); ?></h2>
			<button
				class="bn-modal__close"
				type="button"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeBlockConfirm"
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p><?php esc_html_e( 'Blocking this person will:', 'buddynext' ); ?></p>
			<ul class="bn-modal__list">
				<li><?php esc_html_e( 'Hide their posts and replies from your feed.', 'buddynext' ); ?></li>
				<li><?php esc_html_e( 'Stop them from following you or sending you messages.', 'buddynext' ); ?></li>
				<li><?php esc_html_e( 'Remove any existing connection or follow between you.', 'buddynext' ); ?></li>
			</ul>
			<p class="bn-modal__help"><?php esc_html_e( 'You can unblock from your settings at any time.', 'buddynext' ); ?></p>
		</div>
		<footer class="bn-modal__foot">
			<button
				class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeBlockConfirm"
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				class="bn-btn"
				type="button"
				data-variant="danger"
				data-size="md"
				data-wp-on--click="actions.confirmBlock"
			><?php esc_html_e( 'Block', 'buddynext' ); ?></button>
		</footer>
	</div>
</div>

<div
	class="bn-modal-backdrop bn-pf-report-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-md-report-title"
	data-target-type="user"
	hidden
>
	<div class="bn-modal__panel" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-md-report-title"><?php esc_html_e( 'Report this profile', 'buddynext' ); ?></h2>
			<button
				class="bn-modal__close"
				type="button"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeReport"
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body">
			<p class="bn-modal__help"><?php esc_html_e( 'Reports are reviewed by moderators. The person you report is not notified.', 'buddynext' ); ?></p>
			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-reason"><?php esc_html_e( 'Reason', 'buddynext' ); ?></label>
				<select class="bn-input" id="bn-pf-report-reason">
					<?php foreach ( $bn_report_reasons as $bn_rk => $bn_rl ) : ?>
						<option value="<?php echo esc_attr( $bn_rk ); ?>"><?php echo esc_html( $bn_rl ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-notes"><?php esc_html_e( 'Additional details (optional)', 'buddynext' ); ?></label>
				<textarea class="bn-textarea" id="bn-pf-report-notes" rows="3" maxlength="500" placeholder="<?php esc_attr_e( 'Tell us more about what you saw...', 'buddynext' ); ?>"></textarea>
			</div>
		</div>
		<footer class="bn-modal__foot">
			<button
				class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeReport"
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				class="bn-btn"
				type="button"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.submitReport"
			><?php esc_html_e( 'Submit report', 'buddynext' ); ?></button>
		</footer>
	</div>
</div>
<?php
/**
 * Fires after the members directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_members_after', $current_user_id );
