<?php
/**
 * Moderation queue template (v2).
 *
 * Restricted to users with the buddynext-moderation/review-queue ability.
 * Lists pending reports from bn_reports with severity classification,
 * reporter stacks, and inline actions:
 *   - Dismiss, Remove content, Warn user, Strike user, Suspend account.
 *
 * Each action calls buddynext/v1/moderation/{report_id}/{action} via the
 * WP Interactivity API store.
 *
 * Composes the v2 primitive vocabulary defined in bn-base.css:
 *   .bn-card[data-v2]            section wrappers + report rows
 *   .bn-tabs / .bn-tab            Open / Resolved / Escalated tab strip
 *   .bn-tab__count                count chip per filter
 *   .bn-input / .bn-select        search + sort controls
 *   .bn-badge[data-tone]          severity + reason + status pills
 *   .bn-avatar[data-size]         offender + reporter avatars
 *   .bn-btn[data-variant][data-size] action cluster + destructive confirms
 *   .bn-stat / .bn-stat-grid      queue-depth counters
 *
 * All visual rules live in assets/css/bn-moderation.css.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BuddyNext\Profile\AvatarService;

$current_user_id = get_current_user_id();

// Access gate — must have review-queue ability.
if ( ! $current_user_id || ! buddynext_can( $current_user_id, 'buddynext-moderation/review-queue' ) ) {
	?>
	<div class="bn-mod-restricted" role="alert">
		<div class="bn-mod-restricted__panel">
			<span class="bn-mod-restricted__icon" aria-hidden="true"><?php buddynext_icon( 'ban' ); ?></span>
			<h2 class="bn-mod-restricted__title"><?php esc_html_e( 'Access Restricted', 'buddynext' ); ?></h2>
			<p class="bn-mod-restricted__body"><?php esc_html_e( 'You do not have permission to access the moderation queue. If you believe this is an error, contact a community administrator.', 'buddynext' ); ?></p>
		</div>
	</div>
	<?php
	return;
}

// Allowed filter values.
$allowed_obj_types = array( 'all', 'post', 'comment', 'user', 'space', 'message' );
$allowed_urgency   = array( 'all', 'urgent' );
$allowed_sorts     = array( 'newest', 'most_reported' );

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

$bn_mod    = buddynext_service( 'moderation' );
$bn_posts  = buddynext_service( 'post_service' );
$bn_spaces = buddynext_service( 'spaces' );

// Queue summary counters in one service pass (replaces the in-template stats SQL).
$queue_stats     = $bn_mod->queue_stats();
$pending_count   = (int) $queue_stats['pending'];
$resolved_today  = (int) $queue_stats['resolved_today'];
$total_all_time  = (int) $queue_stats['total_all_time'];
$suspended_count = (int) $queue_stats['at_risk'];

// Fetch the consolidated queue (one row per reported object, reasons merged,
// offender strikes + identity enriched) via the service the REST controller uses.
// Paginated: the service caps each page at 50 and returns the full match total,
// so a busy site can review past the first 50 reports (the template previously
// hard-capped at 50 with no way forward).
$bn_mq_per_page = 50;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_mq_page = isset( $_GET['mq_page'] ) ? max( 1, absint( wp_unslash( $_GET['mq_page'] ) ) ) : 1;
$queue      = $bn_mod->get_queue(
	array(
		'per_page'    => $bn_mq_per_page,
		'page'        => $bn_mq_page,
		'object_type' => 'all' !== $filter_type ? $filter_type : '',
		'enrich'      => true,
	)
);
$reports     = (array) ( $queue['items'] ?? array() );
$bn_mq_total = (int) ( $queue['total'] ?? count( $reports ) );
$bn_mq_pages = (int) max( 1, (int) ceil( $bn_mq_total / $bn_mq_per_page ) );

// Urgency filter + most-reported sort run in PHP on report_count — the service
// returns the count, so no SQL fragment assembly lives in this template.
if ( 'urgent' === $filter_urgency ) {
	$reports = array_values( array_filter( $reports, static fn( $r ) => (int) ( $r['report_count'] ?? 0 ) >= 3 ) );
}
if ( 'most_reported' === $sort_by ) {
	usort(
		$reports,
		static fn( $a, $b ) => (int) ( $b['report_count'] ?? 0 ) <=> (int) ( $a['report_count'] ?? 0 )
			?: strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) )
	);
}

$urgent_count = count( array_filter( $reports, static fn( $r ) => (int) ( $r['report_count'] ?? 0 ) >= 3 ) );

// Resolve display content (post/comment excerpts + author, space names) through
// the owning services — both are cache-backed and capped at the 50-row queue.
$post_excerpts = array();
$space_names   = array();
foreach ( $reports as $rpt ) {
	$obj_type = (string) ( $rpt['object_type'] ?? '' );
	$obj_id   = (int) ( $rpt['object_id'] ?? 0 );
	if ( $obj_id <= 0 ) {
		continue;
	}
	if ( in_array( $obj_type, array( 'post', 'comment' ), true ) && ! isset( $post_excerpts[ $obj_id ] ) ) {
		$bn_mod_post = $bn_posts->get( $obj_id );
		if ( null !== $bn_mod_post ) {
			$post_excerpts[ $obj_id ] = array(
				'content'   => (string) ( $bn_mod_post['content'] ?? '' ),
				'author_id' => (int) ( $bn_mod_post['user_id'] ?? 0 ),
			);
		}
	} elseif ( 'space' === $obj_type && ! isset( $space_names[ $obj_id ] ) ) {
		$space = $bn_spaces->get( $obj_id );
		if ( null !== $space ) {
			$space_names[ $obj_id ] = (string) ( $space['name'] ?? '' );
		}
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
 * Map a reason string to a v2 badge tone + label.
 *
 * @param string $reason Report reason.
 * @return array{label: string, tone: string}
 */
$reason_badge = static function ( string $reason ): array {
	if ( false !== stripos( $reason, 'hate' ) ) {
		return array(
			'label' => __( 'Hate speech', 'buddynext' ),
			'tone'  => 'danger',
		);
	}
	if ( false !== stripos( $reason, 'harass' ) ) {
		return array(
			'label' => __( 'Harassment', 'buddynext' ),
			'tone'  => 'danger',
		);
	}
	if ( false !== stripos( $reason, 'spam' ) || false !== stripos( $reason, 'promo' ) ) {
		return array(
			'label' => __( 'Spam', 'buddynext' ),
			'tone'  => 'warn',
		);
	}
	return array(
		'label' => __( 'Off-topic', 'buddynext' ),
		'tone'  => 'info',
	);
};

/**
 * Fires before the moderation queue inner content.
 */
do_action( 'buddynext_moderation_queue_before' );
?>

<div class="bn-mod-shell"
	data-wp-interactive="buddynext/moderation"
	data-wp-context='{"restNonce":"<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>","restUrl":"<?php echo esc_attr( rest_url( 'buddynext/v1/' ) ); ?>"}'>

	<!-- Page header -->
	<header class="bn-mod-header">
		<div class="bn-mod-header__copy">
			<h1 class="bn-mod-title">
				<?php buddynext_icon( 'shield' ); ?>
				<?php esc_html_e( 'Moderation Queue', 'buddynext' ); ?>
			</h1>
			<p class="bn-mod-sub"><?php esc_html_e( 'Review and act on reported content', 'buddynext' ); ?></p>
		</div>
	</header>

	<!-- Stats — composed from .bn-stat / .bn-stat-grid -->
	<div class="bn-stat-grid bn-mod-stat-grid" role="list">
		<div class="bn-stat" role="listitem" aria-live="polite">
			<div class="bn-stat__label"><?php esc_html_e( 'Urgent reports', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $urgent_count ) ); ?></div>
			<div class="bn-stat__delta" data-trend="<?php echo esc_attr( $urgent_count > 0 ? 'down' : 'flat' ); ?>">
				<?php echo $urgent_count > 0 ? esc_html__( 'Needs attention', 'buddynext' ) : esc_html__( 'All clear', 'buddynext' ); ?>
			</div>
		</div>
		<div class="bn-stat" role="listitem">
			<div class="bn-stat__label"><?php esc_html_e( 'Pending review', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
		</div>
		<div class="bn-stat" role="listitem">
			<div class="bn-stat__label"><?php esc_html_e( 'Resolved today', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $resolved_today ) ); ?></div>
		</div>
		<div class="bn-stat" role="listitem">
			<div class="bn-stat__label"><?php esc_html_e( 'Total all time', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $total_all_time ) ); ?></div>
		</div>
		<div class="bn-stat" role="listitem">
			<div class="bn-stat__label"><?php esc_html_e( 'Suspended users', 'buddynext' ); ?></div>
			<div class="bn-stat__value"><?php echo esc_html( number_format_i18n( $suspended_count ) ); ?></div>
		</div>
	</div>

	<!-- Filter strip — .bn-tabs primitive + .bn-select for sort -->
	<div class="bn-mod-filterbar">
		<div class="bn-mod-filterbar__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter reports', 'buddynext' ); ?>">
			<nav class="bn-tabs" aria-label="<?php esc_attr_e( 'Report type', 'buddynext' ); ?>">
				<?php
				$type_filters = array(
					'all'     => array(
						'label' => __( 'All', 'buddynext' ),
						'count' => $pending_count,
					),
					'urgent'  => array(
						'label' => __( 'Urgent', 'buddynext' ),
						'count' => $urgent_count,
					),
					'post'    => array(
						'label' => __( 'Posts', 'buddynext' ),
						'count' => null,
					),
					'comment' => array(
						'label' => __( 'Comments', 'buddynext' ),
						'count' => null,
					),
					'message' => array(
						'label' => __( 'DMs', 'buddynext' ),
						'count' => null,
					),
					'user'    => array(
						'label' => __( 'Profiles', 'buddynext' ),
						'count' => null,
					),
				);
				foreach ( $type_filters as $fkey => $fmeta ) :
					if ( 'urgent' === $fkey ) {
						$is_active = ( 'urgent' === $filter_urgency );
						$fhref     = add_query_arg(
							array(
								'type'    => 'all',
								'urgency' => 'urgent',
								'sort'    => $sort_by,
							)
						);
					} else {
						$is_active = ( $fkey === $filter_type && 'all' === $filter_urgency );
						$fhref     = add_query_arg(
							array(
								'type'    => $fkey,
								'urgency' => 'all',
								'sort'    => $sort_by,
							)
						);
					}
					?>
					<a
						href="<?php echo esc_url( $fhref ); ?>"
						class="bn-tab"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					>
						<?php echo esc_html( $fmeta['label'] ); ?>
						<?php if ( null !== $fmeta['count'] ) : ?>
							<span class="bn-tab__count"><?php echo esc_html( number_format_i18n( (int) $fmeta['count'] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="bn-mod-filterbar__controls">
			<label class="screen-reader-text" for="bn-mod-sort">
				<?php esc_html_e( 'Sort reports', 'buddynext' ); ?>
			</label>
			<select
				id="bn-mod-sort"
				class="bn-select bn-mod-filterbar__sort"
				data-wp-on--change="actions.applySort"
			>
				<option value="newest" <?php selected( $sort_by, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'buddynext' ); ?></option>
				<option value="most_reported" <?php selected( $sort_by, 'most_reported' ); ?>><?php esc_html_e( 'Most reported', 'buddynext' ); ?></option>
			</select>
		</div>
	</div>

	<?php
	/**
	 * Filter the logical columns advertised by the moderation queue.
	 *
	 * Pro plugins read this set to align their parallel admin tables (bulk
	 * moderation, exports) with the canonical Free queue. The list is
	 * declarative — the v2 card layout below does not iterate it.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, string> $columns Map of column slug => label.
	 */
	$bn_mod_queue_columns = (array) apply_filters(
		'buddynext_mod_queue_columns',
		array(
			'reporter' => __( 'Reporter', 'buddynext' ),
			'reported' => __( 'Reported', 'buddynext' ),
			'reason'   => __( 'Reason', 'buddynext' ),
			'severity' => __( 'Severity', 'buddynext' ),
			'created'  => __( 'When', 'buddynext' ),
			'actions'  => __( 'Actions', 'buddynext' ),
		)
	);
	unset( $bn_mod_queue_columns ); // Reserved for Pro consumption; suppress unused-variable lints in this render scope.
	?>

	<!-- Report rows -->
	<?php if ( empty( $reports ) ) : ?>
		<div class="bn-mod-empty" role="status">
			<span class="bn-mod-empty__icon" aria-hidden="true"><?php buddynext_icon( 'check-circle' ); ?></span>
			<h2 class="bn-mod-empty__title"><?php esc_html_e( 'Nothing to review', 'buddynext' ); ?></h2>
			<p class="bn-mod-empty__body"><?php esc_html_e( 'No pending reports match the current filter. New reports will appear here as members flag content.', 'buddynext' ); ?></p>
		</div>
	<?php else : ?>
		<div class="bn-mod-list" role="list">
			<?php
			foreach ( $reports as $report ) :
				$report_id     = (int) $report['id'];
				$obj_id        = (int) $report['object_id'];
				$obj_type      = (string) $report['object_type'];
				$reason        = (string) ( $report['reason'] ?? '' );
				$report_count  = (int) ( $report['report_count'] ?? 1 );
				$strikes_count = (int) ( $report['strikes_count'] ?? 0 );
				$created_at    = (string) ( $report['created_at'] ?? '' );

				$severity = $severity_class( $report_count, $reason );
				$rbadge   = $reason_badge( $reason );

				// Determine offender identity — offender_name/offender_joined are
				// supplied by the service's enrich pass for 'user' reports.
				if ( 'user' === $obj_type ) {
					$offender_name = (string) ( $report['offender_name'] ?? '' );
					if ( '' === $offender_name ) {
						$offender_name = __( 'Unknown user', 'buddynext' );
					}
					$offender_joined = (string) ( $report['offender_joined'] ?? '' );
					$joined_date     = '' !== $offender_joined
						? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( (int) strtotime( $offender_joined ), time() ) )
						: '';
				} else {
					$offender_name = __( 'Reported content', 'buddynext' );
					$joined_date   = '';
				}
				$offender_inits = AvatarService::initials_for( $offender_name );

				// Offender user ID for user-level actions (warn / strike / suspend).
				// user reports → the reported user; post/comment → content author.
				if ( 'user' === $obj_type ) {
					$offender_id = $obj_id;
				} elseif ( isset( $post_excerpts[ $obj_id ]['author_id'] ) ) {
					$offender_id = (int) $post_excerpts[ $obj_id ]['author_id'];
				} else {
					$offender_id = 0;
				}

				// Verb describing what was reported.
				$verb_map = array(
					'post'    => __( 'posted content', 'buddynext' ),
					'comment' => __( 'left a comment', 'buddynext' ),
					'message' => __( 'sent a message', 'buddynext' ),
					'user'    => __( 'profile flagged', 'buddynext' ),
					'space'   => __( 'space flagged', 'buddynext' ),
				);
				$verb     = $verb_map[ $obj_type ] ?? __( 'flagged', 'buddynext' );

				// Content preview snippet.
				$content_excerpt = '';
				if ( in_array( $obj_type, array( 'post', 'comment' ), true ) && isset( $post_excerpts[ $obj_id ] ) ) {
					$content_excerpt = substr( (string) $post_excerpts[ $obj_id ]['content'], 0, 200 );
				} elseif ( 'space' === $obj_type ) {
					$content_excerpt = $space_names[ $obj_id ] ?? __( 'Space content', 'buddynext' );
				} elseif ( 'user' === $obj_type ) {
					$content_excerpt = __( 'User profile reported.', 'buddynext' );
				} elseif ( 'message' === $obj_type ) {
					$content_excerpt = __( 'Private message — content not shown to protect privacy.', 'buddynext' );
				}

				// Time ago + ISO datetime.
				$created_ts   = $created_at ? (int) strtotime( $created_at . ' UTC' ) : 0;
				$time_diff    = $created_ts ? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( $created_ts, time() ) ) : '';
				$iso_datetime = $created_ts ? gmdate( DATE_ATOM, $created_ts ) : '';

				// Reporter avatar initials.
				$reporter_id    = (int) ( $report['reporter_id'] ?? 0 );
				$reporter_user  = get_userdata( $reporter_id );
				$reporter_inits = $reporter_user ? AvatarService::initials_for( $reporter_user->display_name ) : '?';
				?>
				<article class="bn-report-row"
					role="listitem"
					data-severity="<?php echo esc_attr( $severity ); ?>"
					data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
					data-wp-context='{"reportId":<?php echo (int) $report_id; ?>,"userId":<?php echo (int) $offender_id; ?>}'
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: offender name. */ __( 'Report against %s', 'buddynext' ), $offender_name ) ); ?>">

					<div class="bn-report-row__avatar">
						<span class="bn-avatar" data-size="md" aria-hidden="true"><?php echo esc_html( $offender_inits ); ?></span>
					</div>

					<div class="bn-report-row__body">
						<div class="bn-report-row__head">
							<span class="bn-report-row__name"><?php echo esc_html( $offender_name ); ?></span>
							<span class="bn-report-row__verb"><?php echo esc_html( $verb ); ?></span>
							<span class="bn-badge" data-tone="<?php echo esc_attr( $rbadge['tone'] ); ?>"><?php echo esc_html( $rbadge['label'] ); ?></span>
							<?php if ( $report_count > 1 ) : ?>
								<span class="bn-badge" data-tone="danger">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of reports. */
											_n( '%d report', '%d reports', $report_count, 'buddynext' ),
											$report_count
										)
									);
									?>
								</span>
							<?php endif; ?>
							<?php if ( $iso_datetime ) : ?>
								<time class="bn-report-row__time" datetime="<?php echo esc_attr( $iso_datetime ); ?>"><?php echo esc_html( $time_diff ); ?></time>
							<?php endif; ?>
						</div>

						<?php if ( $joined_date || $strikes_count > 0 ) : ?>
							<div class="bn-report-row__meta">
								<?php if ( $joined_date ) : ?>
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: time since joining. */
												__( 'Joined %s', 'buddynext' ),
												$joined_date
											)
										);
										?>
									</span>
								<?php endif; ?>
								<?php if ( $strikes_count > 0 ) : ?>
									<?php if ( $joined_date ) : ?>
										<span class="bn-report-row__meta-dot" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="bn-strike-dots" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: strike count. */ _n( '%d strike', '%d strikes', $strikes_count, 'buddynext' ), $strikes_count ) ); ?>">
										<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
											<span class="bn-strike-dots__dot"<?php echo $i <= $strikes_count ? ' data-active' : ''; ?> aria-hidden="true"></span>
										<?php endfor; ?>
									</span>
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: strike count. */
												_n( '%d strike', '%d strikes', $strikes_count, 'buddynext' ),
												$strikes_count
											)
										);
										?>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( $content_excerpt ) : ?>
							<blockquote class="bn-report-row__excerpt"><?php echo wp_kses_post( $content_excerpt ); ?></blockquote>
						<?php endif; ?>

						<div class="bn-report-row__reason">
							<span class="bn-report-row__reason-label"><?php esc_html_e( 'Reason', 'buddynext' ); ?></span>
							<span><?php echo esc_html( $reason ); ?></span>
						</div>

						<div class="bn-report-row__reporters">
							<span class="bn-report-row__reporters-stack" aria-hidden="true">
								<span class="bn-avatar" data-size="xs"><?php echo esc_html( $reporter_inits ); ?></span>
							</span>
							<?php if ( $report_count > 1 ) : ?>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of members who reported. */
											_n( '%d member reported this', '%d members reported this', $report_count, 'buddynext' ),
											$report_count
										)
									);
									?>
								</span>
							<?php else : ?>
								<span><?php esc_html_e( '1 member reported this', 'buddynext' ); ?></span>
							<?php endif; ?>
							<span class="bn-report-row__meta-dot" aria-hidden="true"></span>
							<button type="button"
								class="bn-report-row__context-link"
								data-wp-on--click="actions.viewInContext"
								data-object-type="<?php echo esc_attr( $obj_type ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								<?php esc_html_e( 'View in context', 'buddynext' ); ?>
							</button>
						</div>

						<div class="bn-report-row__actions">
							<?php
							/**
							 * Fires inside each moderation-queue row's action
							 * cluster, before Free's built-in action buttons.
							 *
							 * Pro hooks here to inject bulk-select checkboxes,
							 * extra inline actions, or status badges. The report
							 * is the consolidated, hydrated queue item from
							 * ModerationService::get_queue() (one row per reported
							 * object) — keys: id, reporter_id, object_type,
							 * object_id, reason, reasons, report_count,
							 * reporter_count, status, created_at, plus offender
							 * enrichment (strikes_count, offender_name,
							 * offender_joined) on 'user' reports.
							 *
							 * Output is rendered verbatim inside a
							 * .bn-report-row__actions container — handlers
							 * must escape on output.
							 *
							 * @since 1.1.0
							 *
							 * @param array<string,mixed> $report Hydrated queue item.
							 */
							do_action( 'buddynext_mod_queue_row_actions', $report );
							?>

							<button type="button"
								class="bn-btn"
								data-variant="secondary"
								data-size="sm"
								data-wp-on--click="actions.viewObject"
								data-object-type="<?php echo esc_attr( $obj_type ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								<?php buddynext_icon( 'eye' ); ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: object type (post, comment, etc.). */
										__( 'View %s', 'buddynext' ),
										$obj_type
									)
								);
								?>
							</button>

							<button type="button"
								class="bn-btn"
								data-variant="ghost"
								data-size="sm"
								data-wp-on--click="actions.dismiss"
								data-report-id="<?php echo esc_attr( (string) $report_id ); ?>">
								<?php buddynext_icon( 'check' ); ?>
								<?php esc_html_e( 'Dismiss', 'buddynext' ); ?>
							</button>

							<?php if ( in_array( $obj_type, array( 'post', 'comment', 'message' ), true ) ) : ?>
								<button type="button"
									class="bn-btn"
									data-variant="danger"
									data-size="sm"
									data-wp-on--click="actions.removeContent"
									data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
									data-object-type="<?php echo esc_attr( $obj_type ); ?>"
									data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
									<?php buddynext_icon( 'trash' ); ?>
									<?php esc_html_e( 'Remove content', 'buddynext' ); ?>
								</button>
							<?php endif; ?>

							<button type="button"
								class="bn-btn"
								data-variant="secondary"
								data-size="sm"
								data-wp-on--click="actions.warnUser"
								data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
								data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
								<?php buddynext_icon( 'alert-triangle' ); ?>
								<?php esc_html_e( 'Warn user', 'buddynext' ); ?>
							</button>

							<?php if ( buddynext_can( $current_user_id, 'buddynext-moderation/issue-strike' ) ) : ?>
								<button type="button"
									class="bn-btn"
									data-variant="secondary"
									data-size="sm"
									data-wp-on--click="actions.strikeUser"
									data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
									data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
									<?php buddynext_icon( 'zap' ); ?>
									<?php esc_html_e( 'Strike user', 'buddynext' ); ?>
								</button>
							<?php endif; ?>

							<?php if ( buddynext_can( $current_user_id, 'buddynext-moderation/suspend-user' ) ) : ?>
								<button type="button"
									class="bn-btn"
									data-variant="danger"
									data-size="sm"
									data-wp-on--click="actions.suspendUser"
									data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
									data-object-id="<?php echo esc_attr( (string) $obj_id ); ?>">
									<?php buddynext_icon( 'ban' ); ?>
									<?php esc_html_e( 'Suspend account', 'buddynext' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ( $bn_mq_pages > 1 ) : ?>
			<nav class="bn-mq-pagination" aria-label="<?php esc_attr_e( 'Moderation queue pages', 'buddynext' ); ?>">
				<?php if ( $bn_mq_page > 1 ) : ?>
					<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mq_page', $bn_mq_page - 1 ) ); ?>" rel="prev">
						<?php esc_html_e( 'Previous', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
				<span class="bn-mq-pagination__status">
					<?php
					/* translators: 1: current page number, 2: total page count. */
					printf( esc_html__( 'Page %1$d of %2$d', 'buddynext' ), (int) $bn_mq_page, (int) $bn_mq_pages );
					?>
				</span>
				<?php if ( $bn_mq_page < $bn_mq_pages ) : ?>
					<a class="bn-btn" data-variant="secondary" data-size="sm" href="<?php echo esc_url( add_query_arg( 'mq_page', $bn_mq_page + 1 ) ); ?>" rel="next">
						<?php esc_html_e( 'Next', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	// Destructive-action confirmation is handled by the shared shell helper
	// bnConfirm() (assets/js/shell/dialog.js), invoked from the moderation
	// store's removeContent / suspendUser actions. No state-bound confirm
	// modal is rendered here — a stale one used to live in this slot bound to
	// state.confirmTitle / actions.confirmAction that the store never
	// implemented, which produced an empty, unusable dialog.
	?>

</div>
