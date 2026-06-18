<?php
/**
 * BuddyNext template part: sidebar-this-week-stats.
 *
 * "This week" stat-grid card on the notifications right-sidebar. Mirrors
 * the v2 prototype notifications sidebar card by surfacing four week-
 * over-week metrics in a 2×2 grid:
 *
 *   ┌──────────────────────────────────────────┐
 *   │ This week                                │
 *   │   187 notifications  +18%                │
 *   │   142 read rate      76%                 │
 *   │    12 new followers                      │
 *   │    34 engagement received                │
 *   └──────────────────────────────────────────┘
 *
 * Each tile rows: value + label + (optional) delta or %-of-total.
 *
 * @package BuddyNext
 *
 * @var int   $user_id  Required. Viewer ID. Widget returns silently when 0.
 * @var array $classes  Optional. Extra CSS classes appended to `.bn-sidebar-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_sidebar_this_week_stats_before', $args )
 *   - do_action( 'buddynext_part_sidebar_this_week_stats_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_sidebar_this_week_stats_args',    array $args )
 *   - apply_filters( 'buddynext_part_sidebar_this_week_stats_classes', array $classes, array $args )
 *
 * Gamification-bridge seams (wb-gamification or any equivalent plugin
 * can supply canonical metrics instead of BN's inline COUNT queries):
 *   - apply_filters( 'buddynext_user_weekly_notifications_count',
 *                    int $count, int $user_id ) — received this week.
 *   - apply_filters( 'buddynext_user_weekly_notifications_prev_count',
 *                    int $count, int $user_id ) — received the prior week
 *                    (used to compute the WoW % delta).
 *   - apply_filters( 'buddynext_user_weekly_notifications_read_count',
 *                    int $count, int $user_id ) — count marked-read this week.
 *   - apply_filters( 'buddynext_user_weekly_followers_gained',
 *                    int $count, int $user_id ) — new followers this week.
 *   - apply_filters( 'buddynext_user_weekly_engagement_received',
 *                    int $count, int $user_id ) — reactions + comments
 *                    received on the viewer's posts this week.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'user_id' => isset( $user_id ) ? (int) $user_id : 0,
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_sidebar_this_week_stats_args', $args );

$bn_uid = (int) $args['user_id'];
if ( $bn_uid <= 0 ) {
	return;
}

// All metrics + derived labels come from Sidebar\WidgetService::weekly_stats()
// (cached per-user, keeps the buddynext_user_weekly_* gamification filters) so
// this template stays SQL-free. The widget service is only bound when the
// Sidebar feature is enabled; fall back to a zeroed block otherwise.
$bn_widgets = function_exists( 'buddynext_service' ) ? buddynext_service( 'sidebar_widgets' ) : null;
if ( is_object( $bn_widgets ) && method_exists( $bn_widgets, 'weekly_stats' ) ) {
	$bn_stats = $bn_widgets->weekly_stats( $bn_uid );
} else {
	$bn_stats = array(
		'notifications'   => 0,
		'read'            => 0,
		'new_followers'   => 0,
		'engagement'      => 0,
		'wow_delta_label' => '',
		'wow_trend'       => 'flat',
		'read_rate_label' => '',
	);
}

$bn_notifs_7d        = (int) $bn_stats['notifications'];
$bn_notifs_read_7d   = (int) $bn_stats['read'];
$bn_new_followers_7d = (int) $bn_stats['new_followers'];
$bn_engagement_in_7d = (int) $bn_stats['engagement'];
$bn_wow_delta_label  = (string) $bn_stats['wow_delta_label'];
$bn_wow_trend        = (string) $bn_stats['wow_trend'];
$bn_read_rate_label  = (string) $bn_stats['read_rate_label'];

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-card', 'bn-this-week-stats' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_sidebar_this_week_stats_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

$bn_format = static fn( int $n ): string => $n >= 1000 ? round( $n / 1000, 1 ) . 'k' : (string) $n;

do_action( 'buddynext_part_sidebar_this_week_stats_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'This week stats', 'buddynext' ); ?>">
	<h3 class="bn-this-week-stats__title"><?php esc_html_e( 'This week', 'buddynext' ); ?></h3>
	<div class="bn-this-week-stats__grid">

		<div class="bn-stat">
			<div class="bn-stat__value">
				<?php echo esc_html( $bn_format( $bn_notifs_7d ) ); ?>
				<?php if ( '' !== $bn_wow_delta_label ) : ?>
					<span class="bn-stat__delta" data-trend="<?php echo esc_attr( $bn_wow_trend ); ?>">
						<?php echo esc_html( $bn_wow_delta_label ); ?>
					</span>
				<?php endif; ?>
			</div>
			<div class="bn-stat__label"><?php esc_html_e( 'notifications', 'buddynext' ); ?></div>
		</div>

		<div class="bn-stat">
			<div class="bn-stat__value">
				<?php echo esc_html( $bn_format( $bn_notifs_read_7d ) ); ?>
				<?php if ( '' !== $bn_read_rate_label ) : ?>
					<span class="bn-stat__delta" data-trend="flat">
						<?php echo esc_html( $bn_read_rate_label ); ?>
					</span>
				<?php endif; ?>
			</div>
			<div class="bn-stat__label"><?php esc_html_e( 'read', 'buddynext' ); ?></div>
		</div>

		<div class="bn-stat">
			<div class="bn-stat__value"><?php echo esc_html( $bn_format( $bn_new_followers_7d ) ); ?></div>
			<div class="bn-stat__label"><?php esc_html_e( 'new followers', 'buddynext' ); ?></div>
		</div>

		<div class="bn-stat">
			<div class="bn-stat__value"><?php echo esc_html( $bn_format( $bn_engagement_in_7d ) ); ?></div>
			<div class="bn-stat__label"><?php esc_html_e( 'reactions + comments', 'buddynext' ); ?></div>
		</div>

	</div>
</section>
<?php
do_action( 'buddynext_part_sidebar_this_week_stats_after', $args );
