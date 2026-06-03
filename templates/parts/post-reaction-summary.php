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
 * @var array $top_reactions   List of `{ slug, count }` rows — the top-N
 *                             reaction types on this post in DESC order.
 *                             When supplied, the chip strip renders
 *                             per-type emoji + count chips using
 *                             Microsoft Fluent SVGs via `buddynext_emoji()`.
 *                             When empty, the strip falls back to the
 *                             single aggregate `<heart icon> N` summary.
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

// The summary strip now shows reactions only — comment and share counts live
// directly on their action buttons. Render only when there are reactions.
if ( 0 === (int) $args['reaction_count'] ) {
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

$bn_top_reactions = (array) $args['top_reactions'];
$bn_reaction_n    = (int) $args['reaction_count'];
$bn_comment_n     = (int) $args['comment_count'];
$bn_share_n       = (int) $args['share_count'];

do_action( 'buddynext_part_post_reaction_summary_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" aria-label="<?php esc_attr_e( 'Post summary', 'buddynext' ); ?>">
	<?php if ( $bn_reaction_n > 0 ) : ?>
		<?php
		// Clickable reactor-list trigger — opens the FB-style popover
		// listing who reacted with what. data-bn-reactors attribute is
		// the hook the feed JS uses to wire the click handler.
		$bn_reactors_label = sprintf(
			/* translators: %d: total number of reactions */
			_n( 'See %d reaction', 'See all %d reactions', $bn_reaction_n, 'buddynext' ),
			$bn_reaction_n
		);
		?>
		<button
			type="button"
			class="bn-post-card__reactors-trigger"
			aria-label="<?php echo esc_attr( $bn_reactors_label ); ?>"
			data-bn-reactors
			data-bn-object-type="post"
			data-bn-object-id="<?php echo absint( (int) $args['bn_post_id'] ); ?>"
			data-bn-count="<?php echo esc_attr( (string) $bn_reaction_n ); ?>"
		>
		<?php if ( ! empty( $bn_top_reactions ) ) : ?>
			<?php
			// Per-type chips (v2 prototype pattern). Each chip renders a
			// Microsoft Fluent emoji + count. If an exotic slug has no
			// vendored asset, `buddynext_get_emoji()` returns ''; fall back
			// to the slug as a small text token so the count still shows.
			foreach ( $bn_top_reactions as $bn_top ) :
				$bn_slug  = isset( $bn_top['slug'] ) ? (string) $bn_top['slug'] : '';
				$bn_count = isset( $bn_top['count'] ) ? (int) $bn_top['count'] : 0;
				if ( '' === $bn_slug || $bn_count < 1 ) {
					continue;
				}
				$bn_emoji_img = buddynext_get_emoji( $bn_slug, 'bn-post-card__reaction-emoji', '' );
				?>
				<span class="bn-post-card__summary-chip bn-post-card__summary-chip--reaction">
					<?php if ( '' !== $bn_emoji_img ) : ?>
						<?php echo $bn_emoji_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside IconService::render_emoji(). ?>
					<?php else : ?>
						<span class="bn-post-card__reaction-fallback"><?php echo esc_html( $bn_slug ); ?></span>
					<?php endif; ?>
					<?php echo esc_html( (string) $bn_count ); ?>
				</span>
			<?php endforeach; ?>
		<?php else : ?>
			<span class="bn-post-card__summary-chip">
				<?php buddynext_icon( 'heart' ); ?> <?php echo esc_html( (string) $bn_reaction_n ); ?>
			</span>
		<?php endif; ?>
		</button>
	<?php endif; ?>
</div>
<?php
do_action( 'buddynext_part_post_reaction_summary_after', $args );
