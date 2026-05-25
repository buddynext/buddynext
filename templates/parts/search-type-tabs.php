<?php
/**
 * BuddyNext template part: search-type-tabs.
 *
 * Renders the type-filter tab strip on the search results page
 * (All / Members / Posts / Spaces / Hashtags / Media). Each tab is a
 * link that updates the `type` query var; per-tab counts are emitted when
 * a search query is present.
 *
 * The `buddynext_part_search_type_tabs_args` filter is the canonical seam
 * Pro / bridge plugins use to register additional result types.
 *
 * Used by: templates/search/results.php.
 *
 * @package BuddyNext
 *
 * @var string $active_type     Optional. Currently active type key. Default 'all'.
 * @var array  $tabs            Optional. Tab definition map, keyed by type slug, with
 *                              `[ 'label' => string ]`. Default empty array (caller supplies).
 * @var array  $counts_by_type  Optional. Map of type slug => count. Default empty array.
 * @var string $query           Optional. Current search query string (drives whether
 *                              counts are emitted). Default ''.
 * @var array  $classes         Optional. Extra CSS classes appended to `.bn-tabs`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_search_type_tabs_before', $args )
 *   - do_action( 'buddynext_part_search_type_tabs_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_search_type_tabs_args',    array $args )
 *   - apply_filters( 'buddynext_part_search_type_tabs_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'active_type'    => isset( $active_type ) ? (string) $active_type : 'all',
	'tabs'           => isset( $tabs ) ? (array) $tabs : array(),
	'counts_by_type' => isset( $counts_by_type ) ? (array) $counts_by_type : array(),
	'query'          => isset( $query ) ? (string) $query : '',
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_search_type_tabs_args', $args );

$bn_classes = array_merge( array( 'bn-tabs', 'bn-search-tabs' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_search_type_tabs_classes', $bn_classes, $args );
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

$bn_active_type = (string) $args['active_type'];
$bn_tabs        = (array) $args['tabs'];
$bn_counts      = (array) $args['counts_by_type'];
$bn_query       = (string) $args['query'];

do_action( 'buddynext_part_search_type_tabs_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by type', 'buddynext' ); ?>">
	<?php
	foreach ( $bn_tabs as $type_key => $bn_tab ) :
		if ( ! is_array( $bn_tab ) ) {
			continue;
		}
		$bn_label = isset( $bn_tab['label'] ) ? (string) $bn_tab['label'] : '';
		if ( '' === $bn_label ) {
			continue;
		}
		$slug      = (string) $type_key;
		$is_active = ( $slug === $bn_active_type );
		$tab_href  = esc_url(
			add_query_arg(
				array(
					'q'    => $bn_query,
					'type' => $slug,
				)
			)
		);
		$bn_count  = isset( $bn_counts[ $slug ] ) ? (int) $bn_counts[ $slug ] : ( isset( $bn_tab['count'] ) ? (int) $bn_tab['count'] : 0 );
		?>
		<a href="<?php echo esc_url( $tab_href ); ?>"
			class="bn-tab"
			role="tab"
			aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
			<?php echo esc_html( $bn_label ); ?>
			<?php if ( '' !== $bn_query ) : ?>
				<span class="bn-tab__count"><?php echo esc_html( (string) $bn_count ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php
do_action( 'buddynext_part_search_type_tabs_after', $args );
