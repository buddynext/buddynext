<?php
/**
 * BuddyNext template part: post-actions.
 *
 * Flat action toolbar — React (with emoji picker), Comment, Share, and Save
 * (Bookmark) buttons. Mirrors the markup previously inlined in
 * `templates/partials/post-card.php` under the
 * `<!-- Flat action row -->` comment.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array       $bn_post         Hydrated post array.
 * @var int         $bn_post_id      Post ID.
 * @var string      $bn_post_type    Post type slug (used to suppress Share on re-shares).
 * @var string|null $user_reaction   The viewer's current reaction (null|like|love|haha|wow|sad|angry).
 * @var bool        $is_bookmarked   Whether the viewer has bookmarked the post.
 * @var bool        $can_react       Whether the viewer can react.
 * @var bool        $can_comment     Whether the viewer can comment.
 * @var bool        $can_share       Whether the viewer can share.
 * @var bool        $can_bookmark    Whether the viewer can bookmark.
 * @var int         $comment_count   Comment count for the aria label / chip.
 * @var array       $classes         Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_actions_before', $args )
 *   - do_action( 'buddynext_part_post_actions_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_actions_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_actions_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'bn_post'       => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id'    => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'bn_post_type'  => isset( $bn_post_type ) ? (string) $bn_post_type : 'text',
	'user_reaction' => isset( $user_reaction ) ? $user_reaction : null,
	'is_bookmarked' => ! empty( $is_bookmarked ),
	'can_react'     => ! empty( $can_react ),
	'can_comment'   => ! empty( $can_comment ),
	'can_share'     => ! empty( $can_share ),
	'can_bookmark'  => ! empty( $can_bookmark ),
	'comment_count' => isset( $comment_count ) ? absint( $comment_count ) : 0,
	'classes'       => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_actions_args', $args );

if ( 0 === (int) $args['bn_post_id'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-post-card__actions' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_actions_classes', $bn_classes, $args );
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

$bn_actions_post_type   = (string) $args['bn_post_type'];
$bn_actions_post_id     = (int) $args['bn_post_id'];
$bn_actions_comment_cnt = (int) $args['comment_count'];

do_action( 'buddynext_part_post_actions_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>" role="toolbar" aria-label="<?php esc_attr_e( 'Post actions', 'buddynext' ); ?>">

	<div class="bn-post-card__react-wrap">
		<button
			type="button"
			class="bn-post-card__action-btn bn-post-card__action-btn--react"
			aria-label="<?php esc_attr_e( 'React to post', 'buddynext' ); ?>"
			data-wp-on--click="actions.toggleReactionPicker"
			data-wp-bind--class="state.reactBtnClass"
		>
			<span data-wp-bind--class="state.reactionIconClass" aria-hidden="true"><?php buddynext_icon( 'heart' ); ?></span>
			<span class="bn-post-card__action-label"><?php esc_html_e( 'React', 'buddynext' ); ?></span>
		</button>

		<div
			class="bn-post-card__emoji-picker"
			role="toolbar"
			aria-label="<?php esc_attr_e( 'Choose reaction', 'buddynext' ); ?>"
			data-wp-bind--hidden="!state.showReactionPicker"
		>
			<?php
			$reaction_icons = array(
				'like'  => 'thumbs-up',
				'love'  => 'heart',
				'haha'  => 'reaction-haha',
				'wow'   => 'reaction-wow',
				'sad'   => 'reaction-sad',
				'angry' => 'reaction-angry',
			);
			foreach ( $reaction_icons as $reaction_key => $icon_slug ) :
				?>
				<button
					type="button"
					class="bn-post-card__emoji-btn"
					aria-label="<?php echo esc_attr( $reaction_key ); ?>"
					data-wp-on--click="actions.setReaction"
					data-reaction-type="<?php echo esc_attr( $reaction_key ); ?>"
				><span class="bn-reaction-icon bn-reaction-icon--<?php echo esc_attr( $reaction_key ); ?>" aria-hidden="true"><?php buddynext_icon( $icon_slug ); ?></span></button>
			<?php endforeach; ?>
		</div>
	</div>

	<button
		type="button"
		class="bn-post-card__action-btn"
		aria-label="
		<?php
			/* translators: %d: comment count */
			echo esc_attr( sprintf( _n( '%d comment', '%d comments', $bn_actions_comment_cnt, 'buddynext' ), $bn_actions_comment_cnt ) );
		?>
		"
		data-wp-on--click="actions.openComments"
		data-post-id="<?php echo absint( $bn_actions_post_id ); ?>"
	>
		<?php buddynext_icon( 'message-circle' ); ?>
		<span class="bn-post-card__action-label"><?php esc_html_e( 'Comment', 'buddynext' ); ?></span>
		<?php if ( $bn_actions_comment_cnt > 0 ) : ?>
			<span class="bn-comment-count"><?php echo esc_html( (string) $bn_actions_comment_cnt ); ?></span>
		<?php else : ?>
			<span class="bn-comment-count" hidden>0</span>
		<?php endif; ?>
	</button>

	<?php if ( 'share' !== $bn_actions_post_type ) : ?>
	<button
		type="button"
		class="bn-post-card__action-btn"
		data-wp-bind--class="state.shareBtnClass"
		aria-label="<?php esc_attr_e( 'Share post', 'buddynext' ); ?>"
		data-wp-on--click="actions.openShare"
		data-post-id="<?php echo absint( $bn_actions_post_id ); ?>"
		data-post-permalink="<?php echo esc_url( PageRouter::post_url( $bn_actions_post_id ) ); ?>"
	>
		<?php buddynext_icon( 'share' ); ?>
		<span class="bn-post-card__action-label" data-wp-text="state.shareLabel"></span>
	</button>
	<?php endif; ?>

	<?php if ( ! empty( $args['can_bookmark'] ) ) : ?>
		<button
			type="button"
			class="bn-post-card__action-btn"
			aria-label="<?php esc_attr_e( 'Bookmark post', 'buddynext' ); ?>"
			data-wp-on--click="actions.toggleBookmark"
			data-post-id="<?php echo absint( $bn_actions_post_id ); ?>"
			data-wp-bind--class="state.bookmarkBtnClass"
		>
			<span data-wp-bind--aria-pressed="state.bookmarked"><?php buddynext_icon( 'bookmark' ); ?></span>
			<span class="bn-post-card__action-label"><?php esc_html_e( 'Save', 'buddynext' ); ?></span>
		</button>
	<?php endif; ?>

</div><!-- .bn-post-card__actions -->
<?php
do_action( 'buddynext_part_post_actions_after', $args );
