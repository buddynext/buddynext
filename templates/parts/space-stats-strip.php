<?php
/**
 * BuddyNext template part: space-stats-strip.
 *
 * Renders the four-tile stat strip (Members / Posts / Active 7d / Created)
 * shown inside the space hero. Thin wrapper around the generic
 * `parts/stat-strip.php` primitive that pins the canonical
 * `.bn-sh-hero__stats` class so the layout stays byte-identical.
 *
 * Used by: templates/parts/space-hero.php (default), and any caller that
 * needs the same stats band outside the hero (e.g. an admin overview card).
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $stats    Required. List of stat-tile descriptors. Each entry is
 *                      `[ 'label' => string, 'value' => string|int,
 *                         'icon' => string?, 'href' => string?,
 *                         'delta' => string?, 'trend' => string?,
 *                         'tone' => string? ]`. Passed through to the
 *                      underlying stat-strip part.
 * @var int   $space_id Optional. Current space ID (for hook context).
 * @var array $classes  Optional. Extra CSS classes appended to `.bn-stat-grid`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_stats_strip_before', $args )
 *   - do_action( 'buddynext_part_space_stats_strip_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_stats_strip_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_stats_strip_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'stats'    => isset( $stats ) && is_array( $stats ) ? $stats : array(),
	'space_id' => isset( $space_id ) ? (int) $space_id : 0,
	'classes'  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_stats_strip_args', $args );

if ( empty( $args['stats'] ) ) {
	return;
}

// Render the space hero stats as a compact pill band (the same visual the
// profile hero uses) instead of four boxed tiles. The pill styles live in
// bn-spaces.css under `.bn-sh-statrow` / `.bn-sh-statpill` — space pages don't
// load bn-profile.css (intentional asset isolation), so the component is
// defined in the space stylesheet rather than shared.
$bn_classes = array_merge( array( 'bn-sh-statrow', 'bn-sh-hero__stats' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_stats_strip_classes', $bn_classes, $args );
$bn_class   = trim( implode( ' ', array_unique( array_filter( $bn_classes, static fn( $c ) => is_string( $c ) && '' !== $c ) ) ) );

do_action( 'buddynext_part_space_stats_strip_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" role="list">
	<?php
	foreach ( (array) $args['stats'] as $bn_stat ) :
		if ( ! is_array( $bn_stat ) || '' === (string) ( $bn_stat['label'] ?? '' ) ) {
			continue;
		}
		$bn_label      = (string) $bn_stat['label'];
		$bn_value      = isset( $bn_stat['value'] ) ? (string) $bn_stat['value'] : '';
		$bn_href       = isset( $bn_stat['href'] ) ? (string) $bn_stat['href'] : '';
		$bn_delta      = isset( $bn_stat['delta'] ) ? trim( (string) $bn_stat['delta'] ) : '';
		$bn_trend      = isset( $bn_stat['trend'] ) && in_array( (string) $bn_stat['trend'], array( 'up', 'down', 'flat' ), true ) ? (string) $bn_stat['trend'] : 'flat';
		$bn_delta_html = '' !== $bn_delta
			? sprintf( '<span class="bn-sh-statpill__delta" data-trend="%1$s">%2$s</span>', esc_attr( $bn_trend ), esc_html( $bn_delta ) )
			: '';
		$bn_tag        = '' !== $bn_href ? 'a' : 'span';
		$bn_attr       = '' !== $bn_href ? ' href="' . esc_url( $bn_href ) . '"' : ' role="listitem"';
		?>
		<<?php echo $bn_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal tag name. ?> class="bn-sh-statpill"<?php echo $bn_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attr pre-escaped. ?>>
			<span class="bn-sh-statpill__value"><?php echo esc_html( $bn_value ); ?></span>
			<span class="bn-sh-statpill__label"><?php echo esc_html( $bn_label ); ?></span>
			<?php echo $bn_delta_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>
		</<?php echo $bn_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal tag name. ?>>
	<?php endforeach; ?>
</div>
<?php
do_action( 'buddynext_part_space_stats_strip_after', $args );
