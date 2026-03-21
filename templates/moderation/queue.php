<?php
/**
 * Moderation queue template.
 *
 * Restricted to users with the buddynext-moderation/review-queue ability.
 * Lists pending reports from bn_reports with severity classification,
 * reporter stacks, and inline actions:
 *   - Dismiss, Remove content, Warn user, Strike user, Suspend account.
 *
 * Each action calls buddynext/v1/moderation/{report_id}/{action} via the
 * WP Interactivity API store.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$current_user_id = get_current_user_id();

// Access gate — must have review-queue ability.
if ( ! $current_user_id || ! buddynext_can( $current_user_id, 'buddynext-moderation/review-queue' ) ) {
	?>
	<style>
	:root {
		--font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
		--text-sm: 13px; --text-base: 15px; --text-2xl: 24px;
		--bg: #ffffff; --surface: #ffffff; --border: #e8e8e5;
		--text-1: #37352f; --text-2: #787774; --text-3: #aeaca8;
		--brand: #0073aa; --brand-light: #e8f4fb;
		--red: #dc2626; --red-bg: #fef2f2;
		--s3: 12px; --s4: 16px; --s5: 20px; --s6: 24px; --s8: 32px;
		--radius-lg: 14px;
	}
	[data-theme="dark"] {
		--bg: #191919; --surface: #252525; --border: #333330;
		--text-1: #e8e8e6; --text-2: #9b9b97; --text-3: #6b6b67;
		--brand: #4dabdb; --brand-light: #1a2e3a;
		--red: #f87171; --red-bg: #2d0f0f;
	}
	.bn-mod-restricted {
		max-width: 600px; margin: var(--s8) auto; padding: 0 var(--s5);
		font-family: var(--font-body); text-align: center;
	}
	.bn-mod-restricted-box {
		background: var(--red-bg);
		border: 1px solid var(--red);
		border-radius: var(--radius-lg);
		padding: var(--s8) var(--s6);
	}
	.bn-mod-restricted-icon { font-size: 40px; display: block; margin-bottom: var(--s4); }
	.bn-mod-restricted h2 { font-size: var(--text-2xl); font-weight: 800; color: var(--text-1); margin-bottom: var(--s3); }
	.bn-mod-restricted p { font-size: var(--text-sm); color: var(--text-2); line-height: 1.6; }
	</style>
	<div class="bn-mod-restricted">
		<div class="bn-mod-restricted-box">
			<span class="bn-mod-restricted-icon" aria-hidden="true">&#x1F6AB;</span>
			<h2><?php esc_html_e( 'Access Restricted', 'buddynext' ); ?></h2>
			<p><?php esc_html_e( 'You do not have permission to access the moderation queue. If you believe this is an error, contact a community administrator.', 'buddynext' ); ?></p>
		</div>
	</div>
	<?php
	return;
}

// Allowed filter values.
$allowed_obj_types = [ 'all', 'post', 'comment', 'user', 'space', 'message' ];
$allowed_urgency   = [ 'all', 'urgent' ];
$allowed_sorts     = [ 'newest', 'most_reported' ];

$filter_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $filter_type, $allowed_obj_types, true ) ) {
	$filter_type = 'all';
}

$filter_urgency = isset( $_GET['urgency'] ) ? sanitize_key( wp_unslash( $_GET['urgency'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $filter_urgency, $allowed_urgency, true ) ) {
	$filter_urgency = 'all';
}

$sort_by = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'newest'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $sort_by, $allowed_sorts, true ) ) {
	$sort_by = 'newest';
}

// Build SQL conditionals.
$type_sql    = ( 'all' !== $filter_type ) ? $wpdb->prepare( ' AND r.object_type = %s', $filter_type ) : '';
$urgency_sql = ( 'urgent' === $filter_urgency ) ? ' AND r.report_count >= 3' : '';
$sort_sql    = ( 'most_reported' === $sort_by ) ? 'ORDER BY r.report_count DESC, r.created_at DESC' : 'ORDER BY r.created_at DESC';

// Stats query.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
$stats = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	"SELECT
		SUM( CASE WHEN status = 'pending' AND report_count >= 3 THEN 1 ELSE 0 END ) AS urgent,
		SUM( CASE WHEN status = 'pending' THEN 1 ELSE 0 END )                       AS pending,
		SUM( CASE WHEN status IN ('dismissed','actioned') AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END ) AS resolved_today,
		COUNT(*)                                                                      AS total_all_time
	 FROM {$wpdb->prefix}bn_reports"
);

// Count currently suspended users.
$suspended_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	"SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_strikes WHERE suspended = 1"
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching

$urgent_count   = (int) ( $stats->urgent ?? 0 );
$pending_count  = (int) ( $stats->pending ?? 0 );
$resolved_today = (int) ( $stats->resolved_today ?? 0 );
$total_all_time = (int) ( $stats->total_all_time ?? 0 );

// Fetch pending reports.
// $type_sql, $urgency_sql, $sort_sql are built from hardcoded literals + wpdb->prepare() — no raw user data.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching
$reports = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	"SELECT r.id, r.reporter_id, r.object_type, r.object_id, r.reason, r.status,
	        r.created_at, r.report_count,
	        s.strikes_count, s.suspended,
	        u.display_name AS offender_name, u.user_registered AS offender_joined
	 FROM {$wpdb->prefix}bn_reports AS r
	 LEFT JOIN {$wpdb->prefix}bn_user_strikes AS s ON s.user_id = r.reporter_id
	 LEFT JOIN {$wpdb->users} AS u ON u.ID = r.object_id AND r.object_type = 'user'
	 WHERE r.status = 'pending'
	 $type_sql
	 $urgency_sql
	 $sort_sql
	 LIMIT 50"
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching

// Collect all object IDs per type to resolve display content.
$post_ids  = [];
$user_ids  = [];
$space_ids = [];
foreach ( $reports ?? [] as $rpt ) {
	switch ( $rpt->object_type ) {
		case 'post':
		case 'comment':
			$post_ids[] = (int) $rpt->object_id;
			break;
		case 'user':
			$user_ids[] = (int) $rpt->object_id;
			break;
		case 'space':
			$space_ids[] = (int) $rpt->object_id;
			break;
	}
}

// Batch-fetch post/comment content excerpts.
$post_excerpts = [];
if ( ! empty( $post_ids ) ) {
	$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
	// $placeholders is built entirely from hardcoded '%d' strings — no user data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$post_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT id, content, author_id FROM {$wpdb->prefix}bn_posts WHERE id IN ($placeholders)",
			...$post_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	foreach ( $post_rows ?? [] as $pr ) {
		$post_excerpts[ (int) $pr->id ] = [
			'content'   => $pr->content,
			'author_id' => (int) $pr->author_id,
		];
	}
}

// Batch-fetch space names.
$space_names = [];
if ( ! empty( $space_ids ) ) {
	$placeholders = implode( ', ', array_fill( 0, count( $space_ids ), '%d' ) );
	// $placeholders is built entirely from hardcoded '%d' strings — no user data.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$space_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			"SELECT id, name FROM {$wpdb->prefix}bn_spaces WHERE id IN ($placeholders)",
			...$space_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	foreach ( $space_rows ?? [] as $sr ) {
		$space_names[ (int) $sr->id ] = $sr->name;
	}
}

/**
 * Determine severity class from report_count and reason.
 *
 * @param int    $report_count Number of reports.
 * @param string $reason       Reported reason string.
 * @return string 'urgent' | 'medium' | 'low'
 */
$severity_class = static function ( int $report_count, string $reason ): string {
	if ( $report_count >= 3 || false !== stripos( $reason, 'hate' ) || false !== stripos( $reason, 'harassment' ) ) {
		return 'urgent';
	}
	if ( $report_count >= 2 || false !== stripos( $reason, 'spam' ) ) {
		return 'medium';
	}
	return 'low';
};

/**
 * Map a reason string to a badge label and CSS class.
 *
 * @param string $reason Report reason.
 * @return array{label: string, class: string}
 */
$reason_badge = static function ( string $reason ): array {
	if ( false !== stripos( $reason, 'hate' ) ) {
		return [
			'label' => __( 'Hate speech', 'buddynext' ),
			'class' => 'bn-badge--hate',
		];
	}
	if ( false !== stripos( $reason, 'harass' ) ) {
		return [
			'label' => __( 'Harassment', 'buddynext' ),
			'class' => 'bn-badge--harass',
		];
	}
	if ( false !== stripos( $reason, 'spam' ) || false !== stripos( $reason, 'promo' ) ) {
		return [
			'label' => __( 'Spam', 'buddynext' ),
			'class' => 'bn-badge--spam',
		];
	}
	return [
		'label' => __( 'Off-topic', 'buddynext' ),
		'class' => 'bn-badge--other',
	];
};

// Avatar palette.
$avatar_palette = [ '#0073aa', '#059669', '#7c3aed', '#ea580c', '#db2777', '#0f766e', '#d97706', '#dc2626' ];
$avatar_color   = static function ( int $uid ) use ( $avatar_palette ): string {
	return $avatar_palette[ $uid % count( $avatar_palette ) ];
};

$mod_nonce = wp_create_nonce( 'bn_moderation_action' );
?>
<style>
/* ── Design tokens ── */
:root {
	--font-body:    'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
	--font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;
	--text-xs:  11px; --text-sm: 13px; --text-base: 15px;
	--text-lg:  17px; --text-xl: 20px; --text-2xl: 24px;
	--leading-body: 1.7;
	--bg:          #ffffff;
	--bg-subtle:   #f8f8f7;
	--bg-hover:    #f1f1f0;
	--surface:     #ffffff;
	--border:      #e8e8e5;
	--border-soft: #f1f1ee;
	--text-1:      #37352f;
	--text-2:      #787774;
	--text-3:      #aeaca8;
	--brand:       #0073aa;
	--brand-light: #e8f4fb;
	--brand-hover: #005f8e;
	--green:       #059669; --green-bg:  #ecfdf5;
	--amber:       #d97706; --amber-bg:  #fffbeb;
	--red:         #dc2626; --red-bg:    #fef2f2;
	--orange:      #ea580c; --orange-bg: #fff7ed;
	--s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 20px; --s6: 24px; --s8: 32px;
	--radius-sm: 6px; --radius: 10px; --radius-lg: 14px;
}
[data-theme="dark"] {
	--bg:          #191919;
	--bg-subtle:   #202020;
	--bg-hover:    #2a2a2a;
	--surface:     #252525;
	--border:      #333330;
	--border-soft: #2c2c2a;
	--text-1:      #e8e8e6;
	--text-2:      #9b9b97;
	--text-3:      #6b6b67;
	--brand:       #4dabdb;
	--brand-light: #1a2e3a;
	--brand-hover: #5fbfe8;
	--green:       #34d399; --green-bg:  #0d2420;
	--amber:       #fbbf24; --amber-bg:  #2a2000;
	--red:         #f87171; --red-bg:    #2d0f0f;
	--orange:      #fb923c; --orange-bg: #271500;
}

/* ── Shell ── */
.bn-mod-shell {
	max-width: 1060px;
	margin: 0 auto;
	padding: var(--s6) var(--s5);
	font-family: var(--font-body);
	font-size: var(--text-base);
	color: var(--text-1);
}

/* ── Page header ── */
.bn-mod-page-title {
	font-family: var(--font-display);
	font-size: var(--text-2xl);
	font-weight: 800;
	color: var(--text-1);
	margin-bottom: 4px;
}
.bn-mod-page-sub {
	font-size: var(--text-sm);
	color: var(--text-2);
	margin-bottom: var(--s5);
}

/* ── Stats row ── */
.bn-mod-stats {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: var(--s3);
	margin-bottom: var(--s6);
}
.bn-stat-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	padding: var(--s4);
}
.bn-stat-num {
	font-family: var(--font-display);
	font-size: 28px;
	font-weight: 800;
	color: var(--text-1);
	margin-bottom: 4px;
}
.bn-stat-label { font-size: var(--text-xs); color: var(--text-2); }
.bn-stat-card--urgent .bn-stat-num { color: var(--red); }
.bn-stat-card--pending .bn-stat-num { color: var(--amber); }
.bn-stat-card--resolved .bn-stat-num { color: var(--green); }
.bn-stat-card--suspended .bn-stat-num { color: var(--red); }

/* ── Filter bar ── */
.bn-mod-filter-bar {
	display: flex;
	gap: var(--s2);
	margin-bottom: var(--s5);
	flex-wrap: wrap;
	align-items: center;
}
.bn-filter-btn {
	padding: 6px 14px;
	border-radius: var(--radius);
	border: 1.5px solid var(--border);
	background: var(--surface);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	color: var(--text-1);
	text-decoration: none;
	transition: background 0.1s, border-color 0.1s;
}
.bn-filter-btn:hover { background: var(--bg-hover); }
.bn-filter-btn--active { background: var(--brand); border-color: var(--brand); color: #fff; }
.bn-filter-btn--active:hover { background: var(--brand-hover); }
.bn-filter-select {
	border: 1.5px solid var(--border);
	border-radius: var(--radius-sm);
	padding: 7px var(--s2);
	font-size: var(--text-xs);
	background: var(--surface);
	color: var(--text-1);
	cursor: pointer;
}

/* ── Report cards ── */
.bn-report-card {
	background: var(--surface);
	border: 1px solid var(--border);
	border-radius: var(--radius);
	margin-bottom: var(--s3);
	overflow: hidden;
}
.bn-report-card--urgent { border-left: 4px solid var(--red); }
.bn-report-card--medium { border-left: 4px solid var(--amber); }
.bn-report-card--low    { border-left: 4px solid var(--text-3); }

/* Card header */
.bn-rc-header {
	display: flex;
	align-items: center;
	gap: var(--s3);
	padding: var(--s3) var(--s4);
	background: var(--bg-subtle);
	border-bottom: 1px solid var(--border-soft);
}
.bn-rc-ava {
	width: 32px; height: 32px; border-radius: 50%;
	color: #fff; font-weight: 700; font-size: var(--text-xs);
	display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.bn-rc-who { flex: 1; min-width: 0; }
.bn-rc-name { font-weight: 600; font-size: var(--text-sm); color: var(--text-1); }
.bn-rc-meta { font-size: var(--text-xs); color: var(--text-3); }
.bn-rc-time { font-size: var(--text-xs); color: var(--text-3); flex-shrink: 0; }

/* Reason badges */
.bn-badge {
	font-size: 10px; padding: 2px var(--s2); border-radius: var(--radius);
	font-weight: 700; flex-shrink: 0; white-space: nowrap;
}
.bn-badge--hate    { background: #7f1d1d; color: #fff; }
.bn-badge--harass  { background: var(--red-bg); color: #991b1b; }
.bn-badge--spam    { background: var(--amber-bg); color: #92400e; }
.bn-badge--other   { background: var(--bg-hover); color: var(--text-2); }
.bn-badge--count   { background: var(--red-bg); color: #991b1b; }

/* Card body */
.bn-rc-body { padding: 14px var(--s4); }
.bn-content-preview {
	background: var(--bg-subtle);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
	padding: var(--s2) var(--s3);
	font-size: var(--text-sm);
	color: var(--text-2);
	line-height: 1.5;
	margin-bottom: var(--s3);
}
.bn-content-flagged {
	background: var(--red-bg);
	padding: 0 2px;
	border-radius: 2px;
	color: var(--red);
}
.bn-report-reason {
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-bottom: var(--s3);
}
.bn-report-reason strong { color: var(--text-1); }
.bn-reporters {
	display: flex;
	align-items: center;
	gap: var(--s2);
	font-size: var(--text-xs);
	color: var(--text-2);
	margin-bottom: 14px;
}
.bn-reporters-stack { display: flex; }
.bn-reporter-ava {
	width: 20px; height: 20px; border-radius: 50%;
	font-size: 8px; font-weight: 700; color: #fff;
	display: flex; align-items: center; justify-content: center;
	margin-left: -4px;
	border: 2px solid var(--surface);
}
.bn-reporter-ava:first-child { margin-left: 0; }
.bn-context-link { color: var(--brand); cursor: pointer; text-decoration: underline; font-weight: 600; }

/* Actions */
.bn-rc-actions { display: flex; gap: var(--s2); flex-wrap: wrap; }
.bn-action-btn {
	padding: 7px 14px;
	border-radius: var(--radius-sm);
	font-size: var(--text-xs);
	font-weight: 600;
	cursor: pointer;
	border: 1.5px solid;
	display: inline-flex;
	align-items: center;
	gap: 4px;
	transition: opacity 0.1s;
}
.bn-action-btn:hover { opacity: 0.85; }
.bn-action-btn--view    { border-color: var(--brand); background: var(--brand-light); color: var(--brand); }
.bn-action-btn--dismiss { border-color: var(--border); background: var(--surface); color: var(--text-2); }
.bn-action-btn--remove  { border-color: #fca5a5; background: var(--red-bg); color: var(--red); }
.bn-action-btn--warn    { border-color: #fde68a; background: var(--amber-bg); color: #92400e; }
.bn-action-btn--strike  { border-color: var(--orange); background: var(--orange-bg); color: var(--orange); }
.bn-action-btn--suspend { border-color: var(--red); background: var(--red); color: #fff; }

/* ── Empty state ── */
.bn-mod-empty {
	text-align: center;
	padding: var(--s8);
	color: var(--text-3);
	font-size: var(--text-sm);
}
.bn-mod-empty-icon { font-size: 40px; display: block; margin-bottom: var(--s3); }

/* ── Responsive ── */
@media ( max-width: 1024px ) {
	.bn-mod-stats { grid-template-columns: repeat(3, 1fr); }
}
@media ( max-width: 640px ) {
	.bn-mod-shell { padding: var(--s4) var(--s3); }
	.bn-mod-stats { grid-template-columns: repeat(2, 1fr); }
	.bn-mod-filter-bar { gap: var(--s1); }
	.bn-rc-actions { gap: 6px; }
	.bn-action-btn { padding: 6px var(--s2); font-size: 11px; }
	.bn-rc-header { flex-wrap: wrap; gap: var(--s2); }
}
</style>

<div class="bn-mod-shell"
	data-wp-interactive="buddynext/moderation"
	data-wp-context='{"nonce":"<?php echo esc_attr( $mod_nonce ); ?>","restBase":"<?php echo esc_attr( rest_url( 'buddynext/v1/moderation' ) ); ?>"}'>

	<h1 class="bn-mod-page-title">&#x1F6E1;&#xFE0F; <?php esc_html_e( 'Moderation Queue', 'buddynext' ); ?></h1>
	<div class="bn-mod-page-sub"><?php esc_html_e( 'Review and act on reported content', 'buddynext' ); ?></div>

	<!-- Stats row -->
	<div class="bn-mod-stats">
		<div class="bn-stat-card bn-stat-card--urgent">
			<div class="bn-stat-num"><?php echo esc_html( (string) $urgent_count ); ?></div>
			<div class="bn-stat-label"><?php esc_html_e( 'Urgent reports', 'buddynext' ); ?></div>
		</div>
		<div class="bn-stat-card bn-stat-card--pending">
			<div class="bn-stat-num"><?php echo esc_html( (string) $pending_count ); ?></div>
			<div class="bn-stat-label"><?php esc_html_e( 'Pending review', 'buddynext' ); ?></div>
		</div>
		<div class="bn-stat-card bn-stat-card--resolved">
			<div class="bn-stat-num"><?php echo esc_html( (string) $resolved_today ); ?></div>
			<div class="bn-stat-label"><?php esc_html_e( 'Resolved today', 'buddynext' ); ?></div>
		</div>
		<div class="bn-stat-card">
			<div class="bn-stat-num"><?php echo esc_html( (string) $total_all_time ); ?></div>
			<div class="bn-stat-label"><?php esc_html_e( 'Total all time', 'buddynext' ); ?></div>
		</div>
		<div class="bn-stat-card bn-stat-card--suspended">
			<div class="bn-stat-num"><?php echo esc_html( (string) $suspended_count ); ?></div>
			<div class="bn-stat-label"><?php esc_html_e( 'Suspended users', 'buddynext' ); ?></div>
		</div>
	</div>

	<!-- Filter bar -->
	<div class="bn-mod-filter-bar">
		<?php
		$type_filters = [
			'all'     => sprintf(
				/* translators: %d is total pending reports. */
				__( 'All (%d)', 'buddynext' ),
				$pending_count
			),
			'urgent'  => sprintf(
				/* translators: %d is urgent count. */
				__( 'Urgent (%d)', 'buddynext' ),
				$urgent_count
			),
			'post'    => __( 'Posts', 'buddynext' ),
			'comment' => __( 'Comments', 'buddynext' ),
			'message' => __( 'DMs', 'buddynext' ),
			'user'    => __( 'Profiles', 'buddynext' ),
		];
		foreach ( $type_filters as $fkey => $flabel ) :
			$is_active = ( 'urgent' === $fkey )
				? ( 'urgent' === $filter_urgency )
				: ( $fkey === $filter_type && 'all' !== $filter_urgency && 'urgent' !== $fkey ? false : $fkey === $filter_type );
			// Correct active detection: filter_type tab or urgency=urgent tab.
			if ( 'urgent' === $fkey ) {
				$is_active = ( 'urgent' === $filter_urgency );
			} else {
				$is_active = ( $fkey === $filter_type && 'all' === $filter_urgency );
			}
			$fhref = ( 'urgent' === $fkey )
				? esc_url(
					add_query_arg(
						[
							'type'    => 'all',
							'urgency' => 'urgent',
							'sort'    => $sort_by,
						]
					)
				)
				: esc_url(
					add_query_arg(
						[
							'type'    => $fkey,
							'urgency' => 'all',
							'sort'    => $sort_by,
						]
					)
				);
			?>
			<a href="<?php echo $fhref; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped. ?>"
				class="bn-filter-btn<?php echo $is_active ? ' bn-filter-btn--active' : ''; ?>">
				<?php echo esc_html( $flabel ); ?>
			</a>
		<?php endforeach; ?>

		<!-- Sort select — submit via JS action -->
		<select class="bn-filter-select"
			data-wp-on--change="actions.applySort"
			aria-label="<?php esc_attr_e( 'Sort reports', 'buddynext' ); ?>">
			<option value="newest" <?php selected( $sort_by, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
			<option value="most_reported" <?php selected( $sort_by, 'most_reported' ); ?>><?php esc_html_e( 'Most reported', 'buddynext' ); ?></option>
		</select>
	</div>

	<!-- Report cards -->
	<?php if ( empty( $reports ) ) : ?>
		<div class="bn-mod-empty">
			<span class="bn-mod-empty-icon" aria-hidden="true">&#x2705;</span>
			<?php esc_html_e( 'No pending reports matching the current filter.', 'buddynext' ); ?>
		</div>
	<?php else : ?>
		<?php
		foreach ( $reports as $report ) :
			$report_id     = (int) $report->id;
			$obj_id        = (int) $report->object_id;
			$obj_type      = $report->object_type;
			$reason        = $report->reason ?? '';
			$report_count  = (int) ( $report->report_count ?? 1 );
			$strikes_count = (int) ( $report->strikes_count ?? 0 );
			$is_suspended  = (bool) ( $report->suspended ?? false );
			$created_at    = $report->created_at ?? '';

			$severity = $severity_class( $report_count, $reason );
			$rbadge   = $reason_badge( $reason );

			// Determine offender identity.
			if ( 'user' === $obj_type ) {
				$offender_user = get_userdata( $obj_id );
				$offender_name = $offender_user ? $offender_user->display_name : __( 'Unknown User', 'buddynext' );
				$joined_date   = $offender_user ? human_time_diff( (int) strtotime( $offender_user->user_registered ), time() ) . ' ' . __( 'ago', 'buddynext' ) : '';
			} else {
				$offender_name = __( 'Reported Content', 'buddynext' );
				$joined_date   = '';
			}
			$offender_inits = strtoupper( substr( $offender_name, 0, 1 ) . substr( strrchr( $offender_name, ' ' ), 1, 1 ) );

			// Content preview snippet.
			$content_excerpt = '';
			if ( in_array( $obj_type, [ 'post', 'comment' ], true ) && isset( $post_excerpts[ $obj_id ] ) ) {
				$content_excerpt = substr( $post_excerpts[ $obj_id ]['content'], 0, 200 );
			} elseif ( 'space' === $obj_type ) {
				$content_excerpt = $space_names[ $obj_id ] ?? __( 'Space content', 'buddynext' );
			} elseif ( 'user' === $obj_type ) {
				$content_excerpt = __( 'User profile reported.', 'buddynext' );
			} elseif ( 'message' === $obj_type ) {
				$content_excerpt = __( 'Private message — content not shown to protect privacy.', 'buddynext' );
			}

			// Time ago.
			$time_diff = $created_at ? human_time_diff( (int) strtotime( $created_at ), time() ) . ' ' . __( 'ago', 'buddynext' ) : '';

			// Gather reporter user IDs (simplified — the row has reporter_id).
			$reporter_id    = (int) $report->reporter_id;
			$reporter_user  = get_userdata( $reporter_id );
			$reporter_inits = $reporter_user ? strtoupper( substr( $reporter_user->display_name, 0, 1 ) . substr( strrchr( $reporter_user->display_name, ' ' ), 1, 1 ) ) : '?';
			?>
			<div class="bn-report-card bn-report-card--<?php echo esc_attr( $severity ); ?>"
				data-report-id="<?php echo esc_attr( (string) $report_id ); ?>">

				<!-- Header -->
				<div class="bn-rc-header">
					<div class="bn-rc-ava" style="background:<?php echo esc_attr( $avatar_color( $obj_id ) ); ?>;">
						<?php echo esc_html( $offender_inits ); ?>
					</div>
					<div class="bn-rc-who">
						<div class="bn-rc-name"><?php echo esc_html( $offender_name ); ?></div>
						<div class="bn-rc-meta">
							<?php if ( $joined_date ) : ?>
								<?php
								// translators: %s is the time since joining.
								echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), $joined_date ) );
								?>
								&nbsp;&middot;&nbsp;
							<?php endif; ?>
							<?php if ( $strikes_count > 0 ) : ?>
								<?php
								// translators: %d is the number of strikes.
								echo esc_html( sprintf( _n( '%d strike', '%d strikes', $strikes_count, 'buddynext' ), $strikes_count ) );
								?>
							<?php endif; ?>
							<?php if ( $is_suspended ) : ?>
								&nbsp;<strong style="color:var(--red);"><?php esc_html_e( 'Suspended', 'buddynext' ); ?></strong>
							<?php endif; ?>
						</div>
					</div>

					<span class="bn-badge <?php echo esc_attr( $rbadge['class'] ); ?>">
						<?php echo esc_html( $rbadge['label'] ); ?>
					</span>

					<span class="bn-rc-time"><?php echo esc_html( $time_diff ); ?></span>

					<?php if ( $report_count > 1 ) : ?>
						<span class="bn-badge bn-badge--count">
							<?php
							// translators: %d is the number of reports.
							echo esc_html( sprintf( _n( '%d report', '%d reports', $report_count, 'buddynext' ), $report_count ) );
							?>
						</span>
					<?php endif; ?>
				</div>

				<!-- Body -->
				<div class="bn-rc-body">

					<!-- Content preview -->
					<?php if ( $content_excerpt ) : ?>
						<div class="bn-content-preview">
							<?php echo wp_kses_post( $content_excerpt ); ?>
						</div>
					<?php endif; ?>

					<!-- Reason -->
					<div class="bn-report-reason">
						<strong><?php esc_html_e( 'Reported for:', 'buddynext' ); ?></strong>
						<?php echo esc_html( $reason ); ?>
					</div>

					<!-- Reporters stack -->
					<div class="bn-reporters">
						<div class="bn-reporters-stack">
							<div class="bn-reporter-ava" style="background:<?php echo esc_attr( $avatar_color( $reporter_id ) ); ?>;">
								<?php echo esc_html( $reporter_inits ); ?>
							</div>
						</div>
						<?php if ( $report_count > 1 ) : ?>
							<?php
							// translators: %d is the number of members who reported.
							echo esc_html( sprintf( _n( '%d member reported this', '%d members reported this', $report_count, 'buddynext' ), $report_count ) );
							?>
						<?php else : ?>
							<?php esc_html_e( '1 member reported this', 'buddynext' ); ?>
						<?php endif; ?>
						&nbsp;&middot;&nbsp;
						<button class="bn-context-link"
							data-wp-on--click="actions.viewInContext"
							data-object-type="<?php echo esc_attr( $obj_type ); ?>"
							data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
							<?php esc_html_e( 'View in context', 'buddynext' ); ?>
						</button>
					</div>

					<!-- Action buttons -->
					<div class="bn-rc-actions">
						<button class="bn-action-btn bn-action-btn--view"
							data-wp-on--click="actions.viewObject"
							data-object-type="<?php echo esc_attr( $obj_type ); ?>"
							data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
							<?php
							// translators: %s is the object type (post, comment, user, etc.).
							echo esc_html( sprintf( __( 'View %s', 'buddynext' ), $obj_type ) );
							?>
						</button>

						<button class="bn-action-btn bn-action-btn--dismiss"
							data-wp-on--click="actions.dismiss"
							data-report-id="<?php echo esc_attr( (string) $report_id ); ?>">
							&#x2713; <?php esc_html_e( 'Dismiss', 'buddynext' ); ?>
						</button>

						<?php if ( in_array( $obj_type, [ 'post', 'comment', 'message' ], true ) ) : ?>
							<button class="bn-action-btn bn-action-btn--remove"
								data-wp-on--click="actions.removeContent"
								data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
								data-object-type="<?php echo esc_attr( $obj_type ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								&#x1F5D1; <?php esc_html_e( 'Remove content', 'buddynext' ); ?>
							</button>
						<?php endif; ?>

						<button class="bn-action-btn bn-action-btn--warn"
							data-wp-on--click="actions.warnUser"
							data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
							data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
							&#x26A0;&#xFE0F; <?php esc_html_e( 'Warn user', 'buddynext' ); ?>
						</button>

						<?php if ( buddynext_can( $current_user_id, 'buddynext-moderation/issue-strike' ) ) : ?>
							<button class="bn-action-btn bn-action-btn--strike"
								data-wp-on--click="actions.strikeUser"
								data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								&#x26A1; <?php esc_html_e( 'Strike user', 'buddynext' ); ?>
							</button>
						<?php endif; ?>

						<?php if ( buddynext_can( $current_user_id, 'buddynext-moderation/suspend-user' ) ) : ?>
							<button class="bn-action-btn bn-action-btn--suspend"
								data-wp-on--click="actions.suspendUser"
								data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								&#x1F6AB; <?php esc_html_e( 'Suspend account', 'buddynext' ); ?>
							</button>
						<?php endif; ?>
					</div>

				</div><!-- /rc-body -->
			</div><!-- /report-card -->
		<?php endforeach; ?>
	<?php endif; // End: empty reports check. ?>

</div>
