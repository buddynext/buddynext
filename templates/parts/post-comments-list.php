<?php
/**
 * BuddyNext template part: post-comments-list.
 *
 * The comment list of a post. On the post's own page (first_page) the first
 * page of comments is rendered here; elsewhere the list starts empty and the
 * post-card script fills it with HTML from the comments API when the viewer
 * opens the comments. Every comment comes from parts/comment.php either way.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array $bn_post     Hydrated post array.
 * @var int   $bn_post_id  Post ID. Part returns silently when 0.
 * @var array $comments    Optional pre-resolved comment rows.
 * @var int   $viewer_id   Current viewer ID (0 for guests).
 * @var bool  $first_page  Render the first page of comments now.
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
	'first_page' => ! empty( $first_page ),
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
// Resolve the colored Fluent Emoji vendor base once per post (the picker's
// built-in fallback set reads it).
$bn_emoji_base = plugins_url( 'assets/emoji/', dirname( __DIR__, 1 ) );

// The viewer's reaction set: the owner-enabled reactions (plus Pro customs), or
// none when Reactions is off or the viewer may not interact. One source with the
// rendered comments (CommentThread::reaction_set()).
$bn_reactions         = \BuddyNext\Comments\CommentThread::reaction_set( (int) $args['viewer_id'] );
$bn_reactions_enabled = ! empty( $bn_reactions );

// First page rendered here when the thread opens at first paint (the post's own
// page), so the conversation is in the HTML; later pages come from the API as HTML
// built by the same template.
$bn_first = null;
if ( $args['first_page'] ) {
	$bn_first = ( new \BuddyNext\Comments\CommentThread() )->page( 'post', (int) $args['bn_post_id'], (int) $args['viewer_id'], 1, \BuddyNext\Comments\CommentThread::PAGE_SIZE );
}
?>
<div
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-comment-list="<?php echo absint( $args['bn_post_id'] ); ?>"
	data-emoji-base="<?php echo esc_attr( trailingslashit( $bn_emoji_base ) ); ?>"
	data-reactions-enabled="<?php echo $bn_reactions_enabled ? '1' : '0'; ?>"
	data-reactions="<?php echo esc_attr( (string) wp_json_encode( $bn_reactions ) ); ?>"
	data-per-page="<?php echo absint( \BuddyNext\Comments\CommentThread::PAGE_SIZE ); ?>"
	<?php if ( null !== $bn_first ) : ?>
		data-loaded="1"
		data-page="1"
	<?php endif; ?>
>
<?php
if ( null !== $bn_first ) {
	echo \BuddyNext\Comments\CommentThread::render( (array) $bn_first['items'], (int) $args['bn_post_id'], (int) $args['viewer_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts/comment.php escapes its own output.
	$bn_remaining = (int) $bn_first['total'] - \BuddyNext\Comments\CommentThread::PAGE_SIZE;
	if ( $bn_remaining > 0 ) {
		printf(
			'<button type="button" class="bn-comment-loadmore">%s</button>',
			/* translators: %d: number of comments not shown yet. */
			esc_html( sprintf( __( 'View more comments (%d)', 'buddynext' ), $bn_remaining ) )
		);
	}
	if ( ! empty( $bn_first['replies_truncated'] ) ) {
		echo '<p class="bn-comment-truncated" role="note">' . esc_html__( "Some replies aren't shown.", 'buddynext' ) . '</p>';
	}
}
?>
</div>
<?php
do_action( 'buddynext_part_post_comments_list_after', $args );
