<?php
/**
 * BuddyNext — Member directory inner template (v2 design system).
 *
 * Renders inside the shell main column (`<main class="bn-app__main">`,
 * see `templates/shell/hub-shell.php`). This inner template does NOT
 * own the rail, the page chrome, or the 2-column grid —
 * the shell handles all of that. Sidebar widgets (online-now, by-type)
 * are registered via MembersSidebarProvider on the `buddynext_sidebar_widgets`
 * filter, scoped to the `members` surface (Surface::set() below); the
 * shell auto-renders the right column when descriptors are present.
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
 *   - parts/member-directory-filter-bar.php — relation tabs + search + sort + type filter
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
use BuddyNext\Profile\AvatarService;
use BuddyNext\Sidebar\Surface;

// Fine-grained sidebar surface for the registry (MembersSidebarProvider) —
// the shell's bn_hub is too coarse to distinguish this directory from other
// surfaces that might share a hub.
Surface::set( 'members' );

// ── Query parameters ──────────────────────────────────────────────────────
$bn_current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$bn_per_page     = 20;
$search_term     = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$orderby_raw     = sanitize_key( $_GET['orderby'] ?? 'registered' );                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$relation_raw    = sanitize_key( $_GET['relation'] ?? 'all' );                      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_online_only  = ( '1' === sanitize_key( wp_unslash( $_GET['online'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Member type comes from ?type=, the one filter contract on this screen. The
// pretty /members/{slug}/ form this used to read first never worked: that shape
// is indistinguishable from /members/{username}/, the user-slug rewrite always
// won, and the bn_member_type query var was never populated on any request.
$type_slug_filter = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );             // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_sort = array( 'registered', 'display_name', 'post_count' );
$bn_orderby   = in_array( $orderby_raw, $allowed_sort, true ) ? $orderby_raw : 'registered';

// Map the UI sort to the SSR WP_User_Query orderby:
// - "newest" (registered) → ID DESC. ID is registration order on an AUTO_INCREMENT
// users table AND the PRIMARY KEY, so it's filesort-free and — crucially — matches
// the REST list_members() newest sort (also ID DESC, post-A6d) exactly, so the SSR
// first page and the live keyset pager never disagree at a page boundary.
// - "alphabetical" (display_name) → display_name ASC.
// - "most active" (post_count) → NEVER the WP wp_posts COUNT subquery; falls back to
// ID DESC for the server paint and the JS re-sorts via REST bn_presence
// ($bn_initial_sort below still hands the JS 'most_active').
$bn_query_orderby = ( 'display_name' === $bn_orderby ) ? 'display_name' : 'ID';
$bn_order         = ( 'display_name' === $bn_query_orderby ) ? 'ASC' : 'DESC';

$allowed_relations = array( 'all', 'following', 'connections' );
$bn_relation       = in_array( $relation_raw, $allowed_relations, true ) ? $relation_raw : 'all';

// Community name for the document title override.
$bn_site_name = buddynext_site_name();
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

// ── Member types for the directory type filter and card badges ────────────
// Use get_all_with_counts() so the type filter (the "All member types" select
// in the filter bar) and the right-sidebar "By type" list can show per-type
// member counts. The returned rows include all columns from get_all() plus a
// `member_count` aggregate.
$all_types_raw = buddynext_service( 'member_types' )->get_all_with_counts();
$dir_types     = array_values( array_filter( $all_types_raw, static fn( $t ) => ! empty( $t['show_in_dir'] ) ) );
// Flat slug → type data map for O(1) card badge lookup inside the member loop.
$type_map = array();
foreach ( $all_types_raw as $t ) {
	$type_map[ (string) $t['slug'] ] = $t;
}
unset( $all_types_raw, $t );

// ── Fetch users ───────────────────────────────────────────────────────────
// The server-rendered first page applies the SAME exclusions + filters the
// REST/live pipeline (MemberDirectoryService::list_members) does — suspended,
// shadow-banned, directory-opted-out, bidirectional blocks, member-type, and
// online-only — but as correlated subqueries injected via pre_user_query (below),
// never as a materialised IN / NOT IN id list, so the query stays bounded at 50k.
$bn_directory_service = buddynext_service( 'member_directory' );

// Directory-accurate per-type counts for the "By type" facet. list_members()
// filters a type via INNER JOIN wp_users + the discovery gate and excludes the
// viewer, so the raw assignment-row counts on get_all_with_counts() (which count
// orphaned rows for deleted users and the viewer's own row) would not match the
// list. Override each row's member_count with the count that reflects exactly
// who the list shows, so clicking a facet of N lands on N members.
if ( method_exists( $bn_directory_service, 'type_member_counts' ) ) {
	$bn_type_counts = $bn_directory_service->type_member_counts( $current_user_id );
	foreach ( $dir_types as &$bn_dir_type ) {
		$bn_dir_type['member_count'] = (int) ( $bn_type_counts[ (int) ( $bn_dir_type['id'] ?? 0 ) ] ?? 0 );
	}
	unset( $bn_dir_type );
}

$user_query_args = array(
	'number'      => $bn_per_page,
	'paged'       => $bn_current_page,
	'orderby'     => $bn_query_orderby,
	'order'       => $bn_order,
	'fields'      => 'all',
	// No SQL_CALC_FOUND_ROWS — it would scan the WHOLE filtered match set (50k) on
	// every render just to size the pager. A bounded capped count (below) sizes it
	// instead; people browse a few pages, not page 2500 (directory-behaviour principle).
	'count_total' => false,
);

// Dynamic, privacy-aware search resolved to user IDs so the server render
// matches the REST/live path exactly (name/login/email + every searchable
// field mirror; private/tightened values have no mirror so never match).
// Applied to `include` below (intersected with any relation constraint).
if ( '' !== $search_term ) {
	$bn_search_ids = $bn_directory_service->matching_user_ids( $search_term );
	if ( empty( $bn_search_ids ) ) {
		$bn_search_ids = array( 0 ); // Term set but nothing matched → force zero results.
	}
} else {
	$bn_search_ids = null;
}

// Relation filter (Following / Connections) — only relevant when logged in.
if ( $current_user_id > 0 && 'all' !== $bn_relation ) {
	if ( 'following' === $bn_relation ) {
		// Approved follows only — matches list_members()'s following JOIN
		// (status = 'approved') so the relation tab + its pager total agree.
		$relation_ids = buddynext_service( 'follows' )->following( $current_user_id );
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

$bn_directory_filters = array(
	'member_type' => $type_slug_filter,
	'online_only' => $bn_online_only,
);

// Bounded directory total — the cached service owns this number now (A5). The template
// used to run a SECOND WP_User_Query purely to count, uncached, on every directory page
// load; that is the bypass A5 was about. directory_total() runs the capped COUNT once and
// caches it under the same per-viewer salt as the list pages, so the two surfaces share
// one number and a block/membership change busts both together.
$total_users = $bn_directory_service->directory_total( $current_user_id, $bn_directory_filters );

// Member ROWS for first paint. Exclusions (suspended / shadow-banned / dir-opt-out /
// bidirectional blocks), the member-type filter, and the online-only filter are injected
// as correlated subqueries via pre_user_query — never a materialised IN / NOT IN list — so
// the query stays bounded at 50k members. The service owns the query + caching (the
// template never touches $wpdb or WP_User_Query): it returns the ordered id list from the
// SAME versioned bn_dir_ cache as the REST list_members(), so the landing is not an
// uncached query on every load and both surfaces invalidate together. We hydrate the
// WP_User objects the grid needs from the primed user cache.
$bn_page_ids = $bn_directory_service->ssr_page_user_ids( $current_user_id, $user_query_args, $bn_directory_filters );
cache_users( $bn_page_ids );
$members     = array_values( array_filter( array_map( 'get_userdata', $bn_page_ids ) ) );
$total_pages = (int) ceil( $total_users / max( 1, $bn_per_page ) );

// ── Batch-prime per-page member state (no per-card N+1) ───────────────────
// Every per-card lookup the grid needs — follow-state, online dot, mutual
// connections, block-restrict — is resolved for the WHOLE page up front, in a
// handful of set-based queries, so the card loop issues ZERO queries (cold-cache
// safe; the global rule is object cache is a bonus, never a dependency).
$bn_member_ids = array_map( static fn( $m ) => (int) $m->ID, (array) $members );
$bn_blocks_svc = buddynext_service( 'blocks' );

// Follow-state map (target_id => bool) in one query.
$bn_following_map = $current_user_id > 0
	? buddynext_service( 'follows' )->following_map( $current_user_id, $bn_member_ids )
	: array();

// Prime the viewer→peer block-restrict cache so the per-card restrict gate is a
// cache hit, not a query; build the online subset (one bounded bn_presence IN,
// replacing the per-card last_active_at lookup) and the mutual-connection peer map
// (two batched queries, replacing the per-card mutual_connections() self-join).
if ( $current_user_id > 0 && ! empty( $bn_member_ids ) ) {
	$bn_blocks_svc->prime_restricted_cache( $current_user_id, $bn_member_ids );
}
$bn_online_set = $bn_directory_service->online_among( $bn_member_ids );
$bn_mutual_map = $current_user_id > 0
	? $bn_directory_service->mutual_peers_for_page( $current_user_id, $bn_member_ids )
	: array();

// ── Per-card helpers (read the prebuilt maps — O(1), no query) ────────────
$bn_is_online = static function ( int $user_id ) use ( $current_user_id, $bn_online_set, $bn_blocks_svc ): bool {
	if ( empty( $bn_online_set[ $user_id ] ) ) {
		return false;
	}
	// Block restrict gate, resolved from the primed cache (no per-card query).
	if ( $current_user_id > 0 && $current_user_id !== $user_id && $bn_blocks_svc->is_restricted( $current_user_id, $user_id ) ) {
		return false;
	}
	return true;
};

$bn_mutual_ids = static function ( int $user_a, int $user_b ) use ( $bn_mutual_map ): array {
	// $user_a is the viewer, $user_b the rendered member — read the page map.
	return ( 0 === $user_a || 0 === $user_b || $user_a === $user_b )
		? array()
		: ( $bn_mutual_map[ $user_b ] ?? array() );
};

$bn_is_following = static function ( int $target_user_id ) use ( $bn_following_map ): bool {
	return ! empty( $bn_following_map[ $target_user_id ] );
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

// Shared with the member-directory / member-card blocks via
// buddynext_members_directory_context() so the page and the blocks seed the
// buddynext/members store identically. Only the request-specific values are
// passed here; the store defaults (restUrl, the block/report modal fields, …)
// live in the helper. onlineOnly is seeded from the request exactly like the
// other filters — the store reads ctx.onlineOnly when it builds the REST query
// and rewrites the URL, so dropping it here stripped `online=1` from the address
// bar and the checkbox stayed ticked while the results ignored it.
$bn_directory_context = buddynext_members_directory_context(
	array(
		'search'     => $search_term,
		'sort'       => $bn_initial_sort,
		'relation'   => $bn_relation,
		'memberType' => $type_slug_filter,
		'onlineOnly' => $bn_online_only,
		'restNonce'  => $bn_rest_nonce,
		'isEmpty'    => empty( $members ),
		'peopleUrl'  => $bn_directory_url,
	)
);

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

	<?php
	// Distinguish "you are the only member" from "filters matched nothing". On a
	// fresh single-user site the viewer is excluded from the directory, so the
	// grid is empty with NO active filters — show an invite-oriented message
	// instead of "No members match your filters" (which contradicted the sidebar
	// showing the admin). The Reset-filters button only makes sense with filters.
	$bn_filters_active = ( '' !== $search_term )
		|| ( 'all' !== $relation_raw )
		|| $bn_online_only
		|| ( '' !== $type_slug_filter );
	$bn_only_member    = empty( $members ) && ! $bn_filters_active && $current_user_id > 0 && 0 === $total_users;
	?>
	<div
		class="bn-md-empty"
		data-wp-bind--hidden="!state.showEmpty"
		<?php echo empty( $members ) ? '' : 'hidden'; ?>
	>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			$bn_only_member
				? array(
					'icon'  => 'users',
					'title' => __( "You're the only member so far", 'buddynext' ),
					'body'  => __( 'Invite others to join and they will show up here.', 'buddynext' ),
				)
				: array(
					'icon'  => 'users',
					'title' => __( 'No members match your filters', 'buddynext' ),
					'body'  => __( 'Try widening your filters or clearing the search term.', 'buddynext' ),
				)
		);
		?>
		<?php if ( ! $bn_only_member ) : ?>
		<div class="bn-md-empty__actions">
			<button
				type="button"
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				data-wp-on--click="actions.resetFilters"
			><?php esc_html_e( 'Reset filters', 'buddynext' ); ?></button>
		</div>
		<?php endif; ?>
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
