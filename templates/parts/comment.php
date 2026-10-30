<?php
/**
 * One comment, with its replies.
 *
 * The single renderer for a comment: the first page of a post's comments, every
 * later page, a new comment and a new reply all come from here (via
 * \BuddyNext\Comments\CommentThread::render()), so the thread looks the same
 * however it arrived. The controls carry no Interactivity directives: the
 * post-card script handles them with delegated listeners that read the data-*
 * attributes below, which is what lets HTML inserted after page load work.
 *
 * Overridable: copy to {theme}/buddynext/parts/comment.php.
 *
 * @package BuddyNext
 *
 * @var array<string, mixed> $comment   Comment from CommentThread (enriched).
 * @var int                  $post_id   Post the thread belongs to.
 * @var int                  $viewer_id Viewer (0 = guest).
 * @var int                  $depth     Nesting depth (0 = top level).
 * @var array<int, array>    $reactions The viewer's reaction set (empty = no React control).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Comments\CommentService;
use BuddyNext\Comments\CommentThread;
use BuddyNext\Profile\AvatarService;

/**
 * Filter a comment's data before it is rendered.
 *
 * Replaces the client-side `buddynext.comment` filter: the comment is now
 * rendered on the server, so this is where an extension changes what it shows.
 *
 * @since 1.2.8
 *
 * @param array<string, mixed> $comment   Enriched comment.
 * @param int                  $viewer_id Viewer.
 * @param int                  $depth     Nesting depth.
 */
$bn_c        = (array) apply_filters( 'buddynext_comment_render_data', (array) $comment, (int) $viewer_id, (int) $depth );
$bn_viewer   = (int) $viewer_id;
$bn_post     = (int) $post_id;
$bn_depth    = min( (int) $depth, CommentService::MAX_REPLY_DEPTH );
$bn_id       = (int) ( $bn_c['id'] ?? 0 );
$bn_deleted  = ! empty( $bn_c['is_deleted'] );
$bn_hidden   = ! empty( $bn_c['is_hidden'] );
$bn_pinned   = ! empty( $bn_c['is_pinned'] );
$bn_frozen   = $bn_deleted || $bn_hidden;
$bn_own      = $bn_viewer > 0 && (int) ( $bn_c['user_id'] ?? 0 ) === $bn_viewer;
$bn_can_edit = ( $bn_c['can_edit'] ?? $bn_own ) && ! $bn_frozen;
$bn_can_del  = ( $bn_c['can_delete'] ?? $bn_own ) && ! $bn_frozen;
$bn_can_pin  = ! empty( $bn_c['can_pin'] ) && ! $bn_frozen;
// Reply is offered at every depth: past the cap a reply folds back to the
// deepest visible level, and the server flattens the same way when listing.
$bn_can_reply  = $bn_viewer > 0 && ! $bn_frozen;
$bn_can_report = $bn_viewer > 0 && ! $bn_own && ! $bn_frozen;
$bn_name       = (string) ( $bn_c['author_name'] ?? '' );
$bn_name       = '' !== $bn_name ? $bn_name : __( 'User', 'buddynext' );

$bn_classes = array( 'bn-comment-card' );
if ( $bn_pinned ) {
	$bn_classes[] = 'bn-comment-card--pinned';
}
if ( $bn_deleted ) {
	$bn_classes[] = 'bn-comment-card--deleted';
}
if ( $bn_hidden ) {
	$bn_classes[] = 'bn-comment-card--under-review';
}

// One action control: icon + label, the label also its accessible name.
$bn_action = static function ( string $css_class, string $icon, string $label, string $aria = '', bool $disabled = false ): void {
	?>
	<button type="button" class="<?php echo esc_attr( $css_class ); ?>"<?php disabled( $disabled ); ?> aria-label="<?php echo esc_attr( '' !== $aria ? $aria : $label ); ?>"><span class="bn-comment__act-ico"><?php buddynext_icon( $icon ); ?></span><span class="bn-comment__act-txt"><?php echo esc_html( $label ); ?></span></button>
	<?php
};

// One reaction glyph: the bundled emoji, else the owner's character/colour glyph.
$bn_glyph = static function ( ?array $reaction, int $size ): void {
	if ( null === $reaction ) {
		echo '&#9825;'; // ♡ - nothing chosen.
		return;
	}
	if ( '' !== (string) $reaction['emoji_url'] ) {
		printf(
			'<img src="%1$s" alt="%2$s" width="%3$d" height="%3$d">',
			esc_url( (string) $reaction['emoji_url'] ),
			esc_attr( (string) $reaction['label'] ),
			absint( $size )
		);
		return;
	}
	$bn_char  = '' !== (string) $reaction['char'] ? (string) $reaction['char'] : mb_strtoupper( mb_substr( (string) ( $reaction['label'] ?: $reaction['slug'] ), 0, 1 ) );
	$bn_style = sprintf( 'width:%1$dpx;height:%1$dpx;', absint( $size ) );
	if ( preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $reaction['color'] ) ) {
		$bn_style .= 'color:' . $reaction['color'] . ';';
	}
	printf( '<span class="bn-reaction-glyph" style="%1$s">%2$s</span>', esc_attr( $bn_style ), esc_html( $bn_char ) );
};

$bn_reactions_by_slug = array();
foreach ( (array) $reactions as $bn_r ) {
	$bn_reactions_by_slug[ (string) $bn_r['slug'] ] = $bn_r;
}
?>
<div class="<?php echo esc_attr( implode( ' ', $bn_classes ) ); ?>" data-comment-id="<?php echo absint( $bn_id ); ?>" data-depth="<?php echo absint( $bn_depth ); ?>">
	<div class="bn-comment__avatar" aria-hidden="true">
		<?php if ( '' !== (string) ( $bn_c['author_avatar_url'] ?? '' ) ) : ?>
			<img src="<?php echo esc_url( (string) $bn_c['author_avatar_url'] ); ?>" alt="" width="32" height="32" loading="lazy" data-bn-initials="<?php echo esc_attr( AvatarService::initials_for( $bn_name ) ); ?>">
		<?php else : ?>
			<?php echo esc_html( AvatarService::initials_for( $bn_name ) ); ?>
		<?php endif; ?>
	</div>
	<div class="bn-comment__body">
		<div class="bn-comment__header">
			<span class="bn-comment__author"><?php echo esc_html( $bn_name ); ?></span>
			<?php if ( '' !== (string) ( $bn_c['author_meta_html'] ?? '' ) ) : ?>
				<span class="bn-comment__author-meta"><?php echo wp_kses_post( (string) $bn_c['author_meta_html'] ); ?></span>
			<?php endif; ?>
			<?php if ( $bn_pinned ) : ?>
				<span class="bn-comment__pinned-badge"><?php esc_html_e( 'Pinned', 'buddynext' ); ?></span>
			<?php endif; ?>
			<?php if ( $bn_hidden ) : ?>
				<span class="bn-comment__review-badge"><?php esc_html_e( 'Under review', 'buddynext' ); ?></span>
			<?php endif; ?>
			<time class="bn-comment__time" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( (string) ( $bn_c['created_at'] ?? '' ) . ' UTC' ) ) ); ?>"><?php echo esc_html( buddynext_time_ago( (string) ( $bn_c['created_at'] ?? '' ) ) ); ?></time>
			<?php if ( ! empty( $bn_c['is_edited'] ) && ! $bn_deleted ) : ?>
				<span class="bn-comment__edited"><?php esc_html_e( '(edited)', 'buddynext' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $bn_deleted ) : ?>
			<p class="bn-comment__content" data-placeholder="1"><?php esc_html_e( 'This comment was deleted.', 'buddynext' ); ?></p>
		<?php else : ?>
			<p class="bn-comment__content"><?php echo wp_kses_post( (string) ( $bn_c['content_html'] ?? '' ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! $bn_deleted ) : ?>
		<div class="bn-comment__actions">
			<?php
			if ( ! $bn_frozen && ! empty( $bn_reactions_by_slug ) ) :
				$bn_default = (string) array_key_first( $bn_reactions_by_slug );
				$bn_mine    = ! empty( $bn_c['viewer_liked'] ) ? (string) ( $bn_c['viewer_reaction'] ?? '' ) : '';
				$bn_mine    = ( ! empty( $bn_c['viewer_liked'] ) && ! isset( $bn_reactions_by_slug[ $bn_mine ] ) ) ? $bn_default : $bn_mine;
				$bn_meta    = '' !== $bn_mine ? $bn_reactions_by_slug[ $bn_mine ] : null;
				$bn_tint    = ( $bn_meta && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $bn_meta['color'] ) ) ? '--bn-comment-reaction-color:' . $bn_meta['color'] . ';' : '';
				?>
				<span class="bn-comment__react-wrap">
					<button type="button" class="bn-comment__like-btn" data-reaction="<?php echo esc_attr( $bn_mine ); ?>" data-liked="<?php echo '' !== $bn_mine ? '1' : '0'; ?>" aria-pressed="<?php echo '' !== $bn_mine ? 'true' : 'false'; ?>"<?php echo '' !== $bn_tint ? ' style="' . esc_attr( $bn_tint ) . '"' : ''; ?>>
						<span class="bn-comment__like-icon"><?php $bn_glyph( $bn_meta, 16 ); ?></span>
						<span class="bn-comment__like-label"><?php echo esc_html( $bn_meta ? (string) $bn_meta['label'] : __( 'React', 'buddynext' ) ); ?></span>
						<span class="bn-comment__like-count"><?php echo esc_html( (string) (int) ( $bn_c['like_count'] ?? 0 ) ); ?></span>
					</button>
					<div class="bn-comment__react-picker" role="toolbar" aria-label="<?php esc_attr_e( 'Choose reaction', 'buddynext' ); ?>" hidden>
						<?php foreach ( $bn_reactions_by_slug as $bn_slug => $bn_reaction ) : ?>
							<button type="button" class="bn-comment__react-option" data-reaction="<?php echo esc_attr( (string) $bn_slug ); ?>" aria-label="<?php echo esc_attr( (string) $bn_reaction['label'] ); ?>" title="<?php echo esc_attr( (string) $bn_reaction['label'] ); ?>"><?php $bn_glyph( $bn_reaction, 28 ); ?></button>
						<?php endforeach; ?>
					</div>
				</span>
			<?php endif; ?>
			<?php
			if ( $bn_can_reply ) {
				$bn_action( 'bn-comment__reply-btn', 'reply', __( 'Reply', 'buddynext' ) );
			}
			if ( $bn_can_edit ) {
				$bn_action( 'bn-comment__edit-btn', 'edit', __( 'Edit', 'buddynext' ) );
			}
			if ( $bn_can_del ) {
				$bn_action( 'bn-comment__delete-btn', 'trash', __( 'Delete', 'buddynext' ) );
			}
			if ( $bn_can_pin ) {
				$bn_action( 'bn-comment__pin-btn', 'pin', $bn_pinned ? __( 'Unpin', 'buddynext' ) : __( 'Pin', 'buddynext' ) );
			}
			if ( $bn_can_report ) {
				// Already reported: the same disabled "Reported" the button turns into after a report.
				if ( ! empty( $bn_c['viewer_reported'] ) ) {
					$bn_action( 'bn-comment__report-btn', 'flag', __( 'Reported', 'buddynext' ), __( 'Reported', 'buddynext' ), true );
				} else {
					$bn_action( 'bn-comment__report-btn', 'flag', __( 'Report', 'buddynext' ), __( 'Report this comment', 'buddynext' ) );
				}
			}
			?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $bn_c['replies'] ) ) : ?>
			<div class="bn-comment__replies">
				<?php echo CommentThread::render( (array) $bn_c['replies'], $bn_post, $bn_viewer, $bn_depth + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each comment escapes its own output in this template. ?>
			</div>
		<?php endif; ?>
	</div>
</div>
