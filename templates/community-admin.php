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

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! current_user_can( 'manage_options' ) && ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) {
	wp_die( esc_html__( 'You do not have permission to access the Community Admin Panel.', 'buddynext' ) );
}

$current_user_id = get_current_user_id();
$bn_admin_user   = get_userdata( $current_user_id );

// ── Active section ────────────────────────────────────────────────────────────

$admin_section = isset( $_GET['bn_admin'] ) ? sanitize_key( wp_unslash( $_GET['bn_admin'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$admin_base    = buddynext_community_admin_url();

// ── Platform stats (consolidated via AdminHub::overview_stats) ─────────────────

$bn_ca_stats         = \BuddyNext\Admin\AdminHub::overview_stats();
$total_members       = (int) $bn_ca_stats['members_total'];
$new_today           = (int) $bn_ca_stats['members_new_today'];
$active_spaces       = (int) $bn_ca_stats['active_spaces'];
$open_reports        = (int) $bn_ca_stats['open_reports'];
$urgent_reports      = (int) $bn_ca_stats['urgent_reports'];
$posts_today         = (int) $bn_ca_stats['posts_today'];
$posts_pct           = (int) $bn_ca_stats['posts_pct'];
$total_pending_joins = (int) $bn_ca_stats['pending_joins'];

// ── Recent signups (WP-native, registration-ordered) ──────────────────────────

$recent_signups = get_users(
	array(
		'orderby' => 'registered',
		'order'   => 'DESC',
		'number'  => 10,
		'fields'  => array( 'ID', 'display_name', 'user_email', 'user_registered' ),
	)
);

// ── Pending space join requests (cross-space) ─────────────────────────────────

$bn_ca_spaces  = buddynext_service( 'spaces' );
$pending_joins = $bn_ca_spaces->get_pending_join_requests_all( 10 );

// ── Pending moderation appeals (suspension appeals awaiting review) ───────────

$bn_ca_mod             = buddynext_service( 'moderation' );
$pending_appeals       = $bn_ca_mod->get_pending_appeals( 25 );
$total_pending_appeals = $bn_ca_mod->count_pending_appeals();

// Batch-resolve appellant display names + the suspensions being contested so the
// review surface shows full context in two queries, never one query per row.
$bn_appeal_user_ids = array();
$bn_appeal_susp_ids = array();
foreach ( $pending_appeals as $bn_ca_ap ) {
	$bn_appeal_user_ids[] = (int) $bn_ca_ap['user_id'];
	$bn_appeal_susp_ids[] = (int) $bn_ca_ap['suspension_id'];
}
$bn_appeal_names = array();
if ( ! empty( $bn_appeal_user_ids ) ) {
	foreach ( get_users(
		array(
			'include' => array_values( array_unique( $bn_appeal_user_ids ) ),
			'fields'  => array( 'ID', 'display_name' ),
		)
	) as $bn_ca_u ) {
		$bn_appeal_names[ (int) $bn_ca_u->ID ] = (string) $bn_ca_u->display_name;
	}
}
$bn_appeal_susps = $bn_ca_mod->get_suspensions_by_ids( $bn_appeal_susp_ids );

// ── Open reports (cross-space, consolidated per content group) ─────────────────

$bn_ca_queue = $bn_ca_mod->get_queue(
	array(
		'per_page' => 10,
		'page'     => 1,
	)
);
$report_rows = $bn_ca_queue['items'];

// ── Recent activity log (site-wide) ──────────────────────────────────────────

$bn_ca_log     = buddynext_service( 'activity_log' );
$activity_rows = $bn_ca_log->recent( 20 );

// ── Helpers ───────────────────────────────────────────────────────────────────

if ( ! function_exists( 'bn_time_diff' ) ) {
	/**
	 * Human-readable time diff label (e.g. "3h ago").
	 *
	 * @param string $datetime MySQL datetime string.
	 * @return string Localized time diff.
	 */
	function bn_time_diff( string $datetime ): string {
		return sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( $datetime ), time() ) );
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

/**
 * Fires before the community-admin inner content.
 */
do_action( 'buddynext_community_admin_before' );
?>

<?php
// Active section tab. Default 'reports' as the most action-oriented stream.
$allowed_sections = array( 'reports', 'mod_appeals', 'appeals', 'strikes', 'actions' );
$active_section   = isset( $_GET['bn_section'] ) ? sanitize_key( wp_unslash( $_GET['bn_section'] ) ) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $active_section, $allowed_sections, true ) ) {
	$active_section = 'reports';
}

$posts_pct_abs = abs( $posts_pct );
?>

<div class="bn-ca" data-wp-interactive="buddynext/spaces">

	<!-- Admin subheader -->
	<div class="bn-ca-subheader" role="banner">
		<span class="bn-ca-subheader__title">
			<?php buddynext_icon( 'shield' ); ?>
			<?php esc_html_e( 'Community Admin Panel', 'buddynext' ); ?>
		</span>
		<span class="bn-ca-subheader__role"><?php esc_html_e( 'Community Manager', 'buddynext' ); ?></span>
		<span class="bn-ca-subheader__site">
			<?php
			printf(
				/* translators: %s: site name. */
				esc_html__( 'Managing: %s community', 'buddynext' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</span>
		<div class="bn-ca-subheader__actions">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bn-ca-subheader__link" data-emphasis="primary">
				<?php buddynext_icon( 'chevron-left' ); ?>
				<?php esc_html_e( 'Back to community', 'buddynext' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url() ); ?>" class="bn-ca-subheader__link">
				<?php esc_html_e( 'WP Admin', 'buddynext' ); ?>
			</a>
		</div>
	</div>

	<div class="bn-ca-wrap">

		<!-- Sidebar -->
		<aside class="bn-ca-sidebar" aria-label="<?php esc_attr_e( 'Admin navigation', 'buddynext' ); ?>">
			<div class="bn-ca-sidebar__card">
				<div class="bn-ca-sidebar__heading"><?php esc_html_e( 'Admin', 'buddynext' ); ?></div>

				<?php
				$nav_items = array(
					'overview'   => array(
						'icon'  => 'bar-chart',
						'label' => __( 'Overview', 'buddynext' ),
					),
					'members'    => array(
						'icon'  => 'users',
						'label' => __( 'Members', 'buddynext' ),
					),
					'spaces'     => array(
						'icon'  => 'home',
						'label' => __( 'Spaces', 'buddynext' ),
					),
					'moderation' => array(
						'icon'  => 'shield',
						'label' => __( 'Moderation', 'buddynext' ),
						'badge' => $open_reports,
					),
					'reports'    => array(
						'icon'  => 'copy',
						'label' => __( 'Reports', 'buddynext' ),
					),
					'invites'    => array(
						'icon'  => 'mail',
						'label' => __( 'Email invites', 'buddynext' ),
					),
					'settings'   => array(
						'icon'  => 'settings',
						'label' => __( 'Settings', 'buddynext' ),
					),
				);
				foreach ( $nav_items as $key => $item ) :
					$is_active = ( $admin_section === $key );
					?>
					<a
						href="<?php echo esc_url( add_query_arg( 'bn_admin', $key, $admin_base ) ); ?>"
						class="bn-ca-nav-item"
						<?php echo $is_active ? 'aria-current="page"' : ''; ?>
					>
						<span class="bn-ca-nav-item__icon" aria-hidden="true"><?php buddynext_icon( $item['icon'] ); ?></span>
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! empty( $item['badge'] ) && (int) $item['badge'] > 0 ) : ?>
							<span class="bn-ca-nav-item__badge"><?php echo esc_html( number_format_i18n( (int) $item['badge'] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>

				<div class="bn-ca-nav-divider" role="presentation"></div>

				<a href="<?php echo esc_url( admin_url() ); ?>" class="bn-ca-nav-item" data-external>
					<span class="bn-ca-nav-item__icon" aria-hidden="true"><?php buddynext_icon( 'link' ); ?></span>
					<?php esc_html_e( 'WordPress admin', 'buddynext' ); ?>
				</a>

				<p class="bn-ca-sidebar__note">
					<?php esc_html_e( 'Space admins only see their own space in this panel.', 'buddynext' ); ?>
				</p>
			</div>
		</aside>

		<!-- Main content -->
		<main class="bn-ca-main">

			<!-- Stats grid — .bn-stat-grid primitive -->
			<div class="bn-stat-grid" role="list" aria-label="<?php esc_attr_e( 'Community metrics', 'buddynext' ); ?>">

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Members', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></div>
					<div class="bn-stat__delta" data-trend="up">
						<?php buddynext_icon( 'arrow-up' ); ?>
						<span>
							<?php
							printf(
								/* translators: %s: number of new members today (already i18n-formatted). */
								esc_html__( '%s new today', 'buddynext' ),
								esc_html( number_format_i18n( $new_today ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Active spaces', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $active_spaces ) ); ?></div>
					<div class="bn-stat__delta" data-trend="flat"><?php esc_html_e( 'across the community', 'buddynext' ); ?></div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Open reports', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $open_reports ) ); ?></div>
					<div class="bn-stat__delta" data-trend="<?php echo $urgent_reports > 0 ? 'down' : 'flat'; ?>">
						<span class="bn-ca-status-dot" data-severity="<?php echo $urgent_reports > 0 ? 'high' : 'low'; ?>" aria-hidden="true"></span>
						<span>
							<?php
							printf(
								/* translators: %s: number of urgent reports. */
								esc_html__( '%s urgent', 'buddynext' ),
								esc_html( number_format_i18n( $urgent_reports ) )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Posts today', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $posts_today ) ); ?></div>
					<div class="bn-stat__delta" data-trend="<?php echo $posts_pct >= 0 ? 'up' : 'down'; ?>">
						<?php
						if ( $posts_pct >= 0 ) {
							buddynext_icon( 'arrow-up' );
						} else {
							buddynext_icon( 'arrow-down' );
						}
						?>
						<span>
							<?php
							printf(
								/* translators: %d: percentage change vs yesterday. */
								esc_html__( '%d%% vs yesterday', 'buddynext' ),
								absint( $posts_pct_abs )
							);
							?>
						</span>
					</div>
				</div>

				<div class="bn-stat" role="listitem">
					<div class="bn-stat__label"><?php esc_html_e( 'Pending joins', 'buddynext' ); ?></div>
					<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $total_pending_joins ) ); ?></div>
					<div class="bn-stat__delta" data-trend="flat">
						<?php esc_html_e( 'invite-only spaces', 'buddynext' ); ?>
					</div>
				</div>

			</div>

			<!-- Section tabs — Reports / Pending joins / Recent activity -->
			<nav class="bn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Admin sections', 'buddynext' ); ?>">
				<?php
				$sections = array(
					'reports'     => array(
						'label' => __( 'Reports', 'buddynext' ),
						'count' => (int) $open_reports,
					),
					'mod_appeals' => array(
						'label' => __( 'Appeals', 'buddynext' ),
						'count' => (int) $total_pending_appeals,
					),
					'appeals'     => array(
						'label' => __( 'Pending joins', 'buddynext' ),
						'count' => (int) $total_pending_joins,
					),
					'strikes'     => array(
						'label' => __( 'Recent signups', 'buddynext' ),
						'count' => is_countable( $recent_signups ) ? count( $recent_signups ) : 0,
					),
					'actions'     => array(
						'label' => __( 'Recent actions', 'buddynext' ),
						'count' => is_countable( $activity_rows ) ? count( $activity_rows ) : 0,
					),
				);
				foreach ( $sections as $skey => $sdata ) :
					$is_active = ( $active_section === $skey );
					$shref     = add_query_arg( 'bn_section', $skey );
					?>
					<a
						href="<?php echo esc_url( $shref ); ?>"
						class="bn-tab"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					>
						<?php echo esc_html( $sdata['label'] ); ?>
						<span class="bn-tab__count"><?php echo esc_html( number_format_i18n( (int) $sdata['count'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'reports' === $active_section ) : ?>

				<!-- Open reports -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-reports-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-reports-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'shield' ); ?>
							<?php esc_html_e( 'Open reports', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $open_reports ) ); ?></span>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View all', 'buddynext' ); ?>
						</a>
					</header>

					<?php if ( empty( $report_rows ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No open reports.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php
						$displayed   = 0;
						$show_limit  = 5;
						$extra_count = max( 0, count( $report_rows ) - $show_limit );
						?>
						<?php foreach ( $report_rows as $rpt ) : ?>
							<?php
							if ( $displayed >= $show_limit ) {
								break;
							}
							$rpt_count    = (int) ( $rpt['reporter_count'] ?? 1 );
							$rpt_severity = bn_report_severity( $rpt_count );
							$rpt_reason   = ucfirst( (string) ( $rpt['reason'] ?? __( 'Report', 'buddynext' ) ) );
							$rpt_ts       = ! empty( $rpt['created_at'] ) ? (int) strtotime( (string) $rpt['created_at'] . ' UTC' ) : 0;
							$rpt_iso      = $rpt_ts ? gmdate( DATE_ATOM, $rpt_ts ) : '';
							$rpt_time     = $rpt_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( $rpt_ts, time() ) ) : '';
							++$displayed;
							?>
							<div class="bn-ca-report-row" data-severity="<?php echo esc_attr( $rpt_severity ); ?>">
								<div class="bn-ca-report-row__body">
									<div class="bn-ca-report-row__type">
										<span class="bn-ca-status-dot" data-severity="<?php echo esc_attr( $rpt_severity ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( $rpt_reason ); ?>
									</div>
									<div class="bn-ca-report-row__meta">
										<?php
										printf(
											/* translators: 1: number of reporters, 2: time-ago string. */
											esc_html( _n( '%1$s reporter - %2$s', '%1$s reporters - %2$s', (int) $rpt_count, 'buddynext' ) ),
											esc_html( number_format_i18n( $rpt_count ) ),
											esc_html( $rpt_time )
										);
										?>
									</div>
								</div>
								<?php if ( $rpt_iso ) : ?>
									<time class="bn-ca-row__time" datetime="<?php echo esc_attr( $rpt_iso ); ?>"><?php echo esc_html( $rpt_time ); ?></time>
								<?php endif; ?>
								<a
									href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'bn_admin' => 'reports',
												'bn_report_id' => (int) $rpt['id'],
											),
											$admin_base
										)
									);
									?>
									"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
								><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
							</div>
						<?php endforeach; ?>

						<?php if ( $extra_count > 0 ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>" class="bn-ca-card__more">
								<?php
								printf(
									/* translators: %s: number of additional reports. */
									esc_html__( '+ %s more', 'buddynext' ),
									esc_html( number_format_i18n( $extra_count ) )
								);
								?>
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'mod_appeals' === $active_section ) : ?>

				<!-- Suspension appeals awaiting review -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-appeals-title" data-wp-interactive="buddynext/moderation">
					<header class="bn-ca-card__head">
						<span id="bn-ca-appeals-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'message-circle' ); ?>
							<?php esc_html_e( 'Suspension appeals', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $total_pending_appeals ) ); ?></span>
						</span>
					</header>

					<?php if ( empty( $pending_appeals ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No appeals are waiting for review.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $pending_appeals as $bn_ca_ap ) : ?>
							<?php
							$ap_id     = (int) $bn_ca_ap['id'];
							$ap_uid    = (int) $bn_ca_ap['user_id'];
							$ap_name   = $bn_appeal_names[ $ap_uid ] ?? __( 'Member', 'buddynext' );
							$ap_msg    = trim( (string) $bn_ca_ap['message'] );
							$ap_when   = bn_time_diff( (string) $bn_ca_ap['created_at'] );
							$ap_susp   = $bn_appeal_susps[ (int) $bn_ca_ap['suspension_id'] ] ?? null;
							$ap_reason = $ap_susp ? trim( (string) $ap_susp['reason'] ) : '';
							$ap_ctx    = wp_json_encode(
								array(
									'appealId'  => $ap_id,
									'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1' ) ),
									'restNonce' => wp_create_nonce( 'wp_rest' ),
								)
							);
							?>
							<div class="bn-ca-appeal" data-appeal-id="<?php echo esc_attr( (string) $ap_id ); ?>" data-wp-context="<?php echo esc_attr( (string) $ap_ctx ); ?>">
								<div class="bn-ca-appeal__head">
									<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( \BuddyNext\Profile\AvatarService::initials_for( $ap_name ) ); ?></span>
									<div class="bn-ca-appeal__who">
										<a class="bn-ca-appeal__name" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $ap_uid ) ); ?>"><?php echo esc_html( $ap_name ); ?></a>
										<span class="bn-ca-appeal__meta"><?php echo esc_html( $ap_when ); ?></span>
									</div>
								</div>
								<?php if ( '' !== $ap_reason ) : ?>
									<p class="bn-ca-appeal__contesting">
										<span class="bn-ca-appeal__label"><?php esc_html_e( 'Suspended for', 'buddynext' ); ?></span>
										<?php echo esc_html( $ap_reason ); ?>
									</p>
								<?php endif; ?>
								<?php if ( '' !== $ap_msg ) : ?>
									<blockquote class="bn-ca-appeal__msg"><?php echo esc_html( $ap_msg ); ?></blockquote>
								<?php endif; ?>
								<div class="bn-ca-appeal__actions">
									<button
										type="button"
										class="bn-btn"
										data-variant="primary"
										data-size="sm"
										data-wp-on--click="actions.approveAppeal"
									><?php esc_html_e( 'Approve & lift suspension', 'buddynext' ); ?></button>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-wp-on--click="actions.denyAppeal"
									><?php esc_html_e( 'Deny', 'buddynext' ); ?></button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'appeals' === $active_section ) : ?>

				<!-- Pending join requests -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-joins-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-joins-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'mail' ); ?>
							<?php esc_html_e( 'Space join requests', 'buddynext' ); ?>
							<span class="bn-ca-card__count"><?php echo esc_html( number_format_i18n( (int) $total_pending_joins ) ); ?></span>
						</span>
					</header>

					<?php if ( empty( $pending_joins ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No pending join requests.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $pending_joins as $join ) : ?>
							<?php
							$j_uid    = (int) $join['user_id'];
							$j_sid    = (int) $join['space_id'];
							$j_member = '' !== (string) ( $join['member_name'] ?? '' ) ? (string) $join['member_name'] : __( 'Member', 'buddynext' );
							$j_space  = '' !== (string) ( $join['space_name'] ?? '' ) ? (string) $join['space_name'] : __( 'Space', 'buddynext' );
							$j_inits  = \BuddyNext\Profile\AvatarService::initials_for( $j_member );
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( $j_inits ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $j_space ); ?></span>
									<span class="bn-ca-row__sub">
										<?php
										printf(
											/* translators: %s: member display name. */
											esc_html__( '%s wants to join', 'buddynext' ),
											esc_html( $j_member )
										);
										?>
									</span>
								</div>
								<div class="bn-ca-row__actions">
									<button
										type="button"
										class="bn-btn"
										data-variant="primary"
										data-size="sm"
										data-wp-on--click="actions.approveJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
									<button
										type="button"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
										data-wp-on--click="actions.declineJoinRequest"
										data-user-id="<?php echo esc_attr( (string) $j_uid ); ?>"
										data-space-id="<?php echo esc_attr( (string) $j_sid ); ?>"
									><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php elseif ( 'strikes' === $active_section ) : ?>

				<!-- Recent signups -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-signups-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-signups-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'users' ); ?>
							<?php esc_html_e( 'Recent signups', 'buddynext' ); ?>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'members', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View all members', 'buddynext' ); ?>
						</a>
					</header>

					<?php if ( empty( $recent_signups ) ) : ?>
						<p class="bn-ca-card__empty"><?php esc_html_e( 'No signups yet.', 'buddynext' ); ?></p>
					<?php else : ?>
						<?php foreach ( $recent_signups as $signup ) : ?>
							<?php
							$su_uid   = (int) $signup->ID;
							$su_name  = '' !== (string) ( $signup->display_name ?? '' ) ? (string) $signup->display_name : __( 'Member', 'buddynext' );
							$su_init  = \BuddyNext\Profile\AvatarService::initials_for( $su_name );
							$su_email = (string) ( $signup->user_email ?? '' );
							$su_ts    = isset( $signup->user_registered ) ? (int) strtotime( (string) $signup->user_registered ) : 0;
							$su_iso   = $su_ts ? gmdate( DATE_ATOM, $su_ts ) : '';
							$su_time  = $su_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( $su_ts, time() ) ) : '';
							?>
							<div class="bn-ca-row">
								<span class="bn-avatar" data-size="sm" aria-label="<?php echo esc_attr( $su_name ); ?>"><?php echo esc_html( $su_init ); ?></span>
								<div class="bn-ca-row__body">
									<span class="bn-ca-row__title"><?php echo esc_html( $su_name ); ?></span>
									<span class="bn-ca-row__sub"><?php echo esc_html( $su_email ); ?></span>
								</div>
								<?php if ( $su_iso ) : ?>
									<time class="bn-ca-row__time" datetime="<?php echo esc_attr( $su_iso ); ?>"><?php echo esc_html( $su_time ); ?></time>
								<?php endif; ?>
								<div class="bn-ca-row__actions">
									<a
										href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $su_uid ) ); ?>"
										class="bn-btn"
										data-variant="ghost"
										data-size="sm"
									><?php esc_html_e( 'View profile', 'buddynext' ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</section>

			<?php else : // 'actions' section. ?>

				<!-- Recent activity log -->
				<section class="bn-ca-card" aria-labelledby="bn-ca-actions-title">
					<header class="bn-ca-card__head">
						<span id="bn-ca-actions-title" class="bn-ca-card__title">
							<?php buddynext_icon( 'copy' ); ?>
							<?php esc_html_e( 'Recent actions', 'buddynext' ); ?>
						</span>
						<a href="<?php echo esc_url( add_query_arg( 'bn_admin', 'log', $admin_base ) ); ?>" class="bn-ca-card__link">
							<?php esc_html_e( 'View full log', 'buddynext' ); ?>
						</a>
					</header>

					<div class="bn-ca-activity-scroll" role="log" aria-label="<?php esc_attr_e( 'Recent site activity', 'buddynext' ); ?>">
						<?php if ( empty( $activity_rows ) ) : ?>
							<p class="bn-ca-card__empty"><?php esc_html_e( 'No recent activity.', 'buddynext' ); ?></p>
						<?php else : ?>
							<?php foreach ( $activity_rows as $act ) : ?>
								<?php
								$act_action = '' !== (string) ( $act['action'] ?? '' ) ? (string) $act['action'] : 'note';
								$act_icon   = bn_activity_icon( $act_action );
								$act_type   = (string) ( $act['object_type'] ?? '' );
								$act_desc   = '' !== (string) ( $act['action'] ?? '' )
									? ucfirst( str_replace( '_', ' ', (string) $act['action'] ) ) . ( '' !== $act_type ? ' (' . $act_type . ')' : '' )
									: '';
								$act_ts     = ! empty( $act['created_at'] ) ? (int) strtotime( (string) $act['created_at'] ) : 0;
								$act_iso    = $act_ts ? gmdate( DATE_ATOM, $act_ts ) : '';
								$act_meta   = $act_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( $act_ts, time() ) ) : '';
								$act_report = ( 'post_flagged' === $act_action );
								?>
								<div class="bn-ca-activity-row">
									<span class="bn-ca-activity-row__icon" aria-hidden="true"><?php echo $act_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG via buddynext_get_icon(), already wp_kses() sanitized. ?></span>
									<div class="bn-ca-activity-row__body">
										<div class="bn-ca-activity-row__desc"><?php echo esc_html( $act_desc ); ?></div>
										<?php if ( $act_iso ) : ?>
											<time class="bn-ca-activity-row__meta" datetime="<?php echo esc_attr( $act_iso ); ?>"><?php echo esc_html( $act_meta ); ?></time>
										<?php else : ?>
											<div class="bn-ca-activity-row__meta"><?php echo esc_html( $act_meta ); ?></div>
										<?php endif; ?>
									</div>
									<?php if ( $act_report ) : ?>
										<div class="bn-ca-activity-row__action">
											<a
												href="<?php echo esc_url( add_query_arg( 'bn_admin', 'reports', $admin_base ) ); ?>"
												class="bn-btn"
												data-variant="secondary"
												data-size="sm"
											><?php esc_html_e( 'Review', 'buddynext' ); ?></a>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

			<?php endif; ?>

		</main>

	</div><!-- /.bn-ca-wrap -->

</div><!-- /.bn-ca -->
<?php
/**
 * Fires after the community-admin inner content.
 */
do_action( 'buddynext_community_admin_after' );
?>
