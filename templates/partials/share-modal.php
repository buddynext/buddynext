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
				<?php esc_html_e( 'Share this post', 'buddynext' ); ?>
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
			<button type="button"
				class="bn-share-modal__option"
				data-wp-on--click="actions.repost"
				data-wp-bind--disabled="state.busy">
				<span class="bn-share-modal__option-icon" aria-hidden="true">
					<?php buddynext_icon( 'share' ); ?>
				</span>
				<span class="bn-share-modal__option-text">
					<strong><?php esc_html_e( 'Repost', 'buddynext' ); ?></strong>
					<small><?php esc_html_e( 'Share this post to your feed.', 'buddynext' ); ?></small>
				</span>
			</button>
			<button type="button"
				class="bn-share-modal__option"
				data-wp-on--click="actions.quote"
				data-wp-bind--disabled="state.busy">
				<span class="bn-share-modal__option-icon" aria-hidden="true">
					<?php buddynext_icon( 'edit' ); ?>
				</span>
				<span class="bn-share-modal__option-text">
					<strong><?php esc_html_e( 'Quote', 'buddynext' ); ?></strong>
					<small><?php esc_html_e( 'Compose a new post with this one quoted.', 'buddynext' ); ?></small>
				</span>
			</button>
			<button type="button"
				class="bn-share-modal__option"
				data-wp-on--click="actions.copyLink"
				data-wp-bind--disabled="state.busy">
				<span class="bn-share-modal__option-icon" aria-hidden="true">
					<?php buddynext_icon( 'link' ); ?>
				</span>
				<span class="bn-share-modal__option-text">
					<strong><?php esc_html_e( 'Copy link', 'buddynext' ); ?></strong>
					<small><?php esc_html_e( 'Copy a direct link to the post.', 'buddynext' ); ?></small>
				</span>
			</button>
			<p class="bn-share-modal__error"
				role="alert"
				hidden
				data-wp-bind--hidden="state.hasNoError">
				<span data-wp-text="state.error"></span>
			</p>
		</div>
	</div>
</div>
