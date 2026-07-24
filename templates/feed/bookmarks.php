<?php
/**
 * BuddyNext bookmarks hub template.
 *
 * Renders the authenticated user's saved-post list. Visibility re-applies the
 * post-privacy gates at read time (BookmarkService stores raw post_id rows,
 * not denormalised visibility) so unfollowing an author or losing space
 * membership immediately hides their bookmarked post here.
 *
 * Cursor-based pagination keyed on the bookmark's `created_at|id`. Bookmarks
 * remain in the bn_bookmarks table forever — even when the underlying post is
 * deleted — but deleted posts are filtered out at hydrate time.
 *
 * Overridable: copy to {theme}/buddynext/feed/bookmarks.php
 *
 * @package BuddyNext
 * @since   1.5.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\BookmarkService;

// Declares this fine-grained surface for the sidebar registry (SidebarRegistry
// reads it via Surface::current()) before the shell renders the right column.
\BuddyNext\Sidebar\Surface::set( 'bookmarks' );

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

/*
 * "Load more" grows this page (?shown=N) so the router can swap the list region in place
 * and the posts already on screen stay put — the same pagination behaviour as the home feed
 * and Explore, from the same shared control (parts/feed-load-more.php). Clamped to whole
 * pages and to six pages so a crafted URL cannot ask for an unbounded render.
 */
$bn_bm_page_size = 15;
$bn_bm_max_shown = $bn_bm_page_size * 6;
$bn_bm_raw_shown = isset( $_GET['shown'] ) ? absint( wp_unslash( $_GET['shown'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_bm_shown     = max(
	$bn_bm_page_size,
	min( $bn_bm_max_shown, (int) ( ceil( $bn_bm_raw_shown / $bn_bm_page_size ) * $bn_bm_page_size ) )
);

$bn_bookmarks_per_page = $bn_bm_shown;

// Cursor: opaque base64( "bookmark_created_at|post_id" ), decoded and validated
// inside the service. bn_bookmarks has a composite (user_id, post_id) primary
// key and no surrogate `id` column, so post_id is the stable tiebreaker.
$bn_bm_raw_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// One service call owns the keyset pagination, the canonical post-visibility
// gate (blocks, secret-space, followers-only, private, author suspension) and
// hydration — the same path PostController serves. The template just renders.
$bn_bm_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'bookmarks' ) : new BookmarkService();
$bn_bm_page    = $bn_bm_service->user_bookmarks_paged(
	$current_user_id,
	'' !== $bn_bm_raw_cursor ? $bn_bm_raw_cursor : null,
	$bn_bookmarks_per_page
);

$bn_visible_posts  = is_array( $bn_bm_page['items'] ?? null ) ? $bn_bm_page['items'] : array();
$bn_bm_next_cursor = is_string( $bn_bm_page['next_cursor'] ?? null ) ? $bn_bm_page['next_cursor'] : '';

// Batch-prime per-viewer state before the SSR post-card loop (C8.3).
if ( function_exists( 'buddynext_service' ) ) {
	buddynext_service( 'feed' )->prime_viewer_state( $bn_visible_posts, $current_user_id );
}
$bn_bm_has_more = '' !== $bn_bm_next_cursor;

/**
 * Fires before the bookmarks hub renders.
 *
 * @param int $current_user_id Viewer ID.
 */
do_action( 'buddynext_bookmarks_before', $current_user_id );

$bn_bm_rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<div class="bn-feed-stack bn-bookmarks"
	data-bn-rest-nonce="<?php echo esc_attr( $bn_bm_rest_nonce ); ?>"
	data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>">

	<header class="bn-bookmarks__header">
		<h1 class="bn-bookmarks__title"><?php esc_html_e( 'Bookmarks', 'buddynext' ); ?></h1>
		<p class="bn-bookmarks__lead">
			<?php esc_html_e( 'Posts you save show up here. Only you can see your bookmarks.', 'buddynext' ); ?>
		</p>
	</header>

	<?php
	// Router region around the list + Load more, as on the home feed and Explore: the
	// router hydrates what it swaps, so paginating never yields dead cards.
	$bn_bm_region_attrs = (bool) apply_filters( 'buddynext_feed_client_pagination', true )
		? ' data-wp-interactive="buddynext/feed" data-wp-router-region="buddynext/feed"'
		: '';
	?>
	<div class="bn-feed-region"<?php echo $bn_bm_region_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal static attribute string, no user data ?>>
	<?php if ( ! empty( $bn_visible_posts ) ) : ?>
		<div class="bn-feed-list bn-bookmarks__list" role="feed" aria-label="<?php esc_attr_e( 'Bookmarked posts', 'buddynext' ); ?>">
			<?php foreach ( $bn_visible_posts as $bn_bm_post ) : ?>
				<?php
				buddynext_get_template(
					'partials/post-card.php',
					array(
						'post'            => $bn_bm_post,
						'current_user_id' => $current_user_id,
						'context'         => 'bookmarks',
					)
				);
				?>
			<?php endforeach; ?>
		</div>

		<?php if ( $bn_bm_has_more && '' !== $bn_bm_next_cursor ) : ?>
			<?php
			buddynext_get_template(
				'parts/feed-load-more.php',
				array(
					'more_url' => add_query_arg(
						array( 'shown' => $bn_bm_shown + $bn_bm_page_size ),
						PageRouter::bookmarks_url()
					),
				)
			);
			?>
		<?php else : ?>
			<div class="bn-feed-end" role="status">
				<span class="bn-feed-end__text"><?php esc_html_e( "You've reached the end.", 'buddynext' ); ?></span>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<div class="bn-feed-empty" role="status" data-filter="bookmarks">
			<div class="bn-feed-empty__icon" aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></div>
			<div class="bn-feed-empty__title">
				<?php esc_html_e( 'No bookmarks yet', 'buddynext' ); ?>
			</div>
			<p class="bn-feed-empty__text">
				<?php esc_html_e( 'Tap Save on any post to keep it for later. Bookmarks are private — only you can see them.', 'buddynext' ); ?>
			</p>
			<a href="<?php echo esc_url( PageRouter::activity_url() ); ?>" class="bn-btn bn-feed-empty__cta" data-variant="primary">
				<?php esc_html_e( 'Browse the feed', 'buddynext' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
	<?php endif; ?>
	</div><!-- /.bn-feed-region -->

	<?php
	buddynext_get_template(
		'partials/share-modal.php',
		array( 'current_user_id' => $current_user_id )
	);
	?>
</div>
<?php
/**
 * Fires after the bookmarks hub renders.
 *
 * @param int $current_user_id Viewer ID.
 */
do_action( 'buddynext_bookmarks_after', $current_user_id );
