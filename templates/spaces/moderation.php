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

use BuddyNext\Moderation\ModerationService;
use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Profile\AvatarService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

// ── Resolve space ─────────────────────────────────────────────────────────────

$space_id = isset( $space_id ) ? absint( $space_id ) : 0;

if ( ! $space_id ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}

// ── Permission gate ───────────────────────────────────────────────────────────

if ( ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate', array( 'space_id' => $space_id ) ) ) {
	// A demoted moderator may still hold this URL — render a friendly in-shell
	// notice with a way back instead of a bare wp_die() white screen.
	printf(
		'<div class="bn-empty-state bn-space-no-access"><div class="bn-empty-title">%1$s</div><p class="bn-empty-text">%2$s</p><a class="bn-btn" data-variant="primary" href="%3$s">%4$s</a></div>',
		esc_html__( 'You no longer moderate this space', 'buddynext' ),
		esc_html__( 'Your moderator access to this space has changed. You can still view and take part in it.', 'buddynext' ),
		esc_url( \BuddyNext\Core\PageRouter::space_url( $space_id ) ),
		esc_html__( 'Back to space', 'buddynext' )
	);
	return;
}

// Canonical hydrate via SpaceService (no SQL here); cast to object for the
// header markup below which reads $space->name / ->slug / ->type / ->member_count.
$bn_mod_svc     = new ModerationService();
$bn_mod_log_svc = new ModerationLogService();
$bn_member_svc  = new SpaceMemberService();
$bn_space_row   = ( new SpaceService() )->get( $space_id );

if ( null === $bn_space_row ) {
	wp_die( esc_html__( 'Space not found.', 'buddynext' ) );
}
$space = (object) $bn_space_row;

// ── Active filter tab ─────────────────────────────────────────────────────────

$mod_tab = isset( $_GET['bn_mtab'] ) ? sanitize_key( wp_unslash( $_GET['bn_mtab'] ) ) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_mod_tabs = array( 'reports', 'pending', 'log' );
if ( ! in_array( $mod_tab, $allowed_mod_tabs, true ) ) {
	$mod_tab = 'reports';
}

// ── Stats ─────────────────────────────────────────────────────────────────────
// All counts come from the moderation services so the template holds no SQL.

// Open reports for this space (distinct reported-content groups — matches the list).
$open_reports_count = $bn_mod_svc->count_open_reports_for_space( $space_id );

// Pending member requests for this space.
$pending_count = $bn_member_svc->count_pending_requests( $space_id );

// Members warned this week — scoped, action-filtered, dated log read.
$warned_this_week = (int) ( $bn_mod_log_svc->get_log(
	array(
		'space_id' => $space_id,
		'action'   => 'warn',
		'since'    => '-7 days',
		'per_page' => 1,
	)
)['total'] ?? 0 );

// Total actions all time for this space.
$total_actions = (int) ( $bn_mod_log_svc->get_log(
	array(
		'space_id' => $space_id,
		'per_page' => 1,
	)
)['total'] ?? 0 );

// ── Fetch open reports ────────────────────────────────────────────────────────
// get_queue() consolidates per-content reports, enriches user reports with
// strike count / offender name, and scopes by space_ids — replacing the
// hand-rolled aggregation + strike subqueries this template used to assemble.
$open_reports = $bn_mod_svc->get_queue(
	array(
		'space_ids' => array( $space_id ),
		'enrich'    => true,
		'per_page'  => 20,
	)
)['items'];

// ── Fetch pending members ─────────────────────────────────────────────────────
// get_pending_requests() returns user_id + requested_at; resolve display names
// via WP core get_userdata() (no SQL) for the bounded list.
$pending_members = $bn_member_svc->get_pending_requests( $space_id, 20 );

// ── Fetch moderation activity log ─────────────────────────────────────────────
$mod_log = $bn_mod_log_svc->get_log(
	array(
		'space_id' => $space_id,
		'per_page' => 20,
	)
)['items'];

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
	data-wp-context='{"restNonce":"<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>","restUrl":"<?php echo esc_attr( rest_url( 'buddynext/v1/' ) ); ?>"}'
	data-space-id="<?php echo esc_attr( (string) $space_id ); ?>"
>

	<!-- Unified space header + nav bar (same as every other space tab). -->
	<?php
	buddynext_get_template(
		'parts/space-header.php',
		array(
			'space_id'   => $space_id,
			'active_tab' => 'moderation',
		)
	);
	?>

	<!-- Moderation sub-filter (Reports / Pending / Activity log) as a sub-nav. -->
	<nav class="bn-subnav bn-space-mod__filter" aria-label="<?php esc_attr_e( 'Moderation filter', 'buddynext' ); ?>">
		<a class="bn-subnav__item" href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'reports', $mod_base_url ) ); ?>" aria-selected="<?php echo ( 'reports' === $mod_tab ) ? 'true' : 'false'; ?>">
			<span class="bn-subnav__label"><?php esc_html_e( 'Reports', 'buddynext' ); ?></span>
			<?php if ( $open_reports_count > 0 ) : ?><span class="bn-subnav__count"><?php echo esc_html( (string) $open_reports_count ); ?></span><?php endif; ?>
		</a>
		<a class="bn-subnav__item" href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'pending', $mod_base_url ) ); ?>" aria-selected="<?php echo ( 'pending' === $mod_tab ) ? 'true' : 'false'; ?>">
			<span class="bn-subnav__label"><?php esc_html_e( 'Pending members', 'buddynext' ); ?></span>
			<?php if ( $pending_count > 0 ) : ?><span class="bn-subnav__count"><?php echo esc_html( (string) $pending_count ); ?></span><?php endif; ?>
		</a>
		<a class="bn-subnav__item" href="<?php echo esc_url( add_query_arg( 'bn_mtab', 'log', $mod_base_url ) ); ?>" aria-selected="<?php echo ( 'log' === $mod_tab ) ? 'true' : 'false'; ?>">
			<span class="bn-subnav__label"><?php esc_html_e( 'Activity log', 'buddynext' ); ?></span>
		</a>
	</nav>

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
							$reported_uid = (int) ( $report['object_id'] ?? 0 );
							$is_user_rep  = 'user' === ( $report['object_type'] ?? '' );
							$r_name       = ( $is_user_rep && ! empty( $report['offender_name'] ) )
								? (string) $report['offender_name']
								: __( 'Unknown', 'buddynext' );
							$r_avatar_url = ( $is_user_rep && $reported_uid ) ? get_avatar_url( $reported_uid, array( 'size' => 80 ) ) : '';
							$r_count      = (int) ( $report['reporter_count'] ?? 1 );
							$r_strikes    = (int) ( $report['strikes_count'] ?? 0 );
							$r_reason     = (string) ( $report['reason'] ?? '' );
							$r_content    = '';
							$r_time       = ! empty( $report['created_at'] ) ? bn_time_diff( (string) $report['created_at'] ) : '';
							$r_tone       = bn_report_priority( $r_count );
							$r_id         = (int) ( $report['id'] ?? 0 );
							?>
							<article
								class="bn-card bn-space-mod__report"
								data-tone="<?php echo esc_attr( $r_tone ); ?>"
								data-wp-context='{"reportId":<?php echo (int) $r_id; ?>,"userId":<?php echo (int) $reported_uid; ?>,"spaceId":<?php echo (int) $space_id; ?>}'
							>
								<div class="bn-space-mod__report-head">
									<span class="bn-avatar" data-size="md" aria-hidden="true">
										<?php if ( $r_avatar_url ) : ?>
											<img src="<?php echo esc_url( $r_avatar_url ); ?>" alt="" loading="lazy">
										<?php else : ?>
											<?php echo esc_html( AvatarService::initials_for( $r_name ) ); ?>
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
											data-report-id="<?php echo esc_attr( (string) $r_id ); ?>"
										><?php esc_html_e( 'View post', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="ghost"
											data-size="sm"
											data-wp-on--click="actions.dismissReport"
											data-report-id="<?php echo esc_attr( (string) $r_id ); ?>"
										><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Dismiss', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="secondary"
											data-size="sm"
											data-wp-on--click="actions.warnMember"
											data-report-id="<?php echo esc_attr( (string) $r_id ); ?>"
											data-user-id="<?php echo esc_attr( (string) $reported_uid ); ?>"
										><?php buddynext_icon( 'alert-triangle' ); ?> <?php esc_html_e( 'Warn', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="danger"
											data-size="sm"
											data-wp-on--click="actions.removeContent"
											data-report-id="<?php echo esc_attr( (string) $r_id ); ?>"
											data-bn-confirm="<?php echo esc_attr( __( 'Remove this content? It will be hidden from the space.', 'buddynext' ) ); ?>"
										><?php buddynext_icon( 'trash' ); ?> <?php esc_html_e( 'Remove', 'buddynext' ); ?></button>

										<button
											type="button"
											class="bn-btn"
											data-variant="danger"
											data-size="sm"
											data-wp-on--click="actions.removeFromSpace"
											data-report-id="<?php echo esc_attr( (string) $r_id ); ?>"
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
									$pm_uid    = (int) ( $pm['user_id'] ?? 0 );
									$pm_user   = $pm_uid ? get_userdata( $pm_uid ) : false;
									$pm_name   = ( $pm_user instanceof WP_User ) ? $pm_user->display_name : __( 'Member', 'buddynext' );
									$pm_avatar = get_avatar_url( $pm_uid, array( 'size' => 80 ) );
									$pm_time   = ! empty( $pm['requested_at'] ) ? bn_time_diff( (string) $pm['requested_at'] ) : '';
									?>
									<li class="bn-space-mod__pending-row" role="listitem">
										<span class="bn-avatar" data-size="md" aria-hidden="true">
											<?php if ( $pm_avatar ) : ?>
												<img src="<?php echo esc_url( $pm_avatar ); ?>" alt="" loading="lazy">
											<?php else : ?>
												<?php echo esc_html( AvatarService::initials_for( (string) $pm_name ) ); ?>
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
									$log_action_slug = bn_mod_action_icon( (string) ( $log['action'] ?? 'note' ) );
									$log_time        = ! empty( $log['created_at'] ) ? bn_time_diff( (string) $log['created_at'] ) : '';
									$log_actor_id    = (int) ( $log['actor_id'] ?? 0 );
									$log_is_me       = ( $log_actor_id === $current_uid );
									$log_actor       = ( ! $log_is_me && $log_actor_id ) ? get_userdata( $log_actor_id ) : false;
									$log_actor_name  = ( $log_actor instanceof WP_User ) ? $log_actor->display_name : '';
									?>
									<li class="bn-space-mod__log-row" role="listitem">
										<span class="bn-space-mod__log-icon" aria-hidden="true"><?php buddynext_icon( $log_action_slug ); ?></span>
										<span class="bn-space-mod__log-desc"><?php echo esc_html( (string) ( $log['note'] ?? '' ) ); ?></span>
										<?php if ( $log_is_me ) : ?>
											<span class="bn-space-mod__log-actor"><?php esc_html_e( 'by You', 'buddynext' ); ?></span>
										<?php elseif ( '' !== $log_actor_name ) : ?>
											<span class="bn-space-mod__log-actor"><?php echo esc_html( $log_actor_name ); ?></span>
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
