<?php
/**
 * BuddyNext template part: profile-edit-save-bar.
 *
 * Renders the sticky save-bar at the bottom of the edit-profile page —
 * status pills (saved / unsaved / saving) plus the cancel link and the
 * primary submit button.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var bool   $dirty_state   Optional. Informational only — actual dirty
 *                            tracking lives in the Interactivity context.
 * @var string $save_label    Optional. Submit-button label.
 * @var string $saving_label  Optional. Submit-button label while saving.
 * @var string $discard_label Optional. Cancel-link label.
 * @var string $cancel_url    Required. Where the Cancel link points
 *                            (typically the profile-view URL).
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-ep-save-bar`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_save_bar_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_save_bar_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_save_bar_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_save_bar_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'dirty_state'   => isset( $dirty_state ) ? (bool) $dirty_state : false,
	'save_label'    => isset( $save_label ) ? (string) $save_label : __( 'Save changes', 'buddynext' ),
	'saving_label'  => isset( $saving_label ) ? (string) $saving_label : __( 'Saving...', 'buddynext' ),
	'discard_label' => isset( $discard_label ) ? (string) $discard_label : __( 'Cancel', 'buddynext' ),
	'cancel_url'    => isset( $cancel_url ) ? (string) $cancel_url : '',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_save_bar_args', $args );

$bn_classes = array_merge( array( 'bn-ep-save-bar' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_save_bar_classes', $bn_classes, $args );
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

$bn_save_label    = (string) $args['save_label'];
$bn_saving_label  = (string) $args['saving_label'];
$bn_discard_label = (string) $args['discard_label'];
$bn_cancel_url    = (string) $args['cancel_url'];

do_action( 'buddynext_part_profile_edit_save_bar_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" role="region" aria-label="<?php esc_attr_e( 'Save changes', 'buddynext' ); ?>">
	<div class="bn-ep-save-bar-inner">
		<div class="bn-ep-save-status bn-ep-save-status--saved"
			data-wp-bind--hidden="!context.saved">
			<?php buddynext_icon( 'check' ); ?>
			<span><?php esc_html_e( 'All changes saved', 'buddynext' ); ?></span>
		</div>
		<div class="bn-ep-save-status bn-ep-save-status--dirty"
			data-wp-bind--hidden="!(context.isDirty &amp;&amp; !context.saving &amp;&amp; !context.saved)">
			<span class="bn-ep-dirty-dot" aria-hidden="true"></span>
			<span><?php esc_html_e( 'Unsaved changes', 'buddynext' ); ?></span>
		</div>
		<div class="bn-ep-save-status bn-ep-save-status--saving"
			data-wp-bind--hidden="!context.saving">
			<span class="bn-ep-spinner" aria-hidden="true"></span>
			<span><?php echo esc_html( $bn_saving_label ); ?></span>
		</div>
		<div class="bn-ep-save-actions">
			<a class="bn-btn bn-ep-cancel-link"
				data-variant="ghost"
				data-size="md"
				data-wp-on--click="actions.confirmCancel"
				href="<?php echo esc_url( $bn_cancel_url ); ?>">
				<?php echo esc_html( $bn_discard_label ); ?>
			</a>
			<button class="bn-btn"
				type="submit"
				data-variant="primary"
				data-size="md"
				data-wp-bind--disabled="context.saving">
				<span data-wp-bind--hidden="context.saving"><?php echo esc_html( $bn_save_label ); ?></span>
				<span data-wp-bind--hidden="!context.saving"><?php echo esc_html( $bn_saving_label ); ?></span>
			</button>
		</div>
	</div>
</div>
<?php
do_action( 'buddynext_part_profile_edit_save_bar_after', $args );
