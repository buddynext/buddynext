<?php
/**
 * BuddyNext template part: profile-edit-notif-row.
 *
 * Renders a single notification-preference toggle row used in the
 * Notification preferences section of the edit-profile page.
 *
 * Pro P4.2's `PushPrefService` will hook the
 * `buddynext_part_profile_edit_notif_row_after` action to inject a
 * second per-row toggle column (push-pref toggle).
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $key         Required. Underlying pref meta-key, also used
 *                          as `data-pref` on the toggle button.
 * @var string $label       Required. Visible label.
 * @var string $description Optional. Helper text beneath the label.
 * @var bool   $value       Required. Current value.
 * @var string $label_id    Optional. Override DOM id for the label.
 * @var array  $classes     Optional. Extra CSS classes appended to `.bn-toggle-row`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_notif_row_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_notif_row_after',  $args )
 *     (Pro P4.2 PushPrefService attaches here to inject a push-pref toggle.)
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_notif_row_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_notif_row_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'key'         => isset( $key ) ? (string) $key : '',
	'label'       => isset( $label ) ? (string) $label : '',
	'description' => isset( $description ) ? (string) $description : '',
	'value'       => isset( $value ) ? (bool) $value : false,
	'label_id'    => isset( $label_id ) ? (string) $label_id : '',
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_notif_row_args', $args );

$bn_key   = (string) $args['key'];
$bn_label = (string) $args['label'];
if ( '' === $bn_key || '' === $bn_label ) {
	return;
}

$bn_desc    = (string) $args['description'];
$bn_checked = (bool) $args['value'];

$bn_label_id = '' !== (string) $args['label_id']
	? (string) $args['label_id']
	: 'bn-ep-pref-' . str_replace( '_', '-', preg_replace( '/^bn_pref_(email_)?/', '', $bn_key ) ) . '-lbl';

$bn_classes = array_merge( array( 'bn-toggle-row' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_notif_row_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_profile_edit_notif_row_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-toggle-row__copy">
		<div class="bn-toggle-row__label" id="<?php echo esc_attr( $bn_label_id ); ?>">
			<?php echo esc_html( $bn_label ); ?>
		</div>
		<?php if ( '' !== $bn_desc ) : ?>
			<div class="bn-toggle-row__desc">
				<?php echo esc_html( $bn_desc ); ?>
			</div>
		<?php endif; ?>
	</div>
	<button class="bn-toggle"
		type="button"
		role="switch"
		aria-labelledby="<?php echo esc_attr( $bn_label_id ); ?>"
		aria-checked="<?php echo $bn_checked ? 'true' : 'false'; ?>"
		data-pref="<?php echo esc_attr( $bn_key ); ?>"
		data-wp-on--click="actions.togglePref">
	</button>
</div>
<?php
do_action( 'buddynext_part_profile_edit_notif_row_after', $args );
