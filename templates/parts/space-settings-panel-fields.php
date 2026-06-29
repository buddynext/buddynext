<?php
/**
 * BuddyNext template part: space-settings-panel-fields.
 *
 * Renders the "Custom fields" panel — the registry-driven settings surface for
 * developer-registered (non-core) per-space fields. Each field is rendered from
 * its definition via FieldType::render_input(); the panel saves over
 * POST /buddynext/v1/spaces/{id}/fields through the buddynext/space-fields store
 * (inline 422 errors). BuddyNext's own 8 built-in fields are core=true and keep
 * their bespoke tabs, so they never appear here.
 *
 * @package BuddyNext
 * @since   1.0.4
 *
 * @var object $space         Required. Space row.
 * @var array  $fields_settings Required. Bundle: `space_id` (int).
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_fields_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_fields_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_fields_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_fields_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Profile\FieldType;
use BuddyNext\Spaces\SpaceFieldRegistry;

$args = array(
	'space'           => isset( $space ) ? $space : null,
	'fields_settings' => isset( $fields_settings ) && is_array( $fields_settings ) ? $fields_settings : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_fields_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_space_id      = isset( $args['fields_settings']['space_id'] ) ? (int) $args['fields_settings']['space_id'] : 0;
$bn_custom_fields = SpaceFieldRegistry::instance()->get_custom_fields();

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_fields_classes', $bn_classes, $args );
$bn_class   = trim( implode( ' ', array_unique( array_filter( $bn_classes, static fn( $c ) => is_string( $c ) && '' !== $c ) ) ) );

$bn_sf_ctx = (string) wp_json_encode(
	array(
		'spaceId'   => $bn_space_id,
		'restNonce' => wp_create_nonce( 'wp_rest' ),
	)
);

do_action( 'buddynext_part_space_settings_panel_fields_before', $args );
?>
<div
	class="bn-space-settings__fields"
	data-wp-interactive="buddynext/space-fields"
	data-wp-context="<?php echo esc_attr( $bn_sf_ctx ); ?>"
>
	<form class="bn-space-settings__form" data-bn-space-fields-form data-wp-on--submit="actions.saveFields">
		<div class="<?php echo esc_attr( $bn_class ); ?>">
			<header class="bn-space-settings__panel-head">
				<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Custom fields', 'buddynext' ); ?></h2>
				<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Extra fields added by your installed plugins.', 'buddynext' ); ?></p>
			</header>

			<?php if ( empty( $bn_custom_fields ) ) : ?>
				<p class="bn-space-settings__empty"><?php esc_html_e( 'No custom fields are registered on this community.', 'buddynext' ); ?></p>
			<?php else : ?>
				<?php
				foreach ( $bn_custom_fields as $bn_field ) :
					$bn_key     = (string) $bn_field['key'];
					$bn_value   = buddynext_get_space_field( $bn_space_id, $bn_key );
					$bn_fid     = 'bn-field-' . sanitize_html_class( $bn_key );
					$bn_is_bool = 'boolean' === ( $bn_field['type'] ?? '' );
					?>
					<div class="bn-space-settings__field" data-field-key="<?php echo esc_attr( $bn_key ); ?>">
						<?php if ( ! $bn_is_bool ) : ?>
							<label for="<?php echo esc_attr( $bn_fid ); ?>">
								<?php echo esc_html( (string) $bn_field['label'] ); ?>
								<?php if ( ! empty( $bn_field['is_required'] ) ) : ?>
									<span class="bn-space-settings__required" aria-hidden="true">*</span>
								<?php endif; ?>
							</label>
						<?php endif; ?>
						<?php
						// FieldType::render_input() returns escaped markup (the same
						// engine the profile editor echoes).
						echo FieldType::render_input( $bn_field, $bn_value, $bn_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<?php if ( '' !== (string) ( $bn_field['description'] ?? '' ) ) : ?>
							<p class="bn-space-settings__hint"><?php echo esc_html( (string) $bn_field['description'] ); ?></p>
						<?php endif; ?>
						<p class="bn-space-settings__field-error" data-bn-field-error hidden></p>
					</div>
				<?php endforeach; ?>

				<div class="bn-space-settings__save-row">
					<button type="submit" class="bn-btn" data-variant="primary" data-size="md" data-bn-save>
						<?php esc_html_e( 'Save fields', 'buddynext' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
	</form>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_fields_after', $args );
