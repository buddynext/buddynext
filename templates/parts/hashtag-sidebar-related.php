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

global $wpdb;

$args = array(
	'related_tags'    => isset( $related_tags ) ? (array) $related_tags : array(),
	'is_logged_in'    => isset( $is_logged_in ) ? (bool) $is_logged_in : false,
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
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

$bn_related     = (array) $args['related_tags'];
$bn_logged_in   = (bool) $args['is_logged_in'];
$bn_viewer_id   = (int) $args['current_user_id'];
$hashtags_table = $wpdb->prefix . 'bn_hashtags';
$follows_table  = $wpdb->prefix . 'bn_hashtag_follows';

do_action( 'buddynext_part_hashtag_sidebar_related_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'Related hashtags', 'buddynext' ); ?></h2>
	<ul class="bn-hashtag-related">
		<?php foreach ( $bn_related as $rel_tag ) : ?>
			<?php
			$rel_following = false;
			if ( $bn_logged_in ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rel_following = (bool) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$follows_table} hf
						 INNER JOIN {$hashtags_table} h ON h.id = hf.hashtag_id
						 WHERE hf.user_id = %d AND h.slug = %s LIMIT 1",
						$bn_viewer_id,
						$rel_tag->slug
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			?>
			<li class="bn-hashtag-related__row">
				<a class="bn-badge bn-hashtag-related__chip" data-tone="accent"
					href="<?php echo esc_url( PageRouter::hashtag_feed_url( $rel_tag->slug ) ); ?>"
				>#<?php echo esc_html( $rel_tag->slug ); ?></a>
				<span class="bn-hashtag-related__count">
					<?php
					printf(
						/* translators: %s: post count */
						esc_html__( '%s posts', 'buddynext' ),
						esc_html( number_format_i18n( absint( $rel_tag->post_count ) ) )
					);
					?>
				</span>
				<?php if ( $bn_logged_in ) : ?>
					<button
						class="bn-btn bn-hashtag-related__follow"
						data-variant="<?php echo $rel_following ? 'secondary' : 'primary'; ?>"
						data-size="xs"
						type="button"
						data-wp-on--click="actions.toggleFollowHashtag"
						data-hashtag="<?php echo esc_attr( $rel_tag->slug ); ?>"
						aria-pressed="<?php echo $rel_following ? 'true' : 'false'; ?>"
					>
						<?php echo $rel_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?>
					</button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
do_action( 'buddynext_part_hashtag_sidebar_related_after', $args );
