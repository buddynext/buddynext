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
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

global $wpdb;

$bn_bookmarks_per_page = 15;

// Cursor: base64( "bookmark_created_at|post_id" ). bn_bookmarks has a composite
// (user_id, post_id) primary key and no surrogate `id` column, so post_id is the
// stable tiebreaker — a unique row identifier within a single user's bookmarks.
$bn_bm_raw_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_bm_decoded    = null;
if ( '' !== $bn_bm_raw_cursor ) {
	$raw = base64_decode( $bn_bm_raw_cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false !== $raw ) {
		$parts = explode( '|', $raw, 2 );
		if ( 2 === count( $parts ) && '' !== $parts[0] && ctype_digit( $parts[1] ) ) {
			$bn_bm_decoded = array(
				'created_at' => $parts[0],
				'post_id'    => (int) $parts[1],
			);
		}
	}
}

$bn_bm_cursor_sql    = '';
$bn_bm_cursor_params = array();
if ( null !== $bn_bm_decoded ) {
	$bn_bm_cursor_sql    = 'AND (b.created_at < %s OR (b.created_at = %s AND b.post_id < %d))';
	$bn_bm_cursor_params = array( $bn_bm_decoded['created_at'], $bn_bm_decoded['created_at'], $bn_bm_decoded['post_id'] );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$bn_bm_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT b.created_at AS bookmark_created_at, b.post_id
		   FROM {$wpdb->prefix}bn_bookmarks b
		  WHERE b.user_id = %d
		  {$bn_bm_cursor_sql}
		  ORDER BY b.created_at DESC, b.post_id DESC
		  LIMIT %d",
		...array_merge( array( $current_user_id ), $bn_bm_cursor_params, array( $bn_bookmarks_per_page + 1 ) )
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$bn_bm_has_more = is_array( $bn_bm_rows ) && count( $bn_bm_rows ) > $bn_bookmarks_per_page;
if ( $bn_bm_has_more ) {
	array_pop( $bn_bm_rows );
}

$bn_bm_next_cursor = '';
if ( $bn_bm_has_more && ! empty( $bn_bm_rows ) ) {
	$bn_bm_last        = end( $bn_bm_rows );
	$bn_bm_next_cursor = base64_encode( $bn_bm_last->bookmark_created_at . '|' . $bn_bm_last->post_id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

// ── Visibility filter ─────────────────────────────────────────────────────
// Re-apply the same gates as PostController::get_post(): blocks, secret-space
// membership, followers-only, private, and author-status. Iterating in PHP is
// fine — the page is capped at 15 rows.
$bn_post_service     = new PostService();
$bn_block_service    = function_exists( 'buddynext_service' ) ? buddynext_service( 'blocks' ) : new BlockService();
$bn_follow_service   = function_exists( 'buddynext_service' ) ? buddynext_service( 'follows' ) : new FollowService();
$bn_space_service    = new SpaceService();
$bn_space_member_svc = new SpaceMemberService();

$bn_visible_posts = array();
foreach ( $bn_bm_rows as $bn_bm_row ) {
	$post = $bn_post_service->get( (int) $bn_bm_row->post_id );
	if ( null === $post ) {
		continue;
	}
	if ( isset( $post['status'] ) && 'published' !== $post['status'] ) {
		continue;
	}
	$author_id = (int) ( $post['user_id'] ?? 0 );
	if ( $author_id <= 0 ) {
		continue;
	}

	// Block list.
	if ( $author_id !== $current_user_id && $bn_block_service->is_blocking_either( $current_user_id, $author_id ) ) {
		continue;
	}

	// Secret space.
	$space_id = (int) ( $post['space_id'] ?? 0 );
	if ( $space_id > 0 ) {
		$space = $bn_space_service->get( $space_id );
		if ( null !== $space && 'secret' === ( $space['type'] ?? '' ) ) {
			$is_member = $bn_space_member_svc->is_member( $space_id, $current_user_id );
			if ( ! $is_member && ! user_can( $current_user_id, 'manage_options' ) ) {
				continue;
			}
		}
	}

	// Followers-only.
	if ( 'followers' === ( $post['privacy'] ?? '' ) && $author_id !== $current_user_id ) {
		if ( ! $bn_follow_service->is_following( $current_user_id, $author_id ) ) {
			continue;
		}
	}

	// Private.
	if ( 'private' === ( $post['privacy'] ?? '' ) && $author_id !== $current_user_id ) {
		continue;
	}

	// Author suspended / shadow-banned (skip for admin viewers).
	if ( ! user_can( $current_user_id, 'manage_options' ) && $author_id !== $current_user_id ) {
		$suspended = (bool) get_user_meta( $author_id, 'bn_suspended', true );
		$shadow    = (bool) get_user_meta( $author_id, 'bn_shadow_banned', true );
		if ( $suspended || $shadow ) {
			continue;
		}
	}

	$bn_visible_posts[] = $post;
}

// ── Sidebar — same as home feed ────────────────────────────────────────────
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'partials/sidebar.php',
			array( 'sidebar_user_id' => $current_user_id )
		);
	}
);

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
			<div class="bn-load-more">
				<a
					href="<?php echo esc_url( add_query_arg( 'cursor', rawurlencode( $bn_bm_next_cursor ), PageRouter::bookmarks_url() ) ); ?>"
					class="bn-btn bn-load-more__btn"
					data-variant="secondary"
				>
					<?php esc_html_e( 'Load more', 'buddynext' ); ?>
				</a>
			</div>
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
