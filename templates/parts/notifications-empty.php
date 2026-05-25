<?php
/**
 * BuddyNext template part: notifications-empty.
 *
 * Filter-specific empty-state card. Renders an emblem, a title, supporting
 * copy, and a "Go to activity" CTA when the notifications list has no rows
 * for the active filter. Copy is selected based on the active filter slug.
 *
 * Used by: templates/notifications/index.php.
 *
 * @package BuddyNext
 *
 * @var string $active_filter Optional. The currently-active filter slug. Default 'all'.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-notif-empty`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notifications_empty_before', $args )
 *   - do_action( 'buddynext_part_notifications_empty_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notifications_empty_args',    array $args )
 *   - apply_filters( 'buddynext_part_notifications_empty_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'active_filter' => isset( $active_filter ) ? (string) $active_filter : 'all',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notifications_empty_args', $args );

$bn_classes = array_merge( array( 'bn-card', 'bn-notif-empty' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notifications_empty_classes', $bn_classes, $args );
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

// Filter-specific empty-state copy so each tab tells the user what
// they should see when activity exists for that category.
$empty_copy = array(
	'all'      => array(
		'title' => __( "You're all caught up", 'buddynext' ),
		'sub'   => __( 'New activity will show here. Try posting something or following a few members to get started.', 'buddynext' ),
		'icon'  => 'check-circle',
	),
	'unread'   => array(
		'title' => __( 'No unread notifications', 'buddynext' ),
		'sub'   => __( 'Everything has been read. Use the filters on the left to revisit older activity.', 'buddynext' ),
		'icon'  => 'check-circle',
	),
	'mention'  => array(
		'title' => __( 'No mentions yet', 'buddynext' ),
		'sub'   => __( 'When someone mentions you in a post or a comment, it will appear here.', 'buddynext' ),
		'icon'  => 'at-sign',
	),
	'comment'  => array(
		'title' => __( 'No comments yet', 'buddynext' ),
		'sub'   => __( 'Comments on your posts will appear here.', 'buddynext' ),
		'icon'  => 'message-circle',
	),
	'reaction' => array(
		'title' => __( 'No reactions yet', 'buddynext' ),
		'sub'   => __( 'Reactions to your posts will appear here.', 'buddynext' ),
		'icon'  => 'heart',
	),
	'follow'   => array(
		'title' => __( 'No follow activity', 'buddynext' ),
		'sub'   => __( 'New followers, connection requests, and accepted connections will appear here.', 'buddynext' ),
		'icon'  => 'users',
	),
	'space'    => array(
		'title' => __( 'No space activity', 'buddynext' ),
		'sub'   => __( 'Invites, join requests, and new posts in your spaces will appear here.', 'buddynext' ),
		'icon'  => 'home',
	),
	'message'  => array(
		'title' => __( 'No new messages', 'buddynext' ),
		'sub'   => __( 'Direct messages will appear here when someone reaches out.', 'buddynext' ),
		'icon'  => 'mail',
	),
);

$bn_active_filter = (string) $args['active_filter'];
$empty_state      = $empty_copy[ $bn_active_filter ] ?? $empty_copy['all'];

do_action( 'buddynext_part_notifications_empty_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" data-v2 role="status">
	<span class="bn-notif-empty__emblem" aria-hidden="true"><?php buddynext_icon( $empty_state['icon'] ); ?></span>
	<p class="bn-notif-empty__title"><?php echo esc_html( $empty_state['title'] ); ?></p>
	<p class="bn-notif-empty__sub"><?php echo esc_html( $empty_state['sub'] ); ?></p>
	<a class="bn-btn" data-variant="ghost" data-size="sm" href="<?php echo esc_url( PageRouter::activity_url() ); ?>">
		<?php esc_html_e( 'Go to activity', 'buddynext' ); ?>
	</a>
</div>
<?php
do_action( 'buddynext_part_notifications_empty_after', $args );
