<?php
/**
 * Template: Spaces Directory
 *
 * Renders the full spaces directory with search, category filtering,
 * pagination, and per-card membership state. Loaded via the BuddyNext
 * template loader — no html/body/header/footer wrappers.
 *
 * Available variables (set by template loader):
 *   none — all data is fetched here from the DB.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

// ── Helper: build avatar gradient by space id ─────────────────────────────────

/**
 * Returns a CSS gradient string for a space cover based on its id.
 *
 * @param int $space_id Space ID used to pick a colour pair.
 * @return string CSS gradient value.
 */
function bn_space_cover_gradient( int $space_id ): string {
	$palettes = array(
		array( '#dbeafe', '#bfdbfe' ),
		array( '#f3e8ff', '#e9d5ff' ),
		array( '#fef3c7', '#fde68a' ),
		array( '#dcfce7', '#bbf7d0' ),
		array( '#fce7f3', '#fbcfe8' ),
		array( '#e0e7ff', '#c7d2fe' ),
		array( '#ffedd5', '#fed7aa' ),
		array( '#f0fdf4', '#d1fae5' ),
	);
	$pair     = $palettes[ $space_id % count( $palettes ) ];
	return 'linear-gradient(135deg,' . $pair[0] . ',' . $pair[1] . ')';
}

/**
 * Returns a CSS background color for a space avatar based on its id.
 *
 * @param int $space_id Space ID used to pick a colour.
 * @return string CSS background-color value.
 */
function bn_space_avatar_bg( int $space_id ): string {
	$bgs = array(
		'#eff6ff',
		'#faf5ff',
		'#fffbeb',
		'#f0fdf4',
		'#fdf2f8',
		'#eef2ff',
		'#fff7ed',
		'#ecfdf5',
	);
	return $bgs[ $space_id % count( $bgs ) ];
}

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

$bn_nav_active = 'spaces';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div class="bn-hub-shell">

<div
	class="bn-spaces-dir"
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

	<?php if ( current_user_can( 'read' ) ) : ?>
	<div class="bn-dir-featured">
		<div class="bn-dir-featured__icon" aria-hidden="true"><?php buddynext_icon( 'home' ); ?></div>
		<div class="bn-dir-featured__text">
			<div class="bn-dir-featured__title"><?php esc_html_e( 'Find Your Community', 'buddynext' ); ?></div>
			<p class="bn-dir-featured__sub"><?php esc_html_e( 'Join spaces around topics you care about. Share ideas, ask questions, collaborate.', 'buddynext' ); ?></p>
		</div>
		<a
			href="<?php echo esc_url( buddynext_create_space_url() ); ?>"
			class="bn-btn bn-dir-featured__cta"
			data-variant="primary"
			data-size="md"
		><?php esc_html_e( 'Create a Space', 'buddynext' ); ?></a>
	</div>
	<?php endif; ?>

	<h1 class="bn-dir-heading"><?php buddynext_icon( 'home' ); ?> <?php esc_html_e( 'Spaces', 'buddynext' ); ?></h1>
	<p class="bn-dir-subheading"><?php esc_html_e( 'Browse all community spaces', 'buddynext' ); ?></p>

	<form
		method="get"
		action=""
		class="bn-dir-filters"
	>
		<label class="bn-screen-reader" for="bn-dir-search-input"><?php esc_html_e( 'Search spaces', 'buddynext' ); ?></label>
		<input
			type="text"
			id="bn-dir-search-input"
			name="bn_search"
			class="bn-input bn-dir-search"
			placeholder="<?php esc_attr_e( 'Search spaces&hellip;', 'buddynext' ); ?>"
			value="<?php echo esc_attr( $bn_search ); ?>"
		>

		<label class="bn-screen-reader" for="bn-dir-cat-select"><?php esc_html_e( 'Filter by category', 'buddynext' ); ?></label>
		<select name="bn_cat" id="bn-dir-cat-select" class="bn-select bn-dir-select">
			<option value=""><?php esc_html_e( 'All Categories', 'buddynext' ); ?></option>
			<?php foreach ( $categories as $bn_cat_item ) : ?>
				<option
					value="<?php echo esc_attr( $bn_cat_item->slug ); ?>"
					<?php selected( $bn_cat_slug, $bn_cat_item->slug ); ?>
				><?php echo esc_html( $bn_cat_item->name ); ?></option>
			<?php endforeach; ?>
		</select>

		<label class="bn-screen-reader" for="bn-dir-type-select"><?php esc_html_e( 'Filter by type', 'buddynext' ); ?></label>
		<select name="bn_type" id="bn-dir-type-select" class="bn-select bn-dir-select">
			<option value=""><?php esc_html_e( 'All Types', 'buddynext' ); ?></option>
			<option value="open" <?php selected( $bn_visibility, 'open' ); ?>><?php esc_html_e( 'Open', 'buddynext' ); ?></option>
			<option value="private" <?php selected( $bn_visibility, 'private' ); ?>><?php esc_html_e( 'Private', 'buddynext' ); ?></option>
		</select>

		<label class="bn-screen-reader" for="bn-dir-sort-select"><?php esc_html_e( 'Sort spaces', 'buddynext' ); ?></label>
		<select name="bn_sort" id="bn-dir-sort-select" class="bn-select bn-dir-select">
			<option value="popular" <?php selected( $bn_orderby, 'popular' ); ?>><?php esc_html_e( 'Sort: Popular', 'buddynext' ); ?></option>
			<option value="active" <?php selected( $bn_orderby, 'active' ); ?>><?php esc_html_e( 'Most Active', 'buddynext' ); ?></option>
			<option value="newest" <?php selected( $bn_orderby, 'newest' ); ?>><?php esc_html_e( 'Newest', 'buddynext' ); ?></option>
			<option value="alphabetical" <?php selected( $bn_orderby, 'alphabetical' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
		</select>

		<noscript><button type="submit" class="bn-btn" data-variant="primary" data-size="md"><?php esc_html_e( 'Search', 'buddynext' ); ?></button></noscript>
	</form>

	<nav class="bn-tabs bn-dir-cats" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'buddynext' ); ?>">
		<a
			href="<?php echo esc_url( remove_query_arg( 'bn_cat' ) ); ?>"
			class="bn-tab bn-dir-cat"
			role="tab"
			aria-selected="<?php echo ( '' === $bn_cat_slug ) ? 'true' : 'false'; ?>"
		><?php esc_html_e( 'All', 'buddynext' ); ?></a>

		<?php foreach ( $categories as $bn_cat_chip ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_cat', $bn_cat_chip->slug ) ); ?>"
				class="bn-tab bn-dir-cat"
				role="tab"
				aria-selected="<?php echo ( $bn_cat_chip->slug === $bn_cat_slug ) ? 'true' : 'false'; ?>"
			><?php echo wp_kses_data( bn_space_category_icon( $bn_cat_chip->slug ) ); ?> <?php echo esc_html( $bn_cat_chip->name ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="bn-dir-grid" role="list">

		<?php if ( empty( $spaces ) ) : ?>
			<div class="bn-card bn-dir-empty" role="listitem">
				<div class="bn-dir-empty__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></div>
				<p class="bn-dir-empty__title"><?php esc_html_e( 'No spaces found', 'buddynext' ); ?></p>
				<p><?php esc_html_e( 'Try adjusting your search or filters.', 'buddynext' ); ?></p>
			</div>

		<?php else : ?>

			<?php foreach ( $spaces as $space ) : ?>
				<?php
				$space_id     = (int) $space->id;
				$membership   = $membership_map[ $space_id ] ?? null;
				$is_admin_mod = $membership && in_array( $membership->role, array( 'admin', 'moderator' ), true ) && 'active' === $membership->status;
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

				$cover_bg  = bn_space_cover_gradient( $space_id );
				$avatar_bg = bn_space_avatar_bg( $space_id );
				$cat_icon  = bn_space_category_icon( $space->category_slug ?? '' );

				$space_url    = esc_url( buddynext_space_url( $space->slug ) );
				$member_count = number_format_i18n( (int) $space->member_count );
	?>

				<article class="bn-card bn-space-card" data-interactive role="listitem" aria-label="<?php echo esc_attr( $space->name ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true" class="bn-space-card__cover-link">
						<div
							class="bn-space-card__cover"
							style="background:<?php echo esc_attr( $cover_bg ); ?>;"
						>
							<div
								class="bn-avatar bn-space-card__avatar"
								data-size="lg"
								style="background:<?php echo esc_attr( $avatar_bg ); ?>;"
								aria-hidden="true"
							><?php echo wp_kses_data( $cat_icon ); ?></div>
						</div>
					</a>

					<div class="bn-space-card__body">
						<a href="<?php echo esc_url( $space_url ); ?>" class="bn-space-card__name-link">
							<h2 class="bn-space-card__name"><?php echo esc_html( $space->name ); ?></h2>
						</a>

						<?php if ( ! empty( $space->description ) ) : ?>
							<p class="bn-space-card__desc"><?php echo esc_html( wp_trim_words( $space->description, 18 ) ); ?></p>
						<?php endif; ?>

						<div class="bn-space-card__stats">
							<span><?php buddynext_icon( 'users' ); ?> <?php echo esc_html( $member_count ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
						</div>

						<div class="bn-space-card__footer">
							<span class="bn-badge bn-space-card__privacy" data-tone="<?php echo esc_attr( $privacy_tone ); ?>">
								<?php echo esc_html( $privacy_label ); ?>
							</span>

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

		<?php endif; ?>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<nav class="bn-dir-pagination" aria-label="<?php esc_attr_e( 'Spaces directory pages', 'buddynext' ); ?>">
			<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'bn_page', $p ) ); ?>"
					class="bn-dir-page-btn<?php echo ( $p === $bn_paged ) ? ' bn-dir-page-btn--active' : ''; ?>"
					aria-current="<?php echo ( $p === $bn_paged ) ? 'page' : 'false'; ?>"
				><?php echo esc_html( (string) $p ); ?></a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>

</div><!-- /.bn-spaces-dir -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
