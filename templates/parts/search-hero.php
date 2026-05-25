<?php
/**
 * BuddyNext template part: search-hero.
 *
 * Renders the search hero block at the top of the search results page:
 * the inline search form (input + submit), a hidden `type` field that
 * preserves the active result-type tab on resubmission, and the hint /
 * result-count strip beneath the field.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var string $query         Optional. Current search query string. Default ''.
 * @var int    $total_results Optional. Total result count across all types. Default 0.
 * @var string $active_type   Optional. Active result-type tab (all|members|posts|spaces|hashtags|media).
 *                            Default 'all'.
 * @var array  $classes       Optional. Extra CSS classes appended to `.bn-search-hero`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_hero_before', $args )
 *   - do_action( 'buddynext_part_search_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'query'         => isset( $query ) ? (string) $query : '',
	'total_results' => isset( $total_results ) ? (int) $total_results : 0,
	'active_type'   => isset( $active_type ) ? (string) $active_type : 'all',
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_hero_args', $args );

$bn_classes = array_merge( array( 'bn-search-hero' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_hero_classes', $bn_classes, $args );
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

$bn_query       = (string) $args['query'];
$bn_total       = (int) $args['total_results'];
$bn_active_type = (string) $args['active_type'];

do_action( 'buddynext_part_search_hero_before', $args );
?>
<form action="" method="get" class="<?php echo esc_attr( $bn_class ); ?>" role="search" aria-label="<?php esc_attr_e( 'Search community', 'buddynext' ); ?>">
	<label for="bn-search-q" class="bn-visually-hidden">
		<?php esc_html_e( 'Search', 'buddynext' ); ?>
	</label>
	<div class="bn-search-hero__field">
		<span class="bn-search-hero__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
		<input
			id="bn-search-q"
			class="bn-input bn-search-hero__input"
			type="search"
			name="q"
			value="<?php echo esc_attr( $bn_query ); ?>"
			placeholder="<?php esc_attr_e( 'Search members, posts, spaces, hashtags', 'buddynext' ); ?>"
			autocomplete="off"
		>
		<?php if ( 'all' !== $bn_active_type ) : ?>
			<input type="hidden" name="type" value="<?php echo esc_attr( $bn_active_type ); ?>">
		<?php endif; ?>
		<button type="submit" class="bn-btn bn-search-hero__submit" data-variant="primary" data-size="md">
			<?php esc_html_e( 'Search', 'buddynext' ); ?>
		</button>
	</div>
	<div class="bn-search-hero__hint">
		<?php
		if ( '' !== $bn_query && $bn_total > 0 ) {
			printf(
				/* translators: %1$s = count, %2$s = search query (escaped). */
				esc_html__( '%1$s results for %2$s', 'buddynext' ),
				'<strong>' . esc_html( (string) $bn_total ) . '</strong>',
				'<strong>"' . esc_html( $bn_query ) . '"</strong>'
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
		} elseif ( '' !== $bn_query ) {
			printf(
				/* translators: %s = search query (escaped). */
				esc_html__( 'No results for %s', 'buddynext' ),
				'<strong>"' . esc_html( $bn_query ) . '"</strong>'
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
		} else {
			esc_html_e( 'Tip: press', 'buddynext' );
			echo ' <kbd class="bn-kbd">/</kbd> ';
			esc_html_e( 'anywhere to focus search.', 'buddynext' );
		}
		?>
	</div>
</form>
<?php
do_action( 'buddynext_part_search_hero_after', $args );
