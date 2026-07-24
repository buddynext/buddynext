<?php
/**
 * Feed "Load more" control — one implementation for every paginated feed.
 *
 * WHY THIS IS SHARED. Each feed used to solve pagination its own way: the home feed and
 * Explore injected the next page's cards with JS, bookmarks and the activity block used a
 * plain link. The injecting ones were broken in a way nobody could see — a post card is an
 * Interactivity island, the API only hydrates islands present at first paint, so every
 * injected card was inert: React, Comment, Share and Save silently dead for the rest of the
 * session. Four surfaces, four behaviours, two of them wrong. This is one behaviour.
 *
 * HOW IT WORKS. The control is a real <a href> pointing at the same page with a larger
 * `shown` count (the cumulative server render). With the Interactivity Router available, the
 * feed store's `actions.loadMore` intercepts the click and swaps the enclosing
 * data-wp-router-region in place — the router hydrates what it swaps, which is the one thing
 * plugin JS cannot do. Without JS, without the router, or with the pagination filter off,
 * the same link just loads the page. The href is identical on both paths, so they can never
 * disagree about what the next page is.
 *
 * The CALLER owns the region wrapper, because the region has to enclose the list as well as
 * this control — see templates/feed/home.php for the reference use.
 *
 * Plan: buddynext-pro/free-internal/docs/plans/feed-hydrated-pagination-2026-07-24.md
 *
 * @package BuddyNext
 *
 * @var string $more_url Absolute URL of the grown page (without a fragment).
 */

defined( 'ABSPATH' ) || exit;

$bn_lm_url = isset( $more_url ) ? (string) $more_url : '';
if ( '' === $bn_lm_url ) {
	return;
}

/**
 * Filters whether feeds paginate through the Interactivity Router.
 *
 * False falls back to a plain link and a normal page load — every card still hydrated,
 * just less smooth. Deliberately separate from `buddynext_client_nav_enabled`, which
 * governs whole-hub client navigation rather than one region on one screen.
 *
 * @since 1.1.0
 *
 * @param bool $enabled Default true.
 */
$bn_lm_client = (bool) apply_filters( 'buddynext_feed_client_pagination', true );
?>
<div class="bn-load-more" id="bn-load-more">
	<a
		href="<?php echo esc_url( $bn_lm_url . '#bn-load-more' ); ?>"
		class="bn-btn bn-load-more__btn"
		data-variant="secondary"
		rel="next"
		<?php if ( $bn_lm_client ) : ?>
			data-wp-on--click="actions.loadMore"
			<?php // Continuous scroll: the control fires itself when it comes into view. It ships with every region swap, so it keeps going page after page. ?>
			data-wp-init="actions.initLoadMore"
		<?php endif; ?>
	>
		<?php esc_html_e( 'Load more', 'buddynext' ); ?>
	</a>
</div>
