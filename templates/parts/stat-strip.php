<?php
/**
 * BuddyNext template part: stat-strip.
 *
 * Renders a `.bn-stat-grid` of `.bn-stat` tiles. Each tile shows a label,
 * a value, and an optional delta indicator. Used on profile, space home,
 * gamification leaderboard, admin dashboards, and analytics.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $stats {
 *     Required. List of stat-tile descriptors. Each entry:
 *     @type string      $label  Required. Tile label (already-translated).
 *     @type string|int  $value  Required. Tile value.
 *     @type string      $delta  Optional. Delta text (e.g. '+12%').
 *     @type string      $trend  Optional. 'up'|'down'|'flat'. Default 'flat'.
 *     @type string      $icon   Optional. Lucide-icon slug rendered next to the label.
 *     @type string      $href   Optional. URL — when present, the tile becomes a link.
 *     @type string      $tone   Optional. Reserved (e.g. 'accent'|'danger').
 * }
 * @var array $classes Optional. Extra CSS classes appended to `.bn-stat-grid`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_stat_strip_before', $args )
 *   - do_action( 'buddynext_part_stat_strip_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_stat_strip_args',    array $args )
 *   - apply_filters( 'buddynext_part_stat_strip_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'stats'   => isset( $stats ) && is_array( $stats ) ? $stats : array(),
	'classes' => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_stat_strip_args', $args );

if ( empty( $args['stats'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-stat-grid' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_stat_strip_classes', $bn_classes, $args );
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

$bn_allowed_trends = array( 'up', 'down', 'flat' );

do_action( 'buddynext_part_stat_strip_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<?php
	foreach ( (array) $args['stats'] as $bn_stat ) :
		if ( ! is_array( $bn_stat ) ) {
			continue;
		}
		$bn_label = isset( $bn_stat['label'] ) ? (string) $bn_stat['label'] : '';
		$bn_value = isset( $bn_stat['value'] ) ? (string) $bn_stat['value'] : '';
		if ( '' === $bn_label || '' === $bn_value ) {
			continue;
		}
		$bn_delta = isset( $bn_stat['delta'] ) ? (string) $bn_stat['delta'] : '';
		$bn_trend = isset( $bn_stat['trend'] ) && in_array( (string) $bn_stat['trend'], $bn_allowed_trends, true )
			? (string) $bn_stat['trend']
			: 'flat';
		$bn_icon  = isset( $bn_stat['icon'] ) ? (string) $bn_stat['icon'] : '';
		$bn_href  = isset( $bn_stat['href'] ) ? (string) $bn_stat['href'] : '';
		$bn_tone  = isset( $bn_stat['tone'] ) ? (string) $bn_stat['tone'] : '';

		$bn_tag       = '' !== $bn_href ? 'a' : 'div';
		$bn_href_attr = '' !== $bn_href ? ' href="' . esc_url( $bn_href ) . '"' : '';
		$bn_tone_attr = '' !== $bn_tone ? ' data-tone="' . esc_attr( $bn_tone ) . '"' : '';
		?>
		<<?php echo esc_html( $bn_tag ); ?> class="bn-stat"<?php echo $bn_href_attr . $bn_tone_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<span class="bn-stat__label">
				<?php if ( '' !== $bn_icon && function_exists( 'buddynext_icon' ) ) : ?>
					<?php buddynext_icon( $bn_icon ); ?>
				<?php endif; ?>
				<?php echo esc_html( $bn_label ); ?>
			</span>
			<span class="bn-stat__value"><?php echo esc_html( $bn_value ); ?></span>
			<?php if ( '' !== $bn_delta ) : ?>
				<span class="bn-stat__delta" data-trend="<?php echo esc_attr( $bn_trend ); ?>">
					<?php echo esc_html( $bn_delta ); ?>
				</span>
			<?php endif; ?>
		</<?php echo esc_html( $bn_tag ); ?>>
	<?php endforeach; ?>
</div>
<?php
do_action( 'buddynext_part_stat_strip_after', $args );
