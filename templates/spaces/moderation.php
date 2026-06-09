<?php
/**
 * Template: Space Moderation Panel
 *
 * Shows open reports, pending member requests, removed content, and the
 * moderation activity log scoped to a single space. Composes from v2
 * primitives (.bn-card, .bn-tabs, .bn-stat, .bn-badge, .bn-avatar,
 * .bn-btn) — no bespoke design language.
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
		"SELECT id, name, slug, type, member_count, category_id, cover_image_url FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
		$space_id
	)
);

if ( ! $space ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Active filter tab ─────────────────────────────────────────────────────────

$mod_tab = isset( $_GET['bn_mtab'] ) ? sanitize_key( wp_unslash( $_GET['bn_mtab'] ) ) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_mod_tabs = array( 'reports', 'pending', 'log' );
if ( ! in_array( $mod_tab, $allowed_mod_tabs, true ) ) {
	$mod_tab = 'reports';
}

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

if ( ! function_exists( 'bn_mod_action_icon' ) ) {
	/**
	 * Return an SVG icon slug for a mod log action type.
	 *
	 * @param string $action Moderation action slug.
	 * @return string Icon slug.
	 */
	function bn_mod_action_icon( string $action ): string {
		$map = array(
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
		return $map[ $action ] ?? 'copy';
	}
}

if ( ! function_exists( 'bn_report_priority' ) ) {
	/**
	 * Return a report priority tone based on reporter count.
	 *
	 * @param int $count Number of reporters.
	 * @return string Tone slug ('danger', 'warn', or 'info').
	 */
	function bn_report_priority( int $count ): string {
		if ( $count >= 3 ) {
			return 'danger';
		} elseif ( $count >= 2 ) {
			return 'warn';
		}
		return 'info';
	}
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

// Privacy badge tone + label — single source via the space-type registry.
$mod_type    = (string) ( $space->type ?? 'open' );
$mod_privacy = array(
	'tone'  => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( $mod_type ),
	'label' => \BuddyNext\Spaces\SpaceTypeRegistry::instance()->label( $mod_type ),
);
?>
<div
	class="bn-space-mod"
	data-wp-interactive="buddynext/spaces"
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>

	<!-- Space header (mirrors space-home hero shape) -->
	<div class="bn-sh-header">
		<div class="bn-sh-cover">
			<?php if ( ! empty( $space->cover_image_url ) ) : ?>
				<img
					src="<?php echo esc_url( $space->cover_image_url ); ?>"
					alt="<?php echo esc_attr( $space->name ?? '' ); ?>"
					loading="lazy"
				>
			<?php endif; ?>
		</div>

		<div class="bn-sh-inner">
			<div class="bn-sh-avatar" aria-hidden="true">
				<?php buddynext_icon( 'shield' ); ?>
			</div>

			<div class="bn-sh-info">
				<h1 class="bn-sh-name">
					<?php echo esc_html( $space->name ?? '' ); ?>
					<span class="bn-badge" data-tone="<?php echo esc_attr( $mod_privacy['tone'] ); ?>"><?php echo esc_html( $mod_privacy['label'] ); ?></span>
					<span class="bn-badge" data-tone="accent"><?php esc_html_e( 'Space admin', 'buddynext' ); ?></span>
				</h1>
				<div class="bn-sh-meta">
					<span><?php buddynext_icon( 'users' ); ?> <?php echo esc_html( $member_fmt ); ?> <?php esc_html_e( 'members', 'buddynext' ); ?></span>
					<span><?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Reports scoped to this space only', 'buddynext' ); ?></span>
				</div>
			</div>

			<div class="bn-sh-actions">
				<a
					href="<?php echo esc_url( $space_url ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php buddynext_icon( 'chevron-left' ); ?> <?php esc_html_e( 'Back to space', 'buddynext' ); ?></a>
				<a
					href="<?php echo esc_url( buddynext_space_settings_url( $space->slug ?? '' ) ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
				><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>
				<a
					href="<?php echo esc_url( buddynext_community_admin_url() ); ?>"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
				><?php buddynext_icon( 'external-link' ); ?> <?php esc_html_e( 'Community admin', 'buddynext' ); ?></a>
			</div>
		</div>

		<nav class="bn-tabs bn-sh-tabs" aria-label="<?php esc_attr_e( 'Moderation filter', 'buddynext' ); ?>">
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'reports', $mod_base_url ) ); ?>"
				class="bn-tab bn-sh-tab"
				aria-selected="<?php echo ( 'reports' === $mod_tab ) ? 'true' : 'false'; ?>"
			>
				<?php buddynext_icon( 'flag' ); ?>
				<?php esc_html_e( 'Reports', 'buddynext' ); ?>
				<?php if ( $open_reports_count > 0 ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( (string) $open_reports_count ); ?></span>
				<?php endif; ?>
			</a>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'pending', $mod_base_url ) ); ?>"
				class="bn-tab bn-sh-tab"
				aria-selected="<?php echo ( 'pending' === $mod_tab ) ? 'true' : 'false'; ?>"
			>
				<?php buddynext_icon( 'user-plus' ); ?>
				<?php esc_html_e( 'Pending members', 'buddynext' ); ?>
				<?php if ( $pending_count > 0 ) : ?>
					<span class="bn-tab__count"><?php echo esc_html( (string) $pending_count ); ?></span>
				<?php endif; ?>
			</a>
			<a
				href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'log', $mod_base_url ) ); ?>"
				class="bn-tab bn-sh-tab"
				aria-selected="<?php echo ( 'log' === $mod_tab ) ? 'true' : 'false'; ?>"
			>
				<?php buddynext_icon( 'copy' ); ?>
				<?php esc_html_e( 'Activity log', 'buddynext' ); ?>
			</a>
		</nav>
	</div>

	<div class="bn-space-mod__shell">

		<!-- Stat tiles -->
		<div class="bn-stat-grid bn-space-mod__stats" role="list">
			<div class="bn-stat" role="listitem">
				<div class="bn-stat__label"><?php esc_html_e( 'Open reports', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $open_reports_count ); ?></div>
			</div>
			<div class="bn-stat" role="listitem">
				<div class="bn-stat__label"><?php esc_html_e( 'Pending member requests', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $pending_count ); ?></div>
			</div>
			<div class="bn-stat" role="listitem">
				<div class="bn-stat__label"><?php esc_html_e( 'Members warned (7d)', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $warned_this_week ); ?></div>
			</div>
			<div class="bn-stat" role="listitem">
				<div class="bn-stat__label"><?php esc_html_e( 'Actions taken (all time)', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $total_actions ); ?></div>
			</div>
		</div>

		<!-- Content + sidebar -->
		<div class="bn-space-mod__content">

			<main class="bn-space-mod__main">

				<?php if ( 'reports' === $mod_tab ) : ?>

					<?php if ( empty( $open_reports ) ) : ?>
						<div class="bn-card bn-space-mod__empty">
							<span class="bn-space-mod__empty-icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
							<p class="bn-space-mod__empty-title"><?php esc_html_e( 'No open reports', 'buddynext' ); ?></p>
							<p class="bn-space-mod__empty-desc"><?php esc_html_e( 'This space has no reports requiring attention.', 'buddynext' ); ?></p>
						</div>

					<?php else : ?>

						<?php // Nested Interactivity root: the report-action buttons below bind to the buddynext/moderation store (enqueued for this sub-route), not the page's buddynext/spaces store. restNonce/restUrl here merge into each report card's context. ?>
						<div
							data-wp-interactive="buddynext/moderation"
							data-wp-context='{"restNonce":"<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>","restUrl":"<?php echo esc_attr( rest_url( 'buddynext/v1/' ) ); ?>"}'
						>
						<?php foreach ( $open_reports as $report ) : ?>
							<?php
							$reported_uid = (int) $report->object_id;
							$r_name       = $report->reported_user_name ?? __( 'Unknown', 'buddynext' );
							$r_avatar_url = $reported_uid ? get_avatar_url( $reported_uid, array( 'size' => 80 ) ) : '';
							$r_count      = (int) ( $report->reporter_count ?? 1 );
							$r_strikes    = (int) ( $report->strike_count ?? 0 );
							$r_reason     = $report->reason ?? '';
							$r_content    = $report->content_snapshot ?? '';
							$r_time       = isset( $report->created_at ) ? bn_time_diff( $report->created_at ) : '';
							$r_tone       = bn_report_priority( $r_count );
							?>
							<article
								class="bn-card bn-space-mod__report"
								data-tone="<?php echo esc_attr( $r_tone ); ?>"
								data-wp-context='{"reportId":<?php echo (int) $report->id; ?>,"userId":<?php echo (int) $reported_uid; ?>,"spaceId":<?php echo (int) $space_id; ?>}'
							>
								<div class="bn-space-mod__report-head">
									<span class="bn-avatar" data-size="md" aria-hidden="true">
										<?php if ( $r_avatar_url ) : ?>
											<img src="<?php echo esc_url( $r_avatar_url ); ?>" alt="" loading="lazy">
										<?php else : ?>
											<?php echo esc_html( bn_initials( $r_name ) ); ?>
										<?php endif; ?>
									</span>

									<div class="bn-space-mod__report-who">
										<div class="bn-space-mod__report-name"><?php echo esc_html( $r_name ); ?></div>
										<div class="bn-space-mod__report-meta">
											<?php if ( $r_strikes > 0 ) : ?>
												<?php
												printf(
													/* translators: %d: number of strikes */
													esc_html( _n( '%d strike', '%d strikes', $r_strikes, 'buddynext' ) ),
													absint( $r_strikes )
												);
												?>
												<span aria-hidden="true">&middot;</span>
											<?php endif; ?>
											<span><?php esc_html_e( 'member of this space', 'buddynext' ); ?></span>
											<?php if ( $r_time ) : ?>
												<span aria-hidden="true">&middot;</span>
												<span><?php echo esc_html( $r_time ); ?></span>
											<?php endif; ?>
										</div>
									</div>

									<?php if ( ! empty( $r_reason ) ) : ?>
										<span class="bn-badge" data-tone="<?php echo esc_attr( $r_tone ); ?>"><?php echo esc_html( ucfirst( $r_reason ) ); ?></span>
									<?php endif; ?>
									<span class="bn-badge" data-tone="default">
										<?php
										printf(
											/* translators: %d: number of reports */
											esc_html( _n( '%d report', '%d reports', $r_count, 'buddynext' ) ),
											absint( $r_count )
										);
										?>
									</span>
								</div>

								<div class="bn-space-mod__report-body">
									<?php if ( ! empty( $r_content ) ) : ?>
										<blockquote class="bn-space-mod__report-quote">
											<?php echo esc_html( wp_trim_words( $r_content, 30 ) ); ?>
										</blockquote>
									<?php endif; ?>

									<?php if ( $r_count > 0 ) : ?>
										<p class="bn-space-mod__report-summary">
											<?php
											printf(
												/* translators: %d is the reporter count. */
												esc_html( _n( '%d member from this space reported this.', '%d members from this space reported this.', $r_count, 'buddynext' ) ),
												absint( $r_count )
											);
											?>
										</p>
									<?php endif; ?>

									<div class="bn-space-mod__report-actions">
										<button
											type="button"
											class="bn-btn"
											data-variant="ghost"
											data-size="sm"
											data-wp-on--click="actions.viewReportedPost"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
										><?php esc_html_e( 'View post', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="ghost"
											data-size="sm"
											data-wp-on--click="actions.dismissReport"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
										><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Dismiss', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="secondary"
											data-size="sm"
											data-wp-on--click="actions.warnMember"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
											data-user-id="<?php echo esc_attr( (string) $reported_uid ); ?>"
										><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Warn', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="danger"
											data-size="sm"
											data-wp-on--click="actions.removeContent"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
											data-bn-confirm="<?php echo esc_attr( __( 'Remove this content? It will be hidden from the space.', 'buddynext' ) ); ?>"
										><?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Remove', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="danger"
											data-size="sm"
											data-wp-on--click="actions.removeFromSpace"
											data-report-id="<?php echo esc_attr( (string) $report->id ); ?>"
											data-user-id="<?php echo esc_attr( (string) $reported_uid ); ?>"
											data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
											data-bn-confirm="<?php echo esc_attr( __( 'Remove this member from the space? This does not suspend their platform account.', 'buddynext' ) ); ?>"
										><?php buddynext_icon( 'ban' ); ?> <?php esc_html_e( 'Remove from space', 'buddynext' ); ?></button>
									</div>

									<p class="bn-space-mod__report-note">
										<?php
										// translators: %s is the space name.
										printf( esc_html__( '"Remove from space" removes this member from %s only; it does not suspend their platform account.', 'buddynext' ), '<strong>' . esc_html( $space->name ?? '' ) . '</strong>' );
										?>
									</p>
								</div>
							</article>
						<?php endforeach; ?>
						</div>

					<?php endif; ?>

				<?php elseif ( 'pending' === $mod_tab ) : ?>

					<?php if ( empty( $pending_members ) ) : ?>
						<div class="bn-card bn-space-mod__empty">
							<span class="bn-space-mod__empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></span>
							<p class="bn-space-mod__empty-title"><?php esc_html_e( 'No pending requests', 'buddynext' ); ?></p>
							<p class="bn-space-mod__empty-desc"><?php esc_html_e( 'All join requests have been reviewed.', 'buddynext' ); ?></p>
						</div>

					<?php else : ?>

						<div class="bn-card bn-space-mod__list">
							<header class="bn-space-mod__list-head">
								<h2 class="bn-space-mod__list-title">
									<?php buddynext_icon( 'users' ); ?> <?php esc_html_e( 'Pending member requests', 'buddynext' ); ?>
								</h2>
								<span class="bn-badge" data-tone="default">
									<?php
									printf(
										/* translators: %d is the pending count. */
										esc_html( _n( '%d awaiting approval', '%d awaiting approval', count( $pending_members ), 'buddynext' ) ),
										count( $pending_members )
									);
									?>
								</span>
							</header>

							<ul class="bn-space-mod__pending-list" role="list">
								<?php foreach ( $pending_members as $pm ) : ?>
									<?php
									$pm_uid    = (int) $pm->user_id;
									$pm_name   = $pm->display_name ?? __( 'Member', 'buddynext' );
									$pm_avatar = get_avatar_url( $pm_uid, array( 'size' => 80 ) );
									$pm_time   = isset( $pm->joined_at ) ? bn_time_diff( $pm->joined_at ) : '';
									?>
									<li class="bn-space-mod__pending-row" role="listitem">
										<span class="bn-avatar" data-size="md" aria-hidden="true">
											<?php if ( $pm_avatar ) : ?>
												<img src="<?php echo esc_url( $pm_avatar ); ?>" alt="" loading="lazy">
											<?php else : ?>
												<?php echo esc_html( bn_initials( $pm_name ) ); ?>
											<?php endif; ?>
										</span>
										<div class="bn-space-mod__pending-info">
											<div class="bn-space-mod__pending-name"><?php echo esc_html( $pm_name ); ?></div>
											<div class="bn-space-mod__pending-meta"><?php esc_html_e( 'Requested', 'buddynext' ); ?> <?php echo esc_html( $pm_time ); ?></div>
										</div>
										<div class="bn-space-mod__pending-actions">
											<button
												type="button"
												class="bn-btn"
												data-variant="primary"
												data-size="sm"
												data-wp-on--click="actions.approveJoinRequest"
												data-user-id="<?php echo esc_attr( (string) $pm_uid ); ?>"
												data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
											><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
											<button
												type="button"
												class="bn-btn"
												data-variant="ghost"
												data-size="sm"
												data-wp-on--click="actions.declineJoinRequest"
												data-user-id="<?php echo esc_attr( (string) $pm_uid ); ?>"
												data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
											><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>

					<?php endif; ?>

				<?php elseif ( 'log' === $mod_tab ) : ?>

					<?php if ( empty( $mod_log ) ) : ?>
						<div class="bn-card bn-space-mod__empty">
							<span class="bn-space-mod__empty-icon" aria-hidden="true"><?php buddynext_icon( 'copy' ); ?></span>
							<p class="bn-space-mod__empty-title"><?php esc_html_e( 'No activity yet', 'buddynext' ); ?></p>
							<p class="bn-space-mod__empty-desc"><?php esc_html_e( 'Moderation activity will appear here as it happens.', 'buddynext' ); ?></p>
						</div>
					<?php else : ?>
						<div class="bn-card bn-space-mod__list">
							<header class="bn-space-mod__list-head">
								<h2 class="bn-space-mod__list-title">
									<?php buddynext_icon( 'copy' ); ?>
									<?php
									// translators: %s is the space name.
									printf( esc_html__( 'Recent moderation activity: %s', 'buddynext' ), esc_html( $space->name ?? '' ) );
									?>
								</h2>
							</header>
							<ul class="bn-space-mod__log-list" role="list">
								<?php foreach ( $mod_log as $log ) : ?>
									<?php
									$log_action_slug = bn_mod_action_icon( $log->action ?? 'note' );
									$log_time        = isset( $log->created_at ) ? bn_time_diff( $log->created_at ) : '';
									$log_is_me       = ( (int) $log->actor_id === $current_uid );
									?>
									<li class="bn-space-mod__log-row" role="listitem">
										<span class="bn-space-mod__log-icon" aria-hidden="true"><?php buddynext_icon( $log_action_slug ); ?></span>
										<span class="bn-space-mod__log-desc"><?php echo esc_html( $log->note ?? '' ); ?></span>
										<?php if ( $log_is_me ) : ?>
											<span class="bn-space-mod__log-actor"><?php esc_html_e( 'by You', 'buddynext' ); ?></span>
										<?php elseif ( ! empty( $log->moderator_name ) ) : ?>
											<span class="bn-space-mod__log-actor"><?php echo esc_html( $log->moderator_name ); ?></span>
										<?php endif; ?>
										<span class="bn-space-mod__log-time"><?php echo esc_html( $log_time ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

				<?php endif; ?>

			</main>

			<!-- Sidebar: scope info card -->
			<aside class="bn-space-mod__aside" aria-label="<?php esc_attr_e( 'Moderation scope', 'buddynext' ); ?>">
				<div class="bn-card bn-space-mod__scope">
					<header class="bn-space-mod__scope-head">
						<?php buddynext_icon( 'shield' ); ?> <?php esc_html_e( 'Your moderation scope', 'buddynext' ); ?>
					</header>
					<ul class="bn-space-mod__scope-list" role="list">
						<li class="bn-space-mod__scope-item" role="listitem">
							<span class="bn-space-mod__scope-icon bn-space-mod__scope-icon--ok" aria-hidden="true"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Moderate posts and comments in this space', 'buddynext' ); ?></span>
						</li>
						<li class="bn-space-mod__scope-item" role="listitem">
							<span class="bn-space-mod__scope-icon bn-space-mod__scope-icon--ok" aria-hidden="true"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Approve / decline join requests', 'buddynext' ); ?></span>
						</li>
						<li class="bn-space-mod__scope-item" role="listitem">
							<span class="bn-space-mod__scope-icon bn-space-mod__scope-icon--ok" aria-hidden="true"><?php buddynext_icon( 'check' ); ?></span>
							<span><?php esc_html_e( 'Warn or remove members from this space', 'buddynext' ); ?></span>
						</li>
						<li class="bn-space-mod__scope-item" role="listitem">
							<span class="bn-space-mod__scope-icon bn-space-mod__scope-icon--no" aria-hidden="true"><?php buddynext_icon( 'x' ); ?></span>
							<span>
								<?php esc_html_e( 'Suspend accounts platform-wide', 'buddynext' ); ?>
								<span class="bn-space-mod__scope-note"><?php esc_html_e( '(requires Community admin)', 'buddynext' ); ?></span>
							</span>
						</li>
						<li class="bn-space-mod__scope-item" role="listitem">
							<span class="bn-space-mod__scope-icon bn-space-mod__scope-icon--no" aria-hidden="true"><?php buddynext_icon( 'x' ); ?></span>
							<span><?php esc_html_e( 'See reports from other spaces', 'buddynext' ); ?></span>
						</li>
					</ul>
					<footer class="bn-space-mod__scope-foot">
						<a href="<?php echo esc_url( buddynext_community_admin_url() ); ?>" class="bn-space-mod__scope-link">
							<?php esc_html_e( 'Request platform-wide admin access', 'buddynext' ); ?>
							<?php buddynext_icon( 'arrow-right' ); ?>
						</a>
					</footer>
				</div>
			</aside>

		</div>
	</div>
</div>
