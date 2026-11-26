<?php
/**
 * BuddyNext template part: the Space Blog tab.
 *
 * The articles written for an open space, newest first, as the feed's own post
 * cards (the article card inside), with the shared Load more below. A member who
 * may write for the space gets the "Write for this Space" button in the header
 * and in the empty state; everyone else only reaches this tab once the space has
 * an article. Rendered by MemberBlogBridge::render_space_blog_panel(), which
 * fetches the page; this part only paints it.
 *
 * Overridable: copy to {theme}/buddynext/parts/space-blog-panel.php.
 *
 * @package BuddyNext
 * @since   1.3.0
 *
 * @var int    $space_id  Space id.
 * @var int    $viewer_id Viewer (0 = logged out).
 * @var array  $posts     Hydrated article post arrays (FeedService::space_feed()).
 * @var array  $pager     FeedWindow::links() result for parts/feed-load-more.php.
 * @var string $write_url Where this viewer writes for the space ('' = may not).
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$bn_sb_posts  = isset( $posts ) && is_array( $posts ) ? $posts : array();
$bn_sb_viewer = isset( $viewer_id ) ? (int) $viewer_id : 0;
$bn_sb_pager  = isset( $pager ) && is_array( $pager ) ? $pager : array();
$bn_sb_write  = isset( $write_url ) ? (string) $write_url : '';
?>
<section class="bn-space-blog" aria-labelledby="bn-space-blog-title">
	<header class="bn-space-blog__head">
		<h2 class="bn-space-blog__title" id="bn-space-blog-title"><?php esc_html_e( 'Blog', 'buddynext' ); ?></h2>
		<?php if ( '' !== $bn_sb_write ) : ?>
			<a class="bn-btn bn-space-blog__write" data-variant="primary" href="<?php echo esc_url( $bn_sb_write ); ?>">
				<?php buddynext_icon( 'edit' ); ?>
				<span><?php esc_html_e( 'Write for this Space', 'buddynext' ); ?></span>
			</a>
		<?php endif; ?>
	</header>

	<?php if ( array() !== $bn_sb_posts ) : ?>
		<?php
		// Every per-viewer question the cards ask (reaction, bookmark, follow state)
		// answered for the page up front, as the space feed does.
		buddynext_service( 'feed' )->prime_viewer_state( $bn_sb_posts, $bn_sb_viewer );
		// Router region around the list and its Load more: the router hydrates what it swaps.
		$bn_sb_region = (bool) apply_filters( 'buddynext_feed_client_pagination', true )
			? ' data-wp-interactive="buddynext/feed" data-wp-router-region="buddynext/feed"'
			: '';
		echo '<div class="bn-feed-region"' . $bn_sb_region . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
		?>
		<div class="bn-feed-stack" role="feed" aria-labelledby="bn-space-blog-title">
			<?php
			foreach ( $bn_sb_posts as $bn_sb_post ) {
				$bn_sb_post = (array) $bn_sb_post;
				if ( isset( $bn_sb_post['media_ids'] ) && is_string( $bn_sb_post['media_ids'] ) ) {
					$bn_sb_post['media_ids'] = json_decode( $bn_sb_post['media_ids'], true );
				}
				// The same card, with the same arguments, the space feed renders.
				buddynext_get_template(
					'partials/post-card.php',
					array(
						'post'            => $bn_sb_post,
						'current_user_id' => $bn_sb_viewer,
						'context'         => 'space',
					)
				);
			}
			?>
		</div>
		<?php
		buddynext_get_template( 'parts/feed-load-more.php', $bn_sb_pager );
		\BuddyNext\Core\IconService::print_region_sprite(); // Shapes travel with the swapped region.
		echo '</div>';
		?>
	<?php else : ?>
		<?php
		buddynext_get_template(
			'parts/empty-state.php',
			array(
				'icon'      => 'file-text',
				'title'     => __( 'No articles in this space yet.', 'buddynext' ),
				'body'      => '' !== $bn_sb_write ? __( 'Write the first one. It will appear here and in your profile.', 'buddynext' ) : '',
				'cta_url'   => $bn_sb_write,
				'cta_label' => '' !== $bn_sb_write ? __( 'Write for this Space', 'buddynext' ) : '',
				'cta_icon'  => 'edit',
			)
		);
		?>
	<?php endif; ?>
</section>
