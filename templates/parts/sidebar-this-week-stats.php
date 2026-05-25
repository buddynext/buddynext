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

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Notifications received this week + the prior week (for the WoW delta).
// Each metric goes through a buddynext_user_weekly_* filter so a
// gamification plugin (wb-gamification) can replace BN's inline COUNT
// with its canonical value. The default branch runs only when the
// filter returns null (meaning: nobody overrode it).
$bn_notifs_7d = apply_filters( 'buddynext_user_weekly_notifications_count', null, $bn_uid );
if ( null === $bn_notifs_7d ) {
	$bn_notifs_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid ) );
}
$bn_notifs_7d = (int) $bn_notifs_7d;

$bn_notifs_prev_7d = apply_filters( 'buddynext_user_weekly_notifications_prev_count', null, $bn_uid );
if ( null === $bn_notifs_prev_7d ) {
	$bn_notifs_prev_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 14 DAY ) AND created_at <  DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid ) );
}
$bn_notifs_prev_7d = (int) $bn_notifs_prev_7d;

$bn_notifs_read_7d = apply_filters( 'buddynext_user_weekly_notifications_read_count', null, $bn_uid );
if ( null === $bn_notifs_read_7d ) {
	$bn_notifs_read_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND is_read = 1 AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid ) );
}
$bn_notifs_read_7d = (int) $bn_notifs_read_7d;

$bn_new_followers_7d = apply_filters( 'buddynext_user_weekly_followers_gained', null, $bn_uid );
if ( null === $bn_new_followers_7d ) {
	$bn_new_followers_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid ) );
}
$bn_new_followers_7d = (int) $bn_new_followers_7d;

$bn_engagement_in_7d = apply_filters( 'buddynext_user_weekly_engagement_received', null, $bn_uid );
if ( null === $bn_engagement_in_7d ) {
	$bn_reactions_in_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions r INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id WHERE r.object_type = 'post' AND p.user_id = %d AND r.user_id != %d AND r.created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid, $bn_uid ) );
	$bn_comments_in_7d   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments c INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id WHERE c.object_type = 'post' AND p.user_id = %d AND c.user_id != %d AND c.created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $bn_uid, $bn_uid ) );
	$bn_engagement_in_7d = $bn_reactions_in_7d + $bn_comments_in_7d;
}
$bn_engagement_in_7d = (int) $bn_engagement_in_7d;

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Week-over-week delta percent. Suppressed when prior-week is 0 (we
// can't divide by zero, and "first week" deltas are misleading).
$bn_wow_delta_label = '';
$bn_wow_trend       = 'flat';
if ( $bn_notifs_prev_7d > 0 ) {
	$bn_pct = (int) round( ( ( $bn_notifs_7d - $bn_notifs_prev_7d ) / $bn_notifs_prev_7d ) * 100 );
	if ( 0 !== $bn_pct ) {
		$bn_wow_delta_label = ( $bn_pct > 0 ? '+' : '' ) . $bn_pct . '%';
		$bn_wow_trend       = $bn_pct > 0 ? 'up' : 'down';
	}
}

// Read-rate as a percent label. 0 when no notifs to read.
$bn_read_rate_label = '';
if ( $bn_notifs_7d > 0 ) {
	$bn_read_rate_label = (int) round( ( $bn_notifs_read_7d / $bn_notifs_7d ) * 100 ) . '%';
}

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
