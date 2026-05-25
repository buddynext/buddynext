<?php
/**
 * BuddyNext template part: dm-message.
 *
 * Renders a single message bubble inside the thread body: optional
 * avatar, optional quoted-reply preview, the body, time + read-receipt,
 * and any emoji reactions.
 *
 * Used by: templates/parts/dm-thread-messages.php.
 *
 * @package BuddyNext
 *
 * @var array  $message         Required. MVS message row.
 * @var int    $current_user_id Required. Viewing user ID.
 * @var int    $thread_tone     Optional. Avatar tone slot 1..8 for the other user. Default 1.
 * @var string $thread_initials Optional. Two-character initials for the other user. Default ''.
 * @var array  $classes         Optional. Extra CSS classes on the message wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_message_before', $args )
 *   - do_action( 'buddynext_part_dm_message_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_message_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_message_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'message'         => isset( $message ) ? (array) $message : array(),
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'thread_tone'     => isset( $thread_tone ) ? (int) $thread_tone : 1,
	'thread_initials' => isset( $thread_initials ) ? (string) $thread_initials : '',
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_message_args', $args );

if ( empty( $args['message'] ) ) {
	return;
}

$msg         = (array) $args['message'];
$viewer      = (int) $args['current_user_id'];
$th_tone     = (int) $args['thread_tone'];
$th_initials = (string) $args['thread_initials'];

$msg_id      = absint( $msg['id'] ?? 0 );
$msg_body    = wp_kses(
	$msg['body'] ?? '',
	array(
		'br'     => array(),
		'em'     => array(),
		'strong' => array(),
	)
);
$msg_at      = $msg['created_at'] ?? '';
$msg_uid     = absint( $msg['sender_id'] ?? 0 );
$is_mine     = $msg_uid === $viewer;
$reactions   = (array) ( $msg['reactions'] ?? array() );
$reply_to    = $msg['reply_to'] ?? null;
$read_by_all = ! empty( $msg['read_by_recipient'] );

$msg_ts = is_numeric( $msg_at ) ? (int) $msg_at : strtotime( (string) $msg_at );
$clock  = '' === (string) $msg_at ? '' : wp_date( get_option( 'time_format', 'g:i A' ), $msg_ts );
$iso    = '' === (string) $msg_at ? '' : gmdate( 'c', $msg_ts );

$bn_classes = array_merge( array( 'bn-dm-msg' ), array_filter( (array) $args['classes'], 'is_string' ) );
if ( $is_mine ) {
	$bn_classes[] = 'is-mine';
}
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_message_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_dm_message_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>">
	<?php if ( ! $is_mine ) : ?>
		<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="sm" aria-hidden="true">
			<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
		</span>
	<?php endif; ?>

	<div class="bn-dm-msg__content">
		<div class="bn-dm-bubble<?php echo $is_mine ? ' is-mine' : ''; ?>">
			<?php if ( is_array( $reply_to ) && ! empty( $reply_to['body'] ) ) : ?>
				<div class="bn-dm-bubble__quoted">
					<?php echo esc_html( wp_trim_words( sanitize_text_field( $reply_to['body'] ), 15 ) ); ?>
				</div>
			<?php endif; ?>
			<?php echo $msg_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered via wp_kses above. ?>
		</div>

		<div class="bn-dm-msg__meta">
			<time class="bn-dm-msg__time" datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $clock ); ?></time>
			<?php if ( $is_mine && $read_by_all ) : ?>
				<span class="bn-dm-msg__receipt" aria-label="<?php esc_attr_e( 'Read', 'buddynext' ); ?>">
					<?php buddynext_icon( 'check-double' ); ?>
					<span><?php esc_html_e( 'Read', 'buddynext' ); ?></span>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $reactions ) ) : ?>
			<div class="bn-dm-msg__reactions">
				<?php foreach ( $reactions as $emoji => $count ) : ?>
					<button
						type="button"
						class="bn-dm-msg__reaction"
						data-msg-id="<?php echo esc_attr( (string) $msg_id ); ?>"
						data-emoji="<?php echo esc_attr( sanitize_text_field( (string) $emoji ) ); ?>"
						data-wp-on--click="actions.toggleReaction"
						aria-label="<?php echo esc_attr( sanitize_text_field( (string) $emoji ) . ' ' . absint( $count ) ); ?>"
					>
						<?php echo esc_html( sanitize_text_field( (string) $emoji ) . ' ' . absint( $count ) ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
do_action( 'buddynext_part_dm_message_after', $args );
