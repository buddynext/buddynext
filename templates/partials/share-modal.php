<?php
/**
 * BuddyNext — Post share modal.
 *
 * Rendered once per page in the feed shell. The post-card store opens it by
 * setting the global `buddynext/share-modal` state with the source post ID,
 * permalink, and counts. Provides three CTAs: repost, quote, copy link.
 *
 * Variables (optional):
 *   int $current_user_id  Active viewer.
 *
 * @package BuddyNext
 * @since   1.4.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$share_modal_user_id = absint( $current_user_id ?? get_current_user_id() );
if ( 0 === $share_modal_user_id ) {
	return;
}

$share_modal_nonce = wp_create_nonce( 'wp_rest' );
?>
<div class="bn-modal-backdrop bn-share-modal"
	hidden
	data-wp-interactive="buddynext/share-modal"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			array(
				'open'      => false,
				'postId'    => 0,
				'permalink' => '',
				'author'    => '',
				'excerpt'   => '',
				'note'      => '',
				'busy'      => false,
				'error'     => '',
				'restUrl'   => rest_url( 'buddynext/v1' ),
				'nonce'     => $share_modal_nonce,
			)
		)
	);
	?>
	'
	data-wp-bind--hidden="!state.open"
	data-wp-on-document--bn-open-share-modal="actions.receiveOpen">
	<div
		class="bn-modal__panel bn-share-modal__panel"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-share-modal-title"
		data-size="sm">
		<div class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-share-modal-title">
				<?php esc_html_e( 'Share post', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.close">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body bn-share-modal__body">
			<div class="bn-share-modal__preview" hidden data-wp-bind--hidden="state.hasNoPreview">
				<span class="bn-share-modal__preview-author" data-wp-text="state.author"></span>
				<span class="bn-share-modal__preview-excerpt" data-wp-text="state.excerpt"></span>
			</div>
			<textarea
				class="bn-share-modal__note"
				rows="3"
				placeholder="<?php esc_attr_e( 'Add a comment (optional)', 'buddynext' ); ?>"
				aria-label="<?php esc_attr_e( 'Add a comment (optional)', 'buddynext' ); ?>"
				data-wp-on--input="actions.onNoteInput"
				data-wp-bind--disabled="state.busy"></textarea>
			<div class="bn-share-modal__actions">
				<button type="button"
					class="bn-btn bn-share-modal__repost"
					data-variant="primary"
					data-size="md"
					data-wp-on--click="actions.repost"
					data-wp-bind--disabled="state.busy">
					<?php buddynext_icon( 'share' ); ?>
					<span data-wp-text="state.repostLabel"><?php esc_html_e( 'Repost', 'buddynext' ); ?></span>
				</button>
				<button type="button"
					class="bn-btn bn-share-modal__copy"
					data-variant="ghost"
					data-size="md"
					data-wp-on--click="actions.copyLink"
					data-wp-bind--disabled="state.busy">
					<?php buddynext_icon( 'link' ); ?>
					<span><?php esc_html_e( 'Copy link', 'buddynext' ); ?></span>
				</button>
			</div>
			<p class="bn-share-modal__error"
				role="alert"
				hidden
				data-wp-bind--hidden="state.hasNoError">
				<span data-wp-text="state.error"></span>
			</p>
			</div>
	</div>
</div>
