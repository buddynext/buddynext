<?php
/**
 * BuddyNext template part: hashtag-sidebar-related.
 *
 * Sidebar "Related hashtags" card. Renders a list of related hashtag
 * chips with post counts and a per-row follow toggle (logged-in).
 * Returns silently when `$related_tags` is empty.
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var array $related_tags    Required. Related-hashtag rows (each with slug + post_count).
 * @var bool  $is_logged_in    Optional. Whether viewer is logged in. Default false.
 * @var int   $current_user_id Optional. Viewing user ID. Default 0.
 * @var array $following_map    Optional. slug => bool map of which related tags the
 *                              viewer follows (resolved once by HashtagService::following_map(),
 *                              killing the per-row is_following() N+1). Default [].
 * @var array $classes         Optional. Extra CSS classes appended to `.bn-sidebar-widget`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_sidebar_related_before', $args )
 *   - do_action( 'buddynext_part_hashtag_sidebar_related_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_related_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_related_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'related_tags'    => isset( $related_tags ) ? (array) $related_tags : array(),
	'is_logged_in'    => isset( $is_logged_in ) ? (bool) $is_logged_in : false,
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'following_map'   => isset( $following_map ) ? (array) $following_map : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_sidebar_related_args', $args );

if ( empty( $args['related_tags'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-widget' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_sidebar_related_classes', $bn_classes, $args );
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

$bn_related       = (array) $args['related_tags'];
$bn_logged_in     = (bool) $args['is_logged_in'];
$bn_following_map = (array) $args['following_map'];

do_action( 'buddynext_part_hashtag_sidebar_related_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'Related hashtags', 'buddynext' ); ?></h2>
	<ul class="bn-hashtag-related">
		<?php
		foreach ( $bn_related as $rel_tag ) :
			// Service rows are arrays; tolerate object rows defensively.
			$rel_tag   = (array) $rel_tag;
			$rel_slug  = (string) ( $rel_tag['slug'] ?? '' );
			$rel_count = absint( $rel_tag['post_count'] ?? 0 );
			if ( '' === $rel_slug ) {
				continue;
			}
			// Follow state resolved once via following_map() — no per-row query.
			$rel_following = ! empty( $bn_following_map[ $rel_slug ] );
			?>
			<li class="bn-hashtag-related__row">
				<a class="bn-badge bn-hashtag-related__chip" data-tone="accent"
					href="<?php echo esc_url( PageRouter::hashtag_feed_url( $rel_slug ) ); ?>"
				>#<?php echo esc_html( $rel_slug ); ?></a>
				<span class="bn-hashtag-related__count">
					<?php
					printf(
						/* translators: %s: post count */
						esc_html__( '%s posts', 'buddynext' ),
						esc_html( number_format_i18n( $rel_count ) )
					);
					?>
				</span>
				<?php if ( $bn_logged_in ) : ?>
					<?php
					// Each chip carries its own reactive context so the follow
					// toggle re-renders class / aria-pressed / label off the one
					// ctx.following value (no querySelectorAll paint loop).
					$rel_ctx = wp_json_encode(
						array(
							'hashtag'   => $rel_slug,
							'following' => $rel_following,
						)
					);
					?>
					<button
						class="bn-btn bn-hashtag-related__follow<?php echo $rel_following ? ' following' : ''; ?>"
						data-variant="<?php echo $rel_following ? 'secondary' : 'primary'; ?>"
						data-size="xs"
						type="button"
						data-wp-context="<?php echo esc_attr( (string) $rel_ctx ); ?>"
						data-wp-on--click="actions.toggleFollowHashtag"
						data-wp-class--following="context.following"
						data-wp-bind--aria-pressed="state.hashtagFollowPressed"
						data-hashtag="<?php echo esc_attr( $rel_slug ); ?>"
						aria-pressed="<?php echo $rel_following ? 'true' : 'false'; ?>"
					>
						<span class="bn-hashtag-related__follow-on"><?php esc_html_e( 'Following', 'buddynext' ); ?></span>
						<span class="bn-hashtag-related__follow-off"><?php esc_html_e( 'Follow', 'buddynext' ); ?></span>
					</button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
do_action( 'buddynext_part_hashtag_sidebar_related_after', $args );
