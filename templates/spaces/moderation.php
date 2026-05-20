<?php
/**
 * Template: Space Moderation Panel
 *
 * Shows open reports, pending member requests, removed content, and the
 * moderation activity log scoped to a single space. Only accessible to
 * space admins/moderators.
 *
 * Expected context var (set by template loader):
 *   $space_id (int) — the current space's primary key.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate', array( 'space_id' => $space_id ) ) ) {
	wp_die( esc_html__( 'You do not have permission to moderate this space.', 'buddynext' ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$space = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT id, name, slug, type, member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Active filter tab ─────────────────────────────────────────────────────────

$mod_tab = isset( $_GET['bn_mtab'] ) ? sanitize_key( wp_unslash( $_GET['bn_mtab'] ) ) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Stats ─────────────────────────────────────────────────────────────────────

// Open reports for this space.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$open_reports_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports WHERE space_id = %d AND status = 'pending'",
		$space_id
	)
);

// Pending member requests.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND status = 'pending'",
		$space_id
	)
);

// Members warned this week.
$week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$warned_this_week = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE space_id = %d AND action = 'warn' AND created_at >= %s",
		$space_id,
		$week_ago
	)
);

// Total actions all time.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$total_actions = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE space_id = %d",
		$space_id
	)
);

// ── Fetch open reports ────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$open_reports = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT r.*,
		u.display_name AS reported_user_name,
		( SELECT COUNT(*) FROM {$wpdb->prefix}bn_reports r2 WHERE r2.object_type = r.object_type AND r2.object_id = r.object_id AND r2.space_id = %d ) AS reporter_count,
		( SELECT COUNT(*) FROM {$wpdb->prefix}bn_user_strikes us WHERE us.user_id = r.object_id ) AS strike_count,
		sm.joined_at
		FROM {$wpdb->prefix}bn_reports r
		LEFT JOIN {$wpdb->users} u ON u.ID = r.object_id AND r.object_type = 'user'
		LEFT JOIN {$wpdb->prefix}bn_space_members sm ON sm.user_id = r.object_id AND sm.space_id = %d
		WHERE r.space_id = %d AND r.status = 'pending'
		ORDER BY reporter_count DESC, r.created_at DESC
		LIMIT 20",
		$space_id,
		$space_id,
		$space_id
	)
);

// ── Fetch pending members ─────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_members = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT sm.*, u.display_name, u.user_email
		FROM {$wpdb->prefix}bn_space_members sm
		INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
		WHERE sm.space_id = %d AND sm.status = 'pending'
		ORDER BY sm.joined_at ASC
		LIMIT 20",
		$space_id
	)
);

// ── Fetch moderation activity log ─────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$mod_log = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT ml.*, u.display_name AS moderator_name
		FROM {$wpdb->prefix}bn_mod_log ml
		LEFT JOIN {$wpdb->users} u ON u.ID = ml.actor_id
		WHERE ml.space_id = %d
		ORDER BY ml.created_at DESC
		LIMIT 20",
		$space_id
	)
);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return an SVG icon for a mod log action type.
 *
 * @param string $action Moderation action slug.
 * @return string SVG markup.
 */
function bn_mod_action_icon( string $action ): string {
	$map  = array(
		'dismiss'        => 'check-circle',
		'remove'         => 'trash',
		'warn'           => 'alert-triangle',
		'ban'            => 'ban',
		'approve_member' => 'check-circle',
		'decline_member' => 'x-circle',
		'remove_member'  => 'ban',
		'pin'            => 'bookmark',
		'unpin'          => 'bookmark',
	);
	$slug = $map[ $action ] ?? 'copy';
	return buddynext_get_icon( $slug );
}

/**
 * Return a report priority class based on reporter count.
 *
 * @param int $count Number of reporters.
 * @return string CSS modifier class ('high', 'medium', or 'low').
 */
function bn_report_priority( int $count ): string {
	if ( $count >= 3 ) {
		return 'high';
	} elseif ( $count >= 2 ) {
		return 'medium';
	}
	return 'low';
}

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

$space_url    = buddynext_space_url( $space->slug ?? '' );
$mod_base_url = buddynext_space_moderation_url( $space->slug ?? '' );
$member_fmt   = number_format_i18n( (int) $space->member_count );
$current_uid  = get_current_user_id();

$bn_nav_active = 'spaces';
buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => $bn_nav_active ) );
?>
<div
	class="bn-mod"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>

	<!-- Space context subheader -->
	<div class="bn-mod-subheader">
		<span class="bn-mod-subheader__icon" aria-hidden="true"><?php buddynext_icon( 'cpu' ); ?></span>
		<div class="bn-mod-subheader__info">
			<div class="bn-mod-subheader__name"><?php echo esc_html( $space->name ?? '' ); ?></div>
			<div class="bn-mod-subheader__meta">
				<?php echo esc_html( $member_fmt ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?>
				&middot; <?php echo esc_html( ucfirst( $space->type ?? '' ) ); ?>
			</div>
		</div>
		<span class="bn-mod-admin-badge"><?php esc_html_e( 'Space Admin', 'buddynext' ); ?></span>
		<div class="bn-mod-subheader__actions">
			<a href="<?php echo esc_url( $space_url ); ?>">&#x2190; <?php esc_html_e( 'Back to Space', 'buddynext' ); ?></a>
			<a href="<?php echo esc_url( buddynext_community_admin_url() ); ?>"><?php esc_html_e( 'Community Admin Panel', 'buddynext' ); ?></a>
		</div>
	</div>

	<!-- Space tab strip -->
	<nav class="bn-mod-tabs" aria-label="<?php esc_attr_e( 'Space tabs', 'buddynext' ); ?>">
		<a href="<?php echo esc_url( $space_url ); ?>" class="bn-mod-tab"><?php esc_html_e( 'Feed', 'buddynext' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'bn_tab', 'members', $space_url ) ); ?>" class="bn-mod-tab"><?php esc_html_e( 'Members', 'buddynext' ); ?></a>
		<a href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ?? '' ) ); ?>" class="bn-mod-tab"><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>
		<a href="<?php echo esc_url( $mod_base_url ); ?>" class="bn-mod-tab bn-mod-tab--active" aria-current="page"><?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Moderation', 'buddynext' ); ?></a>
	</nav>

	<!-- Main content -->
	<div class="bn-mod-shell">

		<!-- Page title row -->
		<div class="bn-mod-title-row">
			<div>
				<h1 class="bn-mod-title"><?php buddynext_icon( 'shield' ); ?>
				<?php
				// translators: %s is the space name.
				printf( esc_html__( 'Moderation &mdash; %s', 'buddynext' ), esc_html( $space->name ?? '' ) );
				?>
				</h1>
				<p class="bn-mod-subtitle"><?php esc_html_e( 'Reports and member actions scoped to this space only.', 'buddynext' ); ?></p>
			</div>
			<span class="bn-scope-badge"><?php esc_html_e( 'Space scope &middot; You cannot see reports from other spaces', 'buddynext' ); ?></span>
		</div>

		<!-- Stats row -->
		<div class="bn-mod-stats" role="list">
			<div class="bn-mod-stat" role="listitem">
				<div class="bn-mod-stat__num bn-mod-stat__num--red"><?php echo esc_html( (string) $open_reports_count ); ?></div>
				<div class="bn-mod-stat__label"><?php esc_html_e( 'Open Reports', 'buddynext' ); ?></div>
			</div>
			<div class="bn-mod-stat" role="listitem">
				<div class="bn-mod-stat__num bn-mod-stat__num--amber"><?php echo esc_html( (string) $pending_count ); ?></div>
				<div class="bn-mod-stat__label"><?php esc_html_e( 'Pending Member Requests', 'buddynext' ); ?></div>
			</div>
			<div class="bn-mod-stat" role="listitem">
				<div class="bn-mod-stat__num bn-mod-stat__num--orange"><?php echo esc_html( (string) $warned_this_week ); ?></div>
				<div class="bn-mod-stat__label"><?php esc_html_e( 'Members warned this week', 'buddynext' ); ?></div>
			</div>
			<div class="bn-mod-stat" role="listitem">
				<div class="bn-mod-stat__num bn-mod-stat__num--grey"><?php echo esc_html( (string) $total_actions ); ?></div>
				<div class="bn-mod-stat__label"><?php esc_html_e( 'Actions taken (all time)', 'buddynext' ); ?></div>
			</div>
		</div>

		<!-- Filter pills -->
		<nav class="bn-mod-filter" aria-label="<?php esc_attr_e( 'Moderation filter', 'buddynext' ); ?>">
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'reports', $mod_base_url ) ); ?>"
				class="bn-mod-pill<?php echo ( 'reports' === $mod_tab ) ? ' bn-mod-pill--active' : ''; ?>"
				aria-current="<?php echo ( 'reports' === $mod_tab ) ? 'page' : 'false'; ?>"
			>
				<?php
				// translators: %d is the open reports count.
				printf( esc_html__( 'Reports (%d)', 'buddynext' ), absint( $open_reports_count ) );
				?>
			</a>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'pending', $mod_base_url ) ); ?>"
				class="bn-mod-pill<?php echo ( 'pending' === $mod_tab ) ? ' bn-mod-pill--active' : ''; ?>"
			>
				<?php
				// translators: %d is the pending member count.
				printf( esc_html__( 'Pending Members (%d)', 'buddynext' ), absint( $pending_count ) );
				?>
			</a>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'log', $mod_base_url ) ); ?>"
				class="bn-mod-pill<?php echo ( 'log' === $mod_tab ) ? ' bn-mod-pill--active' : ''; ?>"
			><?php esc_html_e( 'Activity Log', 'buddynext' ); ?></a>
		</nav>

		<!-- Content + sidebar -->
		<div class="bn-mod-content">

			<main>

				<?php if ( 'reports' === $mod_tab ) : ?>

					<?php if ( empty( $open_reports ) ) : ?>
						<div class="bn-empty">
							<div class="bn-empty__icon"><?php buddynext_icon( 'check-circle' ); ?></div>
							<p class="bn-empty__title"><?php esc_html_e( 'No open reports', 'buddynext' ); ?></p>
							<p><?php esc_html_e( 'This space has no reports requiring attention.', 'buddynext' ); ?></p>
						</div>

					<?php else : ?>

						<?php foreach ( $open_reports as $report ) : ?>
							<?php
							$priority     = bn_report_priority( (int) ( $report->reporter_count ?? 1 ) );
							$reported_uid = (int) $report->object_id;
							$r_name       = $report->reported_user_name ?? __( 'Unknown', 'buddynext' );
							$r_init       = bn_initials( $r_name );
							$r_color      = bn_avatar_color( $reported_uid );
							$r_count      = (int) ( $report->reporter_count ?? 1 );
							$r_strikes    = (int) ( $report->strike_count ?? 0 );
							$r_reason     = $report->reason ?? '';
							$r_content    = $report->content_snapshot ?? '';
							$r_time       = isset( $report->created_at ) ? bn_time_diff( $report->created_at ) : '';
							?>
							<article class="bn-report-card bn-report-card--<?php echo esc_attr( $priority ); ?>">
								<div class="bn-rc-header">
									<div
										class="bn-rc-avatar"
										style="background:<?php echo esc_attr( $r_color ); ?>;"
										aria-label="<?php echo esc_attr( $r_name ); ?>"
									><?php echo esc_html( $r_init ); ?></div>

									<div class="bn-rc-who">
										<div class="bn-rc-name"><?php echo esc_html( $r_name ); ?></div>
										<div class="bn-rc-meta">
											<?php if ( $r_strikes > 0 ) : ?>
												<?php echo esc_html( (string) $r_strikes ); ?> <?php esc_html_e( 'strikes', 'buddynext' ); ?> &middot;
											<?php endif; ?>
											<?php esc_html_e( 'member of this space', 'buddynext' ); ?>
										</div>
									</div>

									<?php if ( ! empty( $r_reason ) ) : ?>
										<span class="bn-rc-badge bn-rc-badge--spam"><?php echo esc_html( ucfirst( $r_reason ) ); ?></span>
									<?php endif; ?>

									<span class="bn-rc-time"><?php echo esc_html( $r_time ); ?></span>
									<span class="bn-rc-badge bn-rc-badge--count">
										<?php
										// translators: %d is the report count.
										printf( esc_html__( '%d reports', 'buddynext' ), absint( $r_count ) );
										?>
									</span>
								</div>

								<div class="bn-rc-body">
									<?php if ( ! empty( $r_content ) ) : ?>
										<div class="bn-content-preview"><?php echo esc_html( wp_trim_words( $r_content, 30 ) ); ?></div>
									<?php endif; ?>

									<?php if ( ! empty( $r_reason ) ) : ?>
										<p class="bn-report-reason">
											<strong><?php esc_html_e( 'Reported for:', 'buddynext' ); ?></strong>
											<?php echo esc_html( ucfirst( $r_reason ) ); ?>
										</p>
									<?php endif; ?>

									<?php if ( $r_count > 0 ) : ?>
										<div class="bn-reporters">
											<div class="bn-reporters__stacks" aria-hidden="true">
												<?php
												$max_reporter_dots = min( $r_count, 4 );
												for ( $i = 0; $i < $max_reporter_dots; $i++ ) :
													?>
													<div
														class="bn-reporters__dot"
														style="background:<?php echo esc_attr( bn_avatar_color( $i + 1 ) ); ?>;"
													></div>
												<?php endfor; ?>
											</div>
											<?php
											// translators: %d is the reporter count.
											printf( esc_html__( '%d member(s) from this space reported this', 'buddynext' ), absint( $r_count ) );
											?>
										</div>
									<?php endif; ?>

									<div class="bn-rc-actions">
										<button
											type="button"
											class="bn-rc-btn bn-rc-btn--view"
											data-wp-on--click="actions.viewReportedPost"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
										><?php esc_html_e( 'View Post', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-rc-btn bn-rc-btn--dismiss"
											data-wp-on--click="actions.dismissReport"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
										><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Dismiss', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-rc-btn bn-rc-btn--remove"
											data-wp-on--click="actions.removeContent"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
										><?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Remove', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-rc-btn bn-rc-btn--warn"
											data-wp-on--click="actions.warnMember"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
											data-user-id="<?php echo esc_attr( (string) $reported_uid ); ?>"
										><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Warn', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-rc-btn bn-rc-btn--space-remove"
											data-wp-on--click="actions.removeFromSpace"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
											data-user-id="<?php echo esc_attr( (string) $reported_uid ); ?>"
											data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
										><?php buddynext_icon( 'ban' ); ?> <?php esc_html_e( 'Remove from Space', 'buddynext' ); ?></button>
									</div>

									<p class="bn-rc-action-note">
										<?php
										// translators: %s is the space name.
										printf( esc_html__( '"Remove from Space" removes this member from %s only &mdash; it does not suspend their platform account.', 'buddynext' ), '<strong>' . esc_html( $space->name ?? '' ) . '</strong>' );
										?>
									</p>
								</div>
							</article>
						<?php endforeach; ?>

					<?php endif; ?>

				<?php elseif ( 'pending' === $mod_tab ) : ?>

					<?php if ( empty( $pending_members ) ) : ?>
						<div class="bn-empty">
							<div class="bn-empty__icon"><?php buddynext_icon( 'users' ); ?></div>
							<p class="bn-empty__title"><?php esc_html_e( 'No pending requests', 'buddynext' ); ?></p>
							<p><?php esc_html_e( 'All join requests have been reviewed.', 'buddynext' ); ?></p>
						</div>

					<?php else : ?>

						<div class="bn-section-collapsed">
							<div class="bn-section-collapsed__header">
								<div>
									<span class="bn-section-collapsed__title"><?php buddynext_icon( 'users' ); ?> <?php esc_html_e( 'Pending Member Requests', 'buddynext' ); ?></span>
									<span class="bn-section-collapsed__meta">
										<?php
										// translators: %d is the pending count.
										printf( esc_html__( '%d requests awaiting approval', 'buddynext' ), count( $pending_members ) );
										?>
									</span>
								</div>
							</div>

							<?php foreach ( $pending_members as $pm ) : ?>
								<?php
								$pm_uid   = (int) $pm->user_id;
								$pm_name  = $pm->display_name ?? __( 'Member', 'buddynext' );
								$pm_color = bn_avatar_color( $pm_uid );
								$pm_init  = bn_initials( $pm_name );
								$pm_time  = isset( $pm->joined_at ) ? bn_time_diff( $pm->joined_at ) : '';
								?>
								<div class="bn-pending-row">
									<div
										class="bn-rc-avatar"
										style="background:<?php echo esc_attr( $pm_color ); ?>;"
										aria-label="<?php echo esc_attr( $pm_name ); ?>"
									><?php echo esc_html( $pm_init ); ?></div>
									<div class="bn-pending-row__info">
										<div class="bn-pending-row__name"><?php echo esc_html( $pm_name ); ?></div>
										<div class="bn-pending-row__meta"><?php esc_html_e( 'Requested', 'buddynext' ); ?> <?php echo esc_html( $pm_time ); ?></div>
									</div>
									<div class="bn-pending-row__actions">
										<button
											type="button"
											class="bn-btn-approve"
											data-wp-on--click="actions.approveJoinRequest"
											data-user-id="<?php echo esc_attr( (string) $pm_uid ); ?>"
											data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
										><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
										<button
											type="button"
											class="bn-btn-decline"
											data-wp-on--click="actions.declineJoinRequest"
											data-user-id="<?php echo esc_attr( (string) $pm_uid ); ?>"
											data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
										><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

					<?php endif; ?>

				<?php elseif ( 'log' === $mod_tab ) : ?>

					<div class="bn-section-header">
						<?php buddynext_icon( 'copy' ); ?>
						<?php
						// translators: %s is the space name.
						printf( esc_html__( 'Recent Moderation Activity &mdash; %s', 'buddynext' ), esc_html( $space->name ?? '' ) );
						?>
					</div>

					<?php if ( empty( $mod_log ) ) : ?>
						<div class="bn-empty">
							<div class="bn-empty__icon"><?php buddynext_icon( 'copy' ); ?></div>
							<p class="bn-empty__title"><?php esc_html_e( 'No activity yet', 'buddynext' ); ?></p>
						</div>
					<?php else : ?>
						<div class="bn-activity-log" role="list">
							<?php foreach ( $mod_log as $log ) : ?>
								<?php
								$log_action = $log->action ?? 'note';
								$log_icon   = bn_mod_action_icon( $log_action );
								$log_time   = isset( $log->created_at ) ? bn_time_diff( $log->created_at ) : '';
								$log_is_me  = ( (int) $log->actor_id === $current_uid );
								?>
								<div class="bn-log-row" role="listitem">
									<span class="bn-log-icon" aria-hidden="true"><?php echo wp_kses_data( $log_icon ); ?></span>
									<span class="bn-log-desc"><?php echo esc_html( $log->note ?? '' ); ?></span>
									<?php if ( $log_is_me ) : ?>
										<span class="bn-log-actor"><?php esc_html_e( 'by You', 'buddynext' ); ?></span>
									<?php elseif ( ! empty( $log->moderator_name ) ) : ?>
										<span class="bn-log-actor"><?php echo esc_html( $log->moderator_name ); ?></span>
									<?php endif; ?>
									<span class="bn-log-time"><?php echo esc_html( $log_time ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				<?php endif; ?>

			</main>

			<!-- Sidebar: scope info card -->
			<aside aria-label="<?php esc_attr_e( 'Moderation scope', 'buddynext' ); ?>">
				<div class="bn-scope-card">
					<div class="bn-scope-card__header"><?php buddynext_icon( 'search' ); ?> <?php esc_html_e( 'Your moderation scope', 'buddynext' ); ?></div>
					<div class="bn-scope-list">
						<div class="bn-scope-item">
							<span class="bn-scope-can" aria-label="<?php esc_attr_e( 'Can', 'buddynext' ); ?>"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Moderate posts and comments in this space', 'buddynext' ); ?></span>
						</div>
						<div class="bn-scope-item">
							<span class="bn-scope-can" aria-label="<?php esc_attr_e( 'Can', 'buddynext' ); ?>"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Approve / decline join requests', 'buddynext' ); ?></span>
						</div>
						<div class="bn-scope-item">
							<span class="bn-scope-can" aria-label="<?php esc_attr_e( 'Can', 'buddynext' ); ?>"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Warn or remove members from this space', 'buddynext' ); ?></span>
						</div>
						<div class="bn-scope-item">
							<span class="bn-scope-cant" aria-label="<?php esc_attr_e( 'Cannot', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></span>
							<span>
								<?php esc_html_e( 'Suspend accounts platform-wide', 'buddynext' ); ?>
								<span style="color:var(--text-3);"><?php esc_html_e( '(requires Community Admin)', 'buddynext' ); ?></span>
							</span>
						</div>
						<div class="bn-scope-item">
							<span class="bn-scope-cant" aria-label="<?php esc_attr_e( 'Cannot', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></span>
							<span><?php esc_html_e( 'See reports from other spaces', 'buddynext' ); ?></span>
						</div>
					</div>
					<div class="bn-scope-card__footer">
						<a href="<?php echo esc_url( buddynext_community_admin_url() ); ?>">
							<?php esc_html_e( 'Request platform-wide admin access', 'buddynext' ); ?> &rarr;
						</a>
					</div>
				</div>
			</aside>

		</div><!-- /.bn-mod-content -->

	</div><!-- /.bn-mod-shell -->

</div><!-- /.bn-mod -->
