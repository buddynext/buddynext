<?php
/**
 * BuddyNext — Composer AI helper modal.
 *
 * When BuddyNext Pro is active (BUDDYNEXTPRO_VERSION defined) Pro replaces
 * the body via the `buddynext_composer_ai_modal_body` action. When Pro is
 * not active a soft upsell renders pointing to the Pro upgrade page.
 *
 * Variables:
 *   int  $composer_user_id Author user ID.
 *   bool $has_pro          Whether Pro is loaded.
 *
 * @package BuddyNext
 * @since   1.4.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$composer_user_id = absint( $composer_user_id ?? 0 );
$has_pro          = (bool) ( $has_pro ?? false );
if ( 0 === $composer_user_id ) {
	return;
}

/**
 * Filter the Pro upgrade URL used on the composer AI upsell.
 *
 * @since 1.4.0
 *
 * @param string $url Default upgrade URL.
 */
$pro_url = (string) apply_filters( 'buddynext_pro_upgrade_url', 'https://wbcomdesigns.com/downloads/buddynext-pro/' );
?>
<div class="bn-modal-backdrop bn-composer-modal"
	hidden
	data-wp-interactive="buddynext/post-composer"
	data-wp-bind--hidden="!state.aiOpen">
	<div
		class="bn-modal__panel bn-composer-modal__panel"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-composer-ai-title"
		data-size="lg">
		<div class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-composer-ai-title">
				<?php esc_html_e( 'AI writing assistant', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeAiHelper">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body bn-composer-modal__body">
			<?php if ( $has_pro ) : ?>
				<?php
				/**
				 * Fires inside the AI helper modal body when Pro is active.
				 *
				 * Pro hooks here to render the AI suggestion list, tone picker,
				 * and apply-to-composer button. The free-plugin body is the
				 * upsell branch.
				 *
				 * @since 1.4.0
				 *
				 * @param int $composer_user_id Composer author ID.
				 */
				do_action( 'buddynext_composer_ai_modal_body', $composer_user_id );
				?>
			<?php else : ?>
				<div class="bn-composer-modal__upsell">
					<span class="bn-composer-modal__upsell-icon" aria-hidden="true">
						<?php buddynext_icon( 'sparkles' ); ?>
					</span>
					<p class="bn-composer-modal__upsell-title">
						<?php esc_html_e( 'AI writing assistant ships with BuddyNext Pro', 'buddynext' ); ?>
					</p>
					<p class="bn-composer-modal__upsell-text">
						<?php esc_html_e( 'Generate post drafts, polish tone, and translate replies — all from the composer.', 'buddynext' ); ?>
					</p>
					<a class="bn-btn"
						data-variant="primary"
						href="<?php echo esc_url( $pro_url ); ?>"
						target="_blank"
						rel="noopener noreferrer">
						<?php esc_html_e( 'Learn more about Pro', 'buddynext' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
