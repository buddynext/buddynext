<?php
/**
 * Gamification leaderboard template.
 *
 * Requires WBGamification to be active. If the plugin is absent, renders a
 * friendly notice instead of an empty page.
 *
 * When WBGamification is active, fetches ranked user data from its tables and
 * renders a three-part layout:
 *  - Podium (top 3)
 *  - Ranked table (positions 4–10)
 *  - Current user's rank row with progress toward next milestone
 *  - Right sidebar: Your Badges, Points Breakdown, Next Milestone
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Guard: WBGamification must be active.
if ( ! class_exists( 'WBGamification\Plugin' ) ) {
	?>
	<style>
	:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
}.bn-lb-shell { max-width: 780px; margin: 0 auto; padding: var(--s8) var(--s5); font-family: var(--font-body); }
	.bn-lb-notice {
		background: var(--amber-bg);
		border: 1px solid var(--amber);
		border-radius: var(--radius-lg);
		padding: var(--s6);
		text-align: center;
		color: var(--text-1);
	}
	.bn-lb-notice-icon { font-size: 40px; display: block; margin-bottom: var(--s3); }
	.bn-lb-notice h2 { font-size: var(--text-2xl); font-weight: 800; margin-bottom: var(--s3); }
	.bn-lb-notice p { font-size: var(--text-sm); color: var(--text-2); line-height: 1.6; }
	</style>
	<div class="bn-lb-shell">
		<div class="bn-lb-notice">
			<span class="bn-lb-notice-icon" aria-hidden="true"><?php buddynext_icon( 'award' ); ?></span>
			<h2><?php esc_html_e( 'Leaderboard', 'buddynext' ); ?></h2>
			<p>
				<?php esc_html_e( 'The leaderboard requires the WBGamification plugin to be active. Install and activate WBGamification to start earning points and see where you rank in the community.', 'buddynext' ); ?>
			</p>
		</div>
	</div>
	<?php
	return;
}

$current_user_id = get_current_user_id();

// Period tab — week | month | alltime.
$allowed_periods = array( 'week', 'month', 'alltime' );
$period          = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $period, $allowed_periods, true ) ) {
	$period = 'month';
}

// Category tab — contributors | connectors | rising.
$allowed_cats = array( 'contributors', 'connectors', 'rising' );
$category     = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : 'contributors'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $category, $allowed_cats, true ) ) {
	$category = 'contributors';
}

// Resolve WBGamification table names via its class constants if available.
$wbg_points_table = $wpdb->prefix . 'wbg_user_points';
$wbg_badges_table = $wpdb->prefix . 'wbg_user_badges';
$wbg_badge_defs   = $wpdb->prefix . 'wbg_badges';

// Build date boundary for the selected period.
$period_sql = '';
switch ( $period ) {
	case 'week':
		$period_sql = ' AND p.awarded_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )';
		break;
	case 'month':
		$period_sql = ' AND p.awarded_at >= DATE_SUB( NOW(), INTERVAL 1 MONTH )';
		break;
	// alltime: no boundary.
}

// Category-based event_type filter for points.
$cat_sql = '';
if ( 'connectors' === $category ) {
	$cat_sql = " AND p.event_type IN ( 'bn_followed', 'bn_connected' )";
} elseif ( 'rising' === $category ) {
	// Rising stars: only count points earned in the past 7 days regardless of period.
	$cat_sql    = ' AND p.awarded_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )';
	$period_sql = '';
}

// All queries below interpolate WBGamification table names (trusted, plugin-controlled)
// and literal SQL clauses ($period_sql, $cat_sql — no user data).
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching

// Fetch top 10 ranked users from WBGamification points table.
$leaderboard = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	"SELECT p.user_id, SUM( p.points ) AS total_points
	 FROM {$wbg_points_table} AS p
	 WHERE 1=1
	 {$period_sql}
	 {$cat_sql}
	 GROUP BY p.user_id
	 ORDER BY total_points DESC
	 LIMIT 10"
) ?? array();

// Fetch current user's rank.
$current_user_rank = 0;
$current_user_pts  = 0;
if ( $current_user_id ) {
	$user_pts_row     = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT SUM( points ) AS total FROM {$wbg_points_table} WHERE user_id = %d {$period_sql} {$cat_sql}",
			$current_user_id
		)
	);
	$current_user_pts = (int) ( $user_pts_row->total ?? 0 );

	$rank_result       = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT COUNT(*) + 1
			 FROM (
				SELECT user_id, SUM( points ) AS pts
				FROM {$wbg_points_table}
				WHERE 1=1 {$period_sql} {$cat_sql}
				GROUP BY user_id
			 ) AS ranked
			 WHERE ranked.pts > %d",
			$current_user_pts
		)
	);
	$current_user_rank = (int) $rank_result;
}

// Fetch current user's badges.
$user_badges = array();
if ( $current_user_id ) {
	$user_badges = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT b.name, b.image_url, b.id AS badge_id
			 FROM {$wbg_badges_table} AS ub
			 INNER JOIN {$wbg_badge_defs} AS b ON b.id = ub.badge_id
			 WHERE ub.user_id = %d
			 LIMIT 8",
			$current_user_id
		)
	) ?? array();
}

// Fetch current user's points breakdown by event_type.
$points_breakdown = array();
if ( $current_user_id ) {
	$points_breakdown = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT event_type, SUM( points ) AS pts
			 FROM {$wbg_points_table}
			 WHERE user_id = %d {$period_sql}
			 GROUP BY event_type
			 ORDER BY pts DESC
			 LIMIT 8",
			$current_user_id
		)
	) ?? array();
}

// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching

// Map event_type to human-readable labels and colours.
$event_labels = array(
	'bn_post_created' => array(
		'label' => __( 'Posts', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'edit' ),
		'color' => 'var(--brand)',
	),
	'bn_comment'      => array(
		'label' => __( 'Comments', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'message-circle' ),
		'color' => 'var(--jetonomy, #5b21b6)',
	),
	'bn_reaction'     => array(
		'label' => __( 'Reactions Given', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'heart' ),
		'color' => 'var(--red)',
	),
	'bn_space_joined' => array(
		'label' => __( 'Space Contributions', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'home' ),
		'color' => 'var(--mvs, #0f766e)',
	),
	'bn_connected'    => array(
		'label' => __( 'New Connections', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'users' ),
		'color' => 'var(--green)',
	),
	'bn_followed'     => array(
		'label' => __( 'Follows', 'buddynext' ),
		'icon'  => buddynext_get_icon( 'user' ),
		'color' => 'var(--amber)',
	),
);

// Determine rank change — WBGamification does not currently expose a rank_snapshot API.
// Default to 0 (no change) until the bridge data source is available.
$rank_changes = array();
foreach ( $leaderboard as $idx => $entry ) {
	$rank_changes[ (int) $entry->user_id ] = 0;
}

// Avatar palette.
$avatar_palette = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0f766e', '#d97706', '#475569' );
$avatar_color   = static function ( int $uid ) use ( $avatar_palette ): string {
	return $avatar_palette[ $uid % count( $avatar_palette ) ];
};

$medal_icons = array(
	1 => '<span class="bn-rank-medal bn-rank-medal--gold">1</span>',
	2 => '<span class="bn-rank-medal bn-rank-medal--silver">2</span>',
	3 => '<span class="bn-rank-medal bn-rank-medal--bronze">3</span>',
);

// Compute next milestone for current user (simplistic: next 100-pt boundary).
$next_milestone_pts  = $current_user_pts > 0 ? (int) ( ceil( ( $current_user_pts + 1 ) / 100 ) * 100 ) : 100;
$milestone_progress  = $next_milestone_pts > 0 ? min( 100, (int) ( ( $current_user_pts % 100 ) ) ) : 0;
$milestone_remaining = $next_milestone_pts - $current_user_pts;

$bn_nav_active = 'feed';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<style>
/* ── Design tokens ── */
:root {
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
}

/* ── Shell ── */
.bn-lb-shell {
	max-width: 1100px;
	margin: 0 auto;
	padding: var(--s6) var(--s8);
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
}

/* ── Page header ── */
.bn-lb-page-header { margin-bottom: var(--s5); }
.bn-lb-header-top {
	display: flex;
	align-items: center;
	gap: var(--s3);
	margin-bottom: 4px;
}
.bn-lb-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	letter-spacing: -0.5px;
}
.bn-lb-integration-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--green-bg);
	color: var(--green);
	border: 1px solid var(--green);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 700;
	padding: 3px var(--s2);
}
.bn-lb-subtitle {
	font-size: var(--text-sm);
	color: var(--text-3);
	margin-bottom: var(--s4);
}

/* ── Period tabs ── */
.bn-lb-period-tabs {
	display: flex;
	gap: var(--s1);
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: 4px;
	width: fit-content;
	margin-bottom: var(--s3);
}
.bn-lb-tab {
	padding: 6px var(--s4);
	border-radius: var(--radius-sm);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	text-decoration: none;
	transition: background 0.1s;
}
.bn-lb-tab:hover { background: var(--bg-hover); color: var(--text-1); }
.bn-lb-tab--active { background: var(--brand); color: #fff; font-weight: 600; }
.bn-lb-tab--active:hover { background: var(--brand-hover); }

/* ── Category tabs ── */
.bn-lb-cat-tabs {
	display: flex;
	gap: 0;
	border-bottom: 2px solid var(--border);
	margin-bottom: var(--s5);
}
.bn-lb-cat {
	padding: var(--s2) var(--s4);
	font-size: var(--text-sm);
	font-weight: 500;
	color: var(--text-2);
	cursor: pointer;
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	text-decoration: none;
	transition: color 0.1s;
}
.bn-lb-cat:hover { color: var(--text-1); }
.bn-lb-cat--active {
	color: var(--brand);
	font-weight: 600;
	border-bottom-color: var(--brand);
}

/* ── Two-column grid ── */
.bn-lb-grid {
	display: grid;
	grid-template-columns: 1fr 300px;
	gap: var(--s5);
	align-items: start;
}
.bn-lb-main { min-width: 0; }

/* ── Podium ── */
.bn-lb-podium {
	display: grid;
	grid-template-columns: 1fr 1.15fr 1fr;
	gap: var(--s3);
	margin-bottom: var(--s4);
	align-items: end;
}
.bn-podium-card {
	border-radius: var(--radius-lg);
	border: 1.5px solid var(--border);
	padding: var(--s4) var(--s3) var(--s3);
	text-align: center;
	position: relative;
}
.bn-podium-card--1 { background: var(--gold-bg);   border-color: var(--gold-border);   padding-top: var(--s5); }
.bn-podium-card--2 { background: var(--silver-bg); border-color: var(--silver-border); }
.bn-podium-card--3 { background: var(--bronze-bg); border-color: var(--bronze-border); }
.bn-podium-medal { font-size: 22px; line-height: 1; margin-bottom: var(--s2); display: block; }
.bn-podium-ava {
	width: 52px; height: 52px; border-radius: 50%;
	display: flex; align-items: center; justify-content: center;
	font-weight: 700; color: #fff; font-size: var(--text-base);
	margin: 0 auto var(--s2);
}
.bn-podium-card--1 .bn-podium-ava { width: 60px; height: 60px; font-size: var(--text-lg); box-shadow: 0 0 0 3px var(--gold-border); }
.bn-podium-name { font-size: var(--text-sm); font-weight: 700; color: var(--text-1); line-height: 1.3; margin-bottom: 3px; }
.bn-podium-points { font-size: var(--text-xs); font-weight: 700; color: var(--text-2); margin-bottom: var(--s2); }
.bn-podium-card--1 .bn-podium-points { font-size: var(--text-sm); color: var(--gold); }
.bn-podium-badge {
	display: inline-block;
	font-size: 10px; font-weight: 600;
	background: var(--bg-hover); color: var(--text-2);
	border-radius: 10px; padding: 2px var(--s2);
}
.bn-podium-card--1 .bn-podium-badge { background: var(--gold-border); color: var(--gold); }

/* ── Ranked table ── */
.bn-lb-table {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	overflow: hidden;
	margin-bottom: var(--s4);
}
.bn-lb-thead {
	display: grid;
	grid-template-columns: 40px 1fr 100px 100px 60px;
	gap: var(--s3);
	padding: var(--s2) var(--s4);
	border-bottom: 1px solid var(--border);
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--text-3);
	text-transform: uppercase;
	letter-spacing: 0.06em;
}
.bn-lb-row {
	display: grid;
	grid-template-columns: 40px 1fr 100px 100px 60px;
	gap: var(--s3);
	padding: var(--s3) var(--s4);
	align-items: center;
	border-bottom: 1px solid var(--border-soft);
	transition: background 0.1s;
}
.bn-lb-row:last-child { border-bottom: none; }
.bn-lb-row:hover { background: var(--bg-subtle); }
.bn-lb-rank { font-size: var(--text-sm); font-weight: 700; color: var(--text-3); text-align: center; }
.bn-lb-member { display: flex; align-items: center; gap: var(--s2); min-width: 0; }
.bn-lb-ava {
	width: 34px; height: 34px; border-radius: 50%;
	color: #fff; font-weight: 700; font-size: var(--text-xs);
	display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.bn-lb-name {
	font-size: var(--text-sm); font-weight: 600; color: var(--text-1);
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bn-lb-sub { font-size: var(--text-xs); color: var(--text-3); }
.bn-lb-points { font-size: var(--text-sm); font-weight: 700; color: var(--text-1); }
.bn-lb-badges { font-size: 15px; display: flex; gap: 3px; align-items: center; }
.bn-lb-change { font-size: var(--text-xs); font-weight: 700; text-align: center; }
.bn-lb-change--up   { color: var(--green); }
.bn-lb-change--down { color: var(--red); }
.bn-lb-change--same { color: var(--text-3); }

/* ── Your rank row ── */
.bn-lb-your-rank {
	background: var(--brand-light);
	border: 1.5px solid var(--brand);
	border-radius: var(--radius-lg);
	display: grid;
	grid-template-columns: 40px 1fr 100px 1fr;
	gap: var(--s3);
	padding: var(--s3) var(--s4);
	align-items: center;
}
.bn-lb-your-rank-label { font-size: var(--text-xs); font-weight: 700; color: var(--brand); text-align: center; line-height: 1.2; }
.bn-lb-your-rank-name { font-size: var(--text-sm); font-weight: 700; color: var(--brand); }
.bn-lb-your-rank-sub { font-size: var(--text-xs); color: var(--text-2); }
.bn-lb-your-pts { font-size: var(--text-sm); font-weight: 700; color: var(--brand); }
.bn-lb-progress-label { font-size: var(--text-xs); color: var(--text-2); margin-bottom: 4px; }
.bn-lb-progress-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.bn-lb-progress-fill { height: 100%; background: var(--brand); border-radius: 3px; }
.bn-lb-progress-hint { font-size: var(--text-xs); color: var(--text-3); margin-top: 3px; }

/* ── Sidebar widgets ── */
.bn-lb-sidebar { display: flex; flex-direction: column; gap: var(--s4); }
.bn-widget {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-lg);
	padding: var(--s4);
}
.bn-widget-title {
	font-size: var(--text-sm); font-weight: 700; color: var(--text-1);
	margin-bottom: var(--s3);
	display: flex; align-items: center; gap: var(--s2);
}

/* Badge grid */
.bn-badge-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: var(--s2);
	margin-bottom: var(--s2);
}
.bn-badge-cell {
	aspect-ratio: 1;
	border-radius: var(--radius-sm);
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	display: flex; flex-direction: column;
	align-items: center; justify-content: center;
	font-size: 20px;
	cursor: pointer;
	position: relative;
	transition: border-color 0.1s;
}
.bn-badge-cell:hover { border-color: var(--brand); }
.bn-badge-cell--locked { opacity: 0.35; filter: grayscale(1); }
.bn-badge-label {
	font-size: 9px; font-weight: 600; color: var(--text-3);
	margin-top: 3px; text-align: center; line-height: 1.2;
}
.bn-badge-hint { font-size: var(--text-xs); color: var(--text-3); }

/* Points breakdown */
.bn-points-item { display: flex; flex-direction: column; gap: 4px; margin-bottom: var(--s2); }
.bn-points-item-top { display: flex; align-items: center; justify-content: space-between; }
.bn-points-item-label { font-size: var(--text-xs); font-weight: 500; color: var(--text-2); }
.bn-points-item-val { font-size: var(--text-xs); font-weight: 700; color: var(--text-1); }
.bn-points-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.bn-points-bar-fill { height: 100%; border-radius: 3px; }
.bn-points-total { margin-top: var(--s3); padding-top: var(--s3); border-top: 1px solid var(--border-soft); font-size: var(--text-xs); color: var(--text-3); }

/* Milestone widget */
.bn-milestone-name { font-size: var(--text-sm); font-weight: 700; color: var(--text-1); margin-bottom: 3px; }
.bn-milestone-desc { font-size: var(--text-xs); color: var(--text-2); margin-bottom: var(--s3); line-height: 1.5; }
.bn-milestone-bar { height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; margin-bottom: var(--s2); }
.bn-milestone-fill { height: 100%; background: linear-gradient(90deg, var(--brand), var(--mvs)); border-radius: 4px; }
.bn-milestone-row { display: flex; justify-content: space-between; font-size: var(--text-xs); color: var(--text-3); }
.bn-milestone-hint { margin-top: var(--s3); padding-top: var(--s3); border-top: 1px solid var(--border-soft); font-size: var(--text-xs); color: var(--text-2); line-height: 1.5; }

/* ── Responsive ── */
@media ( max-width: 1024px ) {
	.bn-lb-grid { grid-template-columns: 1fr; }
	.bn-lb-shell { padding: var(--s5) var(--s5); }
}
@media ( max-width: 640px ) {
	.bn-lb-shell { padding: var(--s4) var(--s3); }
	.bn-lb-podium { grid-template-columns: 1fr 1fr; }
	.bn-lb-podium .bn-podium-card--2 { order: 2; }
	.bn-lb-podium .bn-podium-card--1 { order: 1; grid-column: 1 / -1; }
	.bn-lb-podium .bn-podium-card--3 { order: 3; }
	.bn-lb-thead,
	.bn-lb-row { grid-template-columns: 32px 1fr 80px 60px; }
	.bn-lb-thead div:nth-child(4),
	.bn-lb-row .bn-lb-badges { display: none; }
	.bn-lb-your-rank { grid-template-columns: 32px 1fr 1fr; }
	.bn-lb-your-rank .bn-lb-your-pts { display: none; }
	.bn-lb-period-tabs { overflow-x: auto; }
	.bn-lb-tab { flex: 0 0 auto; }
}
</style>

<div class="bn-hub-shell">

<div class="bn-lb-shell"
	data-wp-interactive="buddynext/gamification"
	data-wp-context='{"period":"<?php echo esc_attr( $period ); ?>","category":"<?php echo esc_attr( $category ); ?>"}'>

	<!-- Page header -->
	<div class="bn-lb-page-header">
		<div class="bn-lb-header-top">
			<h1 class="bn-lb-title"><?php esc_html_e( 'Leaderboard', 'buddynext' ); ?></h1>
			<span class="bn-lb-integration-badge"><?php buddynext_icon( 'award' ); ?> <?php esc_html_e( 'via WBGamification', 'buddynext' ); ?></span>
		</div>
		<div class="bn-lb-subtitle">
			<?php esc_html_e( 'Top contributors this period', 'buddynext' ); ?>
		</div>

		<!-- Period tabs -->
		<div class="bn-lb-period-tabs">
			<?php
			$period_tabs = array(
				'week'    => __( 'This Week', 'buddynext' ),
				'month'   => __( 'This Month', 'buddynext' ),
				'alltime' => __( 'All Time', 'buddynext' ),
			);
			foreach ( $period_tabs as $pkey => $plabel ) :
				$phref = esc_url(
					add_query_arg(
						array(
							'period'   => $pkey,
							'category' => $category,
						)
					)
				);
				?>
				<a href="<?php echo $phref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
					class="bn-lb-tab<?php echo ( $pkey === $period ) ? ' bn-lb-tab--active' : ''; ?>">
					<?php echo esc_html( $plabel ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<!-- Category tabs -->
		<div class="bn-lb-cat-tabs">
			<?php
			$cat_tabs = array(
				'contributors' => __( 'Top Contributors', 'buddynext' ),
				'connectors'   => __( 'Top Connectors', 'buddynext' ),
				'rising'       => __( 'Rising Stars', 'buddynext' ),
			);
			foreach ( $cat_tabs as $ckey => $clabel ) :
				$chref = esc_url(
					add_query_arg(
						array(
							'period'   => $period,
							'category' => $ckey,
						)
					)
				);
				?>
				<a href="<?php echo $chref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
					class="bn-lb-cat<?php echo ( $ckey === $category ) ? ' bn-lb-cat--active' : ''; ?>">
					<?php echo esc_html( $clabel ); ?>
				</a>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( empty( $leaderboard ) ) : ?>
		<p style="color:var(--text-3);font-size:var(--text-sm);text-align:center;padding:var(--s8) 0;">
			<?php esc_html_e( 'No leaderboard data yet. Start contributing to earn points!', 'buddynext' ); ?>
		</p>
	<?php else : ?>

	<div class="bn-lb-grid">
		<div class="bn-lb-main">

			<?php
			// Separate podium (top 3) from table rows (4–10).
			$top3  = array_slice( $leaderboard, 0, 3 );
			$table = array_slice( $leaderboard, 3 );

			// Podium order: 2nd, 1st, 3rd visually.
			$podium_order = array();
			if ( isset( $top3[1] ) ) {
				$podium_order[] = array(
					'rank' => 2,
					'row'  => $top3[1],
				);
			}
			if ( isset( $top3[0] ) ) {
				$podium_order[] = array(
					'rank' => 1,
					'row'  => $top3[0],
				);
			}
			if ( isset( $top3[2] ) ) {
				$podium_order[] = array(
					'rank' => 3,
					'row'  => $top3[2],
				);
			}
			?>

			<!-- Podium -->
			<div class="bn-lb-podium">
				<?php
				foreach ( $podium_order as $pod ) :
					$puser    = get_userdata( (int) $pod['row']->user_id );
					$pname    = $puser ? $puser->display_name : __( 'Unknown', 'buddynext' );
					$pinits   = strtoupper( substr( $pname, 0, 1 ) . substr( strrchr( $pname, ' ' ), 1, 1 ) );
					$pts      = number_format( (float) $pod['row']->total_points );
					$rank_num = $pod['rank'];
					$card_mod = "bn-podium-card--{$rank_num}";
					$medal    = $medal_icons[ $rank_num ] ?? '';
					?>
					<div class="bn-podium-card <?php echo esc_attr( $card_mod ); ?>">
						<span class="bn-podium-medal" aria-hidden="true"><?php echo $medal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS class span, no user data. ?></span>
						<div class="bn-podium-ava" style="background:<?php echo esc_attr( $avatar_color( (int) $pod['row']->user_id ) ); ?>;">
							<?php echo esc_html( $pinits ); ?>
						</div>
						<div class="bn-podium-name"><?php echo esc_html( $pname ); ?></div>
						<div class="bn-podium-points">
							<?php
							// translators: %s is the number of points formatted.
							echo esc_html( sprintf( __( '%s pts', 'buddynext' ), $pts ) );
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Rank 4–10 table -->
			<?php if ( ! empty( $table ) ) : ?>
				<div class="bn-lb-table">
					<div class="bn-lb-thead">
						<div style="text-align:center;">#</div>
						<div><?php esc_html_e( 'Member', 'buddynext' ); ?></div>
						<div><?php esc_html_e( 'Points', 'buddynext' ); ?></div>
						<div><?php esc_html_e( 'Badges', 'buddynext' ); ?></div>
						<div style="text-align:center;"><?php esc_html_e( 'Change', 'buddynext' ); ?></div>
					</div>

					<?php
					foreach ( $table as $i => $trow ) :
						$trank   = $i + 4;
						$tuser   = get_userdata( (int) $trow->user_id );
						$tname   = $tuser ? $tuser->display_name : __( 'Unknown', 'buddynext' );
						$tinits  = strtoupper( substr( $tname, 0, 1 ) . substr( strrchr( $tname, ' ' ), 1, 1 ) );
						$tpts    = number_format( (float) $trow->total_points );
						$tchange = (int) ( $rank_changes[ (int) $trow->user_id ] ?? 0 );
						if ( $tchange > 0 ) {
							$change_class = 'bn-lb-change--up';
							$change_text  = '+' . $tchange . ' &uarr;';
						} elseif ( $tchange < 0 ) {
							$change_class = 'bn-lb-change--down';
							$change_text  = $tchange . ' &darr;';
						} else {
							$change_class = 'bn-lb-change--same';
							$change_text  = '&mdash; 0';
						}
						?>
						<div class="bn-lb-row">
							<div class="bn-lb-rank"><?php echo esc_html( (string) $trank ); ?></div>
							<div class="bn-lb-member">
								<div class="bn-lb-ava" style="background:<?php echo esc_attr( $avatar_color( (int) $trow->user_id ) ); ?>;">
									<?php echo esc_html( $tinits ); ?>
								</div>
								<div>
									<div class="bn-lb-name"><?php echo esc_html( $tname ); ?></div>
								</div>
							</div>
							<div class="bn-lb-points">
								<?php
								// translators: %s is the number of points.
								echo esc_html( sprintf( __( '%s pts', 'buddynext' ), $tpts ) );
								?>
							</div>
							<div class="bn-lb-badges" aria-hidden="true">&mdash;</div>
							<div class="bn-lb-change <?php echo esc_attr( $change_class ); ?>">
								<?php echo $change_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML arrow entities only, no user data. ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Your rank row -->
			<?php
			if ( $current_user_id && $current_user_rank > 0 ) :
				$cu    = get_userdata( $current_user_id );
				$cunam = $cu ? $cu->display_name : __( 'You', 'buddynext' );
				?>
				<div class="bn-lb-your-rank">
					<div class="bn-lb-your-rank-label">
						#<?php echo esc_html( (string) $current_user_rank ); ?><br>
						<span style="font-weight:400;color:var(--text-3);"><?php esc_html_e( 'You', 'buddynext' ); ?></span>
					</div>
					<div>
						<div class="bn-lb-your-rank-name"><?php echo esc_html( $cunam ); ?></div>
					</div>
					<div class="bn-lb-your-pts">
						<?php
						// translators: %s is the number of points.
						echo esc_html( sprintf( __( '%s pts', 'buddynext' ), number_format( (float) $current_user_pts ) ) );
						?>
					</div>
					<div>
						<div class="bn-lb-progress-label">
							<?php esc_html_e( 'Next milestone', 'buddynext' ); ?>:
							<strong><?php echo esc_html( (string) $next_milestone_pts ); ?> pts</strong>
						</div>
						<div class="bn-lb-progress-bar">
							<div class="bn-lb-progress-fill" style="width:<?php echo esc_attr( (string) $milestone_progress ); ?>%;"></div>
						</div>
						<div class="bn-lb-progress-hint">
							<?php
							// translators: %d is the number of points remaining.
							echo esc_html( sprintf( _n( '%d pt to go', '%d pts to go', $milestone_remaining, 'buddynext' ), $milestone_remaining ) );
							?>
						</div>
					</div>
				</div>
			<?php endif; ?>

		</div><!-- /lb-main -->

		<!-- Sidebar -->
		<aside class="bn-lb-sidebar">

			<!-- Your Badges -->
			<div class="bn-widget">
				<div class="bn-widget-title"><?php buddynext_icon( 'award' ); ?> <?php esc_html_e( 'Your Badges', 'buddynext' ); ?></div>
				<?php if ( ! empty( $user_badges ) ) : ?>
					<div class="bn-badge-grid">
						<?php foreach ( $user_badges as $badge ) : ?>
							<div class="bn-badge-cell" title="<?php echo esc_attr( $badge->name ); ?>">
								<?php if ( ! empty( $badge->image_url ) ) : ?>
									<img src="<?php echo esc_url( $badge->image_url ); ?>" alt="<?php echo esc_attr( $badge->name ); ?>" class="bn-badge-img" />
								<?php endif; ?>
								<span class="bn-badge-label"><?php echo esc_html( $badge->name ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="bn-badge-hint">
						<?php
						// translators: %d is the badge count.
						echo esc_html( sprintf( _n( '%d badge earned', '%d badges earned', count( $user_badges ), 'buddynext' ), count( $user_badges ) ) );
						?>
					</div>
				<?php else : ?>
					<p style="font-size:var(--text-xs);color:var(--text-3);">
						<?php esc_html_e( 'Earn your first badge by contributing to the community.', 'buddynext' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Points Breakdown -->
			<?php
			if ( ! empty( $points_breakdown ) ) :
				$max_pts       = max( array_column( array_map( 'get_object_vars', $points_breakdown ), 'pts' ) );
				$max_pts       = max( 1, (int) $max_pts );
				$total_pts_sum = array_sum( array_column( array_map( 'get_object_vars', $points_breakdown ), 'pts' ) );
				?>
				<div class="bn-widget">
					<div class="bn-widget-title"><?php buddynext_icon( 'bar-chart' ); ?> <?php esc_html_e( 'Points Breakdown', 'buddynext' ); ?></div>
					<?php
					foreach ( $points_breakdown as $bp ) :
						$event  = $bp->event_type;
						$pts_v  = (int) $bp->pts;
						$evinfo = $event_labels[ $event ] ?? array(
							'label' => esc_html( ucwords( str_replace( array( 'bn_', '_' ), array( '', ' ' ), $event ) ) ),
							'icon'  => buddynext_get_icon( 'star' ),
							'color' => 'var(--text-3)',
						);
						$bar_w  = (int) round( $pts_v / $max_pts * 100 );
						?>
						<div class="bn-points-item">
							<div class="bn-points-item-top">
								<span class="bn-points-item-label">
									<?php echo $evinfo['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG via buddynext_get_icon(), already wp_kses() sanitized. ?>
									<?php echo esc_html( $evinfo['label'] ); ?>
								</span>
								<span class="bn-points-item-val">
									<?php
									// translators: %d is the number of points.
									echo esc_html( sprintf( _n( '%d pt', '%d pts', $pts_v, 'buddynext' ), $pts_v ) );
									?>
								</span>
							</div>
							<div class="bn-points-bar">
								<div class="bn-points-bar-fill"
									style="width:<?php echo esc_attr( (string) $bar_w ); ?>%;background:<?php echo esc_attr( $evinfo['color'] ); ?>;"></div>
							</div>
						</div>
					<?php endforeach; ?>
					<div class="bn-points-total">
						<?php esc_html_e( 'Total:', 'buddynext' ); ?>
						<strong style="color:var(--text-1);">
							<?php
							// translators: %s is the formatted total points.
							echo esc_html( sprintf( __( '%s pts', 'buddynext' ), number_format( (float) $total_pts_sum ) ) );
							?>
						</strong>
					</div>
				</div>
			<?php endif; ?>

			<!-- Next Milestone -->
			<?php if ( $current_user_id ) : ?>
				<div class="bn-widget">
					<div class="bn-widget-title"><?php buddynext_icon( 'target' ); ?> <?php esc_html_e( 'Next Milestone', 'buddynext' ); ?></div>
					<div class="bn-milestone-name">
						<?php
						// translators: %d is the total points target.
						echo esc_html( sprintf( __( '%d pts milestone', 'buddynext' ), $next_milestone_pts ) );
						?>
					</div>
					<div class="bn-milestone-desc">
						<?php
						// translators: %d is the number of points remaining.
						echo esc_html( sprintf( __( 'Earn %d more points to reach the next milestone.', 'buddynext' ), $milestone_remaining ) );
						?>
					</div>
					<div class="bn-milestone-bar">
						<div class="bn-milestone-fill" style="width:<?php echo esc_attr( (string) $milestone_progress ); ?>%;"></div>
					</div>
					<div class="bn-milestone-row">
						<span><?php echo esc_html( (string) $current_user_pts ); ?> pts</span>
						<span><?php echo esc_html( (string) $next_milestone_pts ); ?> pts</span>
					</div>
					<div class="bn-milestone-hint">
						<?php
						// translators: %d is the number of remaining points.
						echo esc_html( sprintf( __( '%d points to go to reach the next level.', 'buddynext' ), $milestone_remaining ) );
						?>
					</div>
				</div>
			<?php endif; ?>

		</aside>
	</div><!-- /lb-grid -->

	<?php endif; // End: leaderboard data check. ?>
</div><!-- /.bn-lb-shell -->

<?php buddynext_get_template( 'partials/sidebar.php' ); ?>

</div><!-- /.bn-hub-shell -->
