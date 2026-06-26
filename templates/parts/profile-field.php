<?php
/**
 * BuddyNext template part: profile-field.
 *
 * Renders a single editable profile field — label + input + optional
 * error span + optional hint — used inside the About, Social Links,
 * Privacy and (eventually) custom-field sections of the edit page.
 *
 * Pro's `AdvancedFieldRenderer` (P11) replaces the default markup by
 * hooking the `buddynext_part_profile_field_args` filter; the canonical
 * extension point is the args filter itself, not a separate
 * `buddynext_profile_field_render` hook (which was proposed in early
 * drafts but never shipped — this single args seam supersedes it).
 *
 * Supported field types (via `$field['type']`):
 *   - `text`      — `<input type="text">`
 *   - `url`       — `<input type="url">` (gets validation-error wiring)
 *   - `email`     — `<input type="email">`
 *   - `textarea`  — `<textarea>`
 *   - `select`    — `<select>` driven by `$field['options']`
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array  $field    Required. Field descriptor. Shape:
 *   - `type`              (string) One of text|url|email|textarea|select.
 *   - `key`               (string) Field key (also used as input name + error key).
 *   - `label`             (string) Visible label.
 *   - `value`             (string) Current value.
 *   - `placeholder`       (string) Optional placeholder.
 *   - `hint`              (string) Optional helper text below the input.
 *   - `options`           (array)  Select options as key => label.
 *   - `required`          (bool)   Whether the field is required.
 *   - `full_width`        (bool)   When true, wraps in `.bn-ep-field--full`.
 *   - `field_id`          (string) Override DOM id for the input.
 *   - `name`              (string) Override input `name` attribute.
 *   - `error_key`         (string) Override the error key used by IA bindings.
 *   - `extra_input_class` (string) Extra class appended to the input element.
 *   - `data_attrs`        (array<string,string>) Extra data-* attributes.
 *   - `rows`              (int)    Rows for textarea (default 4).
 *   - `validate_on_blur`  (bool)   Wire data-wp-on--blur="actions.validateField"
 *                                   (used by url fields). Default false (text/textarea
 *                                   wire autosave via the calling site / per field).
 *   - `autosave_on_blur`  (bool)   Wire data-wp-on--blur="actions.autosave".
 * @var bool   $is_owner Optional. Currently informational only (kept for the seam).
 * @var string $field_id Optional. Legacy override for `field.field_id`.
 * @var array  $classes  Optional. Extra CSS classes appended to `.bn-ep-field`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_field_before', $args )
 *   - do_action( 'buddynext_part_profile_field_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_field_args',    array $args )
 *     (Pro P11's AdvancedFieldRenderer attaches here.)
 *   - apply_filters( 'buddynext_part_profile_field_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'field'    => isset( $field ) && is_array( $field ) ? $field : array(),
	'is_owner' => isset( $is_owner ) ? (bool) $is_owner : false,
	'field_id' => isset( $field_id ) ? (string) $field_id : '',
	'classes'  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_field_args', $args );

$bn_field = (array) $args['field'];
$bn_type  = isset( $bn_field['type'] ) ? (string) $bn_field['type'] : 'text';
$bn_key   = isset( $bn_field['key'] ) ? (string) $bn_field['key'] : '';
$bn_label = isset( $bn_field['label'] ) ? (string) $bn_field['label'] : '';

if ( '' === $bn_key || '' === $bn_label ) {
	return;
}

$bn_value       = isset( $bn_field['value'] ) ? (string) $bn_field['value'] : '';
$bn_placeholder = isset( $bn_field['placeholder'] ) ? (string) $bn_field['placeholder'] : '';
$bn_hint        = isset( $bn_field['hint'] ) ? (string) $bn_field['hint'] : '';
$bn_options     = isset( $bn_field['options'] ) && is_array( $bn_field['options'] ) ? $bn_field['options'] : array();
$bn_required    = ! empty( $bn_field['required'] );
$bn_full        = ! empty( $bn_field['full_width'] );
$bn_input_id    = isset( $bn_field['field_id'] ) && '' !== (string) $bn_field['field_id']
	? (string) $bn_field['field_id']
	: ( '' !== (string) $args['field_id'] ? (string) $args['field_id'] : 'bn-ep-' . str_replace( '_', '-', $bn_key ) );
$bn_input_name  = isset( $bn_field['name'] ) && '' !== (string) $bn_field['name']
	? (string) $bn_field['name']
	: $bn_key;
$bn_error_key   = isset( $bn_field['error_key'] ) && '' !== (string) $bn_field['error_key']
	? (string) $bn_field['error_key']
	: $bn_key;
$bn_input_extra = isset( $bn_field['extra_input_class'] ) ? (string) $bn_field['extra_input_class'] : '';
$bn_rows        = isset( $bn_field['rows'] ) ? (int) $bn_field['rows'] : 4;
$bn_data_attrs  = isset( $bn_field['data_attrs'] ) && is_array( $bn_field['data_attrs'] ) ? $bn_field['data_attrs'] : array();
$bn_validate    = ! empty( $bn_field['validate_on_blur'] );
$bn_autosave    = ! empty( $bn_field['autosave_on_blur'] );

$bn_classes_arr = array( 'bn-ep-field' );
if ( $bn_full ) {
	$bn_classes_arr[] = 'bn-ep-field--full';
}
$bn_classes_arr = array_merge( $bn_classes_arr, array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes_arr */
$bn_classes_arr = (array) apply_filters( 'buddynext_part_profile_field_classes', $bn_classes_arr, $args );
$bn_class       = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes_arr,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

$bn_input_cls    = trim( 'bn-input' . ( '' !== $bn_input_extra ? ' ' . $bn_input_extra : '' ) );
$bn_textarea_cls = trim( 'bn-textarea' . ( '' !== $bn_input_extra ? ' ' . $bn_input_extra : '' ) );
$bn_error_id     = 'bn-ep-error-' . $bn_error_key;
$bn_hint_id      = 'bn-ep-hint-' . $bn_input_id;

do_action( 'buddynext_part_profile_field_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<label class="bn-ep-label" for="<?php echo esc_attr( $bn_input_id ); ?>">
		<?php echo esc_html( $bn_label ); ?>
		<?php if ( $bn_required ) : ?>
			<span class="bn-ep-required" aria-hidden="true">*</span>
		<?php endif; ?>
	</label>
	<?php if ( 'textarea' === $bn_type ) : ?>
		<textarea class="<?php echo esc_attr( $bn_textarea_cls ); ?>"
			id="<?php echo esc_attr( $bn_input_id ); ?>"
			name="<?php echo esc_attr( $bn_input_name ); ?>"
			rows="<?php echo (int) $bn_rows; ?>"
			placeholder="<?php echo esc_attr( $bn_placeholder ); ?>"
			<?php
			if ( '' !== $bn_hint ) :
				?>
				aria-describedby="<?php echo esc_attr( $bn_hint_id ); ?>"<?php endif; ?>
			<?php
			if ( $bn_required ) :
				?>
				required aria-required="true"<?php endif; ?>
			<?php
			if ( $bn_autosave ) :
				?>
				data-wp-on--blur="actions.autosave"<?php endif; ?>
			<?php
			foreach ( $bn_data_attrs as $bn_da_k => $bn_da_v ) {
				printf( ' %s="%s"', esc_attr( (string) $bn_da_k ), esc_attr( (string) $bn_da_v ) );
			}
			?>
		><?php echo esc_textarea( $bn_value ); ?></textarea>
	<?php elseif ( 'select' === $bn_type ) : ?>
		<select class="<?php echo esc_attr( $bn_input_cls ); ?>"
			id="<?php echo esc_attr( $bn_input_id ); ?>"
			name="<?php echo esc_attr( $bn_input_name ); ?>"
			<?php
			if ( $bn_required ) :
				?>
				required aria-required="true"<?php endif; ?>
			<?php
			foreach ( $bn_data_attrs as $bn_da_k => $bn_da_v ) {
				printf( ' %s="%s"', esc_attr( (string) $bn_da_k ), esc_attr( (string) $bn_da_v ) );
			}
			?>
		>
			<?php foreach ( $bn_options as $bn_opt_key => $bn_opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $bn_opt_key ); ?>" <?php selected( $bn_value, (string) $bn_opt_key ); ?>>
					<?php echo esc_html( (string) $bn_opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	<?php else : ?>
		<input class="<?php echo esc_attr( $bn_input_cls ); ?>"
			type="<?php echo esc_attr( $bn_type ); ?>"
			id="<?php echo esc_attr( $bn_input_id ); ?>"
			name="<?php echo esc_attr( $bn_input_name ); ?>"
			value="<?php echo esc_attr( $bn_value ); ?>"
			placeholder="<?php echo esc_attr( $bn_placeholder ); ?>"
			<?php
			if ( $bn_required ) :
				?>
				required aria-required="true"<?php endif; ?>
			<?php if ( $bn_validate ) : ?>
				aria-describedby="<?php echo esc_attr( $bn_error_id ); ?>"
				<?php
				// CONTROLLED-INPUT REQUIREMENT: the error-class directive below is bound
				// to context.errors, which validateField mutates on blur. That mutation
				// re-renders this element, and an uncontrolled input with a non-empty
				// server `value` gets reset by Preact back to that value (the exact
				// "display name won't change" bug fixed on the hero field). Today no
				// caller passes validate_on_blur with a pre-filled value, so this is
				// safe. If you ever render a validated field WITH a value, also bind
				// the value reactively (data-wp-bind--value + a sync action seeding
				// context) the way profile-edit-hero.php does, or the typed value will
				// be wiped on blur.
				?>
				data-wp-class--bn-input--error="context.errors.<?php echo esc_attr( $bn_error_key ); ?>"
				data-wp-on--blur="actions.validateField"
			<?php elseif ( '' !== $bn_hint ) : ?>
				aria-describedby="<?php echo esc_attr( $bn_hint_id ); ?>"
				<?php
				if ( $bn_autosave ) :
					?>
					data-wp-on--blur="actions.autosave"<?php endif; ?>
			<?php elseif ( $bn_autosave ) : ?>
				data-wp-on--blur="actions.autosave"
			<?php endif; ?>
			<?php
			foreach ( $bn_data_attrs as $bn_da_k => $bn_da_v ) {
				printf( ' %s="%s"', esc_attr( (string) $bn_da_k ), esc_attr( (string) $bn_da_v ) );
			}
			?>
		/>
	<?php endif; ?>
	<?php if ( $bn_validate ) : ?>
		<span class="bn-ep-field-error"
			id="<?php echo esc_attr( $bn_error_id ); ?>"
			role="alert"
			data-wp-text="context.errors.<?php echo esc_attr( $bn_error_key ); ?>"
			data-wp-bind--hidden="!context.errors.<?php echo esc_attr( $bn_error_key ); ?>"></span>
	<?php endif; ?>
	<?php if ( '' !== $bn_hint ) : ?>
		<span class="bn-ep-hint" id="<?php echo esc_attr( $bn_hint_id ); ?>">
			<?php echo esc_html( $bn_hint ); ?>
		</span>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_profile_field_after', $args );
