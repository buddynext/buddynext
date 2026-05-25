<?php
/**
 * BuddyNext template part: dm-delete-modal.
 *
 * Confirmation modal for deleting a conversation. Visibility is bound
 * to `state.confirmOpen` in the messages Interactivity store.
 *
 * Used by: templates/messages/thread.php.
 *
 * @package BuddyNext
 *
 * @var array $classes Optional. Extra CSS classes appended to the backdrop.
 *
 * Fires:
 *   - do_action( 'buddynext_part_dm_delete_modal_before', $args )
 *   - do_action( 'buddynext_part_dm_delete_modal_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_dm_delete_modal_args',    array $args )
 *   - apply_filters( 'buddynext_part_dm_delete_modal_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_dm_delete_modal_args', $args );

$bn_classes = array_merge( array( 'bn-modal-backdrop', 'bn-dm-confirm' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_dm_delete_modal_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_dm_delete_modal_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-dm-confirm-title"
	data-wp-class--is-hidden="!state.confirmOpen"
	data-wp-on--click="actions.closeDeleteConfirm"
>
	<div class="bn-modal__panel" data-tone="danger" data-size="sm" data-wp-on--click="actions.stopPropagation">
		<div class="bn-modal__head">
			<h2 id="bn-dm-confirm-title" class="bn-modal__title"><?php esc_html_e( 'Delete conversation?', 'buddynext' ); ?></h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close dialog', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeDeleteConfirm"
			>
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body">
			<p><?php esc_html_e( 'This permanently removes all messages in this conversation for you. The other participant keeps their copy.', 'buddynext' ); ?></p>
		</div>
		<div class="bn-modal__foot">
			<button type="button" class="bn-btn" data-variant="ghost" data-size="md" data-wp-on--click="actions.closeDeleteConfirm">
				<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
			</button>
			<button type="button" class="bn-btn" data-variant="danger" data-size="md" data-wp-on--click="actions.confirmDeleteConversation">
				<?php esc_html_e( 'Delete', 'buddynext' ); ?>
			</button>
		</div>
	</div>
</div>
<?php
do_action( 'buddynext_part_dm_delete_modal_after', $args );
