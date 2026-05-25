<?php
/**
 * BuddyNext template part: profile-edit-privacy-row.
 *
 * Renders a single privacy preference control — either a full-width
 * audience `<select>` (key + label + options) or a labelled toggle row
 * (`.bn-toggle-row`). Used by the Privacy section of the edit-profile
 * page.
 *
 * Choose the variant by passing either:
 *   - `options` (array)   → renders the audience-select variant
 *   - `options` (empty)   → renders the toggle-row variant
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $key         Required. Underlying pref key (used as input name
 *                          for selects and as `data-pref` for toggles).
 * @var string $label       Required. Visible label.
 * @var string $description Optional. Helper text shown beneath the label
 *                          in the toggle variant.
 * @var mixed  $value       Required. Current value (string for select, bool for toggle).
 * @var array  $options     Optional. Audience options as key => label.
 * @var string $input_id    Optional. Override DOM id for the `<select>`.
 * @var string $label_id    Optional. Override DOM id for the label (toggle variant).
 * @var array  $classes     Optional. Extra CSS classes appended to the root element.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_edit_privacy_row_before', $args )
 *   - do_action( 'buddynext_part_profile_edit_privacy_row_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_edit_privacy_row_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_edit_privacy_row_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'key'         => isset( $key ) ? (string) $key : '',
	'label'       => isset( $label ) ? (string) $label : '',
	'description' => isset( $description ) ? (string) $description : '',
	'value'       => isset( $value ) ? $value : '',
	'options'     => isset( $options ) && is_array( $options ) ? $options : array(),
	'input_id'    => isset( $input_id ) ? (string) $input_id : '',
	'label_id'    => isset( $label_id ) ? (string) $label_id : '',
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_edit_privacy_row_args', $args );

$bn_key   = (string) $args['key'];
$bn_label = (string) $args['label'];
if ( '' === $bn_key || '' === $bn_label ) {
	return;
}

$bn_is_select = ! empty( $args['options'] );
$bn_root_base = $bn_is_select ? 'bn-ep-field' : 'bn-toggle-row';
if ( $bn_is_select ) {
	$bn_root_classes_default = array( 'bn-ep-field', 'bn-ep-field--full' );
} else {
	$bn_root_classes_default = array( 'bn-toggle-row' );
}

$bn_classes = array_merge( $bn_root_classes_default, array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_edit_privacy_row_classes', $bn_classes, $args );
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

$bn_input_id = '' !== (string) $args['input_id']
	? (string) $args['input_id']
	: 'bn-ep-privacy-' . str_replace( '_', '-', preg_replace( '/^bn_privacy_/', '', $bn_key ) );
$bn_label_id = '' !== (string) $args['label_id']
	? (string) $args['label_id']
	: $bn_input_id . '-lbl';

do_action( 'buddynext_part_profile_edit_privacy_row_before', $args );

if ( $bn_is_select ) :
	$bn_value = (string) $args['value'];
	?>
	<div class="<?php echo esc_attr( $bn_class ); ?>">
		<label class="bn-ep-label" for="<?php echo esc_attr( $bn_input_id ); ?>">
			<?php echo esc_html( $bn_label ); ?>
		</label>
		<select class="bn-input"
			id="<?php echo esc_attr( $bn_input_id ); ?>"
			name="<?php echo esc_attr( $bn_key ); ?>"
			data-wp-on--change="actions.markDirty">
			<?php foreach ( (array) $args['options'] as $bn_opt_key => $bn_opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $bn_opt_key ); ?>" <?php selected( $bn_value, (string) $bn_opt_key ); ?>>
					<?php echo esc_html( (string) $bn_opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
else :
	$bn_checked = (bool) $args['value'];
	$bn_desc    = (string) $args['description'];
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
endif;

do_action( 'buddynext_part_profile_edit_privacy_row_after', $args );
