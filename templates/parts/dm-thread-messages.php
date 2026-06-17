<?php
/**
 * BuddyNext template part: dm-thread-messages.
 *
 * Renders the scrollable messages log inside the thread pane: day
 * dividers (Today / Yesterday / date), each message via
 * `parts/dm-message.php`, and the live "typing…" indicator.
 *
 * Used by: templates/messages/thread.php.
 *
 * @package BuddyNext
 *
 * @var array  $messages         Required. MVS message rows.
 * @var int    $current_user_id  Required. Viewing user ID.
 * @var bool   $other_is_typing  Optional. Whether the other user is typing. Default false.
 * @var string $other_first_name Optional. Other user's first name for the typing label. Default ''.
 * @var int    $thread_tone        Optional. Avatar tone slot 1..8. Default 1.
 * @var string $thread_initials    Optional. Two-character initials. Default ''.
 * @var string $thread_avatar_html Optional. Other user's get_avatar() markup for bubble avatars. Default ''.
 * @var string $aria_label         Optional. ARIA label for the log region. Default ''.
 * @var array  $classes          Optional. Extra CSS classes on the wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_thread_messages_before', $args )
 *   - do_action( 'buddynext_part_dm_thread_messages_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_thread_messages_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_thread_messages_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'messages'           => isset( $messages ) ? (array) $messages : array(),
	'current_user_id'    => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'other_is_typing'    => isset( $other_is_typing ) ? (bool) $other_is_typing : false,
	'other_first_name'   => isset( $other_first_name ) ? (string) $other_first_name : '',
	'thread_tone'        => isset( $thread_tone ) ? (int) $thread_tone : 1,
	'thread_initials'    => isset( $thread_initials ) ? (string) $thread_initials : '',
	'thread_avatar_html' => isset( $thread_avatar_html ) ? (string) $thread_avatar_html : '',
	'aria_label'         => isset( $aria_label ) ? (string) $aria_label : '',
	'classes'            => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_thread_messages_args', $args );

$bn_classes = array_merge( array( 'bn-split__pane-body', 'bn-dm-pane__body' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_thread_messages_classes', $bn_classes, $args );
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

$messages_iter = (array) $args['messages'];
$viewer        = (int) $args['current_user_id'];
$is_typing     = (bool) $args['other_is_typing'];
$first_name    = (string) $args['other_first_name'];
$th_tone       = (int) $args['thread_tone'];
$th_initials   = (string) $args['thread_initials'];
$th_avatar     = (string) $args['thread_avatar_html'];
$aria_label    = (string) $args['aria_label'];

do_action( 'buddynext_part_dm_thread_messages_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" role="log" aria-live="polite" aria-label="<?php echo esc_attr( $aria_label ); ?>" data-wp-on--click="actions.onThreadClick">

	<?php
	$prev_date = '';
	foreach ( $messages_iter as $msg ) :
		$msg_at    = $msg['created_at'] ?? '';
		$msg_ts    = is_numeric( $msg_at ) ? (int) $msg_at : strtotime( (string) $msg_at );
		$msg_date  = wp_date( 'Y-m-d', $msg_ts );
		$today     = wp_date( 'Y-m-d' );
		$yesterday = wp_date( 'Y-m-d', time() - 86400 );

		if ( $msg_date !== $prev_date ) :
			if ( $msg_date === $today ) {
				$date_label = __( 'Today', 'buddynext' );
			} elseif ( $msg_date === $yesterday ) {
				$date_label = __( 'Yesterday', 'buddynext' );
			} else {
				$date_label = wp_date( get_option( 'date_format', 'F j, Y' ), $msg_ts );
			}
			?>
			<div class="bn-dm-divider" role="separator">
				<time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $msg_ts ) ); ?>">
					<?php echo esc_html( $date_label ); ?>
				</time>
			</div>
			<?php
			$prev_date = $msg_date;
		endif;

		buddynext_get_template(
			'parts/dm-message.php',
			array(
				'message'            => $msg,
				'current_user_id'    => $viewer,
				'thread_tone'        => $th_tone,
				'thread_initials'    => $th_initials,
				'thread_avatar_html' => $th_avatar,
			)
		);
	endforeach;
	?>

	<?php if ( $is_typing ) : ?>
		<div class="bn-dm-typing" aria-live="polite">
			<span class="bn-avatar bn-dm-avatar bn-dm-tone-<?php echo (int) $th_tone; ?>" data-size="sm" aria-hidden="true">
				<?php if ( false !== strpos( $th_avatar, 'src=' ) ) : ?>
					<?php
					echo wp_kses(
						$th_avatar,
						array(
							'img' => array(
								'src'      => true,
								'class'    => true,
								'alt'      => true,
								'width'    => true,
								'height'   => true,
								'loading'  => true,
								'decoding' => true,
							),
						)
					);
					?>
				<?php else : ?>
					<span class="bn-avatar__initials"><?php echo esc_html( $th_initials ); ?></span>
				<?php endif; ?>
			</span>
			<span class="bn-dm-typing__dots" aria-hidden="true">
				<span class="bn-dm-typing__dot"></span>
				<span class="bn-dm-typing__dot"></span>
				<span class="bn-dm-typing__dot"></span>
			</span>
			<span class="bn-dm-typing__label">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: other user's first name */
						__( '%s is typing…', 'buddynext' ),
						'' !== $first_name ? $first_name : __( 'Someone', 'buddynext' )
					)
				);
				?>
			</span>
		</div>
	<?php endif; ?>

</div>
<?php
do_action( 'buddynext_part_dm_thread_messages_after', $args );
