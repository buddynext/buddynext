<?php
/**
 * BuddyNext — Composer Voice-room modal.
 *
 * Sub-modal for scheduling a voice room. Save button POSTs to
 * /buddynext/v1/posts with type=voice_room.
 *
 * Variables:
 *   int $composer_user_id Author user ID.
 *
 * @package BuddyNext
 * @since   1.4.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$composer_user_id = absint( $composer_user_id ?? 0 );
if ( 0 === $composer_user_id ) {
	return;
}
?>
<div class="bn-modal-backdrop bn-composer-modal"
	hidden
	data-wp-interactive="buddynext/post-composer"
	data-wp-bind--hidden="!state.voiceOpen">
	<div
		class="bn-modal__panel bn-composer-modal__panel"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-composer-voice-title"
		data-size="lg">
		<div class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-composer-voice-title">
				<?php esc_html_e( 'Schedule a voice room', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeVoice">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body bn-composer-modal__body">
			<label class="bn-composer-modal__field">
				<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Title', 'buddynext' ); ?></span>
				<input
					type="text"
					class="bn-input bn-composer-modal__input"
					data-bn-voice-field="title"
					placeholder="<?php esc_attr_e( 'Friday community chat', 'buddynext' ); ?>"
					maxlength="120">
			</label>
			<div class="bn-composer-modal__row">
				<label class="bn-composer-modal__field">
					<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Starts at', 'buddynext' ); ?></span>
					<input
						type="datetime-local"
						class="bn-input bn-composer-modal__input"
						data-bn-voice-field="scheduled_at">
				</label>
				<label class="bn-composer-modal__field">
					<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Duration (min)', 'buddynext' ); ?></span>
					<input
						type="number"
						class="bn-input bn-composer-modal__input"
						data-bn-voice-field="duration"
						min="5"
						max="240"
						step="5"
						value="30">
				</label>
			</div>
			<label class="bn-composer-modal__field">
				<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Description', 'buddynext' ); ?></span>
				<textarea
					class="bn-input bn-textarea bn-composer-modal__textarea"
					data-bn-voice-field="description"
					rows="3"
					placeholder="<?php esc_attr_e( 'Describe what you will talk about.', 'buddynext' ); ?>"></textarea>
			</label>
			<p class="bn-composer-modal__error"
				role="alert"
				hidden
				data-wp-bind--hidden="state.hasNoVoiceError">
				<span data-wp-text="state.voiceError"></span>
			</p>
		</div>
		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-wp-on--click="actions.closeVoice">
				<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
			</button>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-wp-on--click="actions.submitVoice"
				data-wp-bind--disabled="state.submitting">
				<span data-wp-text="state.voiceSubmitLabel"><?php esc_html_e( 'Schedule room', 'buddynext' ); ?></span>
			</button>
		</div>
	</div>
</div>
