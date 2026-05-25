<?php
/**
 * BuddyNext template part: hashtag-sidebar-top-contributors.
 *
 * Sidebar "Top contributors" card. Lists the users with the most posts
 * for this hashtag, each linking to the contributor's profile.
 * Returns silently when `$top_contributors` is empty.
 *
 * Used by: templates/hashtags/feed.php.
 *
 * @package BuddyNext
 *
 * @var array $top_contributors Required. Contributor rows (each with user_id + post_count).
 * @var array $classes          Optional. Extra CSS classes appended to `.bn-sidebar-widget`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_hashtag_sidebar_top_contributors_before', $args )
 *   - do_action( 'buddynext_part_hashtag_sidebar_top_contributors_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_top_contributors_args',    array $args )
 *   - apply_filters( 'buddynext_part_hashtag_sidebar_top_contributors_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'top_contributors' => isset( $top_contributors ) ? (array) $top_contributors : array(),
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_hashtag_sidebar_top_contributors_args', $args );

if ( empty( $args['top_contributors'] ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-sidebar-widget' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_hashtag_sidebar_top_contributors_classes', $bn_classes, $args );
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

$bn_contributors = (array) $args['top_contributors'];

do_action( 'buddynext_part_hashtag_sidebar_top_contributors_before', $args );
?>
<div class="<?php echo esc_attr( $bn_class ); ?>">
	<h2 class="bn-sidebar-widget__title"><?php esc_html_e( 'Top contributors', 'buddynext' ); ?></h2>
	<ul class="bn-hashtag-contributors">
		<?php foreach ( $bn_contributors as $contrib ) : ?>
			<?php
			$contrib_id      = (int) $contrib->user_id;
			$contrib_user    = get_userdata( $contrib_id );
			$contrib_display = $contrib_user instanceof WP_User ? $contrib_user->display_name : __( 'Community Member', 'buddynext' );
			$contrib_init    = function_exists( 'bn_initials' ) ? bn_initials( $contrib_display ) : strtoupper( substr( $contrib_display, 0, 2 ) );
			$contrib_avatar  = get_avatar_url( $contrib_id, array( 'size' => 72 ) );
			$contrib_url     = PageRouter::profile_url( $contrib_id );
			?>
			<li class="bn-hashtag-contributors__row">
				<a class="bn-hashtag-contributors__link" href="<?php echo esc_url( $contrib_url ); ?>">
					<?php if ( $contrib_avatar ) : ?>
						<img
							src="<?php echo esc_url( $contrib_avatar ); ?>"
							alt=""
							class="bn-avatar"
							data-size="sm"
							loading="lazy"
							width="28"
							height="28"
						>
					<?php else : ?>
						<span class="bn-avatar" data-size="sm" aria-hidden="true">
							<?php echo esc_html( $contrib_init ); ?>
						</span>
					<?php endif; ?>
					<span class="bn-hashtag-contributors__info">
						<span class="bn-hashtag-contributors__name"><?php echo esc_html( $contrib_display ); ?></span>
						<span class="bn-hashtag-contributors__sub">
							<?php
							printf(
								/* translators: %d: number of posts */
								esc_html( _n( '%d post', '%d posts', (int) $contrib->post_count, 'buddynext' ) ),
								(int) $contrib->post_count
							);
							?>
						</span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
<?php
do_action( 'buddynext_part_hashtag_sidebar_top_contributors_after', $args );
