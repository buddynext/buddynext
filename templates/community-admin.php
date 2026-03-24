<?php
/**
 * Template: Community Admin Panel
 *
 * Site-wide admin overview for community managers. Shows platform stats,
 * recent signups, pending join requests, open reports, and the activity
 * log. Accessible to users with manage_options or buddynext_community_admin.
 *
 * No context vars required — all data is fetched here.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! current_user_can( 'manage_options' ) && ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) {
	wp_die( esc_html__( 'You do not have permission to access the Community Admin Panel.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();
$bn_admin_user   = get_userdata( $current_user_id );

// ── Active section ────────────────────────────────────────────────────────────

$admin_section = isset( $_GET['bn_admin'] ) ? sanitize_key( wp_unslash( $_GET['bn_admin'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$admin_base    = buddynext_community_admin_url();

// ── Platform stats ────────────────────────────────────────────────────────────

// Total members.
$total_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// New members today.
$today_start = gmdate( 'Y-m-d 00:00:00' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$new_today = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered >= %s",
		$today_start
	)
);

// Active spaces.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$active_spaces = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces"
);

// Spaces pending approval — bn_spaces has no approval-state column; always 0 until schema adds one.
$pending_spaces = 0;

// Open reports.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$open_reports = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE status = 'pending'"
);

// Urgent (high reporter count) reports.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$urgent_reports = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports r WHERE r.status = 'pending' AND ( SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE object_type = r.object_type AND object_id = r.object_id ) >= 3"
);

// Posts today.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$posts_today = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE status = 'published' AND created_at >= %s",
		$today_start
	)
);

// Posts yesterday for comparison.
$yesterday_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
$yesterday_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$posts_yesterday = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE status = 'published' AND created_at BETWEEN %s AND %s",
		$yesterday_start,
		$yesterday_end
	)
);

// All pending space join requests.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total_pending_joins = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE status = 'pending'"
);

// ── Recent signups ────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_signups = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT ID, display_name, user_email, user_registered
		FROM {$wpdb->users}
		ORDER BY user_registered DESC
		LIMIT %d",
		10
	)
);

// ── Pending space join requests (cross-space) ─────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_joins = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.user_id, sm.space_id, sm.joined_at, u.display_name AS member_name, s.name AS space_name, s.slug AS space_slug
		FROM {$wpdb->prefix}bn_space_members sm
		INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
		INNER JOIN {$wpdb->prefix}bn_spaces s ON s.id = sm.space_id
		WHERE sm.status = 'pending'
		ORDER BY sm.joined_at ASC
		LIMIT %d",
		10
	)
);

// ── Open reports (cross-space) ────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$report_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.id, r.reason, r.created_at, s.name AS space_name,
		        ( SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE object_type = r.object_type AND object_id = r.object_id ) AS reporter_count
		FROM {$wpdb->prefix}bn_reports r
		LEFT JOIN {$wpdb->prefix}bn_spaces s ON s.id = r.space_id
		WHERE r.status = 'pending'
		ORDER BY reporter_count DESC, r.created_at ASC
		LIMIT %d",
		10
	)
);

// ── Recent activity log (site-wide) ──────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$activity_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT al.action, al.object_type, al.object_id, al.created_at, u.display_name AS actor_name
		FROM {$wpdb->prefix}bn_activity_log al
		LEFT JOIN {$wpdb->users} u ON u.ID = al.user_id
		ORDER BY al.created_at DESC
		LIMIT %d",
		20
	)
);

// ── Helpers ───────────────────────────────────────────────────────────────────

if ( ! function_exists( 'bn_initials' ) ) {
	/**
	 * Return initials (up to 2 chars) from a display name.
	 *
	 * @param string $name Full display name.
	 * @return string Uppercase initials.
	 */
	function bn_initials( string $name ): string {
		$parts = array_filter( explode( ' ', trim( $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( end( $parts ), 0, 1 ) );
		}
		return strtoupper( mb_substr( $name, 0, 2 ) );
	}
}

if ( ! function_exists( 'bn_avatar_color' ) ) {
	/**
	 * Return a deterministic avatar background colour based on a user id.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string CSS hex colour.
	 */
	function bn_avatar_color( int $user_id ): string {
		$colors = array( '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0d9488', '#d97706' );
		return $colors[ $user_id % count( $colors ) ];
	}
}

if ( ! function_exists( 'bn_time_diff' ) ) {
	/**
	 * Human-readable time diff label (e.g. "3h ago").
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string Localized time diff.
	 */
	function bn_time_diff( string $datetime ): string {
		return human_time_diff( strtotime( $datetime ), time() ) . ' ' . __( 'ago', 'buddynext' );
	}
}

if ( ! function_exists( 'bn_activity_icon' ) ) {
	/**
	 * Return an activity log SVG icon based on action type.
	 *
	 * @param string $action Activity action slug.
	 * @return string SVG icon markup via buddynext_get_icon().
	 */
	function bn_activity_icon( string $action ): string {
		$map = array(
			'new_member'      => buddynext_get_icon( 'user' ),
			'space_created'   => buddynext_get_icon( 'home' ),
			'report_resolved' => buddynext_get_icon( 'shield' ),
			'post_flagged'    => buddynext_get_icon( 'flag' ),
			'member_approved' => buddynext_get_icon( 'check-circle' ),
			'member_warned'   => buddynext_get_icon( 'ban' ),
			'space_requested' => buddynext_get_icon( 'home' ),
			'invite_sent'     => buddynext_get_icon( 'mail' ),
			'space_approved'  => buddynext_get_icon( 'check-circle' ),
			'profile_flagged' => buddynext_get_icon( 'user' ),
		);
		return $map[ $action ] ?? buddynext_get_icon( 'copy' );
	}
}

if ( ! function_exists( 'bn_report_severity' ) ) {
	/**
	 * Return a report severity label from reporter count.
	 *
	 * @param int $count Number of reporters.
	 * @return string Severity label: 'high', 'medium', or 'low'.
	 */
	function bn_report_severity( int $count ): string {
		if ( $count >= 3 ) {
			return 'high';
		} elseif ( $count >= 2 ) {
			return 'medium';
		}
		return 'low';
	}
}

$posts_pct = $posts_yesterday > 0
	? (int) round( ( ( $posts_today - $posts_yesterday ) / $posts_yesterday ) * 100 )
	: 0;

$bn_nav_active = '';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<style>
<?php /* phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline CSS token block */ ?>
:root {
	/* Local radius aliases → canonical tokens */
	--radius-sm: var(--r-sm);
	--radius:    var(--r-md);
	--radius-lg: var(--r-lg);
	/* Shadow token */
	--shadow-sm: 0 2px 8px rgba(0,0,0,0.07);
	/* Badge white */
	--color-on-brand: #fff;
}

.bn-ca {
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
	background: var(--bg-subtle);
}

/* ── Admin subheader ── */
.bn-ca-subheader {
	background: var(--amber-bg);
	border-bottom: 2px solid var(--border);
	padding: 10px var(--s6);
	display: flex;
	align-items: center;
	gap: var(--s4);
	flex-wrap: wrap;
}
.bn-ca-subheader__title {
	font-size: var(--text-base);
	font-weight: 700;
}
.bn-ca-role-badge {
	background: var(--amber);
	color: var(--color-on-brand);
	font-size: var(--text-xs);
	font-weight: var(--fw-bold);
	padding: 2px var(--s2);
	border-radius: var(--r-full);
	flex-shrink: 0;
}
.bn-ca-site-label {
	color: var(--amber);
	font-size: var(--text-xs);
}
.bn-ca-subheader__actions {
	margin-left: auto;
	display: flex;
	gap: var(--s2);
	align-items: center;
}
.bn-ca-subheader__actions a {
	font-size: var(--text-xs);
	text-decoration: none;
}
.bn-ca-link-back    { color: var(--brand); font-weight: 600; }
.bn-ca-link-wpadmin { color: var(--text-2); }

/* ── Main layout (sits inside .bn-hub-shell grid column) ── */
.bn-ca-wrap {
	display: flex;
	gap: var(--s6);
	min-width: 0;
}

/* ── Sidebar ── */
.bn-ca-sidebar {
	width: 220px;
	flex-shrink: 0;
	align-self: flex-start;
	position: sticky;
	top: var(--s6);
}
.bn-ca-sidebar-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	overflow: hidden;
}
.bn-ca-nav-header {
	font-size: var(--text-2xs);
	font-weight: var(--fw-bold);
	color: var(--text-3);
	text-transform: uppercase;
	letter-spacing: var(--ls-wider);
	padding: var(--s3) var(--s3) var(--s2);
}
.bn-ca-nav-item {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: 10px 14px;
	font-size: var(--text-sm);
	color: var(--text-1);
	cursor: pointer;
	border-left: 3px solid transparent;
	text-decoration: none;
	transition: background 0.1s;
}
.bn-ca-nav-item:hover { background: var(--bg-subtle); color: var(--text-1); }
.bn-ca-nav-item--active {
	border-left-color: var(--brand);
	background: var(--brand-light);
	color: var(--brand);
	font-weight: 600;
}
.bn-ca-nav-item--external {
	color: var(--text-3);
	font-size: var(--text-xs);
	border-left-color: transparent;
}
.bn-ca-nav-badge {
	margin-left: auto;
	background: var(--red);
	color: var(--color-on-brand);
	font-size: var(--text-2xs);
	font-weight: var(--fw-bold);
	padding: 1px var(--s2);
	border-radius: var(--r-sm);
}
.bn-ca-nav-divider { height: 1px; background: var(--border); margin: 6px 0; }
.bn-ca-sidebar-note {
	padding: var(--s2) 14px 14px;
	font-size: var(--text-xs);
	color: var(--text-3);
	line-height: 1.5;
}

/* ── Main content ── */
.bn-ca-main { flex: 1; min-width: 0; }

/* ── Stats grid ── */
.bn-ca-stats {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: var(--s3);
	margin-bottom: var(--s5);
}
.bn-ca-stat {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s4);
	transition: box-shadow 0.15s;
}
.bn-ca-stat:hover { box-shadow: var(--shadow-sm); }
.bn-ca-stat__label {
	font-size: var(--text-xs);
	color: var(--text-2);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-bottom: var(--s1);
}
.bn-ca-stat__number {
	font-size: var(--text-2xl);
	font-weight: var(--fw-bold);
	color: var(--text-1);
	margin-bottom: var(--s1);
}
.bn-ca-stat__sub {
	font-size: var(--text-xs);
	display: flex;
	align-items: center;
	gap: 3px;
}
.bn-ca-stat__sub--green  { color: var(--green); }
.bn-ca-stat__sub--amber  { color: var(--amber); }
.bn-ca-stat__sub--red    { color: var(--red); }
.bn-ca-stat__sub--grey   { color: var(--text-3); }

/* ── Two-column row ── */
.bn-ca-two-col {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--s5);
	margin-bottom: var(--s5);
}

/* ── Cards ── */
.bn-ca-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}
.bn-ca-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px var(--s4);
	border-bottom: 1px solid var(--border-soft);
}
.bn-ca-card__title {
	font-size: var(--text-sm);
	font-weight: 700;
}
.bn-ca-card__link {
	font-size: var(--text-xs);
	color: var(--brand);
	text-decoration: none;
	font-weight: 600;
}
.bn-ca-card__link:hover { text-decoration: underline; }
.bn-ca-pending-badge {
	background: var(--bg-subtle);
	color: var(--text-1);
	font-size: var(--text-xs);
	font-weight: 700;
	padding: 2px 7px;
	border-radius: var(--radius-sm);
}

/* ── Avatar ── */
.bn-ca-avatar {
	border-radius: 50%;
	color: #fff;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.bn-ca-avatar--32 { width: 32px; height: 32px; font-size: var(--text-xs); }

/* ── Signup rows ── */
.bn-ca-signup-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-sm);
}
.bn-ca-signup-row:last-child { border-bottom: none; }
.bn-ca-signup__info { flex: 1; min-width: 0; }
.bn-ca-signup__name { font-weight: 600; font-size: var(--text-sm); }
.bn-ca-signup__email {
	font-size: var(--text-xs);
	color: var(--text-3);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.bn-ca-signup__time { font-size: var(--text-xs); color: var(--text-3); white-space: nowrap; }
.bn-ca-btn-ghost {
	background: var(--surface);
	color: var(--text-2);
	border: 1px solid var(--border);
	padding: 4px var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	white-space: nowrap;
	text-decoration: none;
}
.bn-ca-btn-ghost:hover { border-color: var(--brand); color: var(--brand); }

/* ── Pending section title ── */
.bn-ca-pending-section-title {
	font-size: var(--text-xs);
	font-weight: 700;
	color: var(--text-3);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	padding: var(--s2) var(--s4) var(--s1);
}

/* ── Join request rows ── */
.bn-ca-join-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) var(--s4);
	font-size: var(--text-xs);
	border-bottom: 1px solid var(--border-soft);
}
.bn-ca-join-row:last-of-type { border-bottom: none; }
.bn-ca-join__info { flex: 1; }
.bn-ca-join__space { font-weight: 600; font-size: var(--text-xs); }
.bn-ca-join__member { color: var(--text-2); font-size: var(--text-xs); }
.bn-ca-join__actions { display: flex; gap: var(--s1); }
.bn-ca-btn-approve {
	background: var(--green);
	color: #fff;
	border: none;
	padding: 4px var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-ca-btn-decline {
	background: var(--surface);
	color: var(--text-2);
	border: 1px solid var(--border);
	padding: 4px var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
}
.bn-ca-card-divider { height: 1px; background: var(--border); margin: var(--s1) 0; }

/* ── Report rows ── */
.bn-ca-report-row {
	display: flex;
	align-items: center;
	gap: var(--s2);
	padding: var(--s2) var(--s4);
	font-size: var(--text-xs);
	border-left: 3px solid transparent;
}
.bn-ca-report-row--high   { border-left-color: var(--red); background: #fff8f8; }
.bn-ca-report-row--medium { border-left-color: #f59e0b; background: #fffdf8; }
.bn-ca-report-row--low    { border-left-color: var(--border); background: var(--bg-subtle); }
.bn-ca-report__info { flex: 1; }
.bn-ca-report__type { font-weight: 600; font-size: var(--text-xs); }
.bn-ca-report__meta { font-size: var(--text-xs); color: var(--text-3); }
.bn-ca-btn-review {
	background: var(--brand-light);
	color: var(--brand);
	border: 1px solid #bfdbfe;
	padding: 4px var(--s2);
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	font-family: var(--font-body);
	text-decoration: none;
}
.bn-ca-more-link {
	font-size: var(--text-xs);
	color: var(--brand);
	font-weight: 600;
	text-decoration: none;
	padding: var(--s2) var(--s4);
	display: block;
}
.bn-ca-more-link:hover { text-decoration: underline; }

/* ── Activity card ── */
.bn-ca-activity-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	overflow: hidden;
}
.bn-ca-activity-scroll {
	max-height: 280px;
	overflow-y: auto;
}
.bn-ca-activity-row {
	display: flex;
	align-items: flex-start;
	gap: var(--s2);
	padding: var(--s3) var(--s4);
	border-bottom: 1px solid var(--border-soft);
	font-size: var(--text-sm);
}
.bn-ca-activity-row:nth-child(even) { background: var(--bg-subtle); }
.bn-ca-activity-row:last-child { border-bottom: none; }
.bn-ca-activity__icon { font-size: var(--text-base); flex-shrink: 0; margin-top: 1px; }
.bn-ca-activity__body { flex: 1; }
.bn-ca-activity__desc { font-size: var(--text-sm); color: var(--text-1); line-height: 1.4; }
.bn-ca-activity__desc strong { font-weight: 600; }
.bn-ca-activity__meta { font-size: var(--text-xs); color: var(--text-3); margin-top: 2px; }
.bn-ca-activity__action { flex-shrink: 0; }

/* ── Responsive ── */
@media (max-width: 1024px) {
	.bn-ca-stats { grid-template-columns: repeat(3, 1fr); }
	.bn-ca-wrap  { flex-direction: column; gap: var(--s3); }
	.bn-ca-sidebar { width: 100%; position: static; }
	.bn-ca-sidebar-card { display: flex; flex-wrap: wrap; overflow-x: auto; border-radius: var(--r-sm); }
	.bn-ca-nav-header  { display: none; }
	.bn-ca-nav-item    { border-left: none; border-bottom: 2px solid transparent; white-space: nowrap; }
	.bn-ca-nav-item--active { border-left: none; border-bottom-color: var(--brand); }
	.bn-ca-nav-divider { display: none; }
	.bn-ca-sidebar-note { display: none; }
}
@media (max-width: 640px) {
	.bn-ca-stats { grid-template-columns: repeat(2, 1fr); gap: var(--s2); }
	.bn-ca-two-col { grid-template-columns: 1fr; }
	.bn-ca-subheader { padding: var(--s2) var(--s3); }
	.bn-ca-subheader__actions { display: none; }
}
<?php /* phpcs:enable */ ?>
</style>

<div
	class="bn-ca"
	data-wp-interactive="buddynext/spaces"
>

	<!-- Admin subheader -->
	<div class="bn-ca-subheader">
		<span class="bn-ca-subheader__title"><?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Community Admin Panel', 'buddynext' ); ?></span>
		<span class="bn-ca-role-badge"><?php esc_html_e( 'Community Manager', 'buddynext' ); ?></span>
		<span class="bn-ca-site-label">
			<?php
			// translators: %s is the site name.
			printf( esc_html__( 'Managing: %s community', 'buddynext' ), esc_html( get_bloginfo( 'name' ) ) );
			?>
		</span>
		<div class="bn-ca-subheader__actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bn-ca-link-back"><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to Community', 'buddynext' ); ?></a>
			<a href="<?php echo esc_url( admin_url() ); ?>" class="bn-ca-link-wpadmin"><?php esc_html_e( 'WP Admin', 'buddynext' ); ?></a>
		</div>
	</div>

	<div class="bn-hub-shell">
	<div class="bn-ca-wrap">

		<!-- Sidebar -->
		<aside class="bn-ca-sidebar" aria-label="<?php esc_attr_e( 'Admin navigation', 'buddynext' ); ?>">
			<div class="bn-ca-sidebar-card">
				<div class="bn-ca-nav-header"><?php esc_html_e( 'Admin Navigation', 'buddynext' ); ?></div>

				<?php
				$nav_items = array(
					'overview'   => array(
						'icon'  => buddynext_get_icon( 'bar-chart' ),
						'label' => __( 'Overview', 'buddynext' ),
					),
					'members'    => array(
						'icon'  => buddynext_get_icon( 'users' ),
						'label' => __( 'Members', 'buddynext' ),
					),
					'spaces'     => array(
						'icon'  => buddynext_get_icon( 'home' ),
						'label' => __( 'Spaces', 'buddynext' ),
					),
					'moderation' => array(
						'icon'  => buddynext_get_icon( 'shield' ),
						'label' => __( 'Moderation', 'buddynext' ),
						'badge' => $open_reports,
					),
					'reports'    => array(
						'icon'  => buddynext_get_icon( 'copy' ),
						'label' => __( 'Reports', 'buddynext' ),
					),
					'invites'    => array(
						'icon'  => buddynext_get_icon( 'mail' ),
						'label' => __( 'Email Invites', 'buddynext' ),
					),
					'settings'   => array(
						'icon'  => buddynext_get_icon( 'settings' ),
						'label' => __( 'Settings', 'buddynext' ),
					),
				);
				foreach ( $nav_items as $key => $item ) :
					$is_active = ( $admin_section === $key );
					?>
					<a
						href="<?php echo esc_url( add_query_arg( 'bn_admin', $key, $admin_base ) ); ?>"
						class="bn-ca-nav-item<?php echo $is_active ? ' bn-ca-nav-item--active' : ''; ?>"
						aria-current="<?php echo $is_active ? 'page' : 'false'; ?>"
					>
						<span aria-hidden="true"><?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG via buddynext_get_icon(), already wp_kses() sanitized. ?></span>
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! empty( $item['badge'] ) && (int) $item['badge'] > 0 ) : ?>
							<span class="bn-ca-nav-badge"><?php echo esc_html( (string) $item['badge'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>

				<div class="bn-ca-nav-divider"></div>

				<a href="<?php echo esc_url( admin_url() ); ?>" class="bn-ca-nav-item bn-ca-nav-item--external">
					<?php buddynext_icon( 'link' ); ?> <?php esc_html_e( 'WordPress Admin', 'buddynext' ); ?>
				</a>

				<p class="bn-ca-sidebar-note"><?php esc_html_e( 'Space admins only see their own space in this panel.', 'buddynext' ); ?></p>
			</div>
		</aside>

		<!-- Main content -->
		<main class="bn-ca-main">

			<!-- Stats grid -->
			<div class="bn-ca-stats" role="list">

				<div class="bn-ca-stat" role="listitem">
					<div class="bn-ca-stat__label"><?php esc_html_e( 'Members', 'buddynext' ); ?></div>
					<div class="bn-ca-stat__number"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></div>
					<div class="bn-ca-stat__sub bn-ca-stat__sub--green">
						<?php buddynext_icon( 'arrow-up' ); ?>
						<?php
						// translators: %d is the number of new members today.
						printf( esc_html__( '%d new today', 'buddynext' ), absint( $new_today ) );
						?>
					</div>
				</div>

				<div class="bn-ca-stat" role="listitem">
					<div class="bn-ca-stat__label"><?php esc_html_e( 'Active Spaces', 'buddynext' ); ?></div>
					<div class="bn-ca-stat__number"><?php echo esc_html( number_format_i18n( $active_spaces ) ); ?></div>
					<?php if ( $pending_spaces > 0 ) : ?>
						<div class="bn-ca-stat__sub bn-ca-stat__sub--amber">
							<?php buddynext_icon( 'alert-triangle' ); ?>
							<?php
							// translators: %d is number of spaces pending approval.
							printf( esc_html__( '%d pending approval', 'buddynext' ), absint( $pending_spaces ) );
							?>
						</div>
					<?php else : ?>
						<div class="bn-ca-stat__sub bn-ca-stat__sub--grey"><?php esc_html_e( 'No pending', 'buddynext' ); ?></div>
					<?php endif; ?>
				</div>

				<div class="bn-ca-stat" role="listitem">
					<div class="bn-ca-stat__label"><?php esc_html_e( 'Open Reports', 'buddynext' ); ?></div>
					<div class="bn-ca-stat__number"><?php echo esc_html( (string) $open_reports ); ?></div>
					<div class="bn-ca-stat__sub bn-ca-stat__sub--red">
						<span class="bn-status-dot bn-status-dot--high"></span>
						<?php
						// translators: %d is the number of urgent reports.
						printf( esc_html__( '%d urgent', 'buddynext' ), absint( $urgent_reports ) );
						?>
					</div>
				</div>

				<div class="bn-ca-stat" role="listitem">
					<div class="bn-ca-stat__label"><?php esc_html_e( 'Posts Today', 'buddynext' ); ?></div>
					<div class="bn-ca-stat__number"><?php echo esc_html( (string) $posts_today ); ?></div>
					<div class="bn-ca-stat__sub<?php echo $posts_pct >= 0 ? ' bn-ca-stat__sub--green' : ' bn-ca-stat__sub--red'; ?>">
						<?php
					if ( $posts_pct >= 0 ) {
						buddynext_icon( 'arrow-up' );
					} else {
						buddynext_icon( 'arrow-down' );
					}
					?>
						<?php
						// translators: %d is the percentage change vs yesterday.
						printf( esc_html__( '%d%% vs yesterday', 'buddynext' ), absint( $posts_pct ) );
						?>
					</div>
				</div>

				<div class="bn-ca-stat" role="listitem">
					<div class="bn-ca-stat__label"><?php esc_html_e( 'Pending Joins', 'buddynext' ); ?></div>
					<div class="bn-ca-stat__number"><?php echo esc_html( (string) $total_pending_joins ); ?></div>
					<div class="bn-ca-stat__sub bn-ca-stat__sub--grey"><?php esc_html_e( 'invite-only spaces', 'buddynext' ); ?></div>
				</div>

			</div>

			<!-- Two-column row -->
			<div class="bn-ca-two-col">

				<!-- Recent Signups -->
				<div class="bn-ca-card">
					<div class="bn-ca-card__header">
						<span class="bn-ca-card__title"><?php buddynext_icon( 'users' ); ?> <?php esc_html_e( 'Recent Signups', 'buddynext' ); ?></span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'members', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View All', 'buddynext' ); ?> &rarr;
						</a>
					</div>

					<?php if ( empty( $recent_signups ) ) : ?>
						<p style="padding:var(--s4);color:var(--text-3);font-size:var(--text-sm);"><?php esc_html_e( 'No signups yet.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $recent_signups as $signup ) : ?>
							<?php
							$su_uid   = (int) $signup->ID;
							$su_name  = $signup->display_name ?? __( 'Member', 'buddynext' );
							$su_color = bn_avatar_color( $su_uid );
							$su_init  = bn_initials( $su_name );
							$su_time  = isset( $signup->user_registered ) ? bn_time_diff( $signup->user_registered ) : '';
							$su_email = $signup->user_email ?? '';
							?>
							<div class="bn-ca-signup-row">
								<div
									class="bn-ca-avatar bn-ca-avatar--32"
									style="background:<?php echo esc_attr( $su_color ); ?>;"
									aria-label="<?php echo esc_attr( $su_name ); ?>"
								><?php echo esc_html( $su_init ); ?></div>
								<div class="bn-ca-signup__info">
									<div class="bn-ca-signup__name"><?php echo esc_html( $su_name ); ?></div>
									<div class="bn-ca-signup__email"><?php echo esc_html( $su_email ); ?></div>
								</div>
								<div class="bn-ca-signup__time"><?php echo esc_html( $su_time ); ?></div>
								<a
									href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $su_uid ) ); ?>"
									class="bn-ca-btn-ghost"
								><?php esc_html_e( 'View Profile', 'buddynext' ); ?></a>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Pending Actions -->
				<div class="bn-ca-card">
					<div class="bn-ca-card__header">
						<span class="bn-ca-card__title"><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Pending Actions', 'buddynext' ); ?></span>
						<span class="bn-ca-pending-badge"><?php echo esc_html( (string) ( count( $pending_joins ) + count( $report_rows ) ) ); ?></span>
					</div>

					<!-- Space join requests -->
					<?php if ( ! empty( $pending_joins ) ) : ?>
						<div class="bn-ca-pending-section-title">
							<?php
							// translators: %d is the count of pending join requests.
							printf( esc_html__( 'Space Join Requests (%d)', 'buddynext' ), count( $pending_joins ) );
							?>
						</div>

						<?php foreach ( $pending_joins as $join ) : ?>
							<?php
							$j_uid    = (int) $join->user_id;
							$j_sid    = (int) $join->space_id;
							$j_member = $join->member_name ?? __( 'Member', 'buddynext' );
							$j_space  = $join->space_name ?? __( 'Space', 'buddynext' );
							?>
							<div class="bn-ca-join-row">
								<div class="bn-ca-join__info">
									<div class="bn-ca-join__space"><?php echo esc_html( $j_space ); ?></div>
									<div class="bn-ca-join__member">
										<?php
										// translators: %s is the member display name.
										printf( esc_html__( '%s wants to join', 'buddynext' ), esc_html( $j_member ) );
										?>
									</div>
								</div>
								<div class="bn-ca-join__actions">
									<button
										type="button"
										class="bn-ca-btn-approve"
										data-wp-on--click="actions.approveJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
									<button
										type="button"
										class="bn-ca-btn-decline"
										data-wp-on--click="actions.declineJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
								</div>
							</div>
						<?php endforeach; ?>

						<div class="bn-ca-card-divider"></div>
					<?php endif; ?>

					<!-- Open reports -->
					<?php if ( ! empty( $report_rows ) ) : ?>
						<div class="bn-ca-pending-section-title">
							<?php
							// translators: %d is the number of open reports.
							printf( esc_html__( 'Open Reports (%d)', 'buddynext' ), count( $report_rows ) );
							?>
						</div>

						<?php
						$displayed   = 0;
						$show_limit  = 3;
						$extra_count = max( 0, count( $report_rows ) - $show_limit );
						?>

						<?php foreach ( $report_rows as $rpt ) : ?>
							<?php
							if ( $displayed >= $show_limit ) :
								break;
endif;
							?>
							<?php
							$rpt_count    = (int) ( $rpt->reporter_count ?? 1 );
							$rpt_severity = bn_report_severity( $rpt_count );
							$rpt_reason   = ucfirst( $rpt->reason ?? __( 'Report', 'buddynext' ) );
							$rpt_time     = isset( $rpt->created_at ) ? bn_time_diff( $rpt->created_at ) : '';
							$rpt_icon     = match ( $rpt_severity ) {
								'high'   => '<span class="bn-status-dot bn-status-dot--high"></span>',
								'medium' => '<span class="bn-status-dot bn-status-dot--medium"></span>',
								default  => '<span class="bn-status-dot"></span>',
							};
							++$displayed;
	?>
							<div class="bn-ca-report-row bn-ca-report-row--<?php echo esc_attr( $rpt_severity ); ?>">
								<div class="bn-ca-report__info">
									<div class="bn-ca-report__type"><?php echo $rpt_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS class span, no user data. ?> <?php echo esc_html( $rpt_reason ); ?></div>
									<div class="bn-ca-report__meta">
										<?php
										// translators: 1: reporter count, 2: time ago.
										printf( esc_html__( '%1$d reporter(s) &middot; %2$s', 'buddynext' ), absint( $rpt_count ), esc_html( $rpt_time ) );
										?>
									</div>
								</div>
								<a
									href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'bn_admin' => 'reports',
												'bn_report_id' => $rpt->id,
											),
											$admin_base
										)
									);
									?>
											"
									class="bn-ca-btn-review"
								><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
							</div>
						<?php endforeach; ?>

						<?php if ( $extra_count > 0 ) : ?>
							<a
								href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>"
								class="bn-ca-more-link"
							>
								+ 
								<?php
								// translators: %d is the number of additional reports.
								printf( esc_html__( '%d more', 'buddynext' ), absint( $extra_count ) );
								?>
							</a>
						<?php endif; ?>

					<?php endif; ?>

				</div>

			</div>
			<!-- /two-col -->

			<!-- Recent activity (full width) -->
			<div class="bn-ca-activity-card">
				<div class="bn-ca-card__header">
					<span class="bn-ca-card__title"><?php buddynext_icon( 'copy' ); ?> <?php esc_html_e( 'Recent Activity', 'buddynext' ); ?></span>
					<a
						href="<?php echo esc_url( add_query_arg( 'bn_admin', 'log', $admin_base ) ); ?>"
						class="bn-ca-card__link"
					><?php esc_html_e( 'View Full Log', 'buddynext' ); ?> &rarr;</a>
				</div>

				<div class="bn-ca-activity-scroll" role="log" aria-label="<?php esc_attr_e( 'Recent site activity', 'buddynext' ); ?>">

					<?php if ( empty( $activity_rows ) ) : ?>
						<div style="padding:var(--s6);text-align:center;color:var(--text-3);font-size:var(--text-sm);">
							<?php esc_html_e( 'No recent activity.', 'buddynext' ); ?>
						</div>

					<?php else : ?>

						<?php foreach ( $activity_rows as $act ) : ?>
							<?php
							$act_action = $act->action ?? 'note';
							$act_icon   = bn_activity_icon( $act_action );
							$act_desc   = isset( $act->action, $act->object_type )
							? ucfirst( str_replace( '_', ' ', (string) $act->action ) ) . ' (' . (string) $act->object_type . ')'
							: '';
							$act_meta   = isset( $act->created_at ) ? bn_time_diff( $act->created_at ) : '';
							$act_report = ( 'post_flagged' === $act_action );
							?>
							<div class="bn-ca-activity-row">
								<div class="bn-ca-activity__icon" aria-hidden="true"><?php echo $act_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG via buddynext_get_icon(), already wp_kses() sanitized. ?></div>
								<div class="bn-ca-activity__body">
									<div class="bn-ca-activity__desc"><?php echo esc_html( $act_desc ); ?></div>
									<div class="bn-ca-activity__meta"><?php echo esc_html( $act_meta ); ?></div>
								</div>
								<?php if ( $act_report ) : ?>
									<div class="bn-ca-activity__action">
										<a
											href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>"
											class="bn-ca-btn-review"
										><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

					<?php endif; ?>

				</div>
			</div>

		</main>

	</div><!-- /.bn-ca-wrap -->
	<?php buddynext_get_template( 'partials/sidebar.php' ); ?>
	</div><!-- /.bn-hub-shell -->

</div><!-- /.bn-ca -->
