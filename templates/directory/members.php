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
 * Structural composition (Layer 3 parts):
 *   - parts/member-directory-hero.php       — title + subtitle + actions
 *   - parts/member-directory-tabs.php       — member-type pill row
 *   - parts/member-directory-filter-bar.php — relation tabs + search + sort
 *   - parts/member-directory-grid.php       — grid wrapper + member-card loop
 *   - parts/member-card.php                 — single member row (reusable)
 *   - parts/member-block-modal.php          — block confirmation
 *   - parts/member-report-modal.php         — report profile
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
$bn_online_only  = ( '1' === sanitize_key( wp_unslash( $_GET['online'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
// Use get_all_with_counts() so the pill chips can render per-type member
// counts (v2 prototype pattern). The returned rows include all columns
// from get_all() plus a `member_count` aggregate.
$all_types_raw = buddynext_service( 'member_types' )->get_all_with_counts();
$dir_types     = array_values( array_filter( $all_types_raw, static fn( $t ) => ! empty( $t['show_in_dir'] ) ) );
// Flat slug → type data map for O(1) card badge lookup inside the member loop.
$type_map = array();
foreach ( $all_types_raw as $t ) {
	$type_map[ (string) $t['slug'] ] = $t;
}
unset( $all_types_raw, $t );

// Build the filter-able pill list expected by parts/member-directory-tabs.php.
$bn_pill_types = array();
foreach ( $dir_types as $bn_dir_type ) {
	$bn_pill_types[] = array(
		'slug'  => (string) $bn_dir_type['slug'],
		'label' => (string) $bn_dir_type['name'],
		'count' => isset( $bn_dir_type['member_count'] )
			? (int) $bn_dir_type['member_count']
			: ( isset( $bn_dir_type['count'] ) ? (int) $bn_dir_type['count'] : 0 ),
	);
}
unset( $bn_dir_type );

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

// Exclude suspended + shadow-banned users, and the viewer themselves — the REST
// path (MemberDirectoryService) excludes the viewer, so the server render must
// match or a hard reload would list you while the live/filtered view does not.
$bn_dir_excluded_ids = array_unique(
	array_map(
		'intval',
		array_merge(
			$bn_dir_suspended_ids,
			$bn_dir_shadow_banned_ids,
			$current_user_id > 0 ? array( $current_user_id ) : array()
		)
	)
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

// Dynamic, privacy-aware search resolved to user IDs so the server render
// matches the REST/live path exactly (name/login/email + every searchable
// field mirror; private/tightened values have no mirror so never match).
// Applied to `include` below (intersected with any relation constraint).
if ( '' !== $search_term ) {
	$bn_search_ids = buddynext_service( 'member_directory' )->matching_user_ids( $search_term );
	if ( empty( $bn_search_ids ) ) {
		$bn_search_ids = array( 0 ); // Term set but nothing matched → force zero results.
	}
} else {
	$bn_search_ids = null;
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

// Apply the resolved search IDs to `include`, intersecting with any relation
// constraint already set above (most-restrictive wins).
if ( null !== $bn_search_ids ) {
	if ( isset( $user_query_args['include'] ) && is_array( $user_query_args['include'] ) ) {
		$bn_intersect               = array_values( array_intersect( $user_query_args['include'], $bn_search_ids ) );
		$user_query_args['include'] = empty( $bn_intersect ) ? array( 0 ) : $bn_intersect;
	} else {
		$user_query_args['include'] = $bn_search_ids;
	}
}

// Online-only filter — restrict to users active within the last 5 minutes,
// applied to `include` (most-restrictive wins) exactly like search/relation so
// the rendered members AND the pagination total reflect it. Without this the
// pager would offer pages that the client-side online filter then empties.
if ( $bn_online_only ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_online_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta}
			 WHERE meta_key = 'bn_last_active'
			   AND CAST( meta_value AS UNSIGNED ) > %d",
			time() - 300
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$bn_online_ids = array_map( 'intval', (array) $bn_online_ids );

	if ( empty( $bn_online_ids ) ) {
		$user_query_args['include'] = array( 0 );
	} elseif ( isset( $user_query_args['include'] ) && is_array( $user_query_args['include'] ) ) {
		$bn_online_intersect        = array_values( array_intersect( $user_query_args['include'], $bn_online_ids ) );
		$user_query_args['include'] = empty( $bn_online_intersect ) ? array( 0 ) : $bn_online_intersect;
	} else {
		$user_query_args['include'] = $bn_online_ids;
	}
}

$user_query  = new WP_User_Query( $user_query_args );
$members     = $user_query->get_results();
$total_users = (int) $user_query->get_total();
$total_pages = (int) ceil( $total_users / max( 1, $bn_per_page ) );

// ── Helpers ───────────────────────────────────────────────────────────────
// $online_threshold is the unix timestamp boundary used by the
// raw-SQL "Online now" widget query lower in this file. The
// per-card $bn_is_online check goes through BlockService so the
// restrict gate applies; that helper owns the same window internally.
$online_threshold = time() - 300;
$bn_is_online     = static function ( int $user_id ) use ( $current_user_id ): bool {
	return buddynext_service( 'blocks' )->is_user_online( $current_user_id, $user_id );
};

$bn_initials = static function ( string $name ): string {
	$parts = array_filter( explode( ' ', $name ) );
	if ( count( $parts ) >= 2 ) {
		return mb_strtoupper( mb_substr( (string) reset( $parts ), 0, 1 ) . mb_substr( (string) end( $parts ), 0, 1 ) );
	}
	return mb_strtoupper( mb_substr( $name, 0, 2 ) );
};

$bn_mutual_ids = static function ( int $user_a, int $user_b ): array {
	if ( 0 === $user_a || 0 === $user_b || $user_a === $user_b ) {
		return array();
	}
	return buddynext_service( 'connections' )->mutual_connections( $user_a, $user_b );
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
$bn_directory_url = PageRouter::people_url();

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
		'peopleUrl'        => $bn_directory_url,
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

// "By role" member-summary card — total + admin + moderator + top
// member-types. Pattern D-4 from the v2 prototype member-directory
// sidebar. Renders independently of the online-now / member-type-rows
// callbacks below.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $total_users ): void {
		// Pass the directory's filtered total so the sidebar's "Members" row
		// matches the header count (both exclude suspended/shadow-banned),
		// instead of diverging from raw count_users().
		buddynext_get_template( 'parts/sidebar-by-role.php', array( 'directory_total' => $total_users ) );
	}
);

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
					$bn_type_count  = isset( $bn_type['member_count'] ) ? (int) $bn_type['member_count'] : ( isset( $bn_type['count'] ) ? (int) $bn_type['count'] : 0 );
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
		'parts/member-directory-hero.php',
		array(
			'total_members' => $total_users,
			'current_type'  => $type_slug_filter,
			'view_mode'     => 'grid',
			'viewer_id'     => $current_user_id,
		)
	);

	// Member-type filtering lives in the toolbar's "All member types" select
	// (works on mobile too) and the sidebar's BY TYPE quick-links. The separate
	// top pill row duplicated that control three-deep, so it has been removed
	// for a single, unambiguous filter home.

	buddynext_get_template(
		'parts/member-directory-filter-bar.php',
		array(
			'current_search'  => $search_term,
			'current_sort'    => $bn_initial_sort,
			'current_type'    => $type_slug_filter,
			'current_online'  => $bn_online_only,
			'current_url'     => $bn_directory_url,
			'relation_tabs'   => $bn_relation_tabs,
			'active_relation' => $bn_relation,
		)
	);
	?>

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

	<?php
	buddynext_get_template(
		'parts/member-directory-grid.php',
		array(
			'members'         => $members,
			'viewer_id'       => $current_user_id,
			'view_mode'       => 'grid',
			'avatar_tones'    => $bn_avatar_tones,
			'type_map'        => $type_map,
			'messages_base'   => $bn_messages_base,
			'initials_fn'     => $bn_initials,
			'is_online_fn'    => $bn_is_online,
			'is_following_fn' => $bn_is_following,
			'mutual_ids_fn'   => $bn_mutual_ids,
		)
	);

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
buddynext_get_template(
	'parts/member-block-modal.php',
	array(
		'nonce' => $bn_rest_nonce,
	)
);

buddynext_get_template(
	'parts/member-report-modal.php',
	array(
		'nonce' => $bn_rest_nonce,
	)
);

/**
 * Fires after the members directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_members_after', $current_user_id );
