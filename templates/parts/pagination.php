<?php
/**
 * BuddyNext template part: pagination.
 *
 * Renders a page-link strip using the `.bn-pagination` v2 primitive
 * (see `assets/css/bn-admin.css`). Designed to wrap WordPress' core
 * {@see paginate_links()} so consumers describe state, not markup.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $current      Required. The current page (1-based).
 * @var int    $total        Required. Total number of pages.
 * @var string $base_url     Optional. Base URL pattern with %#% placeholder. When
 *                           omitted, uses the current page URL.
 * @var string $query_var    Optional. Query var used in the base URL. Default 'paged'.
 * @var int    $end_size     Optional. Number of page numbers shown at the edges. Default 1.
 * @var int    $mid_size     Optional. Number of page numbers shown around the current page. Default 2.
 * @var string $prev_text    Optional. Previous-link text. Defaults to a localized arrow.
 * @var string $next_text    Optional. Next-link text. Defaults to a localized arrow.
 * @var string $aria_label   Optional. ARIA label for the wrapping <nav>. Default 'Pagination'.
 * @var array  $classes      Optional. Extra CSS classes appended to `.bn-pagination`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_pagination_before', $args )
 *   - do_action( 'buddynext_part_pagination_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_pagination_args',          array $args )
 *   - apply_filters( 'buddynext_part_pagination_classes',       array $classes, array $args )
 *   - apply_filters( 'buddynext_part_pagination_paginate_args', array $paginate_args, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'current'    => isset( $current ) ? max( 1, (int) $current ) : 1,
	'total'      => isset( $total ) ? max( 0, (int) $total ) : 0,
	'base_url'   => isset( $base_url ) ? (string) $base_url : '',
	'query_var'  => isset( $query_var ) ? (string) $query_var : 'paged',
	'end_size'   => isset( $end_size ) ? max( 0, (int) $end_size ) : 1,
	'mid_size'   => isset( $mid_size ) ? max( 0, (int) $mid_size ) : 2,
	'prev_text'  => isset( $prev_text ) ? (string) $prev_text : __( '&laquo; Prev', 'buddynext' ),
	'next_text'  => isset( $next_text ) ? (string) $next_text : __( 'Next &raquo;', 'buddynext' ),
	'aria_label' => isset( $aria_label ) ? (string) $aria_label : __( 'Pagination', 'buddynext' ),
	'classes'    => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_pagination_args', $args );

if ( (int) $args['total'] < 2 ) {
	return;
}

$bn_classes = array_merge( array( 'bn-pagination' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_pagination_classes', $bn_classes, $args );
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

if ( '' === (string) $args['base_url'] ) {
	$bn_current_url = remove_query_arg( (string) $args['query_var'] );
	$bn_base        = add_query_arg( (string) $args['query_var'], '%#%', $bn_current_url );
} else {
	$bn_base = (string) $args['base_url'];
}

$bn_paginate_args = array(
	'base'      => $bn_base,
	'format'    => '',
	'current'   => (int) $args['current'],
	'total'     => (int) $args['total'],
	'end_size'  => (int) $args['end_size'],
	'mid_size'  => (int) $args['mid_size'],
	'prev_text' => (string) $args['prev_text'],
	'next_text' => (string) $args['next_text'],
	'type'      => 'array',
);

/** Computed paginate_links() args. @var array<string,mixed> $bn_paginate_args */
$bn_paginate_args = (array) apply_filters( 'buddynext_part_pagination_paginate_args', $bn_paginate_args, $args );

$bn_links = paginate_links( $bn_paginate_args );

if ( empty( $bn_links ) || ! is_array( $bn_links ) ) {
	return;
}

// Rewrite default page-numbers classes onto v2 `.bn-page-btn` to match other templates.
$bn_class_rewrite = function ( string $html ): string {
	$html = str_replace( 'class="page-numbers current"', 'class="bn-page-btn current" aria-current="page"', $html );
	$html = str_replace( 'class="page-numbers"', 'class="bn-page-btn"', $html );
	$html = str_replace( 'class="prev page-numbers"', 'class="bn-page-btn"', $html );
	$html = str_replace( 'class="next page-numbers"', 'class="bn-page-btn"', $html );
	$html = str_replace( 'class="page-numbers dots"', 'class="bn-page-btn dots"', $html );
	return $html;
};

do_action( 'buddynext_part_pagination_before', $args );
?>
<nav class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php echo esc_attr( (string) $args['aria_label'] ); ?>">
	<?php
	$bn_allowed = array(
		'a'    => array(
			'href'  => array(),
			'class' => array(),
		),
		'span' => array(
			'class'        => array(),
			'aria-current' => array(),
		),
	);
	foreach ( $bn_links as $bn_link ) {
		// paginate_links() already escapes its href output; we only allowlist tags + rewrite classes.
		echo wp_kses( $bn_class_rewrite( (string) $bn_link ), $bn_allowed );
	}
	?>
</nav>
<?php
do_action( 'buddynext_part_pagination_after', $args );
