<?php
/**
 * Search results template - v2 design system.
 *
 * Members / Posts / Spaces are resolved through the canonical
 * SearchService::search() so the buddynext_search_query_args seam fires on the
 * rendered page — this is what lets buddynext-pro's advanced member filters
 * (tier / space / label / joined-after / active-within) and the pluggable
 * search driver apply on the web /search surface. Media routes through the same
 * service via its `media` pseudo-type; hashtags resolve through
 * HashtagService::autocomplete() (they are not held in the unified index).
 * Result rows are enriched for display by SearchService::enrich_results() so the
 * section parts run no queries. Results render with v2 primitives (.bn-input,
 * .bn-tabs, .bn-tab, .bn-card, .bn-badge, .bn-avatar, .bn-kbd) and tokens.
 *
 * Mirrors `docs/v2 Plans/v2/search-results.html`.
 *
 * Composer responsibilities:
 *   - Sanitize query / tab / date / sort + advanced filter input.
 *   - Resolve members / posts / spaces / media via SearchService::search() and
 *     hashtags via HashtagService::autocomplete(), respecting viewer blocks +
 *     suspended / shadow-banned exclusions (enforced inside SearchService).
 *   - Compute per-tab counts.
 *   - Build the highlight + initials helpers.
 *   - Wire the right-sidebar hook.
 *   - Render the advanced-filter + saved-search aside (Pro option lists are
 *     sourced via the buddynext_search_filter_options filter; when no provider
 *     populates it the advanced card hides itself — Pro-inactive degrades
 *     gracefully, never fatal).
 *   - Delegate every visual block to a part under templates/parts/.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Sanitize query input.
$raw_query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Respect the Hashtags feature: with it off, search exposes no hashtag tab,
// runs no hashtag query, and a bookmarked ?type=hashtags URL falls back to All.
$bn_hashtags_on = buddynext_feature_enabled( 'hashtags' );

// Resolve the search service early so we can discover which extra object types
// are indexed (jobs via Career Board, listings via Listora, …) and give each a
// tab + section automatically — mirroring SearchService::grouped_search()'s
// dynamic type discovery so addon content is never silently unsearchable.
$bn_search_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'search' ) : new \BuddyNext\Search\SearchService();

// Core index types already rendered by bespoke sections (members/posts/spaces
// resolve to user/post/space; media is a post pseudo-type; hashtags live outside
// the index). Everything else indexed is an "extra" type surfaced generically.
$bn_core_index_types = array( 'user', 'post', 'space', 'media' );
$bn_extra_types      = array_values( array_diff( $bn_search_service->available_types(), $bn_core_index_types ) );

// Human, pluralised labels for extra-type tabs/sections. Addons can name their
// own type via the filter; the fallback title-cases the slug.
$bn_type_labels = (array) apply_filters(
	'buddynext_search_type_labels',
	array(
		'job'     => __( 'Jobs', 'buddynext' ),
		'listing' => __( 'Listings', 'buddynext' ),
	)
);
$bn_label_for   = static function ( string $slug ) use ( $bn_type_labels ): string {
	return isset( $bn_type_labels[ $slug ] )
		? (string) $bn_type_labels[ $slug ]
		: ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
};

// Allowed tabs = core + any extra indexed types.
$allowed_tabs = array_merge( array( 'all', 'members', 'posts', 'spaces', 'hashtags', 'media' ), $bn_extra_types );
if ( ! $bn_hashtags_on ) {
	$allowed_tabs = array_values( array_diff( $allowed_tabs, array( 'hashtags' ) ) );
}
$active_tab = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// Accept legacy "people" alias from older bookmarks.
if ( 'people' === $active_tab ) {
	$active_tab = 'members';
}
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'all';
}

// Allowed date filters.
$allowed_dates = array( 'any', 'week', 'month', 'year' );
$date_filter   = isset( $_GET['date'] ) ? sanitize_key( wp_unslash( $_GET['date'] ) ) : 'any'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $date_filter, $allowed_dates, true ) ) {
	$date_filter = 'any';
}

// Allowed sort options.
$allowed_sorts = array( 'relevant', 'recent' );
$sort_by       = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'relevant'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $sort_by, $allowed_sorts, true ) ) {
	$sort_by = 'relevant';
}

// Pagination. The "All" tab shows a fixed 5-row preview per type; a single-type
// tab shows a fuller page ($bn_per_page) and reads ?spage from $_GET so results
// 6+ are reachable. Only the active SearchService-backed tab paginates; the
// other type fetches stay at 5/1 (they only feed the count badges).
$bn_per_page = 20;
$bn_spage    = isset( $_GET['spage'] ) ? max( 1, absint( wp_unslash( $_GET['spage'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Advanced member filters (Pro). Captured here only to (a) reflect the active
// state back into the controls and (b) build "save current search" payloads.
// The filtering itself happens inside SearchService::search() — Pro's
// AdvancedSearchFilters reads these same keys from $_GET on the seam, so this
// template never references any Pro table directly. All values are optional;
// final validation is Pro's responsibility.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$adv_tier_slug    = isset( $_GET['tier_slug'] ) ? sanitize_key( wp_unslash( (string) $_GET['tier_slug'] ) ) : '';
$adv_space_id     = isset( $_GET['space_id'] ) ? absint( wp_unslash( (string) $_GET['space_id'] ) ) : 0;
$adv_member_label = isset( $_GET['member_label'] ) ? sanitize_key( wp_unslash( (string) $_GET['member_label'] ) ) : '';
$adv_joined_after = '';
if ( isset( $_GET['joined_after'] ) ) {
	$jr = sanitize_text_field( wp_unslash( (string) $_GET['joined_after'] ) );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $jr ) ) {
		$adv_joined_after = $jr;
	}
}
$adv_active_days = isset( $_GET['active_within_days'] ) ? min( 365, absint( wp_unslash( (string) $_GET['active_within_days'] ) ) ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

/**
 * Filter the option lists that populate the advanced member-search controls.
 *
 * BuddyNext Pro hooks this to supply its tier / space / member-label option
 * lists (sourced from its own services). When no provider populates a group,
 * that control is hidden — the page degrades cleanly with Pro inactive.
 *
 * Expected shape:
 *   array(
 *     'tiers'  => array( array( 'slug' => 'gold', 'label' => 'Gold' ), … ),
 *     'spaces' => array( array( 'id' => 12, 'label' => 'Design Team' ), … ),
 *     'labels' => array( array( 'slug' => 'expert', 'label' => 'Expert' ), … ),
 *   )
 *
 * @since 1.0.0
 *
 * @param array<string, array<int, array<string, mixed>>> $options  Option lists, empty by default.
 * @param int                                             $viewer_id Current viewer ID.
 */
$adv_options = (array) apply_filters(
	'buddynext_search_filter_options',
	array(
		'tiers'  => array(),
		'spaces' => array(),
		'labels' => array(),
	),
	get_current_user_id()
);
$adv_tiers   = isset( $adv_options['tiers'] ) ? (array) $adv_options['tiers'] : array();
$adv_spaces  = isset( $adv_options['spaces'] ) ? (array) $adv_options['spaces'] : array();
$adv_labels  = isset( $adv_options['labels'] ) ? (array) $adv_options['labels'] : array();
// The advanced filter card renders whenever a provider offers any option group
// OR the active-within / joined-after generic controls are wanted. Those two
// are query-only (no Pro table) so they always show; tier/space/label show
// only when populated.
$adv_has_provider = ! empty( $adv_tiers ) || ! empty( $adv_spaces ) || ! empty( $adv_labels );

// Only run queries when there is a search term. Members / posts / spaces are
// fetched into the raw SearchService result sets below ($bn_res_*) and enriched
// at render time via SearchService::enrich_results(); hashtags and media keep
// their own presentation arrays.
$bn_res_members   = array( 'items' => array() );
$bn_res_posts     = array( 'items' => array() );
$bn_res_spaces    = array( 'items' => array() );
$results_hashtags = array();
$results_media    = array();
$bn_res_extra     = array(); // slug => SearchService result set, for extra indexed types.
$total_counts     = array(
	'all'      => 0,
	'members'  => 0,
	'posts'    => 0,
	'spaces'   => 0,
	'hashtags' => 0,
	'media'    => 0,
);
foreach ( $bn_extra_types as $bn_xt ) {
	$total_counts[ $bn_xt ] = 0;
}

if ( '' !== $raw_query ) {
	$viewer_id = get_current_user_id();

	// ------------------------------------------------------------------ //
	// Route members / posts / spaces through the canonical SearchService so
	// the buddynext_search_query_args seam fires on the rendered page. This
	// is what lets Pro's advanced member filters (tier / space / label /
	// joined-after / active-within) and the pluggable search driver apply on
	// the web /search surface — they only run through SearchService::search().
	//
	// The five advanced keys + the date / sort selections travel to the seam
	// via $_GET: Pro's AdvancedSearchFilters reads them from the request and
	// SearchService reads `date` / `sort` from the same seam args. No params
	// are passed here beyond the type so there is a single source of truth.
	//
	// Hashtags are not held in bn_search_index (they live in bn_hashtags) so they
	// resolve through HashtagService::autocomplete(). Media now routes through the
	// same SearchService via its `media` pseudo-type (the media_ids join moved
	// into the service). The advanced member filters only target the `user` type,
	// so this split loses nothing.
	// ------------------------------------------------------------------ //
	// $bn_search_service was resolved at the top of the template (needed there for
	// available_types() discovery) and is reused here.

	/**
	 * Adapt a SearchService item array into the stdClass shape the
	 * search-result-section parts consume (object_id / content / author_id).
	 *
	 * @param array<string, mixed> $item One item from SearchService::search().
	 * @return \stdClass
	 */
	$bn_to_row = static function ( array $item ): \stdClass {
		$row            = new \stdClass();
		$row->object_id = (int) ( $item['object_id'] ?? 0 );
		$row->author_id = (int) ( $item['author_id'] ?? 0 );
		// Members index their searchable text in `content`; fall back to the
		// title so the snippet is never empty.
		$content      = (string) ( $item['content'] ?? '' );
		$row->content = '' !== $content ? $content : (string) ( $item['title'] ?? '' );
		$row->title   = (string) ( $item['title'] ?? '' );
		return $row;
	};

	// Every tab's count badge is rendered on every result page, so all type
	// counts must be computed regardless of which tab is active — otherwise
	// switching from "All" to a single tab zeroes the other badges. Each block
	// is scoped so its temp vars stay local; the matching $results_* set is only
	// rendered when its tab is active. The per-type fetch is limit-5/12, so the
	// cost matches what the "All" tab already runs.
	{
		$bn_pp_m                 = ( 'members' === $active_tab ) ? $bn_per_page : 5;
		$bn_pg_m                 = ( 'members' === $active_tab ) ? $bn_spage : 1;
		$bn_res_members          = $bn_search_service->search( $raw_query, 'user', $bn_pp_m, $bn_pg_m, $viewer_id );
		$total_counts['members'] = (int) ( $bn_res_members['total'] ?? count( (array) ( $bn_res_members['items'] ?? array() ) ) );
	}

	{
		$bn_pp_p               = ( 'posts' === $active_tab ) ? $bn_per_page : 5;
		$bn_pg_p               = ( 'posts' === $active_tab ) ? $bn_spage : 1;
		$bn_res_posts          = $bn_search_service->search( $raw_query, 'post', $bn_pp_p, $bn_pg_p, $viewer_id );
		$total_counts['posts'] = (int) ( $bn_res_posts['total'] ?? count( (array) ( $bn_res_posts['items'] ?? array() ) ) );
	}

	{
		$bn_pp_s                = ( 'spaces' === $active_tab ) ? $bn_per_page : 5;
		$bn_pg_s                = ( 'spaces' === $active_tab ) ? $bn_spage : 1;
		$bn_res_spaces          = $bn_search_service->search( $raw_query, 'space', $bn_pp_s, $bn_pg_s, $viewer_id );
		$total_counts['spaces'] = (int) ( $bn_res_spaces['total'] ?? count( (array) ( $bn_res_spaces['items'] ?? array() ) ) );
	}

	// Hashtags: slug-prefix match via the canonical HashtagService. Skipped when
	// the feature is off ($results_hashtags + total stay at their empty defaults).
	if ( $bn_hashtags_on ) {
		$bn_hashtag_service       = function_exists( 'buddynext_service' )
			? buddynext_service( 'hashtags' )
			: new \BuddyNext\Hashtags\HashtagService();
		$results_hashtags         = $bn_hashtag_service->autocomplete( ltrim( $raw_query, '#' ), 10 );
		$total_counts['hashtags'] = count( $results_hashtags );
	}

	// Media: posts that carry attachments, resolved through the same canonical
	// SearchService as the other types via its `media` pseudo-type (the
	// BOOLEAN-MODE + media_ids join + date window now live in the service). The
	// `date` / `sort` selections travel to the seam via $_GET like every other
	// type, so no params are passed here beyond the type.
	{
		$bn_media_res          = $bn_search_service->search( $raw_query, 'media', 12, 1, $viewer_id );
		$results_media         = array_map( $bn_to_row, (array) ( $bn_media_res['items'] ?? array() ) );
		$total_counts['media'] = (int) ( $bn_media_res['total'] ?? count( $results_media ) );
	}

	// Extra indexed types (jobs, listings, …): generic fetch + count. The active
	// tab paginates ($bn_per_page); inactive types fetch 5 only to feed the badge.
	foreach ( $bn_extra_types as $bn_xt ) {
		$bn_pp_x                = ( $bn_xt === $active_tab ) ? $bn_per_page : 5;
		$bn_pg_x                = ( $bn_xt === $active_tab ) ? $bn_spage : 1;
		$bn_res_extra[ $bn_xt ] = $bn_search_service->search( $raw_query, $bn_xt, $bn_pp_x, $bn_pg_x, $viewer_id );
		$total_counts[ $bn_xt ] = (int) ( $bn_res_extra[ $bn_xt ]['total'] ?? count( (array) ( $bn_res_extra[ $bn_xt ]['items'] ?? array() ) ) );
	}

	// Sum every per-type count (core + extras) for the "All" badge.
	$total_counts['all'] = array_sum( array_diff_key( $total_counts, array( 'all' => 0 ) ) );
}

/**
 * Highlight search terms in a text snippet by wrapping them in <mark> tags.
 *
 * Returns a safely escaped snippet with allowed <mark> HTML only.
 *
 * @param string $text  Raw text to highlight.
 * @param string $query Search query string.
 * @return string Escaped HTML with highlighted terms.
 */
$highlight = static function ( string $text, string $query ): string {
	if ( '' === $query ) {
		return esc_html( $text );
	}
	// Trim to 200 chars around first match.
	$pos = stripos( $text, $query );
	if ( false !== $pos ) {
		$start = max( 0, $pos - 60 );
		$text  = ( $start > 0 ? '&hellip;' : '' ) . substr( $text, $start, 200 );
	} else {
		$text = substr( $text, 0, 200 );
	}
	// Escape first, then wrap terms in <mark>.
	$escaped = esc_html( $text );
	$terms   = array_filter( array_map( 'trim', explode( ' ', $query ) ) );
	foreach ( $terms as $term ) {
		$escaped = (string) preg_replace(
			'/(' . preg_quote( esc_html( $term ), '/' ) . ')/i',
			'<mark>$1</mark>',
			$escaped
		);
	}
	return $escaped;
};

$current_user_id = get_current_user_id();

add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => $current_user_id,
			)
		);
	}
);

/**
 * Fires before the search results inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_search_before', $current_user_id );

// Pre-compute type tab definitions for the type-tabs part.
$type_tabs = array(
	'all'      => array(
		'label' => __( 'All', 'buddynext' ),
		'count' => $total_counts['all'],
	),
	'members'  => array(
		'label' => __( 'Members', 'buddynext' ),
		'count' => $total_counts['members'],
	),
	'posts'    => array(
		'label' => __( 'Posts', 'buddynext' ),
		'count' => $total_counts['posts'],
	),
	'spaces'   => array(
		'label' => __( 'Spaces', 'buddynext' ),
		'count' => $total_counts['spaces'],
	),
	'hashtags' => array(
		'label' => __( 'Hashtags', 'buddynext' ),
		'count' => $total_counts['hashtags'],
	),
	'media'    => array(
		'label' => __( 'Media', 'buddynext' ),
		'count' => $total_counts['media'],
	),
);
if ( ! $bn_hashtags_on ) {
	unset( $type_tabs['hashtags'] );
}
// Append a tab for each extra indexed type (jobs, listings, …).
foreach ( $bn_extra_types as $bn_xt ) {
	$type_tabs[ $bn_xt ] = array(
		'label' => $bn_label_for( $bn_xt ),
		'count' => (int) ( $total_counts[ $bn_xt ] ?? 0 ),
	);
}
?>

<?php
// Interactivity context. restNonce / restUrl let the saved-search controls talk
// to the Pro REST collection without a page reload. savedSearchUrl points at the
// Pro namespace; the store degrades to a clear notice if Pro is inactive (404).
// currentArgs is the exact query_args payload "Save current search" POSTs.
$bn_search_ctx = array(
	'query'          => $raw_query,
	'activeTab'      => $active_tab,
	'restUrl'        => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
	'restNonce'      => wp_create_nonce( 'wp_rest' ),
	'savedSearchUrl' => esc_url_raw( rest_url( 'buddynext-pro/v1/me/saved-searches' ) ),
	'isLoggedIn'     => is_user_logged_in(),
	'currentArgs'    => array_filter(
		array(
			'query'              => $raw_query,
			'type'               => 'members' === $active_tab ? 'user' : $active_tab,
			'date'               => 'any' !== $date_filter ? $date_filter : '',
			'sort'               => 'relevant' !== $sort_by ? $sort_by : '',
			'tier_slug'          => $adv_tier_slug,
			'space_id'           => $adv_space_id > 0 ? $adv_space_id : '',
			'member_label'       => $adv_member_label,
			'joined_after'       => $adv_joined_after,
			'active_within_days' => $adv_active_days > 0 ? $adv_active_days : '',
		),
		static fn( $v ): bool => '' !== $v && 0 !== $v
	),
);
?>
<div class="bn-feed-stack bn-search-shell"
	data-wp-interactive="buddynext/search"
	data-wp-context='<?php echo esc_attr( (string) wp_json_encode( $bn_search_ctx ) ); ?>'>

	<h1 class="bn-search-shell__h1 screen-reader-text">
		<?php
		if ( '' !== $raw_query ) {
			printf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for %s', 'buddynext' ),
				esc_html( $raw_query )
			);
		} else {
			esc_html_e( 'Search', 'buddynext' );
		}
		?>
	</h1>

	<!-- Search hero -->
	<?php
	buddynext_get_template(
		'parts/search-hero.php',
		array(
			'query'         => $raw_query,
			'total_results' => (int) $total_counts['all'],
			'active_type'   => $active_tab,
		)
	);
	?>

	<!-- Type filter tabs -->
	<?php
	buddynext_get_template(
		'parts/search-type-tabs.php',
		array(
			'active_type'    => $active_tab,
			'tabs'           => $type_tabs,
			'counts_by_type' => $total_counts,
			'query'          => $raw_query,
		)
	);
	?>

	<?php if ( '' === $raw_query ) : ?>

		<!-- Empty state: no query -->
		<?php
		buddynext_get_template(
			'parts/search-empty-state.php',
			array(
				'state' => 'no_query',
				'query' => $raw_query,
			)
		);
		?>

	<?php else : ?>

		<div class="bn-search-layout">
			<div class="bn-search-results">

				<!-- Members section -->
				<?php
				if ( 'all' === $active_tab || 'members' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-members.php',
						array(
							'members'     => $bn_search_service->enrich_results( (array) ( $bn_res_members['items'] ?? array() ), 'user', $current_user_id ),
							'viewer_id'   => $current_user_id,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['members'],
						)
					);
				}
				?>

				<!-- Posts section -->
				<?php
				if ( 'all' === $active_tab || 'posts' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-posts.php',
						array(
							'posts'        => $bn_search_service->enrich_results( (array) ( $bn_res_posts['items'] ?? array() ), 'post', $current_user_id ),
							'viewer_id'    => $current_user_id,
							'query'        => $raw_query,
							'active_type'  => $active_tab,
							'total_count'  => (int) $total_counts['posts'],
							'highlight_fn' => $highlight,
						)
					);
				}
				?>

				<!-- Spaces section -->
				<?php
				if ( 'all' === $active_tab || 'spaces' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-spaces.php',
						array(
							'spaces'      => $bn_search_service->enrich_results( (array) ( $bn_res_spaces['items'] ?? array() ), 'space', $current_user_id ),
							'viewer_id'   => $current_user_id,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['spaces'],
						)
					);
				}
				?>

				<!-- Hashtags section -->
				<?php
				if ( $bn_hashtags_on && ( 'all' === $active_tab || 'hashtags' === $active_tab ) ) {
					buddynext_get_template(
						'parts/search-result-section-hashtags.php',
						array(
							'hashtags'    => $results_hashtags,
							'query'       => $raw_query,
							'active_type' => $active_tab,
							'total_count' => (int) $total_counts['hashtags'],
						)
					);
				}
				?>

				<!-- Media section -->
				<?php
				if ( 'all' === $active_tab || 'media' === $active_tab ) {
					buddynext_get_template(
						'parts/search-result-section-media.php',
						array(
							'media'        => $results_media,
							'viewer_id'    => $current_user_id,
							'query'        => $raw_query,
							'active_type'  => $active_tab,
							'total_count'  => (int) $total_counts['media'],
							'highlight_fn' => $highlight,
						)
					);
				}
				?>

				<!-- Extra indexed types (jobs, listings, …) rendered generically -->
				<?php
				foreach ( $bn_extra_types as $bn_xt ) :
					if ( 'all' === $active_tab || $bn_xt === $active_tab ) {
						buddynext_get_template(
							'parts/search-result-section-generic.php',
							array(
								'items'        => (array) ( $bn_res_extra[ $bn_xt ]['items'] ?? array() ),
								'type'         => $bn_xt,
								'label'        => $bn_label_for( $bn_xt ),
								'query'        => $raw_query,
								'active_type'  => $active_tab,
								'total_count'  => (int) ( $total_counts[ $bn_xt ] ?? 0 ),
								'highlight_fn' => $highlight,
							)
						);
					}
				endforeach;
				?>

				<!-- Pagination (single-type tabs only; "All" shows fixed previews) -->
				<?php
				if ( in_array( $active_tab, array_merge( array( 'members', 'posts', 'spaces' ), $bn_extra_types ), true ) ) :
					$bn_total_active = (int) $total_counts[ $active_tab ];
					$bn_total_pages  = (int) ceil( $bn_total_active / $bn_per_page );
					if ( $bn_total_pages > 1 ) :
						$bn_page_url = static function ( int $page ) use ( $raw_query, $active_tab, $date_filter, $sort_by, $adv_tier_slug, $adv_space_id, $adv_member_label, $adv_joined_after, $adv_active_days ): string {
							// Carry the advanced member filters onto page 2+ — without these
							// in the use()/args the filters silently dropped on paging, so
							// later pages showed an unfiltered result set. Empty values are
							// stripped so unfiltered URLs stay clean.
							$args = array(
								'q'                  => $raw_query,
								'type'               => $active_tab,
								'date'               => $date_filter,
								'sort'               => $sort_by,
								'spage'              => $page,
								'tier_slug'          => '' !== $adv_tier_slug ? $adv_tier_slug : false,
								'space_id'           => $adv_space_id > 0 ? $adv_space_id : false,
								'member_label'       => '' !== $adv_member_label ? $adv_member_label : false,
								'joined_after'       => '' !== $adv_joined_after ? $adv_joined_after : false,
								'active_within_days' => $adv_active_days > 0 ? $adv_active_days : false,
							);
							return esc_url( add_query_arg( array_filter( $args, static fn( $v ): bool => false !== $v ) ) );
						};
						?>
						<nav class="bn-search__pagination" aria-label="<?php esc_attr_e( 'Search results pages', 'buddynext' ); ?>">
							<?php if ( $bn_spage > 1 ) : ?>
								<a class="bn-btn bn-search__page-prev" data-variant="ghost" href="<?php echo $bn_page_url( $bn_spage - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Previous', 'buddynext' ); ?></a>
							<?php endif; ?>
							<span class="bn-search__page-status">
								<?php
								printf(
									/* translators: 1: current page, 2: total pages. */
									esc_html__( 'Page %1$d of %2$d', 'buddynext' ),
									(int) min( $bn_spage, $bn_total_pages ),
									(int) $bn_total_pages
								);
								?>
							</span>
							<?php if ( $bn_spage < $bn_total_pages ) : ?>
								<a class="bn-btn bn-search__page-next" data-variant="ghost" href="<?php echo $bn_page_url( $bn_spage + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Next', 'buddynext' ); ?></a>
							<?php endif; ?>
						</nav>
						<?php
					endif;
				endif;
				?>

				<!-- No results state -->
				<?php
				if ( 0 === $total_counts['all'] ) {
					buddynext_get_template(
						'parts/search-empty-state.php',
						array(
							'state' => 'no_results',
							'query' => $raw_query,
						)
					);
				}
				?>

			</div><!-- /.bn-search-results -->

			<!-- Filter aside -->
			<aside class="bn-search-aside" aria-label="<?php esc_attr_e( 'Refine search', 'buddynext' ); ?>">

				<div class="bn-card bn-search-aside__card" data-interactive>
					<h3 class="bn-search-aside__title">
						<?php esc_html_e( 'Date', 'buddynext' ); ?>
					</h3>
					<?php
					$date_opts = array(
						'any'   => __( 'Any time', 'buddynext' ),
						'week'  => __( 'Past week', 'buddynext' ),
						'month' => __( 'Past month', 'buddynext' ),
						'year'  => __( 'Past year', 'buddynext' ),
					);
					foreach ( $date_opts as $dval => $dlabel ) :
						$dhref  = esc_url(
							add_query_arg(
								array(
									'q'    => $raw_query,
									'type' => $active_tab,
									'date' => $dval,
									'sort' => $sort_by,
								)
							)
						);
						$opt_id = 'bn-search-date-' . sanitize_key( $dval );
						?>
						<label class="bn-search-aside__opt" for="<?php echo esc_attr( $opt_id ); ?>">
							<input
								id="<?php echo esc_attr( $opt_id ); ?>"
								type="radio"
								name="bn_date_filter"
								value="<?php echo esc_attr( $dval ); ?>"
								<?php checked( $date_filter, $dval ); ?>
								data-wp-on--change="actions.applyDateFilter"
								data-href="<?php echo esc_url( $dhref ); ?>">
							<span><?php echo esc_html( $dlabel ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="bn-card bn-search-aside__card" data-interactive>
					<h3 class="bn-search-aside__title">
						<?php esc_html_e( 'Sort by', 'buddynext' ); ?>
					</h3>
					<?php
					$sort_opts = array(
						'relevant' => __( 'Most relevant', 'buddynext' ),
						'recent'   => __( 'Most recent', 'buddynext' ),
					);
					foreach ( $sort_opts as $sval => $slabel ) :
						$shref  = esc_url(
							add_query_arg(
								array(
									'q'    => $raw_query,
									'type' => $active_tab,
									'date' => $date_filter,
									'sort' => $sval,
								)
							)
						);
						$opt_id = 'bn-search-sort-' . sanitize_key( $sval );
						?>
						<label class="bn-search-aside__opt" for="<?php echo esc_attr( $opt_id ); ?>">
							<input
								id="<?php echo esc_attr( $opt_id ); ?>"
								type="radio"
								name="bn_sort_filter"
								value="<?php echo esc_attr( $sval ); ?>"
								<?php checked( $sort_by, $sval ); ?>
								data-wp-on--change="actions.applySortFilter"
								data-href="<?php echo esc_url( $shref ); ?>">
							<span><?php echo esc_html( $slabel ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<?php
				// ── Advanced member filters (Pro) ─────────────────────────
				// Plain GET form: submitting reloads /search with the chosen
				// keys in the query string. SearchService reads them off the
				// seam (Pro's AdvancedSearchFilters sources them from $_GET),
				// so this needs no JS to work. The whole card hides when no
				// provider populates option lists AND the generic date-based
				// member controls are the only thing left — but joined_after /
				// active_within_days are query-only and useful even without
				// Pro tables, so the card shows whenever the members tab is in
				// scope.
				$adv_in_scope = in_array( $active_tab, array( 'all', 'members' ), true );
				if ( $adv_in_scope ) :
					?>
					<form class="bn-card bn-search-aside__card bn-search-aside__adv"
						method="get"
						action="<?php echo esc_url( remove_query_arg( array( 'tier_slug', 'space_id', 'member_label', 'joined_after', 'active_within_days', 'page' ) ) ); ?>">
						<h3 class="bn-search-aside__title">
							<span aria-hidden="true"><?php buddynext_icon( 'filter' ); ?></span>
							<?php esc_html_e( 'Advanced member filters', 'buddynext' ); ?>
						</h3>

						<input type="hidden" name="q" value="<?php echo esc_attr( $raw_query ); ?>">
						<input type="hidden" name="type" value="members">
						<input type="hidden" name="date" value="<?php echo esc_attr( $date_filter ); ?>">
						<input type="hidden" name="sort" value="<?php echo esc_attr( $sort_by ); ?>">

						<?php if ( ! empty( $adv_tiers ) ) : ?>
							<label class="bn-search-aside__field" for="bn-adv-tier">
								<span class="bn-search-aside__label"><?php esc_html_e( 'Membership tier', 'buddynext' ); ?></span>
								<select class="bn-input" id="bn-adv-tier" name="tier_slug">
									<option value=""><?php esc_html_e( 'Any tier', 'buddynext' ); ?></option>
									<?php
									foreach ( $adv_tiers as $bn_tier ) :
										$tslug  = isset( $bn_tier['slug'] ) ? (string) $bn_tier['slug'] : '';
										$tlabel = isset( $bn_tier['label'] ) ? (string) $bn_tier['label'] : $tslug;
										if ( '' === $tslug ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $tslug ); ?>" <?php selected( $adv_tier_slug, $tslug ); ?>>
											<?php echo esc_html( $tlabel ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>

						<?php if ( ! empty( $adv_spaces ) ) : ?>
							<label class="bn-search-aside__field" for="bn-adv-space">
								<span class="bn-search-aside__label"><?php esc_html_e( 'Member of space', 'buddynext' ); ?></span>
								<select class="bn-input" id="bn-adv-space" name="space_id">
									<option value="0"><?php esc_html_e( 'Any space', 'buddynext' ); ?></option>
									<?php
									foreach ( $adv_spaces as $bn_space ) :
										$sid    = isset( $bn_space['id'] ) ? (int) $bn_space['id'] : 0;
										$slabel = isset( $bn_space['label'] ) ? (string) $bn_space['label'] : (string) $sid;
										if ( $sid <= 0 ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( (string) $sid ); ?>" <?php selected( $adv_space_id, $sid ); ?>>
											<?php echo esc_html( $slabel ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>

						<?php if ( ! empty( $adv_labels ) ) : ?>
							<label class="bn-search-aside__field" for="bn-adv-label">
								<span class="bn-search-aside__label"><?php esc_html_e( 'Member label', 'buddynext' ); ?></span>
								<select class="bn-input" id="bn-adv-label" name="member_label">
									<option value=""><?php esc_html_e( 'Any label', 'buddynext' ); ?></option>
									<?php
									foreach ( $adv_labels as $bn_label ) :
										$lslug  = isset( $bn_label['slug'] ) ? (string) $bn_label['slug'] : '';
										$llabel = isset( $bn_label['label'] ) ? (string) $bn_label['label'] : $lslug;
										if ( '' === $lslug ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $lslug ); ?>" <?php selected( $adv_member_label, $lslug ); ?>>
											<?php echo esc_html( $llabel ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>

						<label class="bn-search-aside__field" for="bn-adv-joined">
							<span class="bn-search-aside__label"><?php esc_html_e( 'Joined on or after', 'buddynext' ); ?></span>
							<input class="bn-input" type="date" id="bn-adv-joined" name="joined_after"
								value="<?php echo esc_attr( $adv_joined_after ); ?>">
						</label>

						<label class="bn-search-aside__field" for="bn-adv-active">
							<span class="bn-search-aside__label"><?php esc_html_e( 'Active within (days)', 'buddynext' ); ?></span>
							<input class="bn-input" type="number" id="bn-adv-active" name="active_within_days"
								min="1" max="365" inputmode="numeric"
								placeholder="<?php esc_attr_e( 'Any time', 'buddynext' ); ?>"
								value="<?php echo $adv_active_days > 0 ? esc_attr( (string) $adv_active_days ) : ''; ?>">
						</label>

						<?php if ( ! $adv_has_provider ) : ?>
							<p class="bn-search-aside__hint">
								<?php esc_html_e( 'Tier, space and label filters appear when BuddyNext Pro is active.', 'buddynext' ); ?>
							</p>
						<?php endif; ?>

						<div class="bn-search-aside__actions">
							<button type="submit" class="bn-btn" data-variant="primary">
								<span aria-hidden="true"><?php buddynext_icon( 'filter' ); ?></span>
								<?php esc_html_e( 'Apply filters', 'buddynext' ); ?>
							</button>
							<a class="bn-btn" data-variant="ghost"
								href="<?php echo esc_url( remove_query_arg( array( 'tier_slug', 'space_id', 'member_label', 'joined_after', 'active_within_days', 'page' ) ) ); ?>">
								<?php esc_html_e( 'Reset', 'buddynext' ); ?>
							</a>
						</div>
					</form>

					<?php
					// ── Saved searches (Pro) ──────────────────────────────
					// Bound to the existing buddynext-pro/v1/me/saved-searches
					// REST routes via the search Interactivity store. The list
					// is fetched on hydrate; save / run / delete are live. When
					// Pro is inactive the collection 404s and the store renders
					// a single "requires BuddyNext Pro" line — never fatal.
					if ( $bn_search_ctx['isLoggedIn'] ) :
						?>
						<div class="bn-card bn-search-aside__card bn-search-saved"
							data-interactive
							data-wp-init="callbacks.loadSaved">
							<h3 class="bn-search-aside__title">
								<span aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></span>
								<?php esc_html_e( 'Saved searches', 'buddynext' ); ?>
							</h3>

							<div class="bn-search-saved__save">
								<label class="screen-reader-text" for="bn-saved-name">
									<?php esc_html_e( 'Name this search', 'buddynext' ); ?>
								</label>
								<input class="bn-input" type="text" id="bn-saved-name" maxlength="120"
									placeholder="<?php esc_attr_e( 'Name this search…', 'buddynext' ); ?>"
									data-wp-bind--value="context.savedName"
									data-wp-on--input="actions.setSavedName">
								<button type="button" class="bn-btn" data-variant="primary"
									data-wp-on--click="actions.saveCurrent"
									<?php echo '' === $raw_query ? 'disabled' : ''; ?>>
									<span aria-hidden="true"><?php buddynext_icon( 'plus' ); ?></span>
									<?php esc_html_e( 'Save current', 'buddynext' ); ?>
								</button>
							</div>

							<p class="bn-search-saved__msg" role="status" aria-live="polite"
								data-wp-text="context.savedMsg"
								data-wp-bind--hidden="!context.savedMsg"></p>

							<ul class="bn-search-saved__list" data-wp-bind--hidden="!state.hasSaved">
								<template data-wp-each="state.savedSearches">
									<li class="bn-search-saved__item">
										<a class="bn-search-saved__run"
											data-wp-bind--href="context.item.url"
											data-wp-text="context.item.name"></a>
										<button type="button" class="bn-btn-icon" data-variant="ghost"
											data-wp-on--click="actions.deleteSaved"
											data-wp-bind--data-saved-id="context.item.id"
											aria-label="<?php esc_attr_e( 'Delete saved search', 'buddynext' ); ?>">
											<span aria-hidden="true"><?php buddynext_icon( 'trash' ); ?></span>
										</button>
									</li>
								</template>
							</ul>

							<p class="bn-search-saved__empty"
								data-wp-bind--hidden="state.hasSaved">
								<?php esc_html_e( 'No saved searches yet.', 'buddynext' ); ?>
							</p>
						</div>
						<?php
					endif;
				endif;
				?>

				<?php
				// Related searches: derive from query tokens.
				$related = array_filter(
					array_map( 'trim', explode( ' ', $raw_query ) ),
					static fn( string $t ): bool => mb_strlen( $t ) > 3
				);
				if ( ! empty( $related ) ) :
					?>
					<div class="bn-card bn-search-aside__card" data-interactive>
						<h3 class="bn-search-aside__title">
							<?php esc_html_e( 'Related searches', 'buddynext' ); ?>
						</h3>
						<div class="bn-search-aside__tags">
							<?php foreach ( array_slice( $related, 0, 6 ) as $rel_term ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'q', $rel_term ) ); ?>"
									class="bn-badge bn-search-aside__tag"
									data-tone="accent">
									<span aria-hidden="true"><?php buddynext_icon( 'hash' ); ?></span>
									<?php echo esc_html( $rel_term ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

			</aside>
		</div><!-- /.bn-search-layout -->

	<?php endif; // End: raw_query check. ?>
</div><!-- /.bn-search-shell -->

<?php
/**
 * Fires after the search results inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_search_after', $current_user_id );
?>
