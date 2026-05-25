<?php
/**
 * BuddyNext template part: notifications-hero.
 *
 * Renders the notifications hub header: page title, unread badge, mark-all-read
 * button (when there are unread notifications), and the settings shortcut.
 * Wraps the `.bn-section-head` v2 primitive.
 *
 * Used by: templates/notifications/index.php.
 *
 * @package BuddyNext
 *
 * @var int    $total_unread   Optional. Total unread count. Default 0.
 * @var string $mark_all_nonce Optional. REST nonce used by the mark-all button. Default ''.
 * @var string $rest_url       Optional. Mark-all REST endpoint URL. Default ''.
 * @var array  $classes        Optional. Extra CSS classes appended to `.bn-section-head`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_hero_before', $args )
 *   - do_action( 'buddynext_part_notifications_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'total_unread'   => isset( $total_unread ) ? (int) $total_unread : 0,
	'mark_all_nonce' => isset( $mark_all_nonce ) ? (string) $mark_all_nonce : '',
	'rest_url'       => isset( $rest_url ) ? (string) $rest_url : '',
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_hero_args', $args );

$bn_classes = array_merge( array( 'bn-section-head' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_hero_classes', $bn_classes, $args );
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

$bn_total_unread = (int) $args['total_unread'];
$bn_nonce        = (string) $args['mark_all_nonce'];
$bn_rest_url     = (string) $args['rest_url'];

do_action( 'buddynext_part_notifications_hero_before', $args );
?>
<header class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-section-head__lead">
		<div class="bn-section-head__text">
			<h1 class="bn-section-head__title">
				<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
				<?php if ( $bn_total_unread > 0 ) : ?>
					<span class="bn-badge" data-tone="accent" data-wp-text="state.unreadLabel">
					<?php
					$display = $bn_total_unread > 99 ? '99+' : (string) $bn_total_unread;
					echo esc_html(
						sprintf(
							/* translators: %s is the formatted number of unread notifications (e.g. "12" or "99+"). */
							__( '%s new', 'buddynext' ),
							$display
						)
					);
					?>
					</span>
				<?php endif; ?>
			</h1>
		</div>
	</div>
	<div class="bn-section-head__actions">
		<?php if ( $bn_total_unread > 0 ) : ?>
			<button class="bn-btn" data-variant="secondary" data-size="sm"
				data-wp-on--click="actions.markAllRead"
				data-nonce="<?php echo esc_attr( $bn_nonce ); ?>"
				data-url="<?php echo esc_attr( $bn_rest_url ); ?>">
				<?php buddynext_icon( 'check-circle' ); ?>
				<?php esc_html_e( 'Mark all read', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
		<a class="bn-btn bn-btn--prefs-link" data-variant="ghost" data-size="sm"
			href="<?php echo esc_url( PageRouter::notification_prefs_url() ); ?>"
			aria-label="<?php esc_attr_e( 'Notification preferences', 'buddynext' ); ?>">
			<?php buddynext_icon( 'settings' ); ?>
			<?php esc_html_e( 'Settings', 'buddynext' ); ?>
		</a>
	</div>
</header>
<?php
do_action( 'buddynext_part_notifications_hero_after', $args );
