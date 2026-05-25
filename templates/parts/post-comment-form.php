<?php
/**
 * BuddyNext template part: post-comment-form.
 *
 * Comment textarea + submit button rendered inside the post-card's comments
 * expand region. Returns silently when the viewer is unauthenticated.
 * Mirrors the markup previously inlined in
 * `templates/partials/post-card.php` between `<div class="bn-comment-form">`
 * and its matching closing tag (plus the legacy `buddynext_post_comment_form_extra`
 * action, kept as a transitional bridge — Pro listeners should migrate to
 * `buddynext_part_post_comment_form_after`).
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var array  $bn_post     Hydrated post array.
 * @var int   $bn_post_id   Post ID.
 * @var int   $user_id      Current viewer ID. Part returns silently when 0.
 * @var string $placeholder Textarea placeholder.
 * @var array  $classes     Optional extra CSS classes.
 *
 * Fires:
 *   - do_action( 'buddynext_part_post_comment_form_before', $args )
 *   - do_action( 'buddynext_part_post_comment_form_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_post_comment_form_args',    array $args )
 *   - apply_filters( 'buddynext_part_post_comment_form_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'bn_post'     => isset( $bn_post ) && is_array( $bn_post ) ? $bn_post : array(),
	'bn_post_id'  => isset( $bn_post_id ) ? absint( $bn_post_id ) : 0,
	'user_id'     => isset( $user_id ) ? absint( $user_id ) : 0,
	'placeholder' => isset( $placeholder ) ? (string) $placeholder : __( 'Write a comment...', 'buddynext' ),
	'classes'     => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_post_comment_form_args', $args );

if ( 0 === (int) $args['bn_post_id'] || 0 === (int) $args['user_id'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-comment-form' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_post_comment_form_classes', $bn_classes, $args );
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

$bn_cf_post_id     = (int) $args['bn_post_id'];
$bn_cf_user_id     = (int) $args['user_id'];
$bn_cf_placeholder = (string) $args['placeholder'];

$current_display_name = (string) get_the_author_meta( 'display_name', $bn_cf_user_id );
$name_for_initials    = '' !== $current_display_name ? $current_display_name : 'U';
$current_initials     = implode( '', array_map( static fn( string $w ): string => strtoupper( mb_substr( $w, 0, 1 ) ), explode( ' ', $name_for_initials ) ) );

do_action( 'buddynext_part_post_comment_form_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<span class="bn-avatar bn-comment-form__avatar" data-size="sm" aria-hidden="true"><?php echo esc_html( mb_substr( $current_initials, 0, 2 ) ); ?></span>
	<label for="bn-comment-input-<?php echo absint( $bn_cf_post_id ); ?>" class="screen-reader-text">
		<?php esc_html_e( 'Write a comment', 'buddynext' ); ?>
	</label>
	<textarea
		id="bn-comment-input-<?php echo absint( $bn_cf_post_id ); ?>"
		class="bn-input bn-textarea bn-comment-form__input"
		placeholder="<?php echo esc_attr( $bn_cf_placeholder ); ?>"
		aria-label="<?php esc_attr_e( 'Comment text', 'buddynext' ); ?>"
		data-comment-input="<?php echo absint( $bn_cf_post_id ); ?>"
		rows="1"
	></textarea>
	<button
		type="button"
		class="bn-btn bn-comment-form__submit"
		data-variant="primary"
		data-size="sm"
		data-wp-on--click="actions.submitComment"
		aria-label="<?php esc_attr_e( 'Post comment', 'buddynext' ); ?>"
	>
		<?php buddynext_icon( 'send' ); ?>
	</button>
</div>

<?php
do_action( 'buddynext_part_post_comment_form_after', $args );
