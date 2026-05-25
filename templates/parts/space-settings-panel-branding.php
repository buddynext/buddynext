<?php
/**
 * BuddyNext template part: space-settings-panel-branding.
 *
 * Renders the "Branding" panel. Free emits an upsell card; Pro hooks the
 * legacy `buddynext_space_branding_settings` action (or
 * `buddynext_part_space_settings_panel_branding_after`) to inject real
 * branding controls.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space             Required. Space row.
 * @var array  $branding_settings Required. Bundle:
 *   - `space_id` (int)
 * @var array  $classes           Optional. Extra CSS classes appended to `.bn-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_panel_branding_before', $args )
 *   - do_action( 'buddynext_part_space_settings_panel_branding_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_panel_branding_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_panel_branding_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space'             => isset( $space ) ? $space : null,
	'branding_settings' => isset( $branding_settings ) && is_array( $branding_settings ) ? $branding_settings : array(),
	'classes'           => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_panel_branding_args', $args );

$bn_space = $args['space'];
if ( ! $bn_space ) {
	return;
}

$bn_space_id = isset( $args['branding_settings']['space_id'] ) ? (int) $args['branding_settings']['space_id'] : 0;

$bn_classes = array_merge( array( 'bn-card', 'bn-space-settings__panel' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_panel_branding_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_space_settings_panel_branding_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<header class="bn-space-settings__panel-head">
		<h2 class="bn-space-settings__panel-title"><?php esc_html_e( 'Branding', 'buddynext' ); ?></h2>
		<p class="bn-space-settings__panel-desc"><?php esc_html_e( 'Customize how this space looks for its members.', 'buddynext' ); ?></p>
	</header>

	<?php
	/**
	 * Allow Pro to render the real branding controls (P6.2 per-space
	 * accent hue + cover override). Free renders the upsell card below
	 * when the action returns nothing.
	 */
	ob_start();
	do_action( 'buddynext_space_branding_settings', $bn_space_id );
	$bn_pro_branding_html = (string) ob_get_clean();
	?>

	<?php if ( '' !== trim( $bn_pro_branding_html ) ) : ?>
		<?php echo wp_kses_post( $bn_pro_branding_html ); ?>
	<?php else : ?>
		<div class="bn-space-settings__upsell" data-tone="pro">
			<div class="bn-space-settings__upsell-icon" aria-hidden="true">
				<?php buddynext_icon( 'sparkles' ); ?>
			</div>
			<div class="bn-space-settings__upsell-copy">
				<h3 class="bn-space-settings__upsell-title">
					<?php esc_html_e( 'Custom space brand', 'buddynext' ); ?>
					<span class="bn-badge" data-tone="pro"><?php esc_html_e( 'Pro', 'buddynext' ); ?></span>
				</h3>
				<p class="bn-space-settings__upsell-desc">
					<?php esc_html_e( 'Set a custom accent hue and cover override per space. Available in BuddyNext Pro.', 'buddynext' ); ?>
				</p>
				<a
					href="https://wbcomdesigns.com/products/buddynext-pro/"
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					target="_blank"
					rel="noopener"
				><?php esc_html_e( 'Learn more', 'buddynext' ); ?></a>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_space_settings_panel_branding_after', $args );
