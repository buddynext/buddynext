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

global $wpdb;

// ── Query parameters ─────────────────────────────────────────────────────────

$current_user_id = get_current_user_id();
$bn_search       = isset( $_GET['bn_search'] ) ? sanitize_text_field( wp_unslash( $_GET['bn_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_cat_slug     = isset( $_GET['bn_cat'] ) ? sanitize_key( wp_unslash( $_GET['bn_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_visibility   = isset( $_GET['bn_type'] ) ? sanitize_key( wp_unslash( $_GET['bn_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_orderby      = isset( $_GET['bn_sort'] ) ? sanitize_key( wp_unslash( $_GET['bn_sort'] ) ) : 'popular'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_paged        = isset( $_GET['bn_page'] ) ? max( 1, absint( $_GET['bn_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_per_page     = 18;
$bn_offset       = ( $bn_paged - 1 ) * $bn_per_page;
$rest_nonce      = wp_create_nonce( 'wp_rest' );

// ── Build ORDER BY ────────────────────────────────────────────────────────────

$order_map = array(
	'popular'      => 's.member_count DESC',
	'active'       => 's.member_count DESC',
	'newest'       => 's.created_at DESC',
	'alphabetical' => 's.name ASC',
);
$order_sql = isset( $order_map[ $bn_orderby ] ) ? $order_map[ $bn_orderby ] : 's.member_count DESC';

// ── Fetch categories ──────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$categories = $wpdb->get_results(
	"SELECT id, name, slug FROM {$wpdb->prefix}bn_space_categories ORDER BY name ASC"
);

// ── Build main spaces query ────────────────────────────────────────────────────

$bn_is_site_admin = current_user_can( 'manage_options' );
$bn_unlisted      = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->unlisted_keys();
$query_args       = array();

if ( $bn_unlisted && ! $bn_is_site_admin ) {
	// Hide unlisted (secret-equivalent) spaces from the directory, EXCEPT the
	// viewer's own — a space they own, or one where they hold an active
	// membership (which includes the owner row written at creation). Site
	// admins skip this exclusion entirely (clause not added). Others' secret
	// spaces stay hidden for non-members.
	$bn_unlisted_in = implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $bn_unlisted ) );
	if ( $current_user_id > 0 ) {
		$where_parts  = array(
			'( s.type NOT IN ( ' . $bn_unlisted_in . ' )'
			. ' OR s.owner_id = %d'
			. ' OR s.id IN ( SELECT space_id FROM ' . $wpdb->prefix . "bn_space_members WHERE user_id = %d AND status = 'active' ) )",
		);
		$query_args[] = $current_user_id;
		$query_args[] = $current_user_id;
	} else {
		$where_parts = array( 's.type NOT IN ( ' . $bn_unlisted_in . ' )' );
	}
} else {
	$where_parts = array( '1=1' );
}

if ( ! empty( $bn_search ) ) {
	$where_parts[] = '( s.name LIKE %s OR s.description LIKE %s )';
	$like          = '%' . $wpdb->esc_like( $bn_search ) . '%';
	$query_args[]  = $like;
	$query_args[]  = $like;
}

if ( ! empty( $bn_cat_slug ) ) {
	$where_parts[] = 'c.slug = %s';
	$query_args[]  = $bn_cat_slug;
}

if ( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_valid( $bn_visibility ) ) {
	// Unlisted (secret-equivalent) types are dropped for everyone by the default
	// exclusion above. When one is explicitly requested via the chip, restrict to
	// the viewer's own active memberships so it stays usable for invitees.
	if ( ! \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_listed( $bn_visibility ) ) {
		$where_parts = array( 's.type = %s' );
		$query_args  = array( $bn_visibility );
		// Secret-type chip: site admins see every space of this type; everyone
		// else is restricted to spaces they own or actively belong to, so
		// others' secret spaces stay hidden.
		if ( ! $bn_is_site_admin ) {
			$where_parts[] = '( s.owner_id = %d OR s.id IN ( SELECT space_id FROM ' . $wpdb->prefix . "bn_space_members WHERE user_id = %d AND status = 'active' ) )";
			$query_args[]  = $current_user_id;
			$query_args[]  = $current_user_id;
		}
		if ( ! empty( $bn_search ) ) {
			$where_parts[] = '( s.name LIKE %s OR s.description LIKE %s )';
			$like          = '%' . $wpdb->esc_like( $bn_search ) . '%';
			$query_args[]  = $like;
			$query_args[]  = $like;
		}
		if ( ! empty( $bn_cat_slug ) ) {
			$where_parts[] = 'c.slug = %s';
			$query_args[]  = $bn_cat_slug;
		}
	} else {
		$where_parts[] = 's.type = %s';
		$query_args[]  = $bn_visibility;
	}
}

// "My Spaces" scope — restrict the grid to spaces the viewer owns or actively
// belongs to. Reuses the same owner/active-membership predicate as the secret
// branch above; ignored for guests (no "mine" to scope to).
$bn_scope = isset( $_GET['bn_scope'] ) ? sanitize_key( wp_unslash( $_GET['bn_scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'mine' === $bn_scope && $current_user_id > 0 ) {
	$where_parts[] = '( s.owner_id = %d OR s.id IN ( SELECT space_id FROM ' . $wpdb->prefix . "bn_space_members WHERE user_id = %d AND status = 'active' ) )";
	$query_args[]  = $current_user_id;
	$query_args[]  = $current_user_id;
}

$where_sql = implode( ' AND ', $where_parts );

$join_sql = ! empty( $bn_cat_slug )
	? "INNER JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id"
	: "LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id";

// Count for pagination.
$count_base = "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces s {$join_sql} WHERE {$where_sql}";

if ( ! empty( $query_args ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total_spaces = (int) $wpdb->get_var( $wpdb->prepare( $count_base, $query_args ) );
} else {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total_spaces = (int) $wpdb->get_var( $count_base );
}

$total_pages = (int) ceil( $total_spaces / $bn_per_page );

// Fetch spaces.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$data_base = "SELECT s.id, s.name, s.slug, s.description, s.type, s.cover_image_url, s.avatar_url, s.member_count, s.created_at,
	c.name AS category_name, c.slug AS category_slug
	FROM {$wpdb->prefix}bn_spaces s
	{$join_sql}
	WHERE {$where_sql}
	ORDER BY {$order_sql}
	LIMIT %d OFFSET %d";
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$data_args   = array_merge( $query_args, array( $bn_per_page, $bn_offset ) );
$prepare_sql = ! empty( $data_args )
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	? $wpdb->prepare( $data_base, $data_args )
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	: $data_base;

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$spaces = $wpdb->get_results( $prepare_sql );

// ── Fetch current user membership for all returned spaces ─────────────────────

$membership_map = array();
if ( $current_user_id && ! empty( $spaces ) ) {
	$space_ids       = array_map( 'intval', wp_list_pluck( $spaces, 'id' ) );
	$id_placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
	$membership_args = array_merge( array( $current_user_id ), $space_ids );
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$memberships = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT space_id, role, status FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND space_id IN ({$id_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$membership_args
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( $memberships as $bn_m ) {
		$membership_map[ (int) $bn_m->space_id ] = $bn_m;
	}
}

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
	 * @param object $space Space row exposing ->avatar_url and ->category_slug.
	 * @return string Safe markup (escaped img, or wp_kses-sanitized SVG).
	 */
	function bn_space_side_emblem( $space ): string {
		$avatar = isset( $space->avatar_url ) ? (string) $space->avatar_url : '';
		if ( '' !== $avatar ) {
			return '<img src="' . esc_url( $avatar ) . '" alt="" width="28" height="28" loading="lazy">';
		}
		return bn_space_category_icon( isset( $space->category_slug ) ? (string) $space->category_slug : '' );
	}
}

// ── Right sidebar widgets ────────────────────────────────────────────────────
// Registered on the shared hub-shell action. The shell detects via
// has_action() after the inner buffer flushes and renders the right column.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id, $wpdb ) {
		// Categories are filtered from the primary chip row at the top of the
		// directory now (single-select scope + category), so the old sidebar
		// "Categories" card was removed to avoid two places doing the same job.

		// Card: Your spaces (members only).
		if ( $current_user_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$bn_my_spaces = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.name, s.slug, s.category_id, s.avatar_url, c.slug AS category_slug
					FROM {$wpdb->prefix}bn_spaces s
					INNER JOIN {$wpdb->prefix}bn_space_members m ON m.space_id = s.id
					LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
					WHERE m.user_id = %d AND m.status = 'active'
					ORDER BY m.joined_at DESC
					LIMIT %d",
					$current_user_id,
					6
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! empty( $bn_my_spaces ) ) {
				ob_start();
				?>
				<ul class="bn-sd-side-list">
					<?php foreach ( $bn_my_spaces as $bn_ms ) : ?>
						<li>
							<a href="<?php echo esc_url( buddynext_space_url( $bn_ms->slug ) ); ?>" class="bn-sd-side-row">
								<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo bn_space_side_emblem( $bn_ms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns wp_kses()-sanitized SVG. ?></span>
								<span><?php echo esc_html( $bn_ms->name ); ?></span>
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bn_featured = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.name, s.slug, s.member_count, s.avatar_url, c.slug AS category_slug
				FROM {$wpdb->prefix}bn_spaces s
				LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
				WHERE s.type = 'open'
				ORDER BY s.member_count DESC
				LIMIT %d",
				5
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $bn_featured ) ) {
			ob_start();
			?>
			<ul class="bn-sd-side-list">
				<?php foreach ( $bn_featured as $bn_f ) : ?>
					<li>
						<a href="<?php echo esc_url( buddynext_space_url( $bn_f->slug ) ); ?>" class="bn-sd-side-row">
							<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo bn_space_side_emblem( $bn_f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns wp_kses()-sanitized SVG. ?></span>
							<span class="bn-sd-side-row__main">
								<span><?php echo esc_html( $bn_f->name ); ?></span>
								<span class="bn-sd-side-row__meta"><?php echo esc_html( number_format_i18n( (int) $bn_f->member_count ) ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
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
	<?php if ( empty( $spaces ) && ! $bn_filters_active ) : ?>

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

	<?php elseif ( empty( $spaces ) ) : ?>

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

			<?php foreach ( $spaces as $space ) : ?>
				<?php
				$space_id     = (int) $space->id;
				$membership   = $membership_map[ $space_id ] ?? null;
				$is_admin_mod = $membership && in_array( $membership->role, array( 'admin', 'moderator', 'owner' ), true ) && 'active' === $membership->status;
				$is_member    = $membership && 'active' === $membership->status;
				$is_pending   = $membership && 'pending' === $membership->status;

				$privacy_label = \BuddyNext\Spaces\SpaceService::type_label( (string) $space->type );
				$privacy_tone  = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( (string) $space->type );

				$cover_tone = bn_space_cover_tone( $space_id );
				$cat_icon   = bn_space_category_icon( $space->category_slug ?? '' );

				$space_url    = buddynext_space_url( $space->slug );
				$member_count = number_format_i18n( (int) $space->member_count );
				?>

				<?php
				// Resolve directory-card emblem with the same fallback chain
				// used by templates/parts/space-hero.php: avatar → category
				// icon → first-letter glyph. Never leave the emblem empty.
				$bn_card_emblem = '';
				if ( ! empty( $space->avatar_url ) ) {
					$bn_card_emblem = sprintf(
						'<img src="%s" alt="" loading="lazy">',
						esc_url( $space->avatar_url )
					);
				} elseif ( ! empty( $space->category_slug ) ) {
					// Real category — render its icon. Pre-sanitised by
					// IconService::render(), pass through wp_kses with
					// the same allowlist used elsewhere.
					$bn_card_emblem = wp_kses( $cat_icon, \BuddyNext\Core\IconService::allowed_tags() );
				} else {
					$bn_card_emblem = sprintf(
						'<span class="bn-sd-card__emblem-letter">%s</span>',
						esc_html( mb_strtoupper( mb_substr( (string) $space->name, 0, 1 ) ) )
					);
				}
				?>
				<article class="bn-card bn-sd-card" data-interactive role="listitem" aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space->name, $privacy_label ) ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
						<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $cover_tone ); ?>">
							<?php if ( ! empty( $space->cover_image_url ) ) : ?>
								<img src="<?php echo esc_url( $space->cover_image_url ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<div class="bn-sd-card__emblem" aria-hidden="true"><?php echo $bn_card_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?></div>
						</div>
					</a>

					<div class="bn-sd-card__body">
						<a href="<?php echo esc_url( $space_url ); ?>" class="bn-sd-card__name-link">
							<h2 class="bn-sd-card__name"
								aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $space->name, $privacy_label ) ); ?>"
							><?php echo esc_html( $space->name ); ?><span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span></h2>
						</a>

						<?php if ( ! empty( $space->category_name ) ) : ?>
							<div class="bn-sd-card__category">
								<?php buddynext_icon( 'hash' ); ?>
								<?php echo esc_html( $space->category_name ); ?>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $space->description ) ) : ?>
							<p class="bn-sd-card__desc"><?php echo esc_html( wp_trim_words( $space->description, 18 ) ); ?></p>
						<?php endif; ?>

						<div class="bn-sd-card__stats">
							<span class="bn-sd-card__stat">
								<?php
								// translators: %s: member count.
								printf( esc_html__( '%s members', 'buddynext' ), esc_html( $member_count ) );
								?>
							</span>
						</div>

						<div class="bn-sd-card__foot">
							<?php if ( 0 === (int) $current_user_id ) : ?>
								<a
									href="<?php echo esc_url( \BuddyNext\Core\PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $space->slug ) ) ); ?>"
									class="bn-btn"
									data-variant="primary"
									data-size="sm"
								><?php esc_html_e( 'Log in to join', 'buddynext' ); ?></a>

							<?php elseif ( $is_admin_mod ) : ?>
								<a
									href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ) ); ?>"
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
									data-variant="ghost"
									data-size="sm"
									data-current-state="pending"
									data-wp-on--click="actions.cancelJoinRequest"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									aria-label="<?php esc_attr_e( 'Request pending — click to cancel', 'buddynext' ); ?>"
								><?php esc_html_e( 'Requested', 'buddynext' ); ?></button>

							<?php elseif ( 'direct' === \BuddyNext\Spaces\SpaceTypeRegistry::instance()->join_method( (string) $space->type ) ) : ?>
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
		global $wpdb;
		// Root spaces the viewer owns — the only spaces they may nest a sub-space
		// under. Empty for most users, so the modal's parent field stays hidden.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bn_create_parent_spaces = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name FROM {$wpdb->prefix}bn_spaces WHERE owner_id = %d AND parent_id IS NULL ORDER BY name ASC LIMIT 100",
				get_current_user_id()
			)
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
