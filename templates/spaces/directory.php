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

$where_parts = array( "s.type != 'secret'" );
$query_args  = array();

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

if ( in_array( $bn_visibility, array( 'open', 'private' ), true ) ) {
	$where_parts[] = 's.type = %s';
	$query_args[]  = $bn_visibility;
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
$data_base = "SELECT s.id, s.name, s.slug, s.description, s.type, s.cover_image_url, s.member_count, s.created_at,
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
		$tones = array( 'sky', 'violet', 'emerald', 'amber', 'rose', 'indigo' );
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

// ── Right sidebar widgets ────────────────────────────────────────────────────
// Registered on the shared hub-shell action. The shell detects via
// has_action() after the inner buffer flushes and renders the right column.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $categories, $bn_cat_slug, $current_user_id, $wpdb ) {
		// Card 1: Categories.
		ob_start();
		?>
		<ul class="bn-sd-side-list">
			<li>
				<a href="<?php echo esc_url( remove_query_arg( 'bn_cat' ) ); ?>"
					class="bn-sd-side-row<?php echo ( '' === $bn_cat_slug ) ? ' is-active' : ''; ?>">
					<span><?php esc_html_e( 'All categories', 'buddynext' ); ?></span>
				</a>
			</li>
			<?php foreach ( $categories as $bn_cat_item ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'bn_cat', $bn_cat_item->slug ) ); ?>"
						class="bn-sd-side-row<?php echo ( $bn_cat_item->slug === $bn_cat_slug ) ? ' is-active' : ''; ?>">
						<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $bn_cat_item->slug ) ); ?></span>
						<span><?php echo esc_html( $bn_cat_item->name ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		$bn_cats_html = (string) ob_get_clean();

		buddynext_get_template(
			'parts/sidebar-card.php',
			array(
				'id'         => 'spaces-categories',
				'title'      => __( 'Categories', 'buddynext' ),
				'title_icon' => 'hash',
				'body_html'  => $bn_cats_html,
			)
		);

		// Card 2: Your spaces (members only).
		if ( $current_user_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$bn_my_spaces = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.name, s.slug, s.category_id, c.slug AS category_slug
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
								<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $bn_ms->category_slug ?? '' ) ); ?></span>
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
				"SELECT s.id, s.name, s.slug, s.member_count, c.slug AS category_slug
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
							<span class="bn-sd-side-row__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $bn_f->category_slug ?? '' ) ); ?></span>
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

// Build filter-strip args.
$bn_cat_options = array( '' => __( 'All categories', 'buddynext' ) );
foreach ( $categories as $bn_cat_opt ) {
	$bn_cat_options[ $bn_cat_opt->slug ] = $bn_cat_opt->name;
}

$bn_type_options = array(
	''        => __( 'All types', 'buddynext' ),
	'open'    => __( 'Public', 'buddynext' ),
	'private' => __( 'Private', 'buddynext' ),
);

$bn_sort_options = array(
	'popular'      => __( 'Sort: Popular', 'buddynext' ),
	'active'       => __( 'Most active', 'buddynext' ),
	'newest'       => __( 'Newest', 'buddynext' ),
	'alphabetical' => __( 'A → Z', 'buddynext' ),
);

// Section-head actions slot — Create-space CTA (logged-in only).
$bn_actions_html = '';
if ( current_user_can( 'read' ) ) {
	$bn_actions_html = sprintf(
		'<a href="%s" class="bn-btn" data-variant="primary" data-size="md">%s</a>',
		esc_url( buddynext_create_space_url() ),
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
			'search'  => array(
				'name'        => 'bn_search',
				'value'       => $bn_search,
				'placeholder' => __( 'Search spaces…', 'buddynext' ),
				'aria_label'  => __( 'Search spaces', 'buddynext' ),
			),
			'selects' => array(
				array(
					'name'       => 'bn_cat',
					'value'      => $bn_cat_slug,
					'options'    => $bn_cat_options,
					'aria_label' => __( 'Filter by category', 'buddynext' ),
				),
				array(
					'name'       => 'bn_type',
					'value'      => $bn_visibility,
					'options'    => $bn_type_options,
					'aria_label' => __( 'Filter by type', 'buddynext' ),
				),
				array(
					'name'       => 'bn_sort',
					'value'      => $bn_orderby,
					'options'    => $bn_sort_options,
					'aria_label' => __( 'Sort spaces', 'buddynext' ),
				),
			),
		)
	);
	?>

	<nav class="bn-tabs bn-sd-chips" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'buddynext' ); ?>">
		<a
			href="<?php echo esc_url( remove_query_arg( 'bn_cat' ) ); ?>"
			class="bn-tab bn-sd-chip"
			role="tab"
			aria-selected="<?php echo ( '' === $bn_cat_slug ) ? 'true' : 'false'; ?>"
		><?php esc_html_e( 'All', 'buddynext' ); ?></a>

		<?php foreach ( $categories as $bn_cat_chip ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_cat', $bn_cat_chip->slug ) ); ?>"
				class="bn-tab bn-sd-chip"
				role="tab"
				aria-selected="<?php echo ( $bn_cat_chip->slug === $bn_cat_slug ) ? 'true' : 'false'; ?>"
			><span class="bn-sd-chip__icon" aria-hidden="true"><?php echo wp_kses_data( bn_space_category_icon( $bn_cat_chip->slug ) ); ?></span> <?php echo esc_html( $bn_cat_chip->name ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( empty( $spaces ) ) : ?>

		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'search',
				'title' => __( 'No spaces found', 'buddynext' ),
				'body'  => __( 'Try adjusting your search or filters.', 'buddynext' ),
			)
		);
		?>

	<?php else : ?>

		<div class="bn-sd-grid" role="list">

			<?php foreach ( $spaces as $space ) : ?>
				<?php
				$space_id     = (int) $space->id;
				$membership   = $membership_map[ $space_id ] ?? null;
				$is_admin_mod = $membership && in_array( $membership->role, array( 'admin', 'moderator', 'owner' ), true ) && 'active' === $membership->status;
				$is_member    = $membership && 'active' === $membership->status;
				$is_pending   = $membership && 'pending' === $membership->status;

				$privacy_label = match ( $space->type ) {
					'open'    => __( 'Public', 'buddynext' ),
					'private' => __( 'Private', 'buddynext' ),
					default   => __( 'Invite-only', 'buddynext' ),
				};
				$privacy_tone = match ( $space->type ) {
					'open'    => 'info',
					'private' => 'warn',
					default   => 'danger',
				};

				$cover_tone = bn_space_cover_tone( $space_id );
				$cat_icon   = bn_space_category_icon( $space->category_slug ?? '' );

				$space_url    = buddynext_space_url( $space->slug );
				$member_count = number_format_i18n( (int) $space->member_count );
	?>

				<article class="bn-card bn-sd-card" data-interactive role="listitem" aria-label="<?php echo esc_attr( $space->name ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-sd-card__cover-link">
						<div class="bn-sd-card__cover" data-tone="<?php echo esc_attr( $cover_tone ); ?>">
							<?php if ( ! empty( $space->cover_image_url ) ) : ?>
								<img src="<?php echo esc_url( $space->cover_image_url ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<div class="bn-sd-card__emblem" aria-hidden="true"><?php echo wp_kses_data( $cat_icon ); ?></div>
						</div>
					</a>

					<div class="bn-sd-card__body">
						<a href="<?php echo esc_url( $space_url ); ?>" class="bn-sd-card__name-link">
							<h2 class="bn-sd-card__name">
								<?php echo esc_html( $space->name ); ?>
								<span class="bn-badge" data-tone="<?php echo esc_attr( $privacy_tone ); ?>"><?php echo esc_html( $privacy_label ); ?></span>
							</h2>
						</a>

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
							<?php if ( $is_admin_mod ) : ?>
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

							<?php elseif ( 'open' === $space->type ) : ?>
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

</div>
<?php
/**
 * Fires after the spaces-directory inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_spaces_directory_after', $current_user_id );
