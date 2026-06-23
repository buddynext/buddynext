<?php
/**
 * BuddyNext template part: notification-row.
 *
 * Renders a single notification row: pulse dot for unread state, actor avatar
 * with type pill, the composed message line with optional group counter, the
 * type badge + relative timestamp, and the inline action buttons
 * (Accept/Decline for space invites, Open/Mark-as-read otherwise).
 *
 * The message, link, icon, tone, and label are pre-composed by
 * NotificationMessageService::compose() and passed in via `$payload`, so the
 * row is a pure presenter. Adding a new notification type means adding a case
 * to the service - no template changes required.
 *
 * Used by: templates/parts/notifications-group.php.
 *
 * @package BuddyNext
 *
 * @var object   $notif_row     Required. Notification DB row.
 * @var array    $payload       Required. Pre-composed presentation payload (message,
 *                              url, icon, tone, label, group_count, actor_name).
 * @var callable $render_avatar Required. Avatar render closure.
 * @var callable $time_ago      Required. Time-ago closure.
 * @var array    $classes       Optional. Extra CSS classes appended to `.bn-notif-row`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_notification_row_before', $args )
 *   - do_action( 'buddynext_part_notification_row_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_notification_row_args',    array $args )
 *   - apply_filters( 'buddynext_part_notification_row_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'notif_row'     => isset( $notif_row ) ? $notif_row : null,
	'payload'       => isset( $payload ) && is_array( $payload ) ? $payload : array(),
	'render_avatar' => isset( $render_avatar ) && is_callable( $render_avatar ) ? $render_avatar : null,
	'time_ago'      => isset( $time_ago ) && is_callable( $time_ago ) ? $time_ago : null,
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_notification_row_args', $args );

if ( ! is_object( $args['notif_row'] ) || null === $args['render_avatar'] || null === $args['time_ago'] ) {
	return;
}

$bn_row           = $args['notif_row'];
$bn_payload       = (array) $args['payload'];
$bn_render_avatar = $args['render_avatar'];
$bn_time_ago      = $args['time_ago'];

$is_unread   = ! (bool) $bn_row->is_read;
$actor_id    = (int) $bn_row->sender_id;
$notif_type  = (string) ( $bn_row->type ?? '' );
$message     = (string) ( $bn_payload['message'] ?? '' );
$actor_name  = (string) ( $bn_payload['actor_name'] ?? '' );
$link_url    = (string) ( $bn_payload['url'] ?? '' );
$icon        = (string) ( $bn_payload['icon'] ?? 'bell' );
$tone        = (string) ( $bn_payload['tone'] ?? 'info' );
$pill_label  = (string) ( $bn_payload['label'] ?? __( 'Notification', 'buddynext' ) );
$group_count = (int) ( $bn_payload['group_count'] ?? 1 );

$bn_classes = array_merge(
	array( 'bn-notif-row' ),
	$is_unread ? array( 'bn-notif-row--unread' ) : array(),
	array_filter( (array) $args['classes'], 'is_string' )
);
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_notification_row_classes', $bn_classes, $args );
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

// Wrap the actor name in <strong> for visual emphasis without breaking
// translator placeholder ordering. We do this by escaping the message
// (which includes the actor name) and then substituting the escaped
// actor name token for the bolded variant. This keeps every string
// passing through esc_html and matches the v2 wireframe.
$rendered = esc_html( $message );
if ( '' !== $actor_name && false !== strpos( $message, $actor_name ) ) {
	$rendered = str_replace(
		esc_html( $actor_name ),
		'<strong>' . esc_html( $actor_name ) . '</strong>',
		$rendered
	);
}

do_action( 'buddynext_part_notification_row_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>"
	role="listitem"
	data-interactive
	data-wp-on--click="actions.markRead"
	data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>"
	data-notif-type="<?php echo esc_attr( $notif_type ); ?>"
	<?php if ( '' !== $link_url ) : ?>
		data-notif-link="<?php echo esc_url( $link_url ); ?>"
	<?php endif; ?>>

	<?php if ( $is_unread ) : ?>
		<span class="bn-notif-row__pulse" aria-label="<?php esc_attr_e( 'Unread', 'buddynext' ); ?>"></span>
	<?php endif; ?>

	<div class="bn-notif-row__avatar-wrap">
		<?php $bn_render_avatar( $actor_id, $icon ); ?>
		<?php if ( $actor_id > 0 ) : ?>
			<?php // For a person notification the avatar is their photo, so the small corner pill carries the type icon. A system notification (no actor) already shows the type icon as its avatar, so the pill would just duplicate it. ?>
			<span class="bn-notif-row__type" data-tone="<?php echo esc_attr( $tone ); ?>" aria-label="<?php echo esc_attr( $pill_label ); ?>">
				<?php buddynext_icon( $icon ); ?>
			</span>
		<?php endif; ?>
	</div>

	<div class="bn-notif-row__body">
		<div class="bn-notif-row__text">
			<?php
			// $rendered is built from esc_html()'d output with a single
			// <strong> wrap around the (already-escaped) actor name, so
			// the resulting HTML is safe to emit.
			echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			?>
			<?php if ( $group_count > 1 ) : ?>
				<span class="bn-notif-row__group" aria-hidden="true"><?php echo esc_html( '×' . (string) $group_count ); ?></span>
			<?php endif; ?>
		</div>

		<div class="bn-notif-row__meta">
			<span class="bn-badge" data-tone="<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $pill_label ); ?></span>
			<time class="bn-notif-row__time" datetime="<?php echo esc_attr( mysql2date( DATE_W3C, $bn_row->created_at, false ) ); ?>"><?php echo esc_html( $bn_time_ago( $bn_row->created_at ) ); ?></time>
			<?php if ( 'bn.space_invite' !== $notif_type ) : ?>
				<button class="bn-btn bn-notif-row__dismiss" data-variant="ghost" data-size="sm"
					data-wp-on--click="actions.dismiss"
					data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>"
					aria-label="<?php esc_attr_e( 'Dismiss notification', 'buddynext' ); ?>"
					title="<?php esc_attr_e( 'Dismiss', 'buddynext' ); ?>">
					<?php buddynext_icon( 'x' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( 'bn.space_invite' === $notif_type ) : ?>
			<div class="bn-notif-row__actions">
				<button class="bn-btn" data-variant="primary" data-size="sm"
					data-wp-on--click="actions.acceptSpaceInvite"
					data-object-id="<?php echo esc_attr( (string) $bn_row->object_id ); ?>"
					data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>">
					<?php esc_html_e( 'Accept', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="ghost" data-size="sm"
					data-wp-on--click="actions.declineSpaceInvite"
					data-object-id="<?php echo esc_attr( (string) $bn_row->object_id ); ?>"
					data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>">
					<?php esc_html_e( 'Decline', 'buddynext' ); ?>
				</button>
			</div>
		<?php elseif ( $is_unread ) : ?>
			<div class="bn-notif-row__actions">
				<?php if ( '' !== $link_url ) : ?>
					<a class="bn-btn" data-variant="ghost" data-size="sm"
						href="<?php echo esc_url( $link_url ); ?>"
						data-wp-on--click="actions.openAndMark"
						data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>">
						<?php esc_html_e( 'Open', 'buddynext' ); ?>
					</a>
				<?php endif; ?>
				<button class="bn-btn" data-variant="ghost" data-size="sm"
					data-wp-on--click="actions.markReadOnly"
					data-notif-id="<?php echo esc_attr( (string) $bn_row->id ); ?>"
					aria-label="<?php esc_attr_e( 'Mark this notification as read', 'buddynext' ); ?>">
					<?php esc_html_e( 'Mark as read', 'buddynext' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
do_action( 'buddynext_part_notification_row_after', $args );
