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

// Batch-prime per-viewer state before the SSR post-card loop (C8.3).
$feed_svc->prime_viewer_state( (array) $bn_posts, $viewer_id );
?>
<div class="bn-block-activity-feed" data-scope="<?php echo esc_attr( $scope ); ?>">
	<?php if ( empty( $bn_posts ) ) : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'  => 'message-circle',
				'title' => __( 'No posts yet', 'buddynext' ),
				'body'  => __( 'Be the first to share something with the community!', 'buddynext' ),
			)
		);
		?>
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
			<?php
			/*
			 * This was a <button> carrying data-scope / data-cursor / data-per-page for a
			 * handler that does not exist anywhere in the plugin — clicking it did nothing,
			 * ever. A control that renders but is not wired is exactly what
			 * "if it renders, it is real" forbids.
			 *
			 * A block embed is a WINDOW onto the feed, not a second feed: it is dropped into
			 * an arbitrary page, and several of them can share one page, so a `shown`-style
			 * grow-this-page control cannot address the right one. So it now says what it
			 * actually does and sends the reader to the real feed, where pagination lives.
			 */
			?>
			<a
				href="<?php echo esc_url( 'explore' === $scope ? \BuddyNext\Core\PageRouter::explore_url() : \BuddyNext\Core\PageRouter::activity_url() ); ?>"
				class="bn-btn bn-load-more__btn"
				data-variant="ghost"
				data-size="sm"
			>
				<?php esc_html_e( 'See more in the feed', 'buddynext' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		<?php endif; ?>
	<?php endif; ?>
</div>
