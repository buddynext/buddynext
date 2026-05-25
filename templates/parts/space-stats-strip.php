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

$bn_classes = array_merge( array( 'bn-sh-hero__stats' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_stats_strip_classes', $bn_classes, $args );

do_action( 'buddynext_part_space_stats_strip_before', $args );

buddynext_get_template(
	'parts/stat-strip.php',
	array(
		'stats'   => (array) $args['stats'],
		'classes' => $bn_classes,
	)
);

do_action( 'buddynext_part_space_stats_strip_after', $args );
