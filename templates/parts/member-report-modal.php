<?php
/**
 * BuddyNext template part: member-report-modal.
 *
 * Renders the cross-surface "Report this profile" modal opened
 * imperatively from the per-card kebab menu. The modal is rendered
 * OUTSIDE the `data-wp-interactive="buddynext/members"` element so the
 * embedded `data-wp-on--click` bindings are bound by the parent store.
 *
 * Used by: templates/directory/members.php.
 *
 * @package BuddyNext
 *
 * @var string $nonce          Optional. REST nonce — exposed via `_args` for listeners. Default ''.
 * @var array  $i18n_strings   Optional. Override map of label slugs => translated strings.
 *                             Recognized keys: title, help, reason_label, notes_label,
 *                             notes_placeholder, close_label, cancel, submit. Default [].
 * @var array  $report_reasons Optional. Reason-key => label map. Default ships the standard set.
 * @var array  $classes        Optional. Extra CSS classes appended to the backdrop. Default [].
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_report_modal_before', $args )
 *   - do_action( 'buddynext_part_member_report_modal_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_report_modal_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_report_modal_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_default_reasons = array(
	'spam'           => __( 'Spam', 'buddynext' ),
	'harassment'     => __( 'Harassment or hate speech', 'buddynext' ),
	'misinformation' => __( 'Misinformation', 'buddynext' ),
	'inappropriate'  => __( 'Inappropriate content', 'buddynext' ),
	'fake'           => __( 'Fake account', 'buddynext' ),
	'impersonation'  => __( 'Impersonation', 'buddynext' ),
	'other'          => __( 'Something else', 'buddynext' ),
);

$args = array(
	'nonce'          => isset( $nonce ) ? (string) $nonce : '',
	'i18n_strings'   => isset( $i18n_strings ) ? (array) $i18n_strings : array(),
	'report_reasons' => isset( $report_reasons ) && ! empty( $report_reasons ) ? (array) $report_reasons : $bn_default_reasons,
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_report_modal_args', $args );

$bn_classes = array_merge(
	array( 'bn-modal-backdrop', 'bn-pf-report-backdrop' ),
	array_filter( (array) $args['classes'], 'is_string' )
);
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_report_modal_classes', $bn_classes, $args );
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

$bn_i18n = array_merge(
	array(
		'title'             => __( 'Report this profile', 'buddynext' ),
		'help'              => __( 'Reports are reviewed by moderators. The person you report is not notified.', 'buddynext' ),
		'reason_label'      => __( 'Reason', 'buddynext' ),
		'notes_label'       => __( 'Additional details (optional)', 'buddynext' ),
		'notes_placeholder' => __( 'Tell us more about what you saw...', 'buddynext' ),
		'close_label'       => __( 'Close', 'buddynext' ),
		'cancel'            => __( 'Cancel', 'buddynext' ),
		'submit'            => __( 'Submit report', 'buddynext' ),
	),
	array_filter(
		(array) $args['i18n_strings'],
		static function ( $v ) {
			return is_string( $v ) && '' !== $v;
		}
	)
);

$bn_reasons = (array) $args['report_reasons'];

do_action( 'buddynext_part_member_report_modal_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	role="dialog"
	aria-modal="true"
	aria-labelledby="bn-md-report-title"
	data-target-type="user"
	hidden
>
	<div class="bn-modal__panel" data-size="sm">
		<header class="bn-modal__head">
			<h2 class="bn-modal__title" id="bn-md-report-title"><?php echo esc_html( $bn_i18n['title'] ); ?></h2>
			<button
				class="bn-modal__close"
				type="button"
				aria-label="<?php echo esc_attr( $bn_i18n['close_label'] ); ?>"
				data-wp-on--click="actions.closeReport"
			><?php buddynext_icon( 'x' ); ?></button>
		</header>
		<div class="bn-modal__body bn-modal__body--stack">
			<p class="bn-modal__help"><?php echo esc_html( $bn_i18n['help'] ); ?></p>
			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-reason"><?php echo esc_html( $bn_i18n['reason_label'] ); ?></label>
				<select class="bn-input" id="bn-pf-report-reason">
					<?php foreach ( $bn_reasons as $bn_rk => $bn_rl ) : ?>
						<option value="<?php echo esc_attr( (string) $bn_rk ); ?>"><?php echo esc_html( (string) $bn_rl ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="bn-ep-field bn-ep-field--full">
				<label class="bn-ep-label" for="bn-pf-report-notes"><?php echo esc_html( $bn_i18n['notes_label'] ); ?></label>
				<textarea class="bn-textarea" id="bn-pf-report-notes" rows="3" maxlength="500" placeholder="<?php echo esc_attr( $bn_i18n['notes_placeholder'] ); ?>"></textarea>
			</div>
		</div>
		<footer class="bn-modal__foot">
			<button
				class="bn-btn"
				type="button"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.closeReport"
			><?php echo esc_html( $bn_i18n['cancel'] ); ?></button>
			<button
				class="bn-btn"
				type="button"
				data-variant="primary"
				data-size="md"
				data-wp-on--click="actions.submitReport"
			><?php echo esc_html( $bn_i18n['submit'] ); ?></button>
		</footer>
	</div>
</div>
<?php
do_action( 'buddynext_part_member_report_modal_after', $args );
