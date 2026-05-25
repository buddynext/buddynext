<?php
/**
 * BuddyNext template part: profile-field-group.
 *
 * Renders the repeating-field card pattern used by the Work Experience
 * and Education sections of the edit-profile page. Each entry is a
 * numbered row with a remove button and a grid of inputs; an optional
 * full-width description textarea is appended when the group declares
 * one.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array  $field    Required. Group descriptor. Shape:
 *   - `group_key`        (string) Used as data-group on add/remove buttons
 *                                  and as the input-name prefix.
 *   - `fields`           (array)  Grid sub-fields. Each item:
 *       - `key`         (string) Sub-field key (used in input name).
 *       - `label`       (string) Visible label.
 *       - `placeholder` (string) Placeholder.
 *   - `description_field`(array|null) Optional full-width textarea:
 *       - `key`         (string)
 *       - `label`       (string)
 *       - `placeholder` (string)
 *       - `rows`        (int)
 *   - `add_label`        (string) CTA label for the add-entry button.
 *   - `remove_aria_label`(string) ARIA label for the per-entry remove button.
 *   - `id_prefix`        (string) DOM id prefix for inputs (e.g. `bn-ep-work-`).
 * @var array  $entries  Required. List of saved repeater rows.
 * @var bool   $is_owner Optional. Informational only.
 * @var string $group_id Optional. id for the `<div>` wrapping all entries
 *                       (e.g. `bn-ep-work-entries`).
 * @var array  $classes  Optional. Extra CSS classes appended to `.bn-ep-card-body`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_field_group_before', $args )
 *   - do_action( 'buddynext_part_profile_field_group_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_field_group_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_field_group_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'field'    => isset( $field ) && is_array( $field ) ? $field : array(),
	'entries'  => isset( $entries ) && is_array( $entries ) ? $entries : array(),
	'is_owner' => isset( $is_owner ) ? (bool) $is_owner : false,
	'group_id' => isset( $group_id ) ? (string) $group_id : '',
	'classes'  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_field_group_args', $args );

$bn_group = (array) $args['field'];
$bn_gkey  = isset( $bn_group['group_key'] ) ? (string) $bn_group['group_key'] : '';
if ( '' === $bn_gkey ) {
	return;
}

$bn_entries     = (array) $args['entries'];
$bn_subfields   = isset( $bn_group['fields'] ) && is_array( $bn_group['fields'] ) ? $bn_group['fields'] : array();
$bn_desc_field  = isset( $bn_group['description_field'] ) && is_array( $bn_group['description_field'] ) ? $bn_group['description_field'] : array();
$bn_add_label   = isset( $bn_group['add_label'] ) ? (string) $bn_group['add_label'] : __( 'Add entry', 'buddynext' );
$bn_remove_aria = isset( $bn_group['remove_aria_label'] ) ? (string) $bn_group['remove_aria_label'] : __( 'Remove entry', 'buddynext' );
$bn_id_prefix   = isset( $bn_group['id_prefix'] ) ? (string) $bn_group['id_prefix'] : 'bn-ep-' . str_replace( '_', '-', $bn_gkey ) . '-';
$bn_group_id    = (string) $args['group_id'];

$bn_classes = array_merge( array( 'bn-ep-card-body' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_field_group_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_profile_field_group_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>"
	<?php
	if ( '' !== $bn_group_id ) :
		?>
		id="<?php echo esc_attr( $bn_group_id ); ?>"<?php endif; ?>>
	<?php foreach ( $bn_entries as $bn_idx => $bn_entry ) : ?>
		<?php $bn_idx_int = (int) $bn_idx; ?>
		<div class="bn-ep-repeater-entry" data-entry-index="<?php echo (int) $bn_idx_int; ?>">
			<header class="bn-ep-repeater-header">
				<span class="bn-ep-repeater-num"><?php echo absint( $bn_idx_int + 1 ); ?></span>
				<button class="bn-btn bn-ep-repeater-remove"
					type="button"
					data-variant="ghost"
					data-size="sm"
					data-group="<?php echo esc_attr( $bn_gkey ); ?>"
					data-entry-index="<?php echo (int) $bn_idx_int; ?>"
					data-wp-on--click="actions.removeEntry"
					aria-label="<?php echo esc_attr( $bn_remove_aria ); ?>">
					<?php buddynext_icon( 'x' ); ?>
				</button>
			</header>
			<div class="bn-ep-grid">
				<?php foreach ( $bn_subfields as $bn_sf ) : ?>
					<?php
					if ( ! is_array( $bn_sf ) ) {
						continue;
					}
					$bn_sf_key   = isset( $bn_sf['key'] ) ? (string) $bn_sf['key'] : '';
					$bn_sf_label = isset( $bn_sf['label'] ) ? (string) $bn_sf['label'] : '';
					if ( '' === $bn_sf_key || '' === $bn_sf_label ) {
						continue;
					}
					$bn_sf_ph    = isset( $bn_sf['placeholder'] ) ? (string) $bn_sf['placeholder'] : '';
					$bn_sf_id    = $bn_id_prefix . str_replace( '_', '-', preg_replace( '/^' . preg_quote( $bn_gkey, '/' ) . '_/', '', $bn_sf_key ) ) . '-' . $bn_idx_int;
					$bn_sf_value = isset( $bn_entry[ $bn_sf_key ] ) ? (string) $bn_entry[ $bn_sf_key ] : '';
					?>
					<div class="bn-ep-field">
						<label class="bn-ep-label" for="<?php echo esc_attr( $bn_sf_id ); ?>">
							<?php echo esc_html( $bn_sf_label ); ?>
						</label>
						<input class="bn-input"
							type="text"
							id="<?php echo esc_attr( $bn_sf_id ); ?>"
							name="<?php echo esc_attr( $bn_gkey ); ?>[<?php echo (int) $bn_idx_int; ?>][<?php echo esc_attr( $bn_sf_key ); ?>]"
							value="<?php echo esc_attr( $bn_sf_value ); ?>"
							placeholder="<?php echo esc_attr( $bn_sf_ph ); ?>"
							data-wp-on--blur="actions.autosave" />
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			if ( ! empty( $bn_desc_field ) ) :
				$bn_df_key   = isset( $bn_desc_field['key'] ) ? (string) $bn_desc_field['key'] : '';
				$bn_df_label = isset( $bn_desc_field['label'] ) ? (string) $bn_desc_field['label'] : '';
				if ( '' !== $bn_df_key && '' !== $bn_df_label ) :
					$bn_df_ph    = isset( $bn_desc_field['placeholder'] ) ? (string) $bn_desc_field['placeholder'] : '';
					$bn_df_rows  = isset( $bn_desc_field['rows'] ) ? (int) $bn_desc_field['rows'] : 3;
					$bn_df_id    = $bn_id_prefix . str_replace( '_', '-', preg_replace( '/^' . preg_quote( $bn_gkey, '/' ) . '_/', '', $bn_df_key ) ) . '-' . $bn_idx_int;
					$bn_df_value = isset( $bn_entry[ $bn_df_key ] ) ? (string) $bn_entry[ $bn_df_key ] : '';
					?>
				<div class="bn-ep-field bn-ep-field--full">
					<label class="bn-ep-label" for="<?php echo esc_attr( $bn_df_id ); ?>">
						<?php echo esc_html( $bn_df_label ); ?>
					</label>
					<textarea class="bn-textarea"
						id="<?php echo esc_attr( $bn_df_id ); ?>"
						rows="<?php echo (int) $bn_df_rows; ?>"
						name="<?php echo esc_attr( $bn_gkey ); ?>[<?php echo (int) $bn_idx_int; ?>][<?php echo esc_attr( $bn_df_key ); ?>]"
						placeholder="<?php echo esc_attr( $bn_df_ph ); ?>"
						data-wp-on--blur="actions.autosave"><?php echo esc_textarea( $bn_df_value ); ?></textarea>
				</div>
					<?php
			endif;
endif;
			?>
		</div>
	<?php endforeach; ?>
</div>
<footer class="bn-ep-card-footer">
	<button class="bn-btn bn-ep-add-entry"
		type="button"
		data-variant="ghost"
		data-size="sm"
		data-group="<?php echo esc_attr( $bn_gkey ); ?>"
		data-wp-on--click="actions.addEntry">
		<?php buddynext_icon( 'plus' ); ?>
		<span><?php echo esc_html( $bn_add_label ); ?></span>
	</button>
</footer>
<?php
do_action( 'buddynext_part_profile_field_group_after', $args );
