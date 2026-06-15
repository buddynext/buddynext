<?php
/**
 * BuddyNext template part: post-comments-list.
 *
 * Empty container that the post-card Interactivity module hydrates with the
 * fetched comment list when the viewer expands the comments region. Mirrors
 * the `<div class="bn-comment-list">` element previously inlined in
 * `templates/partials/post-card.php`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $bn_post     Hydrated post array.
 * @var int   $bn_post_id  Post ID. Part returns silently when 0.
 * @var array $comments    Optional pre-resolved comment rows.
 * @var int   $viewer_id   Current viewer ID (0 for guests).
 * @var array $classes     Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_comments_list_before', $args )
 *   - do_action( 'buddynext_part_post_comments_list_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_comments_list_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_comments_list_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'bn_post'    => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id' => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'comments'   => isset( $comments ) && is_array( $comments ) ? $comments : array(),
	'viewer_id'  => isset( $viewer_id ) ? absint( $viewer_id ) : 0,
	'classes'    => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_comments_list_args', $args );

if ( 0 === (int) $args['bn_post_id'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-comment-list' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_comments_list_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_post_comments_list_before', $args );
?>
<?php
// Resolve the colored Fluent Emoji vendor base once per post. The
// comment-builder JS reads this attribute to render reaction
// pickers without round-tripping to PHP per node.
$bn_emoji_base = plugins_url( 'assets/emoji/', dirname( __DIR__, 1 ) );

// Reactions are a site-owner-toggleable feature (Settings → Features, default on).
// Expose its state to the comment-builder JS so per-comment React controls are
// suppressed when the owner disables it (the REST toggle path enforces the same
// gate). "1" when on, "0" when off.
$bn_reactions_enabled = ! function_exists( 'buddynext_service' )
	|| ! is_object( buddynext_service( 'features' ) )
	|| buddynext_service( 'features' )->is_enabled( 'reactions' );
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-comment-list="<?php echo absint( $args['bn_post_id'] ); ?>"
	data-emoji-base="<?php echo esc_attr( trailingslashit( $bn_emoji_base ) ); ?>"
	data-reactions-enabled="<?php echo $bn_reactions_enabled ? '1' : '0'; ?>"
></div>
<?php
do_action( 'buddynext_part_post_comments_list_after', $args );
