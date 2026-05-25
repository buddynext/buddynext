<?php
/**
 * BuddyNext template part: post-cw-overlay.
 *
 * Content-warning blur overlay rendered between the post head row and the
 * post body. JavaScript flips `state.showContent` when the viewer clicks the
 * "Show anyway" button to reveal the underlying body. Mirrors the markup
 * previously inlined in `templates/partials/post-card.php` under the
 * `<!-- Content warning overlay -->` comment.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var bool   $has_cw      Whether the post carries a content warning. Required.
 * @var string $cw_type     CW type slug (nsfw|spoilers|violence|language).
 * @var string $cw_label    Already-escaped display label.
 * @var int    $bn_post_id  Post ID (for hook context).
 * @var array  $classes     Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_cw_overlay_before', $args )
 *   - do_action( 'buddynext_part_post_cw_overlay_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_cw_overlay_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_cw_overlay_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'has_cw'     => ! empty( $has_cw ),
	'cw_type'    => isset( $cw_type ) ? (string) $cw_type : '',
	'cw_label'   => isset( $cw_label ) ? (string) $cw_label : '',
	'bn_post_id' => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'classes'    => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_cw_overlay_args', $args );

if ( empty( $args['has_cw'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__cw-overlay' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_cw_overlay_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_post_cw_overlay_before', $args );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-wp-bind--hidden="state.showContent"
>
	<span class="bn-post-card__cw-icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
	<p class="bn-post-card__cw-label"><?php echo esc_html( (string) $args['cw_label'] ); ?></p>
	<button
		type="button"
		class="bn-post-card__cw-reveal"
		data-wp-on--click="actions.revealContent"
	><?php esc_html_e( 'Show anyway', 'buddynext' ); ?></button>
</div>
<?php
do_action( 'buddynext_part_post_cw_overlay_after', $args );
