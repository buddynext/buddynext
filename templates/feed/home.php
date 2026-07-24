<?php
/**
 * BuddyNext home feed template (v2 inner).
 *
 * Personalised activity feed for the logged-in user. Renders inside the
 * shell main column (`<main class="bn-app__main">` — see
 * templates/shell/hub-shell.php) — this inner template does NOT own
 * the rail or the 2-column page grid. Sidebar widgets are
 * registered on the `buddynext_right_sidebar` action; the shell auto
 * renders the right column whenever the action has callbacks.
 *
 * Features: post composer, announcement banner, cursor pagination,
 * trending-hashtags sidebar widget, suggested-spaces sidebar widget.
 * Guests are redirected to the auth page.
 *
 * Overridable: copy to {theme}/buddynext/feed/home.php.
 *
 * REST endpoint: GET buddynext/v1/feed?scope=home
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;
use BuddyNext\Feed\FeedService;

// Declares this fine-grained surface for the sidebar registry (SidebarRegistry
// reads it via Surface::current()) before the shell renders the right column.
\BuddyNext\Sidebar\Surface::set( 'feed' );

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

$bn_page_size = 15;

/*
 * "Load more" GROWS this page instead of appending cards from JS.
 *
 * Infinite scroll used to fetch the next page and inject the server-rendered
 * cards into the list. A post card is an Interactivity island, and the API only
 * hydrates islands present at first paint — so every card past the first screen
 * arrived inert: React, Comment, Share and Save did nothing, silently, for the
 * rest of the session. Reported from the live feed and reproduced here with a
 * clean single click.
 *
 * Rendering more posts server-side is the honest fix available today: the whole
 * list is hydrated by the browser exactly like the first screen, so every
 * control works. `shown` is the number of posts this page renders — clamped to
 * whole pages and to BN_FEED_MAX_SHOWN so a crafted URL can never ask the feed
 * for an unbounded render.
 *
 * This is interim. The end state is the feed rendering its cards THROUGH the
 * Interactivity API (data-wp-each) so appended cards are interactive by
 * construction, which is how the mainstream networks do it — at which point
 * continuous scroll comes back without dead controls.
 */
$bn_max_shown = $bn_page_size * 6;
$raw_shown    = isset( $_GET['shown'] ) ? absint( wp_unslash( $_GET['shown'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_shown     = max( $bn_page_size, min( $bn_max_shown, (int) ( ceil( $raw_shown / $bn_page_size ) * $bn_page_size ) ) );
$bn_per_page  = $bn_shown;

// The Spaces filter tab + its feed only make sense while the Spaces feature is
// enabled; when the owner turns it off we drop the tab and treat a stale
// ?filter=spaces as the default so the activity page has no dead Spaces UI.
$bn_spaces_on = function_exists( 'buddynext_service' )
	&& is_object( buddynext_service( 'features' ) )
	&& buddynext_service( 'features' )->is_enabled( 'spaces' );

// Filter tab — for-you (default) | following | spaces | network.
$allowed_filters = array( 'for-you', 'following', 'network' );
if ( $bn_spaces_on ) {
	$allowed_filters[] = 'spaces';
}
$raw_filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'for-you'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$bn_filter  = in_array( $raw_filter, $allowed_filters, true ) ? $raw_filter : 'for-you';

// Cursor is base64( "created_at|id" ) — same format as FeedService::encode_cursor().
// Opaque pagination cursor — passed straight to FeedService, which owns the
// keyset decode/encode. Empty string = first page.
$raw_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Home feed posts ─────────────────────────────────────────────────────────
// REST-first: the template runs no feed SQL. Resolve the one FeedService the
// REST endpoints use, so the SSR first paint and the API agree — including the
// Pro AI-ranking rebind (buddynext_feed_query_args / buddynext_feed_order_by),
// suspended/shadow-ban exclusion, cursor pagination, and the pinned-announcement
// card prepended on the first page. Construct it directly when the container is
// unavailable (e.g. the isolation harness strips the bootstrap) so the page still
// renders without inline SQL. home_feed() returns fully hydrated post arrays
// keyed exactly as partials/post-card.php expects — passed straight to the loop.
$bn_feed_service_obj = function_exists( 'buddynext_service' ) ? buddynext_service( 'feed' ) : null;
if ( ! $bn_feed_service_obj instanceof FeedService ) {
	$bn_feed_service_obj = new FeedService(
		new \BuddyNext\SocialGraph\FollowService(),
		new \BuddyNext\Feed\PostService(),
		null
	);
}

$service_result = $bn_feed_service_obj->home_feed(
	$current_user_id,
	'' !== $raw_cursor ? $raw_cursor : null,
	$bn_per_page,
	$bn_filter
);
$feed_posts     = array_values( (array) ( $service_result['items'] ?? array() ) );
$next_cursor    = (string) ( $service_result['next_cursor'] ?? '' );
$has_more       = '' !== $next_cursor;

// Batch-prime every per-viewer cache the post-card reads (reaction / bookmark /
// vote / report) BEFORE the SSR card loop below, so the first paint costs one
// query per service for the whole page instead of ~3 per card (C8.3).
$bn_feed_service_obj->prime_viewer_state( $feed_posts, $current_user_id );

// ── REST nonce + URLs ───────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// New-posts indicator poll cadence (milliseconds) for the feed store. The owner
// toggle gates it; the interval is filterable (seconds; 0 disables the background
// poll while still showing realtime pills on Pro). -1 = indicator off entirely.
$bn_new_pill_ms = (bool) get_option( 'buddynext_feed_new_posts_indicator', true )
	? max( 0, (int) apply_filters( 'buddynext_feed_new_count_interval', 60 ) ) * 1000
	: -1;

/**
 * Fires before the home feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_home_before', $current_user_id );
?>
<div class="bn-feed-stack"
	data-bn-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>"
	data-bn-new-poll-ms="<?php echo esc_attr( (string) $bn_new_pill_ms ); ?>">

	<!-- Post composer -->
	<?php
	buddynext_get_template(
		'partials/composer.php',
		array(
			'space_id'        => null,
			'current_user_id' => $current_user_id,
		)
	);
	?>

	<!-- Home feed filter tabs (For you / Following / Spaces / Network).
		The redundant Home/Explore hub-tab row was removed: Explore lives in the
		left rail and "Home" was just the current page — two full-width tab rows
		for that cost vertical space (worst at 390px) for no IA value. -->
	<!-- Carries the .bn-tabs/.bn-tab primitive so it matches the Home/Explore row
		and every other tab bar (font, focus ring, overflow scroll-fade); the
		.bn-feed-filter-tab* classes + aria-current are kept for the feed-tabs JS. -->
	<?php // .bn-navgroup opts the strip into the shared overflow chevrons (shell/extras.js), the cross-browser "more tabs" affordance - the CSS edge-fade alone is Chrome-only, so at 390px "Network" was clipped mid-word with no hint it was reachable. ?>
	<div class="bn-navgroup">
	<div class="bn-tabs bn-feed-filter-tabs"
		role="tablist"
		aria-label="<?php esc_attr_e( 'Filter home feed', 'buddynext' ); ?>"
		data-wp-interactive="buddynext/feed-tabs"
		data-wp-context='
		<?php
		echo esc_attr(
			wp_json_encode(
				array(
					'filter'  => $bn_filter,
					'restUrl' => rest_url( 'buddynext/v1' ),
					'nonce'   => $rest_nonce,
					'busy'    => false,
				)
			)
		);
		?>
		'>
		<?php
		$filter_tabs = array(
			'for-you'   => array(
				'label' => __( 'For you', 'buddynext' ),
			),
			'following' => array(
				'label' => __( 'Following', 'buddynext' ),
			),
			'spaces'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
			),
			'network'   => array(
				'label' => __( 'Network', 'buddynext' ),
			),
		);
		// Hide the Spaces tab when the feature is disabled (mirrors $allowed_filters).
		if ( ! $bn_spaces_on ) {
			unset( $filter_tabs['spaces'] );
		}
		foreach ( $filter_tabs as $tab_slug => $tab_meta ) :
			$is_active = $tab_slug === $bn_filter;
			$tab_url   = add_query_arg( 'filter', $tab_slug, PageRouter::activity_url() );
			?>
			<a
				class="bn-tab bn-feed-filter-tab"
				role="tab"
				href="<?php echo esc_url( $tab_url ); ?>"
				data-filter="<?php echo esc_attr( $tab_slug ); ?>"
				aria-current="<?php echo $is_active ? 'true' : 'false'; ?>"
				data-wp-on--click="actions.setFilter"
			>
				<span class="bn-tab__label"><?php echo esc_html( $tab_meta['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	</div><!-- /.bn-navgroup -->

	<?php
	// NOTE: the home feed renders server-side and changes views via a full reload
	// (feed-tabs setFilter → window.location), so there is no client-side async
	// load phase. The skeleton / error / retry markup that used to live here was
	// never toggled by any JS (dead markup) and was removed. If client-side filter
	// fetching is added later, reintroduce the loading states wired to it.
	?>
	<?php
	/*
	 * Router region around the list + its "Load more" control.
	 *
	 * The Interactivity API hydrates islands present at first paint, so cards injected by
	 * JS are inert — that is why every card past the first screen used to have dead React /
	 * Comment / Share / Save controls. The core Interactivity Router is the one thing that
	 * CAN hydrate: it fetches a URL and swaps a matching region, hydrating what it swapped.
	 * So "Load more" fetches ?shown=N and the router replaces just this region — one PHP
	 * renderer, no injected HTML, every card interactive.
	 *
	 * The composer, the filter tabs and the "N new posts" pill stay OUTSIDE the region so a
	 * pagination swap never re-initialises them.
	 *
	 * Progressive enhancement: the region is inert markup on its own. With JS off, or the
	 * router unavailable, or buddynext_feed_client_pagination filtered false, the Load-more
	 * link below is a plain <a href> and the page simply loads. Plan:
	 * free-internal/docs/plans/feed-hydrated-pagination-2026-07-24.md
	 */
	$bn_feed_region_attrs = (bool) apply_filters( 'buddynext_feed_client_pagination', true )
		? ' data-wp-interactive="buddynext/feed" data-wp-router-region="buddynext/feed"'
		: '';
	?>
	<div class="bn-feed-region"<?php echo $bn_feed_region_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal static attribute string, no user data ?>>
	<?php if ( ! empty( $feed_posts ) ) : ?>
		<div class="bn-feed-list" role="feed" aria-label="<?php esc_attr_e( 'Home feed', 'buddynext' ); ?>">
			<?php foreach ( $feed_posts as $home_post ) : ?>
				<?php
				// $feed_posts are already canonical hydrated post arrays (service
				// path or PostService::hydrate() fallback) — no per-row remapping.
				buddynext_get_template(
					'partials/post-card.php',
					array(
						'post'            => $home_post,
						'current_user_id' => $current_user_id,
						'context'         => 'home',
					)
				);
				?>
			<?php endforeach; ?>
		</div>

			<?php if ( $has_more && '' !== $next_cursor ) : ?>
				<?php
				/*
				 * A real link, not a JS sentinel. The infinite-scroll trigger
				 * (data-bn-infinite-feed) injected the next page's cards, which the
				 * Interactivity API never hydrates — so everything past the first
				 * screen had dead React / Comment / Share / Save controls. This
				 * grows the SAME page server-side instead, so the posts already on
				 * screen stay put and every new card is hydrated like the first
				 * ones. The anchor returns the member to where they were reading
				 * rather than to the top of the feed.
				 */
				$bn_more_url = add_query_arg(
					array(
						'shown'  => $bn_shown + $bn_page_size,
						'filter' => $bn_filter,
					),
					PageRouter::activity_url()
				);
				?>
				<?php buddynext_get_template( 'parts/feed-load-more.php', array( 'more_url' => $bn_more_url ) ); ?>
			<?php else : ?>
				<div class="bn-feed-end" role="status">
					<span class="bn-feed-end__text"><?php esc_html_e( "You've reached the end.", 'buddynext' ); ?></span>
				</div>
			<?php endif; ?>

	<?php else : ?>
		<?php
		$empty_states = array(
			'for-you'   => array(
				'icon'  => 'users',
				'title' => __( 'Your feed is empty', 'buddynext' ),
				'text'  => __( 'Follow members or join spaces to start seeing posts here.', 'buddynext' ),
				'cta'   => __( 'Discover members', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
			'following' => array(
				'icon'  => 'follow',
				'title' => __( "You aren't following anyone yet", 'buddynext' ),
				'text'  => __( 'Once you follow people their latest posts will show up here.', 'buddynext' ),
				'cta'   => __( 'Find people to follow', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
			'spaces'    => array(
				'icon'  => 'grid',
				'title' => __( 'Join your first space', 'buddynext' ),
				'text'  => __( 'Posts from spaces you join appear here.', 'buddynext' ),
				'cta'   => __( 'Browse spaces', 'buddynext' ),
				'url'   => \BuddyNext\Core\PageRouter::spaces_url(),
			),
			'network'   => array(
				'icon'  => 'users',
				'title' => __( 'Build your network', 'buddynext' ),
				'text'  => __( 'Send a few connection requests to see posts from your network.', 'buddynext' ),
				'cta'   => __( 'Find people to connect with', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
		);
		$empty        = $empty_states[ $bn_filter ] ?? $empty_states['for-you'];
		?>
		<div class="bn-feed-empty" role="status" data-filter="<?php echo esc_attr( $bn_filter ); ?>">
			<div class="bn-feed-empty__icon" aria-hidden="true"><?php buddynext_icon( $empty['icon'] ); ?></div>
			<div class="bn-feed-empty__title">
				<?php echo esc_html( $empty['title'] ); ?>
			</div>
			<p class="bn-feed-empty__text">
				<?php echo esc_html( $empty['text'] ); ?>
			</p>
			<a href="<?php echo esc_url( $empty['url'] ); ?>" class="bn-btn bn-feed-empty__cta" data-variant="primary">
				<?php echo esc_html( $empty['cta'] ); ?>
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
 * Fires after the home feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_home_after', $current_user_id );
