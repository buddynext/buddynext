<?php
/**
 * BuddyNext template part: space-settings-panel-privacy.
 *
 * Renders the "Privacy" panel inside the space settings shell.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space            Required. Space row (from `bn_spaces`).
 * @var array  $privacy_settings Required. Bundle:
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_privacy_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_privacy_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_privacy_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_privacy_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'            => isset( $space ) ? $space : null,
	'privacy_settings' => isset( $privacy_settings ) && is_array( $privacy_settings ) ? $privacy_settings : array(),
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_privacy_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}


$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_privacy_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_privacy_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Privacy', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Who can see and join your space.', 'buddynext' ); ?></p>
	</header>

	<div class="bn-space-settings__field">
		<label for="space_type"><?php esc_html_e( 'Space visibility', 'buddynext' ); ?></label>
		<select name="space_type" id="space_type" class="bn-select">
			<option value="open" <?php selected( $bn_space->type, 'open' ); ?>>
				<?php esc_html_e( 'Open: listed in directory, anyone can join', 'buddynext' ); ?>
			</option>
			<option value="private" <?php selected( $bn_space->type, 'private' ); ?>>
				<?php esc_html_e( 'Private: listed but requires approval to join', 'buddynext' ); ?>
			</option>
			<option value="secret" <?php selected( $bn_space->type, 'secret' ); ?>>
				<?php esc_html_e( 'Secret: not listed, admin invites only', 'buddynext' ); ?>
			</option>
		</select>
	</div>


</div>
<?php
do_action( 'buddynext_part_space_settings_panel_privacy_after', $args );
