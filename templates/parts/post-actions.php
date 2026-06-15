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
	'share_count'   => isset( $share_count ) ? absint( $share_count ) : 0,
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
$bn_actions_share_cnt   = (int) $args['share_count'];

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
			<span data-wp-bind--class="state.reactionIconClass" aria-hidden="true">
				<?php
				// Idle state shows the Lucide heart outline (matches the rest
				// of the chrome); once the user has reacted, CSS swaps in the
				// corresponding colored Fluent Emoji via the
				// .bn-post-card__react-icon--{type} modifier class — see
				// `.bn-post-card__react-icon--*` in bn-feed.css.
				buddynext_icon( 'heart' );
				?>
			</span>
			<span class="bn-post-card__action-label"><?php esc_html_e( 'React', 'buddynext' ); ?></span>
		</button>

		<div
			class="bn-post-card__emoji-picker"
			role="toolbar"
			aria-label="<?php esc_attr_e( 'Choose reaction', 'buddynext' ); ?>"
			data-wp-bind--hidden="!state.showReactionPicker"
			hidden
		>
			<?php
			// Human labels for the six built-in reactions. Pro custom slugs
			// resolve their label/glyph/colour through the
			// `buddynext_reaction_meta` filter below.
			$bn_builtin_labels = array(
				'like'  => __( 'Like', 'buddynext' ),
				'love'  => __( 'Love', 'buddynext' ),
				'haha'  => __( 'Haha', 'buddynext' ),
				'wow'   => __( 'Wow', 'buddynext' ),
				'sad'   => __( 'Sad', 'buddynext' ),
				'angry' => __( 'Angry', 'buddynext' ),
			);

			// Consume the registered reaction-type set so admin-defined custom
			// reactions (Pro) appear alongside the six defaults. Degrade to the
			// built-in slugs if the service is unavailable (e.g. front-end
			// isolation strips a dependency) so the picker never fatals.
			if ( class_exists( '\BuddyNext\Reactions\ReactionService' ) ) {
				$bn_reaction_types = (array) \BuddyNext\Reactions\ReactionService::reaction_types();
			} else {
				$bn_reaction_types = array_keys( $bn_builtin_labels );
			}

			foreach ( $bn_reaction_types as $reaction_key ) :
				$reaction_key = (string) $reaction_key;
				if ( '' === $reaction_key ) {
					continue;
				}

				// Base meta: built-in label when known, bundled SVG glyph, no
				// custom colour. Pro fills label/char/color for custom slugs.
				$bn_meta = array(
					'label' => isset( $bn_builtin_labels[ $reaction_key ] )
						? $bn_builtin_labels[ $reaction_key ]
						: ucfirst( str_replace( array( '-', '_' ), ' ', $reaction_key ) ),
					'char'  => '',
					'color' => '',
				);

				/**
				 * Filter the display meta for a single reaction type.
				 *
				 * @param array<string,string> $bn_meta      Keys: label, char, color.
				 * @param string                $reaction_key Reaction type slug.
				 */
				$bn_meta = (array) apply_filters( 'buddynext_reaction_meta', $bn_meta, $reaction_key );

				$reaction_label = isset( $bn_meta['label'] ) && '' !== (string) $bn_meta['label']
					? (string) $bn_meta['label']
					: $reaction_key;
				$reaction_char  = isset( $bn_meta['char'] ) ? (string) $bn_meta['char'] : '';
				$reaction_color = isset( $bn_meta['color'] ) ? (string) $bn_meta['color'] : '';

				// Prefer the bundled Fluent SVG; custom slugs have none, so fall
				// back to a colour-tinted text glyph so the button is visible.
				$bn_glyph = \BuddyNext\Core\IconService::render_emoji( $reaction_key, '', $reaction_label );
				$bn_chip_style = '';
				if ( '' === $bn_glyph ) {
					if ( '' === $reaction_char ) {
						$reaction_char = mb_strtoupper( mb_substr( $reaction_label, 0, 1 ) );
					}
					if ( '' !== $reaction_color && preg_match( '/^#[0-9a-fA-F]{6}$/', $reaction_color ) ) {
						$bn_chip_style = 'color:' . $reaction_color . ';';
					}
				}
				?>
				<button
					type="button"
					class="bn-post-card__emoji-btn"
					aria-label="<?php echo esc_attr( $reaction_label ); ?>"
					title="<?php echo esc_attr( $reaction_label ); ?>"
					data-wp-on--click="actions.setReaction"
					data-reaction-type="<?php echo esc_attr( $reaction_key ); ?>"
				><span class="bn-reaction-icon bn-reaction-icon--<?php echo esc_attr( $reaction_key ); ?>" aria-hidden="true"<?php echo '' !== $bn_chip_style ? ' style="' . esc_attr( $bn_chip_style ) . '"' : ''; ?>><?php
					if ( '' !== $bn_glyph ) {
						// IconService::render_emoji() returns sanitized markup.
						echo $bn_glyph; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo '<span class="bn-reaction-glyph">' . esc_html( $reaction_char ) . '</span>';
					}
				?></span></button>
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
		<?php // Count chip + aria-label are owned by adjustCommentCount() in feed/store.js (single writer for add + delete). ?>
		<span class="bn-post-card__action-count"<?php echo $bn_actions_comment_cnt > 0 ? '' : ' hidden'; ?>><?php echo esc_html( (string) $bn_actions_comment_cnt ); ?></span>
	</button>

	<?php
	// Share is available on every post, including reshares — sharing a reshare
	// amplifies the original (ShareService flattens the chain). Gated on the
	// site-owner re-shares toggle (BuddyNext → Social): when disabled the control
	// is removed entirely so it cannot be invoked.
	if ( ! empty( $args['can_share'] ) ) :
		?>
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
		<span class="bn-post-card__action-label"><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
		<span class="bn-post-card__action-count" data-wp-text="context.shareCount" data-wp-bind--hidden="!context.shareCount"<?php echo $bn_actions_share_cnt > 0 ? '' : ' hidden'; ?>><?php echo esc_html( (string) $bn_actions_share_cnt ); ?></span>
	</button>
		<?php
	endif;
	?>

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
