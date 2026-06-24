<?php
/**
 * Template: Spaces Directory (v2 inner).
 *
 * Renders the spaces directory inside the shell main column
 * (`<main class="bn-app__main">` — see templates/shell/hub-shell.php).
 * This inner template does NOT own the rail or the
 * 2-column page grid. Sidebar widgets (categories, your spaces,
 * featured) are registered on the `buddynext_right_sidebar` action;
 * the shell auto-renders the right column when callbacks are present.
 *
 * v2 prototype: docs/v2 Plans/v2/spaces-directory.html.
 *
 * Composition:
 *   - parts/section-head.php   Heading + Create-space CTA.
 *   - parts/filter-strip.php   Search + category/type/sort selects.
 *   - bn-tabs                  Category chip strip (All + each category).
 *   - .bn-sd-grid              Space-card grid (auto-fill).
 *   - parts/pagination.php     Page links.
 *
 * Overridable: copy to {theme}/buddynext/spaces/directory.php.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// ── Query parameters ─────────────────────────────────────────────────────────

$current_user_id = get_current_user_id();
$bn_search       = isset( $_GET['bn_search'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_cat_slug     = isset( $_GET['bn_cat'] ) ? sanitize_key( wp_unslash( $_GET['bn_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_visibility   = isset( $_GET['bn_type'] ) ? sanitize_key( wp_unslash( $_GET['bn_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_orderby      = isset( $_GET['bn_sort'] ) ? sanitize_key( wp_unslash( $_GET['bn_sort'] ) ) : 'popular'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_paged        = isset( $_GET['bn_page'] ) ? max( 1, absint( $_GET['bn_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_per_page     = 18;
$bn_scope        = isset( $_GET['bn_scope'] ) ? sanitize_key( wp_unslash( $_GET['bn_scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rest_nonce      = wp_create_nonce( 'wp_rest' );

$bn_is_site_admin = current_user_can( 'manage_options' );
$bn_space_service = new \BuddyNext\Spaces\SpaceService();

// ── Categories (chip row + per-card category label resolution) ────────────────
// Single source: the same service the category controller uses. Each row carries
// id / name / slug; we build an id→row map so per-card category_name/slug are
// resolved in PHP without a join (list_spaces() rows expose category_id only).
$bn_categories  = $bn_space_service->categories_with_counts();
$bn_cat_by_id   = array();
$bn_cat_by_slug = array();
foreach ( $bn_categories as $bn_cat_row ) {
	$bn_cat_by_id[ (int) $bn_cat_row['id'] ]        = $bn_cat_row;
	$bn_cat_by_slug[ (string) $bn_cat_row['slug'] ] = $bn_cat_row;
}

// ── Resolve the active scope/filter into service args ─────────────────────────
// Mirrors SpaceController::list_spaces() so the SSR grid and the GET /spaces
// REST route the reactive filter calls return the identical set of spaces.
$bn_sort_map                           = array(
	'popular'      => array( 'member_count', 'DESC' ),
	'active'       => array( 'member_count', 'DESC' ),
	'newest'       => array( 'created_at', 'DESC' ),
	'alphabetical' => array( 'name', 'ASC' ),
);
list( $bn_orderby_col, $bn_order_dir ) = $bn_sort_map[ $bn_orderby ] ?? $bn_sort_map['popular'];

$bn_query_args = array(
	'per_page' => $bn_per_page,
	'page'     => $bn_paged,
	'orderby'  => $bn_orderby_col,
	'order'    => $bn_order_dir,
	'viewer'   => $current_user_id,
	'is_admin' => $bn_is_site_admin,
);

// Visibility-type chip (rarely used; kept for shareable ?bn_type= links).
if ( '' !== $bn_visibility && \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_valid( $bn_visibility ) ) {
	$bn_query_args['type'] = $bn_visibility;
}

// Category chip → category_id (service filters on the id, not the slug).
if ( '' !== $bn_cat_slug && isset( $bn_cat_by_slug[ $bn_cat_slug ] ) ) {
	$bn_query_args['category_id'] = (int) $bn_cat_by_slug[ $bn_cat_slug ]['id'];
}

// "My Spaces" scope → the service's `member` arg (owned or active membership).
if ( 'mine' === $bn_scope && $current_user_id > 0 ) {
	$bn_query_args['member'] = $current_user_id;
}

// ── Fetch spaces + total via the service layer ────────────────────────────────
if ( '' !== $bn_search ) {
	// search() applies the same secret-space visibility predicate; it has no
	// paginated-total variant (the REST route returns search rows unpaginated
	// too), so the subtitle/pager reflect the returned batch.
	$bn_spaces    = $bn_space_service->search( $bn_search, $bn_query_args );
	$total_spaces = count( $bn_spaces );
} else {
	$bn_listing   = $bn_space_service->list_spaces_with_total( $bn_query_args );
	$bn_spaces    = $bn_listing['items'];
	$total_spaces = (int) $bn_listing['total'];
}

$total_pages = (int) ceil( $total_spaces / $bn_per_page );

// ── Current user's membership across the returned spaces (one query) ──────────
$membership_map = array();
if ( $current_user_id && ! empty( $bn_spaces ) ) {
	$bn_space_ids   = array_map( static fn( $s ) => (int) $s['id'], $bn_spaces );
	$membership_map = ( new \BuddyNext\Spaces\SpaceMemberService() )->membership_map( $current_user_id, $bn_space_ids );
}

// Categories list for the create-space modal (expects objects with ->id/->name).
$categories = array_map(
	static fn( $c ) => (object) array(
		'id'   => (int) $c['id'],
		'name' => (string) $c['name'],
		'slug' => (string) $c['slug'],
	),
	$bn_categories
);

// ── Per-space tone palette (deterministic by id) ──────────────────────────────

if ( ! function_exists( 'bn_space_cover_tone' ) ) {
	/**
	 * Return a cover-tone slug from a deterministic palette.
	 *
	 * @param int $space_id Space ID used to pick a tone.
	 * @return string Tone slug consumed by `.bn-sd-card__cover[data-tone]`.
	 */
	function bn_space_cover_tone( int $space_id ): string {
		$tones = array( 'sky', 'cyan', 'emerald', 'lime', 'amber', 'coral' );
		return $tones[ $space_id % count( $tones ) ];
	}
}

if ( ! function_exists( 'bn_space_category_icon' ) ) {
	/**
	 * Returns an SVG icon for a space category slug.
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

if ( ! function_exists( 'bn_space_side_emblem' ) ) {
	/**
	 * Sidebar space emblem: the real space avatar when set, else the category
	 * glyph. Keeps the spaces-directory sidebar consistent with the activity
	 * sidebar (which shows the real avatar); the category icon is the fallback so
	 * a row is never empty. Single helper used by every directory sidebar list.
	 *
	 * @param array<string, mixed> $space Hydrated space row (avatar_url, category_slug).
	 * @return string Safe markup (escaped img, or wp_kses-sanitized SVG).
	 */
	function bn_space_side_emblem( array $space ): string {
		$avatar = isset( $space['avatar_url'] ) ? (string) $space['avatar_url'] : '';
		if ( '' !== $avatar ) {
			return '<img src="' . esc_url( $avatar ) . '" alt="" width="28" height="28" loading="lazy">';
		}
		return bn_space_category_icon( isset( $space['category_slug'] ) ? (string) $space['category_slug'] : '' );
	}
}

// ── Right sidebar widgets ────────────────────────────────────────────────────
// Registered on the shared hub-shell action. The shell detects via
// has_action() after the inner buffer flushes and renders the right column.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id, $bn_space_service, $bn_cat_by_id ) {
		// Categories are filtered from the primary chip row at the top of the
		// directory now (single-select scope + category), so the old sidebar
		// "Categories" card was removed to avoid two places doing the same job.

		// Resolve the category slug for a hydrated space row (rows carry
		// category_id; the emblem helper wants the slug) so a row is never empty.
		$bn_resolve_slug = static function ( array $space ) use ( $bn_cat_by_id ): array {
			$cid                    = isset( $space['category_id'] ) ? (int) $space['category_id'] : 0;
			$space['category_slug'] = $cid && isset( $bn_cat_by_id[ $cid ] )
				? (string) $bn_cat_by_id[ $cid ]['slug']
				: '';
			return $space;
		};

		// Card: Your spaces (members only). The service's `member` arg INNER JOINs
		// the viewer's active memberships and lifts the secret-space exclusion.
		if ( $current_user_id ) {
			$bn_my_spaces = $bn_space_service->list_spaces(
				array(
					'member'   => $current_user_id,
					'viewer'   => $current_user_id,
					'per_page' => 6,
				)
			);

			if ( ! empty( $bn_my_spaces ) ) {
				ob_start();
				?>
				<ul class="bn-sd-side-list">
					<?php
					foreach ( $bn_my_spaces as $bn_ms ) :
						$bn_ms = $bn_resolve_slug( $bn_ms );
						?>
						<li>
							<a href="<?php echo esc_url( buddynext_space_url( $bn_ms['slug'] ) ); ?>" class="bn-sd-side-row">
								<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo bn_space_side_emblem( $bn_ms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns wp_kses()-sanitized SVG. ?></span>
								<span><?php echo esc_html( $bn_ms['name'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$bn_my_html = (string) ob_get_clean();

				buddynext_get_template(
					'parts/sidebar-card.php',
					array(
						'id'         => 'spaces-yours',
						'title'      => __( 'Your spaces', 'buddynext' ),
						'title_icon' => 'users',
						'body_html'  => $bn_my_html,
					)
				);
			}
		}

		// Card 3: Featured spaces (highest member-count, type=open).
		$bn_featured = $bn_space_service->list_spaces(
			array(
				'type'     => 'open',
				'orderby'  => 'member_count',
				'order'    => 'DESC',
				'per_page' => 5,
				'viewer'   => $current_user_id,
				'is_admin' => current_user_can( 'manage_options' ),
			)
		);

		if ( ! empty( $bn_featured ) ) {
			ob_start();
			?>
			<ul class="bn-sd-side-list">
				<?php
				foreach ( $bn_featured as $bn_f ) :
					$bn_f = $bn_resolve_slug( $bn_f );
					?>
					<li>
						<a href="<?php echo esc_url( buddynext_space_url( $bn_f['slug'] ) ); ?>" class="bn-sd-side-row">
							<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo bn_space_side_emblem( $bn_f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns wp_kses()-sanitized SVG. ?></span>
							<span class="bn-sd-side-row__main">
								<span><?php echo esc_html( $bn_f['name'] ); ?></span>
								<span class="bn-sd-side-row__meta">
								<?php
								$bn_sd_mc = (int) $bn_f['member_count'];
								/* translators: %s: formatted member count */ printf( esc_html( _n( '%s member', '%s members', $bn_sd_mc, 'buddynext' ) ), esc_html( number_format_i18n( $bn_sd_mc ) ) );
								?>
								</span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			$bn_feat_html = (string) ob_get_clean();

			buddynext_get_template(
				'parts/sidebar-card.php',
				array(
					'id'         => 'spaces-featured',
					'title'      => __( 'Popular this week', 'buddynext' ),
					'title_icon' => 'star',
					'body_html'  => $bn_feat_html,
				)
			);
		}
	}
);

/**
 * Fires before the spaces-directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_spaces_directory_before', $current_user_id );

// ── Render ───────────────────────────────────────────────────────────────────

// Primary filter chips are scope + category, not visibility type. A member
// browses by "all / mine / a topic", the way Facebook, X, and LinkedIn group
// spaces — open/private/secret is a creator concern, surfaced on each card and
// in space settings, never as a directory filter. The chips render from
// $categories (fetched above); the row is built inline below.
$bn_sort_options = array(
	'popular'      => __( 'Sort: Popular', 'buddynext' ),
	'active'       => __( 'Most active', 'buddynext' ),
	'newest'       => __( 'Newest', 'buddynext' ),
	'alphabetical' => __( 'A → Z', 'buddynext' ),
);

// Section-head actions slot — Create-space CTA (only for users allowed to create spaces).
$bn_actions_html = '';
if ( buddynext_can( get_current_user_id(), 'buddynext-spaces/create' ) ) {
	$bn_actions_html = sprintf(
		'<button type="button" class="bn-btn" data-variant="primary" data-size="md" data-wp-on--click="actions.openCreate" data-bn-create-space-trigger>%s</button>',
		esc_html__( 'Create a space', 'buddynext' )
	);
}

/* translators: %s: total number of spaces. */
$bn_subtitle = sprintf(
	/* translators: %s: total number of spaces. */
	_n( '%s space available', '%s spaces available', $total_spaces, 'buddynext' ),
	number_format_i18n( $total_spaces )
);
?>
<div class="bn-sd-stack"
	data-wp-interactive="buddynext/spaces"
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

	<?php
	buddynext_get_template(
		'parts/section-head.php',
		array(
			'title'         => __( 'Spaces', 'buddynext' ),
			'subtitle'      => $bn_subtitle,
			'title_icon'    => 'home',
			'heading_level' => 'h1',
			'actions_html'  => $bn_actions_html,
		)
	);
	?>

	<?php
	buddynext_get_template(
		'parts/filter-strip.php',
		array(
			'search'   => array(
				'name'        => 'bn_search',
				'value'       => $bn_search,
				'placeholder' => __( 'Search spaces…', 'buddynext' ),
				'aria_label'  => __( 'Search spaces', 'buddynext' ),
			),
			// Type lives in the pill row below; category lives in the sidebar
			// card. No duplicate select dropdowns here — one home per filter.
			'selects'  => array(),
			'reactive' => true,
		)
	);
	?>

	<div class="bn-sd-filter-row">
		<nav class="bn-tabs bn-sd-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter spaces', 'buddynext' ); ?>" data-bn-scope-chips>
			<?php
			// One single-select row: All Spaces / My Spaces / one chip per
			// category. Exactly one chip is lit at a time — selecting any chip
			// clears the rest (actions.setScope), so the directory never shows two
			// conflicting filters at once. "My Spaces" wins over a stale category
			// in the URL so the initial highlight is always unambiguous.
			$bn_is_mine = ( 'mine' === $bn_scope && $current_user_id > 0 );
			?>
			<button
				type="button"
				class="bn-tab bn-sd-chip"
				role="tab"
				aria-selected="<?php echo ( ! $bn_is_mine && '' === $bn_cat_slug ) ? 'true' : 'false'; ?>"
				data-bn-scope-chip="all"
				data-wp-on--click="actions.setScope"
			><?php esc_html_e( 'All Spaces', 'buddynext' ); ?></button>
			<?php if ( $current_user_id > 0 ) : ?>
				<button
					type="button"
					class="bn-tab bn-sd-chip"
					role="tab"
					aria-selected="<?php echo $bn_is_mine ? 'true' : 'false'; ?>"
					data-bn-scope-chip="mine"
					data-wp-on--click="actions.setScope"
				><?php esc_html_e( 'My Spaces', 'buddynext' ); ?></button>
			<?php endif; ?>
			<?php foreach ( $categories as $bn_cat_item ) : ?>
				<button
					type="button"
					class="bn-tab bn-sd-chip"
					role="tab"
					aria-selected="<?php echo ( ! $bn_is_mine && $bn_cat_item->slug === $bn_cat_slug ) ? 'true' : 'false'; ?>"
					data-bn-scope-chip="cat"
					data-bn-cat-id="<?php echo esc_attr( (string) $bn_cat_item->id ); ?>"
					data-bn-cat-slug="<?php echo esc_attr( (string) $bn_cat_item->slug ); ?>"
					data-wp-on--click="actions.setScope"
				><?php echo esc_html( $bn_cat_item->name ); ?></button>
			<?php endforeach; ?>
		</nav>

		<div class="bn-sd-sort" data-bn-sort-popover>
			<button
				type="button"
				class="bn-btn bn-sd-sort__trigger"
				data-variant="secondary"
				data-size="sm"
				aria-haspopup="listbox"
				aria-expanded="false"
				data-bn-sort-trigger
				data-wp-on--click="actions.toggleSortPopover"
			>
				<span data-bn-sort-label>
					<?php echo esc_html( $bn_sort_options[ $bn_orderby ] ?? $bn_sort_options['popular'] ); ?>
				</span>
				<?php buddynext_icon( 'chevron-down' ); ?>
			</button>
			<ul class="bn-sd-sort__list" role="listbox" aria-label="<?php esc_attr_e( 'Sort spaces', 'buddynext' ); ?>" hidden data-bn-sort-list>
				<?php foreach ( $bn_sort_options as $bn_sort_val => $bn_sort_label ) : ?>
					<li>
						<button
							type="button"
							class="bn-sd-sort__option"
							role="option"
							aria-selected="<?php echo ( $bn_orderby === $bn_sort_val ) ? 'true' : 'false'; ?>"
							data-bn-sort-value="<?php echo esc_attr( (string) $bn_sort_val ); ?>"
							data-wp-on--click="actions.setSort"
						><?php echo esc_html( (string) $bn_sort_label ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<div class="bn-sd-loading" data-bn-loading hidden aria-hidden="true">
		<?php for ( $bn_skel_i = 0; $bn_skel_i < 6; $bn_skel_i++ ) : ?>
			<div class="bn-sd-skeleton" aria-hidden="true">
				<div class="bn-sd-skeleton__cover"></div>
				<div class="bn-sd-skeleton__line"></div>
				<div class="bn-sd-skeleton__line bn-sd-skeleton__line--short"></div>
			</div>
		<?php endfor; ?>
	</div>

	<div class="bn-sd-error" data-bn-error hidden role="alert">
		<p class="bn-sd-error__message"><?php esc_html_e( 'Something went wrong loading spaces.', 'buddynext' ); ?></p>
		<button
			type="button"
			class="bn-btn"
			data-variant="secondary"
			data-size="sm"
			data-wp-on--click="actions.applyFilter"
		><?php esc_html_e( 'Retry', 'buddynext' ); ?></button>
	</div>

	<div class="bn-sd-results" data-bn-sd-results>

	<?php
	// Distinguish "no spaces in the system at all" (cold-start state) from
	// "filter returned zero". The cold-start state pitches the Create CTA
	// instead of a Reset-filters CTA that would no-op.
	$bn_filters_active = ( '' !== $bn_search ) || ( '' !== $bn_cat_slug ) || ( 'mine' === $bn_scope ) || ( 'popular' !== $bn_orderby );
	?>
	<?php if ( empty( $bn_spaces ) && ! $bn_filters_active ) : ?>

		<div class="bn-sd-empty" data-bn-sd-empty>
			<?php
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'home',
					'title' => __( 'No spaces yet', 'buddynext' ),
					'body'  => __( 'Create the first space to start a discussion.', 'buddynext' ),
				)
			);
			?>
			<?php if ( buddynext_can( get_current_user_id(), 'buddynext-spaces/create' ) ) : ?>
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-wp-on--click="actions.openCreate"
					data-bn-create-space-trigger
				><?php esc_html_e( 'Create a space', 'buddynext' ); ?></button>
			<?php endif; ?>
		</div>

	<?php elseif ( empty( $bn_spaces ) ) : ?>

		<div class="bn-sd-empty" data-bn-sd-empty>
			<?php
			buddynext_get_template(
				'parts/empty-state.php',
				array(
					'icon'  => 'search',
					'title' => __( 'No spaces match', 'buddynext' ),
					'body'  => __( 'Try widening your filters.', 'buddynext' ),
				)
			);
			?>
			<button
				type="button"
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				data-wp-on--click="actions.resetFilters"
			><?php esc_html_e( 'Reset filters', 'buddynext' ); ?></button>
		</div>

	<?php else : ?>

		<?php
		// Owner-configurable desktop column count (Settings > Spaces > Directory columns).
		// 'auto' keeps the responsive auto-fill; 2/3/4 cap the desktop row via [data-cols].
		$bn_sd_cols = (string) get_option( 'buddynext_spaces_dir_columns', '3' );
		$bn_sd_cols = in_array( $bn_sd_cols, array( '2', '3', '4' ), true ) ? $bn_sd_cols : 'auto';
		?>
		<div class="bn-sd-grid" role="list" data-bn-sd-grid<?php echo 'auto' !== $bn_sd_cols ? ' data-cols="' . esc_attr( $bn_sd_cols ) . '"' : ''; ?>>

			<?php foreach ( $bn_spaces as $space ) : ?>
				<?php
				$space_id     = (int) $space['id'];
				$membership   = $membership_map[ $space_id ] ?? null;
				$is_admin_mod = $membership && in_array( $membership['role'], array( 'admin', 'moderator', 'owner' ), true ) && 'active' === $membership['status'];
				$is_member    = $membership && 'active' === $membership['status'];
				$is_pending   = $membership && 'pending' === $membership['status'];

				$space_type    = (string) ( $space['type'] ?? 'open' );
				$space_name    = (string) ( $space['name'] ?? '' );
				$space_slug    = (string) ( $space['slug'] ?? '' );
				$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( $space_type );
				$privacy_tone  = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( $space_type );

				// Resolve the card's category label/slug from the chip-row map
				// (rows carry category_id only; no per-row join needed).
				$bn_card_cat_id   = isset( $space['category_id'] ) ? (int) $space['category_id'] : 0;
				$bn_card_cat      = $bn_card_cat_id && isset( $bn_cat_by_id[ $bn_card_cat_id ] ) ? $bn_cat_by_id[ $bn_card_cat_id ] : null;
				$bn_card_cat_name = $bn_card_cat ? (string) $bn_card_cat['name'] : '';
				$bn_card_cat_slug = $bn_card_cat ? (string) $bn_card_cat['slug'] : '';

				$cover_tone = bn_space_cover_tone( $space_id );
				$cat_icon   = bn_space_category_icon( $bn_card_cat_slug );

				$space_url    = buddynext_space_url( $space_slug );
				$member_count = number_format_i18n( (int) ( $space['member_count'] ?? 0 ) );
				?>

				<?php
				// Resolve directory-card emblem with the same fallback chain
				// used by templates/parts/space-hero.php: avatar → category
				// icon → first-letter glyph. Never leave the emblem empty.
				$bn_card_emblem = '';
				if ( ! empty( $space['avatar_url'] ) ) {
					$bn_card_emblem = sprintf(
						'<img src="%s" alt="" loading="lazy">',
						esc_url( (string) $space['avatar_url'] )
					);
				} elseif ( '' !== $bn_card_cat_slug ) {
					// Real category — render its icon. Pre-sanitised by
					// IconService::render(), pass through wp_kses with
					// the same allowlist used elsewhere.
					$bn_card_emblem = wp_kses( $cat_icon, \BuddyNext\Core\IconService::allowed_tags() );
				} else {
					$bn_card_emblem = sprintf(
						'<span class="bn-sd-card__emblem-letter">%s</span>',
						esc_html( mb_strtoupper( mb_substr( $space_name, 0, 1 ) ) )
					);
				}
				?>
				<article class="bn-card bn-sd-card" data-interactive role="listitem" aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
						<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $cover_tone ); ?>">
							<?php if ( ! empty( $space['cover_image_url'] ) ) : ?>
								<img src="<?php echo esc_url( (string) $space['cover_image_url'] ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<div class="bn-sd-card__emblem" aria-hidden="true"><?php echo $bn_card_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?></div>
						</div>
					</a>

					<div class="bn-sd-card__body">
						<a href="<?php echo esc_url( $space_url ); ?>" class="bn-sd-card__name-link">
							<h2 class="bn-sd-card__name"
								aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space_name, $privacy_label ) ); ?>"
							><?php echo esc_html( $space_name ); ?><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></h2>
						</a>

						<?php if ( '' !== $bn_card_cat_name ) : ?>
							<div class="bn-sd-card__category">
								<?php buddynext_icon( 'hash' ); ?>
								<?php echo esc_html( $bn_card_cat_name ); ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $space['description'] ) ) : ?>
							<p class="bn-sd-card__desc"><?php echo esc_html( wp_trim_words( (string) $space['description'], 18 ) ); ?></p>
						<?php endif; ?>

						<div class="bn-sd-card__stats">
							<span class="bn-sd-card__stat">
								<?php
								// translators: %s: member count.
								printf( esc_html( _n( '%s member', '%s members', (int) ( $space['member_count'] ?? 0 ), 'buddynext' ) ), esc_html( $member_count ) );
								?>
							</span>
						</div>

						<div class="bn-sd-card__foot">
							<?php if ( 0 === (int) $current_user_id ) : ?>
								<a
									href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $space_slug ) ) ); ?>"
									class="bn-btn"
									data-variant="primary"
									data-size="sm"
								><?php esc_html_e( 'Log in to join', 'buddynext' ); ?></a>

							<?php elseif ( $is_admin_mod ) : ?>
								<a
									href="<?php echo esc_url( buddynext_space_settings_url( $space_slug ) ); ?>"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
								><?php esc_html_e( 'Manage', 'buddynext' ); ?></a>

							<?php elseif ( $is_member ) : ?>
								<button
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									data-current-state="joined"
									data-wp-on--click="actions.leaveSpace"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									aria-label="<?php esc_attr_e( 'Joined — click to leave', 'buddynext' ); ?>"
								><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>

							<?php elseif ( $is_pending ) : ?>
								<button
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									data-current-state="pending"
									data-wp-on--click="actions.cancelJoinRequest"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									aria-label="<?php esc_attr_e( 'Request pending — click to cancel', 'buddynext' ); ?>"
								><?php esc_html_e( 'Requested', 'buddynext' ); ?></button>

							<?php elseif ( 'direct' === \BuddyNext\Spaces\SpaceTypeRegistry::instance()->join_method( $space_type ) ) : ?>
								<button
									class="bn-btn"
									data-variant="primary"
									data-size="sm"
									data-current-state="join"
									data-wp-on--click="actions.joinSpace"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
								><?php esc_html_e( 'Join', 'buddynext' ); ?></button>

							<?php else : ?>
								<button
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									data-current-state="request"
									data-wp-on--click="actions.requestJoin"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
								><?php esc_html_e( 'Request to join', 'buddynext' ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				</article>

			<?php endforeach; ?>

		</div>

		<?php
		buddynext_get_template(
			'parts/pagination.php',
			array(
				'current'    => $bn_paged,
				'total'      => $total_pages,
				'query_var'  => 'bn_page',
				'aria_label' => __( 'Spaces directory pages', 'buddynext' ),
			)
		);
		?>

	<?php endif; ?>

	</div><!-- /.bn-sd-results -->

	<?php if ( buddynext_can( get_current_user_id(), 'buddynext-spaces/create' ) ) : ?>
		<?php
		// Root spaces the viewer owns — the only spaces they may nest a sub-space
		// under. Empty for most users, so the modal's parent field stays hidden.
		// The modal renders objects (->id/->name), so the hydrated rows are cast.
		$bn_create_parent_spaces = array_map(
			static fn( $s ) => (object) array(
				'id'   => (int) $s['id'],
				'name' => (string) $s['name'],
			),
			$bn_space_service->owned_root_spaces( get_current_user_id() )
		);
		buddynext_get_template(
			'partials/create-space-modal.php',
			array(
				'categories'    => $categories,
				'parent_spaces' => $bn_create_parent_spaces,
			)
		);
		?>
	<?php endif; ?>

</div>
<?php
/**
 * Fires after the spaces-directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_spaces_directory_after', $current_user_id );
