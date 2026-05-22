<?php
/**
 * BuddyNext — Composer Event modal.
 *
 * Sub-modal rendered alongside the composer for scheduling an event post.
 * The Interactivity API store `buddynext/post-composer` controls visibility
 * via `state.eventOpen`; the Save button POSTs to
 * /buddynext/v1/posts with type=event.
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
	data-wp-bind--hidden="!state.eventOpen">
	<div
		class="bn-modal__panel bn-composer-modal__panel"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-composer-event-title"
		data-size="lg">
		<div class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-composer-event-title">
				<?php esc_html_e( 'Schedule an event', 'buddynext' ); ?>
			</h2>
			<button
				type="button"
				class="bn-modal__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeEvent">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<div class="bn-modal__body bn-composer-modal__body">
			<label class="bn-composer-modal__field">
				<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Title', 'buddynext' ); ?></span>
				<input
					type="text"
					class="bn-input bn-composer-modal__input"
					data-bn-event-field="title"
					placeholder="<?php esc_attr_e( 'Community meetup', 'buddynext' ); ?>"
					maxlength="120">
			</label>
			<div class="bn-composer-modal__row">
				<label class="bn-composer-modal__field">
					<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Date', 'buddynext' ); ?></span>
					<input
						type="date"
						class="bn-input bn-composer-modal__input"
						data-bn-event-field="date">
				</label>
				<label class="bn-composer-modal__field">
					<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Time', 'buddynext' ); ?></span>
					<input
						type="time"
						class="bn-input bn-composer-modal__input"
						data-bn-event-field="time">
				</label>
			</div>
			<label class="bn-composer-modal__field">
				<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Location', 'buddynext' ); ?></span>
				<input
					type="text"
					class="bn-input bn-composer-modal__input"
					data-bn-event-field="location"
					placeholder="<?php esc_attr_e( 'Online or city / venue', 'buddynext' ); ?>"
					maxlength="160">
			</label>
			<label class="bn-composer-modal__field">
				<span class="bn-composer-modal__field-label"><?php esc_html_e( 'Description', 'buddynext' ); ?></span>
				<textarea
					class="bn-input bn-textarea bn-composer-modal__textarea"
					data-bn-event-field="description"
					rows="3"
					placeholder="<?php esc_attr_e( 'What is this event about?', 'buddynext' ); ?>"></textarea>
			</label>
			<p class="bn-composer-modal__error"
				role="alert"
				hidden
				data-wp-bind--hidden="state.hasNoEventError">
				<span data-wp-text="state.eventError"></span>
			</p>
		</div>
		<div class="bn-modal__foot">
			<button
				type="button"
				class="bn-btn"
				data-variant="ghost"
				data-wp-on--click="actions.closeEvent">
				<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
			</button>
			<button
				type="button"
				class="bn-btn"
				data-variant="primary"
				data-wp-on--click="actions.submitEvent"
				data-wp-bind--disabled="state.submitting">
				<span data-wp-text="state.eventSubmitLabel"><?php esc_html_e( 'Schedule event', 'buddynext' ); ?></span>
			</button>
		</div>
	</div>
</div>
