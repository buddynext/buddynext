<?php
/**
 * BuddyNext - Space delete confirm modal partial.
 *
 * Two-step deletion gate: user must type the exact space name to enable
 * the Delete button. Opened by `actions.openDeleteSpaceConfirm` from the
 * Danger zone tab; submits via DELETE /buddynext/v1/spaces/{id} with the
 * confirmation header so the controller can re-verify.
 *
 * Variables:
 *   int    $space_id   Space being deleted.
 *   string $space_name Exact space name (display only, also used for the
 *                      gate via the JS dataset).
 *
 * Overridable: copy to {theme}/buddynext/partials/space-delete-confirm-modal.php.
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_dcm_space_id   = isset( $space_id ) ? absint( $space_id ) : 0;
$bn_dcm_space_name = isset( $space_name ) ? (string) $space_name : '';
?>
<div
	class="bn-modal-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-delete-space-confirm-title"
	hidden
	data-bn-modal="delete-space-confirm"
	data-bn-space-id="<?php echo esc_attr( (string) $bn_dcm_space_id ); ?>"
	data-bn-space-name="<?php echo esc_attr( $bn_dcm_space_name ); ?>"
>
	<div class="bn-modal__panel" data-tone="danger" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-delete-space-confirm-title">
				<?php esc_html_e( 'Delete this space?', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-bn-modal-close
			><?php buddynext_icon( 'x' ); ?></button>
		</header>

		<div class="bn-modal__body">
			<p>
				<?php esc_html_e( 'This permanently deletes the space, all of its posts, and all member relationships. This cannot be undone.', 'buddynext' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: the space name, shown in bold. */
					esc_html__( 'Type %s to confirm.', 'buddynext' ),
					'<strong>' . esc_html( $bn_dcm_space_name ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>
			<label class="bn-sr-only" for="bn-delete-space-gate">
				<?php esc_html_e( 'Type the space name to confirm', 'buddynext' ); ?>
			</label>
			<input
				type="text"
				id="bn-delete-space-gate"
				class="bn-input"
				autocomplete="off"
				data-bn-delete-gate
			>
			<p class="bn-modal__error" data-bn-delete-error hidden></p>
		</div>

		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-size="md"
				data-bn-modal-close
			><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
			<button
				type="button"
				class="bn-btn"
				data-variant="danger"
				data-size="md"
				disabled
				data-bn-delete-submit
				data-wp-on--click="actions.deleteSpaceConfirmed"
			><?php esc_html_e( 'Delete permanently', 'buddynext' ); ?></button>
		</div>
	</div>
</div>
