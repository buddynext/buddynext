<?php
/**
 * BuddyNext template part: post-reaction-summary.
 *
 * Renders the reaction / comment / share summary chip strip shown between
 * the post body and the action row. Mirrors the markup previously inlined in
 * `templates/partials/post-card.php` under the
 * `<!-- Reaction summary chips -->` comment.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int   $reaction_count  Total reactions on the post.
 * @var int   $comment_count   Total comments on the post.
 * @var int   $share_count     Total shares of the post.
 * @var array $top_reactions   Pre-resolved top-reaction descriptors (reserved).
 * @var int   $bn_post_id      Post ID (for hook context).
 * @var array $classes         Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_reaction_summary_before', $args )
 *   - do_action( 'buddynext_part_post_reaction_summary_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_reaction_summary_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_reaction_summary_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'reaction_count' => isset( $reaction_count ) ? absint( $reaction_count ) : 0,
	'comment_count'  => isset( $comment_count ) ? absint( $comment_count ) : 0,
	'share_count'    => isset( $share_count ) ? absint( $share_count ) : 0,
	'top_reactions'  => isset( $top_reactions ) && is_array( $top_reactions ) ? $top_reactions : array(),
	'bn_post_id'     => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'classes'        => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_reaction_summary_args', $args );

if ( 0 === (int) $args['reaction_count'] && 0 === (int) $args['comment_count'] && 0 === (int) $args['share_count'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__reactions', 'bn-post-card__reaction-summary' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_reaction_summary_classes', $bn_classes, $args );
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

do_action( 'buddynext_part_post_reaction_summary_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Post summary', 'buddynext' ); ?>">
	<?php if ( (int) $args['reaction_count'] > 0 ) : ?>
		<span class="bn-post-card__summary-chip">
			<?php buddynext_icon( 'heart' ); ?> <?php echo esc_html( (string) (int) $args['reaction_count'] ); ?>
		</span>
	<?php endif; ?>
	<?php if ( (int) $args['comment_count'] > 0 ) : ?>
		<span class="bn-post-card__summary-chip">
			<?php buddynext_icon( 'message-circle' ); ?> <?php echo esc_html( (string) (int) $args['comment_count'] ); ?>
		</span>
	<?php endif; ?>
	<?php if ( (int) $args['share_count'] > 0 ) : ?>
		<span class="bn-post-card__summary-chip">
			<?php buddynext_icon( 'share' ); ?> <?php echo esc_html( (string) (int) $args['share_count'] ); ?>
		</span>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_post_reaction_summary_after', $args );
