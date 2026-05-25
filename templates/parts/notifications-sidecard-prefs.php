<?php
/**
 * BuddyNext template part: notifications-sidecard-prefs.
 *
 * Sidebar widget pointing the viewing user to the notification preferences
 * screen. Pro P4.2 PushPrefService will hook
 * `buddynext_part_notifications_sidecard_prefs_after` to append a push
 * notification toggle row.
 *
 * Used by: templates/notifications/index.php (right sidebar).
 *
 * @package BuddyNext
 *
 * @var string $prefs_url Optional. URL to the notification preferences screen.
 *                        Defaults to `PageRouter::notification_prefs_url()`.
 * @var array  $classes   Optional. Extra CSS classes appended to `.bn-notif-sidecard`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_sidecard_prefs_before', $args )
 *   - do_action( 'buddynext_part_notifications_sidecard_prefs_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_sidecard_prefs_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_sidecard_prefs_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'prefs_url' => isset( $prefs_url ) ? (string) $prefs_url : (string) PageRouter::notification_prefs_url(),
	'classes'   => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_sidecard_prefs_args', $args );

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-sidecard' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_sidecard_prefs_classes', $bn_classes, $args );
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

$bn_prefs_url = (string) $args['prefs_url'];

do_action( 'buddynext_part_notifications_sidecard_prefs_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>" data-v2 aria-labelledby="bn-notif-side-prefs">
	<header id="bn-notif-side-prefs" class="bn-notif-sidecard__head"><?php esc_html_e( 'Preferences', 'buddynext' ); ?></header>
	<a href="<?php echo esc_url( $bn_prefs_url ); ?>" class="bn-notif-sidecard__row">
		<span class="bn-notif-sidecard__icon" aria-hidden="true"><?php buddynext_icon( 'settings' ); ?></span>
		<span class="bn-notif-sidecard__label"><?php esc_html_e( 'Notification preferences', 'buddynext' ); ?></span>
	</a>
</section>
<?php
do_action( 'buddynext_part_notifications_sidecard_prefs_after', $args );
