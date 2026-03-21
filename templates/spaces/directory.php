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

if ( in_array( $bn_visibility, array( 'public', 'private' ), true ) ) {
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
 * Returns an emoji icon for a space category slug.
 *
 * @param string|null $cat_slug Category slug.
 * @return string Emoji character.
 */
function bn_space_category_icon( ?string $cat_slug ): string {
	$map = array(
		'technology'  => '&#x1F4BB;',
		'design'      => '&#x1F3A8;',
		'marketing'   => '&#x1F4E3;',
		'startups'    => '&#x1F680;',
		'ai-ml'       => '&#x1F916;',
		'data'        => '&#x1F4CA;',
		'product'     => '&#x1F3AF;',
		'writing'     => '&#x1F4DD;',
		'open-source' => '&#x1F30D;',
		'business'    => '&#x1F4BC;',
		'creative'    => '&#x1F3A4;',
	);
	return $map[ (string) $cat_slug ] ?? '&#x1F3D8;';
}

?>
<style>
<?php /* phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline CSS block, no user data */ ?>
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs: 11px; --text-sm: 13px; --text-base: 15px;
	--text-lg: 17px; --text-xl: 20px; --text-2xl: 24px;
	--leading-body: 1.7;
	--bg: #ffffff; --bg-subtle: #f8f8f7; --bg-hover: #f1f1f0;
	--surface: #ffffff; --border: #e8e8e5; --border-soft: #f1f1ee;
	--text-1: #37352f; --text-2: #787774; --text-3: #aeaca8;
	--brand: #0073aa; --brand-light: #e8f4fb; --brand-hover: #005f8e;
	--green: #059669; --green-bg: #ecfdf5;
	--amber: #d97706; --amber-bg: #fffbeb;
	--red: #dc2626; --red-bg: #fef2f2;
	--s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 20px;
	--s6: 24px; --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg: #191919; --bg-subtle: #202020; --bg-hover: #2a2a2a;
	--surface: #252525; --border: #333330; --border-soft: #2c2c2a;
	--text-1: #e8e8e6; --text-2: #9b9b97; --text-3: #6b6b67;
	--brand: #4dabdb; --brand-light: #1a2e3a; --brand-hover: #5fbfe8;
}

.bn-spaces-dir {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
	min-height: 100vh;
	padding: var(--s6) var(--s5);
}

/* Featured banner */
.bn-dir-featured {
	background: linear-gradient(135deg, var(--brand), #7c3aed);
	border-radius: var(--radius-lg);
	padding: var(--s6);
	margin-bottom: var(--s6);
	color: #fff;
	display: flex;
	align-items: center;
	gap: var(--s5);
	flex-wrap: wrap;
}
.bn-dir-featured__icon {
	font-size: 48px;
	flex-shrink: 0;
}
.bn-dir-featured__text {
	flex: 1;
	min-width: 200px;
}
.bn-dir-featured__title {
	font-family: var(--font-display);
	font-size: var(--text-xl);
	font-weight: 800;
	margin-bottom: var(--s1);
}
.bn-dir-featured__sub {
	font-size: var(--text-sm);
	opacity: 0.85;
}
.bn-dir-featured__cta {
	background: #fff;
	color: var(--brand);
	border: none;
	padding: 9px 20px;
	border-radius: 20px;
	font-size: var(--text-sm);
	font-weight: 700;
	cursor: pointer;
	white-space: nowrap;
	margin-left: auto;
	text-decoration: none;
	display: inline-block;
}
.bn-dir-featured__cta:hover {
	background: #f0f9ff;
}

/* Page heading */
.bn-dir-heading {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	margin-bottom: var(--s1);
}
.bn-dir-subheading {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s5);
}

/* Filter row */
.bn-dir-filters {
	display: flex;
	gap: var(--s2);
	align-items: center;
	margin-bottom: var(--s5);
	flex-wrap: wrap;
}
.bn-dir-search {
	flex: 1;
	min-width: 240px;
	border: 1.5px solid var(--border);
	border-radius: var(--radius);
	padding: 9px var(--s3);
	font-size: var(--text-sm);
	background: var(--surface);
	color: var(--text-1);
	font-family: var(--font-body);
}
.bn-dir-search:focus {
	outline: none;
	border-color: var(--brand);
	box-shadow: 0 0 0 3px var(--brand-light);
}
.bn-dir-select {
	border: 1.5px solid var(--border);
	border-radius: var(--radius);
	padding: 9px var(--s3);
	font-size: var(--text-sm);
	background: var(--surface);
	color: var(--text-1);
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-dir-select:focus {
	outline: none;
	border-color: var(--brand);
}

/* Category chips */
.bn-dir-cats {
	display: flex;
	gap: var(--s1);
	flex-wrap: wrap;
	margin-bottom: var(--s5);
}
.bn-dir-cat {
	padding: 5px 14px;
	border-radius: 16px;
	border: 1.5px solid var(--border);
	background: var(--surface);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	color: var(--text-1);
	text-decoration: none;
	transition: border-color 0.15s, background 0.15s, color 0.15s;
}
.bn-dir-cat:hover:not(.bn-dir-cat--active) {
	border-color: var(--brand);
	color: var(--brand);
}
.bn-dir-cat--active {
	background: var(--brand);
	border-color: var(--brand);
	color: #fff;
}

/* Spaces grid */
.bn-dir-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: var(--s4);
}

/* Space card */
.bn-space-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	overflow: hidden;
	cursor: pointer;
	transition: border-color 0.15s, box-shadow 0.15s;
	display: flex;
	flex-direction: column;
}
.bn-space-card:hover {
	border-color: var(--brand);
	box-shadow: 0 4px 12px rgba(0, 115, 170, 0.1);
}
.bn-space-card__cover {
	height: 80px;
	position: relative;
	display: flex;
	align-items: flex-end;
}
.bn-space-card__avatar {
	width: 56px;
	height: 56px;
	border-radius: var(--radius);
	border: 3px solid var(--surface);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 24px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
	position: absolute;
	bottom: -28px;
	left: var(--s4);
	z-index: 1;
}
.bn-space-card__body {
	padding: 36px var(--s4) var(--s4);
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: var(--s2);
}
.bn-space-card__name {
	font-family: var(--font-display);
	font-weight: 700;
	font-size: var(--text-base);
	color: var(--text-1);
}
.bn-space-card__desc {
	font-size: var(--text-xs);
	color: var(--text-2);
	line-height: 1.5;
}
.bn-space-card__tags {
	display: flex;
	gap: var(--s1);
	flex-wrap: wrap;
}
.bn-space-card__tag {
	background: var(--bg-subtle);
	color: var(--text-2);
	padding: 2px 8px;
	border-radius: var(--radius-sm);
	font-size: 10px;
}
.bn-space-card__stats {
	display: flex;
	gap: var(--s3);
	font-size: var(--text-xs);
	color: var(--text-3);
}
.bn-space-card__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: auto;
}
.bn-space-card__privacy {
	font-size: 10px;
	color: var(--text-3);
}

/* Buttons */
.bn-btn-join {
	background: var(--brand);
	color: #fff;
	padding: 6px 16px;
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-join:hover { background: var(--brand-hover); }

.bn-btn-joined {
	background: var(--bg-subtle);
	color: var(--text-2);
	padding: 6px 16px;
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: none;
	font-family: var(--font-body);
}

.bn-btn-request {
	background: var(--surface);
	color: var(--brand);
	padding: 6px 16px;
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid var(--brand);
	font-family: var(--font-body);
	transition: background 0.15s;
}
.bn-btn-request:hover { background: var(--brand-light); }

.bn-btn-pending {
	background: var(--amber-bg);
	color: var(--amber);
	padding: 6px 16px;
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid var(--amber);
	font-family: var(--font-body);
}

.bn-btn-manage {
	background: var(--brand-light);
	color: var(--brand);
	padding: 6px 16px;
	border-radius: 14px;
	font-size: var(--text-xs);
	font-weight: 700;
	cursor: pointer;
	border: 1.5px solid var(--brand);
	font-family: var(--font-body);
	text-decoration: none;
}

/* Empty state */
.bn-dir-empty {
	grid-column: 1 / -1;
	text-align: center;
	padding: var(--s8) var(--s4);
	color: var(--text-2);
}
.bn-dir-empty__icon { font-size: 40px; margin-bottom: var(--s3); }
.bn-dir-empty__title {
	font-size: var(--text-lg);
	font-weight: 700;
	color: var(--text-1);
	margin-bottom: var(--s2);
}

/* Pagination */
.bn-dir-pagination {
	display: flex;
	gap: var(--s2);
	justify-content: center;
	margin-top: var(--s6);
	flex-wrap: wrap;
}
.bn-dir-page-btn {
	padding: 7px 14px;
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	background: var(--surface);
	color: var(--text-1);
	font-size: var(--text-sm);
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	transition: border-color 0.15s;
}
.bn-dir-page-btn:hover { border-color: var(--brand); color: var(--brand); }
.bn-dir-page-btn--active {
	background: var(--brand);
	border-color: var(--brand);
	color: #fff;
}

/* Responsive */
@media (max-width: 1024px) {
	.bn-dir-grid { grid-template-columns: repeat(2, 1fr); }
	.bn-dir-featured { flex-direction: column; align-items: flex-start; }
	.bn-dir-featured__cta { margin-left: 0; }
}
@media (max-width: 640px) {
	.bn-spaces-dir { padding: var(--s4) var(--s3); }
	.bn-dir-grid { grid-template-columns: 1fr; }
	.bn-dir-filters { flex-direction: column; }
	.bn-dir-search { min-width: 0; width: 100%; }
	.bn-dir-select { width: 100%; }
	.bn-dir-featured { padding: var(--s4); gap: var(--s3); }
	.bn-dir-featured__icon { font-size: 36px; }
}

[data-theme="dark"] .bn-dir-featured__cta:hover {
	background: #e8f4fb;
}
[data-theme="dark"] .bn-space-card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}
[data-theme="dark"] .bn-space-card__avatar {
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
}
[data-theme="dark"] .bn-btn-pending {
	background: var(--amber-bg);
	color: var(--amber);
	border-color: var(--amber);
}
<?php /* phpcs:enable */ ?>
</style>

<div
	class="bn-spaces-dir"
	data-wp-interactive="buddynext/spaces"
>

	<?php if ( current_user_can( 'read' ) ) : ?>
	<div class="bn-dir-featured">
		<div class="bn-dir-featured__icon">&#x1F3D8;</div>
		<div class="bn-dir-featured__text">
			<div class="bn-dir-featured__title"><?php esc_html_e( 'Find Your Community', 'buddynext' ); ?></div>
			<p class="bn-dir-featured__sub"><?php esc_html_e( 'Join spaces around topics you care about. Share ideas, ask questions, collaborate.', 'buddynext' ); ?></p>
		</div>
		<a
			href="<?php echo esc_url( buddynext_create_space_url() ); ?>"
			class="bn-dir-featured__cta"
		><?php esc_html_e( 'Create a Space', 'buddynext' ); ?></a>
	</div>
	<?php endif; ?>

	<h1 class="bn-dir-heading">&#x1F3D8; <?php esc_html_e( 'Spaces', 'buddynext' ); ?></h1>
	<p class="bn-dir-subheading"><?php esc_html_e( 'Browse all community spaces', 'buddynext' ); ?></p>

	<form
		method="get"
		action=""
		class="bn-dir-filters"
	>
		<input
			type="text"
			name="bn_search"
			class="bn-dir-search"
			placeholder="<?php esc_attr_e( 'Search spaces&hellip;', 'buddynext' ); ?>"
			value="<?php echo esc_attr( $bn_search ); ?>"
		>

		<select name="bn_cat" class="bn-dir-select">
			<option value=""><?php esc_html_e( 'All Categories', 'buddynext' ); ?></option>
			<?php foreach ( $categories as $bn_cat_item ) : ?>
				<option
					value="<?php echo esc_attr( $bn_cat_item->slug ); ?>"
					<?php selected( $bn_cat_slug, $bn_cat_item->slug ); ?>
				><?php echo esc_html( $bn_cat_item->name ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="bn_type" class="bn-dir-select">
			<option value=""><?php esc_html_e( 'All Types', 'buddynext' ); ?></option>
			<option value="public" <?php selected( $bn_visibility, 'public' ); ?>><?php esc_html_e( 'Public', 'buddynext' ); ?></option>
			<option value="private" <?php selected( $bn_visibility, 'private' ); ?>><?php esc_html_e( 'Private', 'buddynext' ); ?></option>
		</select>

		<select name="bn_sort" class="bn-dir-select">
			<option value="popular" <?php selected( $bn_orderby, 'popular' ); ?>><?php esc_html_e( 'Sort: Popular', 'buddynext' ); ?></option>
			<option value="active" <?php selected( $bn_orderby, 'active' ); ?>><?php esc_html_e( 'Most Active', 'buddynext' ); ?></option>
			<option value="newest" <?php selected( $bn_orderby, 'newest' ); ?>><?php esc_html_e( 'Newest', 'buddynext' ); ?></option>
			<option value="alphabetical" <?php selected( $bn_orderby, 'alphabetical' ); ?>><?php esc_html_e( 'Alphabetical', 'buddynext' ); ?></option>
		</select>

		<noscript><button type="submit"><?php esc_html_e( 'Search', 'buddynext' ); ?></button></noscript>
	</form>

	<nav class="bn-dir-cats" aria-label="<?php esc_attr_e( 'Filter by category', 'buddynext' ); ?>">
		<a
			href="<?php echo esc_url( remove_query_arg( 'bn_cat' ) ); ?>"
			class="bn-dir-cat<?php echo ( '' === $bn_cat_slug ) ? ' bn-dir-cat--active' : ''; ?>"
		><?php esc_html_e( 'All', 'buddynext' ); ?></a>

		<?php foreach ( $categories as $bn_cat_chip ) : ?>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_cat', $bn_cat_chip->slug ) ); ?>"
				class="bn-dir-cat<?php echo ( $bn_cat_chip->slug === $bn_cat_slug ) ? ' bn-dir-cat--active' : ''; ?>"
			><?php echo wp_kses_data( bn_space_category_icon( $bn_cat_chip->slug ) ); ?> <?php echo esc_html( $bn_cat_chip->name ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="bn-dir-grid" role="list">

		<?php if ( empty( $spaces ) ) : ?>
			<div class="bn-dir-empty" role="listitem">
				<div class="bn-dir-empty__icon">&#x1F50D;</div>
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
					'public'  => __( 'Public', 'buddynext' ),
					'private' => __( 'Private', 'buddynext' ),
					default   => __( 'Invite-only', 'buddynext' ),
				};
				$privacy_icon = match ( $space->type ) {
					'public'  => '&#x1F310;',
					'private' => '&#x1F512;',
					default   => '&#x1F4E7;',
				};

				$cover_bg  = bn_space_cover_gradient( $space_id );
				$avatar_bg = bn_space_avatar_bg( $space_id );
				$cat_icon  = bn_space_category_icon( $space->category_slug ?? '' );

				$space_url    = esc_url( buddynext_space_url( $space->slug ) );
				$member_count = number_format_i18n( (int) $space->member_count );
	?>

				<article class="bn-space-card" role="listitem" aria-label="<?php echo esc_attr( $space->name ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" tabindex="-1" aria-hidden="true">
						<div
							class="bn-space-card__cover"
							style="background:<?php echo esc_attr( $cover_bg ); ?>;"
						>
							<div
								class="bn-space-card__avatar"
								style="background:<?php echo esc_attr( $avatar_bg ); ?>;"
								aria-hidden="true"
							><?php echo wp_kses_data( $cat_icon ); ?></div>
						</div>
					</a>

					<div class="bn-space-card__body">
						<a href="<?php echo esc_url( $space_url ); ?>" style="text-decoration:none;">
							<h2 class="bn-space-card__name"><?php echo esc_html( $space->name ); ?></h2>
						</a>

						<?php if ( ! empty( $space->description ) ) : ?>
							<p class="bn-space-card__desc"><?php echo esc_html( wp_trim_words( $space->description, 18 ) ); ?></p>
						<?php endif; ?>

						<div class="bn-space-card__stats">
							<span>&#x1F465; <?php echo esc_html( $member_count ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
						</div>

						<div class="bn-space-card__footer">
							<span class="bn-space-card__privacy">
								<?php echo wp_kses_data( $privacy_icon ); ?> <?php echo esc_html( $privacy_label ); ?>
							</span>

							<?php if ( $is_admin_mod ) : ?>
								<a
									href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ) ); ?>"
									class="bn-btn-manage"
								><?php esc_html_e( 'Manage', 'buddynext' ); ?></a>

							<?php elseif ( $is_member ) : ?>
								<button
									class="bn-btn-joined"
									data-wp-on--click="actions.leaveSpace"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									aria-label="<?php esc_attr_e( 'Joined — click to leave', 'buddynext' ); ?>"
								>&#x2713; <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>

							<?php elseif ( $is_pending ) : ?>
								<button
									class="bn-btn-pending"
									data-wp-on--click="actions.cancelJoinRequest"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
									aria-label="<?php esc_attr_e( 'Request pending — click to cancel', 'buddynext' ); ?>"
								><?php esc_html_e( 'Requested', 'buddynext' ); ?></button>

							<?php elseif ( 'public' === $space->type ) : ?>
								<button
									class="bn-btn-join"
									data-wp-on--click="actions.joinSpace"
									data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
								><?php esc_html_e( 'Join', 'buddynext' ); ?></button>

							<?php else : ?>
								<button
									class="bn-btn-request"
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

</div>
