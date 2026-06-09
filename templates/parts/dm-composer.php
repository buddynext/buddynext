<?php
/**
 * BuddyNext template part: dm-composer.
 *
 * Renders the message-composer at the bottom of the thread pane: the
 * "replying to" hint row (visible when context.replyToId is set), the
 * emoji + attach buttons, the autosizing textarea, and the send button.
 *
 * Used by: templates/messages/thread.php.
 *
 * @package BuddyNext
 *
 * @var int    $conversation_id Required. Active conversation ID.
 * @var string $input_id        Optional. DOM id for the textarea. Default 'bn-dm-input'.
 * @var string $placeholder     Optional. Textarea placeholder text. Default 'Type a message…'.
 * @var array  $classes         Optional. Extra CSS classes appended to the wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_composer_before', $args )
 *   - do_action( 'buddynext_part_dm_composer_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_composer_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_composer_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'conversation_id' => isset( $conversation_id ) ? (int) $conversation_id : 0,
	'input_id'        => isset( $input_id ) ? (string) $input_id : 'bn-dm-input',
	'placeholder'     => isset( $placeholder ) ? (string) $placeholder : __( 'Type a message…', 'buddynext' ),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_composer_args', $args );

$bn_classes = array_merge( array( 'bn-dm-composer' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_composer_classes', $bn_classes, $args );
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

$conv_id     = (int) $args['conversation_id'];
$input_id    = (string) $args['input_id'];
$placeholder = (string) $args['placeholder'];

do_action( 'buddynext_part_dm_composer_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">

	<div class="bn-card bn-dm-composer__reply" data-wp-class--is-hidden="!context.replyToId">
		<span class="bn-dm-composer__reply-label"><?php esc_html_e( 'Replying to', 'buddynext' ); ?></span>
		<span class="bn-dm-composer__reply-text" data-wp-text="context.replyToText"></span>
		<button
			type="button"
			class="bn-btn"
			data-variant="ghost"
			data-size="sm"
			aria-label="<?php esc_attr_e( 'Cancel reply', 'buddynext' ); ?>"
			data-wp-on--click="actions.clearReply"
		>
			<?php buddynext_icon( 'x' ); ?>
		</button>
	</div>

	<div class="bn-card bn-dm-composer__attachment" data-wp-class--is-hidden="!context.attachmentVisible">
		<img class="bn-dm-composer__attachment-thumb" data-wp-bind--src="context.attachmentPreview" alt="">
		<span class="bn-dm-composer__attachment-name" data-wp-text="context.attachmentName"></span>
		<button
			type="button"
			class="bn-btn"
			data-variant="ghost"
			data-size="sm"
			aria-label="<?php esc_attr_e( 'Remove attachment', 'buddynext' ); ?>"
			data-wp-on--click="actions.clearAttachment"
		>
			<?php buddynext_icon( 'x' ); ?>
		</button>
	</div>

	<input
		type="file"
		id="bn-dm-file"
		class="bn-visually-hidden"
		accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm"
		data-wp-on--change="actions.onFileSelected"
	>

	<form class="bn-dm-composer__row" data-wp-on--submit="actions.sendMessage">
		<label for="<?php echo esc_attr( $input_id ); ?>" class="bn-visually-hidden">
			<?php esc_html_e( 'Message', 'buddynext' ); ?>
		</label>

		<span class="bn-tooltip-trigger bn-dm-emoji-wrap">
			<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>" aria-haspopup="true" data-wp-on--click="actions.openEmojiPicker">
				<?php buddynext_icon( 'smile' ); ?>
			</button>
			<span class="bn-tooltip" data-pos="top"><?php esc_html_e( 'Emoji', 'buddynext' ); ?></span>
			<?php // Grid populated lazily by the store on first open; emoji are content, sourced in JS (not PHP chrome). ?>
			<div class="bn-dm-emoji-pop" role="menu" aria-label="<?php esc_attr_e( 'Insert emoji', 'buddynext' ); ?>" hidden></div>
		</span>

		<span class="bn-tooltip-trigger">
			<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" aria-label="<?php esc_attr_e( 'Attach photo', 'buddynext' ); ?>" aria-haspopup="dialog" data-wp-on--click="actions.openMediaPicker">
				<?php buddynext_icon( 'paperclip' ); ?>
			</button>
			<span class="bn-tooltip" data-pos="top"><?php esc_html_e( 'Attach', 'buddynext' ); ?></span>
		</span>

		<textarea
			id="<?php echo esc_attr( $input_id ); ?>"
			class="bn-textarea bn-dm-composer__input"
			rows="1"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			data-wp-on--keydown="actions.onInputKeydown"
			data-wp-on--input="actions.onMessageInput"
		></textarea>

		<button
			type="submit"
			class="bn-btn"
			data-variant="primary"
			data-size="md"
			aria-label="<?php esc_attr_e( 'Send message', 'buddynext' ); ?>"
			data-conv-id="<?php echo esc_attr( (string) $conv_id ); ?>"
			data-wp-on--click="actions.sendMessage"
		>
			<?php buddynext_icon( 'send' ); ?>
		</button>
	</form>
</div>
<?php
do_action( 'buddynext_part_dm_composer_after', $args );
