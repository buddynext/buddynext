<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext - Block user confirmation modal.
 *
 * Reactive destructive-confirm modal driven by the `buddynext/profile`
 * Interactivity store (context.blockConfirmOpen). Used in place of a native
 * window.confirm() so block is a deliberate, presentation-grade action.
 *
 * Context variables (read from parent template):
 *   $display_name  string  Member name shown in the body copy.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_name = isset( $display_name ) ? (string) $display_name : '';
?>
<div class="bn-modal-backdrop bn-pf-block-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-pf-block-title"
	hidden
	data-wp-bind--hidden="!context.blockConfirmOpen">
	<div class="bn-modal__panel" data-tone="danger" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-pf-block-title">
				<?php
				if ( '' !== $display_name ) {
					echo esc_html(
						sprintf(
							/* translators: %s: member display name */
							__( 'Block %s?', 'buddynext' ),
							$display_name
						)
					);
				} else {
					esc_html_e( 'Block this member?', 'buddynext' );
				}
				?>
			</h2>
			<button class="bn-modal__close"
				type="button"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeBlockConfirm">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</header>
		<div class="bn-modal__body">
			<p>
				<?php esc_html_e( 'Blocking this person will:', 'buddynext' ); ?>
			</p>
			<ul class="bn-modal__list">
				<li><?php esc_html_e( 'Hide their posts and replies from your feed.', 'buddynext' ); ?></li>
				<li><?php esc_html_e( 'Stop them from following you or sending you messages.', 'buddynext' ); ?></li>
				<li><?php esc_html_e( 'Remove any existing connection or follow between you.', 'buddynext' ); ?></li>
			</ul>
			<p class="bn-modal__help">
				<?php esc_html_e( 'You can unblock from your settings at any time.', 'buddynext' ); ?>
			</p>
		</div>
		<footer class="bn-modal__foot">
			<button class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeBlockConfirm">
				<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
			</button>
			<button class="bn-btn"
				type="button"
				data-variant="danger"
				data-size="md"
				data-wp-on--click="actions.confirmBlock"
				data-wp-bind--disabled="context.blockSubmitting">
				<?php esc_html_e( 'Block', 'buddynext' ); ?>
			</button>
		</footer>
	</div>
</div>
