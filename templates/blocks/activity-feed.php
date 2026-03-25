<?php
/**
 * Block template: Activity Feed
 *
 * Renders a feed of post cards using the shared post-card partial.
 * All interactive actions (React, Comment, Share, Save) are provided
 * by the partial — no inline HTML duplication.
 *
 * Variables:
 *   string $scope    'home' | 'explore' | 'profile'
 *   int    $per_page Items per page
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

$viewer_id   = get_current_user_id();
$scope       = $scope ?? 'home';
$bn_per_page = $per_page ?? 20;
$feed_svc    = buddynext_service( 'feed' );

if ( 'home' === $scope && $viewer_id ) {
	$result = $feed_svc->home_feed( $viewer_id, null, $bn_per_page );
} else {
	$result = $feed_svc->explore_feed( null, $bn_per_page );
}

$bn_posts = $result['items'] ?? array();
$has_more = null !== ( $result['next_cursor'] ?? null );
$context  = in_array( $scope, array( 'home', 'explore', 'profile' ), true ) ? $scope : 'home';
?>
<div class="bn-block-activity-feed" data-scope="<?php echo esc_attr( $scope ); ?>">
	<?php if ( empty( $bn_posts ) ) : ?>
		<div class="bn-empty-state">
			<?php buddynext_icon( 'message-circle' ); ?>
			<div class="bn-empty-state__title"><?php esc_html_e( 'No posts yet', 'buddynext' ); ?></div>
			<p><?php esc_html_e( 'Be the first to share something with the community!', 'buddynext' ); ?></p>
		</div>
	<?php else : ?>
		<?php
		foreach ( $bn_posts as $bn_post ) {
			buddynext_get_template(
				'partials/post-card.php',
				array(
					'post'            => $bn_post,
					'current_user_id' => $viewer_id,
					'context'         => $context,
				)
			);
		}
		?>
		<?php if ( $has_more ) : ?>
			<button class="bn-load-more" data-scope="<?php echo esc_attr( $scope ); ?>" data-cursor="<?php echo esc_attr( $result['next_cursor'] ?? '' ); ?>" data-per-page="<?php echo absint( $bn_per_page ); ?>">
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</div>
