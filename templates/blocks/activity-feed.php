<?php
/**
 * Block template: Activity Feed
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
?>
<div class="bn-block-activity-feed" data-scope="<?php echo esc_attr( $scope ); ?>">
	<?php if ( empty( $bn_posts ) ) : ?>
		<p class="bn-empty"><?php esc_html_e( 'No posts yet. Be the first to share something!', 'buddynext' ); ?></p>
	<?php else : ?>
		<?php foreach ( $bn_posts as $bn_post ) : ?>
			<article class="bn-post-card" data-post-id="<?php echo absint( $bn_post['id'] ); ?>">
				<div class="bn-post-card__author">
					<?php echo get_avatar( $bn_post['user_id'], 36, '', '', array( 'class' => 'bn-avatar' ) ); ?>
					<span class="bn-post-card__name"><?php echo esc_html( get_the_author_meta( 'display_name', $bn_post['user_id'] ) ); ?></span>
					<time class="bn-post-card__time"><?php echo esc_html( human_time_diff( strtotime( $bn_post['created_at'] ), time() ) . ' ago' ); ?></time>
				</div>
				<?php if ( ! empty( $bn_post['content'] ) ) : ?>
					<div class="bn-post-card__body"><?php echo wp_kses_post( $bn_post['content'] ); ?></div>
				<?php endif; ?>
				<div class="bn-post-card__meta">
					<span class="bn-post-card__reactions"><?php echo absint( $bn_post['reaction_count'] ?? 0 ); ?> <?php esc_html_e( 'reactions', 'buddynext' ); ?></span>
					<span class="bn-post-card__comments"><?php echo absint( $bn_post['comment_count'] ?? 0 ); ?> <?php esc_html_e( 'comments', 'buddynext' ); ?></span>
				</div>
			</article>
		<?php endforeach; ?>
		<?php if ( $has_more ) : ?>
			<button class="bn-load-more" data-scope="<?php echo esc_attr( $scope ); ?>" data-cursor="<?php echo esc_attr( $result['next_cursor'] ?? '' ); ?>" data-per-page="<?php echo absint( $bn_per_page ); ?>">
				<?php esc_html_e( 'Load more', 'buddynext' ); ?>
			</button>
		<?php endif; ?>
	<?php endif; ?>
</div>
