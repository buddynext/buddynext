<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext - Report user modal partial.
 *
 * Reactive modal rendered alongside the profile view. Driven by the
 * `buddynext/profile` Interactivity store. The store keeps the open/close
 * state plus the selected reason and notes; this template only renders the
 * shell + form controls.
 *
 * Context variables: none required. Inherits the parent profile context
 * (profileUserId, restNonce).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reasons = array(
	'spam'           => __( 'Spam', 'buddynext' ),
	'harassment'     => __( 'Harassment or hate speech', 'buddynext' ),
	'misinformation' => __( 'Misinformation', 'buddynext' ),
	'inappropriate'  => __( 'Inappropriate content', 'buddynext' ),
	'fake'           => __( 'Fake account', 'buddynext' ),
	'impersonation'  => __( 'Impersonation', 'buddynext' ),
	'other'          => __( 'Something else', 'buddynext' ),
);
?>
<div class="bn-modal-backdrop bn-pf-report-backdrop"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-pf-report-title"
	hidden
	data-wp-bind--hidden="!context.reportOpen">
	<div class="bn-modal__panel" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-pf-report-title">
				<?php esc_html_e( 'Report this profile', 'buddynext' ); ?>
			</h2>
			<button class="bn-modal__close"
				type="button"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
				data-wp-on--click="actions.closeReport">
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</header>
		<div class="bn-modal__body">
			<p class="bn-modal__help">
				<?php esc_html_e( 'Reports are reviewed by moderators. The person you report is not notified.', 'buddynext' ); ?>
			</p>

			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-reason">
					<?php esc_html_e( 'Reason', 'buddynext' ); ?>
				</label>
				<select class="bn-input"
					id="bn-pf-report-reason"
					data-wp-on--change="actions.setReportReason">
					<?php foreach ( $reasons as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>">
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-notes">
					<?php esc_html_e( 'Additional details (optional)', 'buddynext' ); ?>
				</label>
				<textarea class="bn-textarea"
					id="bn-pf-report-notes"
					rows="3"
					maxlength="500"
					placeholder="<?php esc_attr_e( 'Tell us more about what you saw...', 'buddynext' ); ?>"
					data-wp-on--input="actions.setReportNotes"></textarea>
			</div>
		</div>
		<footer class="bn-modal__foot">
			<button class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeReport">
				<?php esc_html_e( 'Cancel', 'buddynext' ); ?>
			</button>
			<button class="bn-btn"
				type="button"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.submitReport"
				data-wp-bind--disabled="context.reportSubmitting">
				<?php esc_html_e( 'Submit report', 'buddynext' ); ?>
			</button>
		</footer>
	</div>
</div>
