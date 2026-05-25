<?php
/**
 * BuddyNext template part: space-settings-tabs.
 *
 * Renders the per-space settings tab bar (`.bn-tabs.bn-sh-tabs`). The tab
 * registry is filterable via `buddynext_part_space_settings_tabs_args`, which
 * is THE extension point Pro modules (e.g. P6.2 per-space brand) use to add
 * additional tabs.
 *
 * Each tab row supports:
 *   - `slug`  (string, required) — query var value, e.g. `general`.
 *   - `label` (string, required) — translated tab label.
 *   - `icon`  (string)           — Lucide icon slug rendered before the label.
 *   - `cap`   (string)           — Optional capability/check string. When set
 *                                  and `current_user_can( $cap )` is falsy the
 *                                  tab is hidden.
 *   - `panel` (string|callable)  — Optional override for the panel renderer.
 *                                  String = template part name relative to
 *                                  `templates/`. Callable = invoked with
 *                                  `( $slug, $args )` to render the panel.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $space_id    Required. Space primary key.
 * @var string $active_tab  Required. Currently-active tab slug.
 * @var array  $tabs        Required. Tab registry (see row shape above).
 * @var string $base_url    Optional. Settings base URL used to build tab links.
 * @var array  $classes     Optional. Extra CSS classes appended to `.bn-tabs.bn-sh-tabs`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_settings_tabs_before', $args )
 *   - do_action( 'buddynext_part_space_settings_tabs_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_settings_tabs_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_settings_tabs_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'space_id'   => isset( $space_id ) ? (int) $space_id : 0,
	'active_tab' => isset( $active_tab ) ? (string) $active_tab : 'general',
	'tabs'       => isset( $tabs ) && is_array( $tabs ) ? $tabs : array(),
	'base_url'   => isset( $base_url ) ? (string) $base_url : '',
	'classes'    => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_settings_tabs_args', $args );

if ( empty( $args['tabs'] ) || ! is_array( $args['tabs'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-tabs', 'bn-sh-tabs' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_settings_tabs_classes', $bn_classes, $args );
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

$bn_active = (string) $args['active_tab'];
$bn_base   = (string) $args['base_url'];

do_action( 'buddynext_part_space_settings_tabs_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'buddynext' ); ?>">
	<?php
	foreach ( (array) $args['tabs'] as $bn_tab ) :
		if ( ! is_array( $bn_tab ) ) {
			continue;
		}
		$bn_slug  = isset( $bn_tab['slug'] ) ? (string) $bn_tab['slug'] : '';
		$bn_label = isset( $bn_tab['label'] ) ? (string) $bn_tab['label'] : '';
		$bn_icon  = isset( $bn_tab['icon'] ) ? (string) $bn_tab['icon'] : '';
		$bn_cap   = isset( $bn_tab['cap'] ) ? (string) $bn_tab['cap'] : '';

		if ( '' === $bn_slug || '' === $bn_label ) {
			continue;
		}
		if ( '' !== $bn_cap && ! current_user_can( $bn_cap ) ) {
			continue;
		}

		$bn_is_active = ( $bn_active === $bn_slug );
		$bn_href      = '' !== $bn_base ? add_query_arg( 'bn_stab', $bn_slug, $bn_base ) : add_query_arg( 'bn_stab', $bn_slug );
		?>
		<a
			href="<?php echo esc_url( $bn_href ); ?>"
			class="bn-tab bn-sh-tab"
			role="tab"
			aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
		>
			<?php if ( '' !== $bn_icon && function_exists( 'buddynext_icon' ) ) : ?>
				<?php buddynext_icon( $bn_icon ); ?>
			<?php endif; ?>
			<?php echo esc_html( $bn_label ); ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php
do_action( 'buddynext_part_space_settings_tabs_after', $args );
