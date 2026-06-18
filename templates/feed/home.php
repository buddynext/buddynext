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

// Guest gate is enforced upstream in PageRouter::dispatch_hub_template().
$current_user_id = get_current_user_id();

$bn_per_page = 15;

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
$feed_posts  = array_values( (array) ( $service_result['items'] ?? array() ) );
$next_cursor = (string) ( $service_result['next_cursor'] ?? '' );
$has_more    = '' !== $next_cursor;

// ── Tab counts ──────────────────────────────────────────────────────────────
$bn_tab_counts = array(
	'for_you'   => 0,
	'following' => 0,
	'spaces'    => 0,
	'network'   => 0,
);
if ( function_exists( 'buddynext_service' ) ) {
	$bn_feed_service = buddynext_service( 'feed' );
	if ( $bn_feed_service ) {
		$bn_tab_counts = $bn_feed_service->home_feed_counts( $current_user_id );
	}
}

// ── REST nonce + URLs ───────────────────────────────────────────────────────
$rest_nonce  = wp_create_nonce( 'wp_rest' );

// ── Right sidebar widgets ────────────────────────────────────────────────
// Register sidebar widget callbacks on the shared hub-shell action. The shell
// detects via has_action() (after this template's output buffer flushes) and
// renders the right column automatically. The action is registered before any
// output, so the detection in templates/shell/hub-shell.php sees it.
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'partials/sidebar.php',
			array(
				'sidebar_user_id' => $current_user_id,
			)
		);
	}
);

/**
 * Fires before the home feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_home_before', $current_user_id );
?>
<div class="bn-feed-stack"
	data-bn-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
	data-bn-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1' ) ); ?>">

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
	<div class="bn-tabs bn-feed-filter-tabs"
		role="tablist"
		aria-label="<?php esc_attr_e( 'Filter home feed', 'buddynext' ); ?>"
		data-wp-interactive="buddynext/feed-tabs"
		data-wp-context='
		<?php
		echo esc_attr(
			wp_json_encode(
				array(
					'filter'    => $bn_filter,
					'tabCounts' => array(
						'for-you'   => (int) $bn_tab_counts['for_you'],
						'following' => (int) $bn_tab_counts['following'],
						'spaces'    => (int) $bn_tab_counts['spaces'],
						'network'   => (int) $bn_tab_counts['network'],
					),
					'restUrl'   => rest_url( 'buddynext/v1' ),
					'nonce'     => $rest_nonce,
					'busy'      => false,
				)
			)
		);
		?>
		'>
		<?php
		$filter_tabs = array(
			'for-you'   => array(
				'label' => __( 'For you', 'buddynext' ),
				'count' => (int) $bn_tab_counts['for_you'],
			),
			'following' => array(
				'label' => __( 'Following', 'buddynext' ),
				'count' => (int) $bn_tab_counts['following'],
			),
			'spaces'    => array(
				'label' => __( 'Spaces', 'buddynext' ),
				'count' => (int) $bn_tab_counts['spaces'],
			),
			'network'   => array(
				'label' => __( 'Network', 'buddynext' ),
				'count' => (int) $bn_tab_counts['network'],
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
				<?php
				if ( $tab_meta['count'] > 0 ) :
					// Cap the badge so a primary feed tab never shows a noisy 4-digit
					// total (e.g. "3,014"); premium feeds cap count chips at 99+.
					$bn_count_label = $tab_meta['count'] > 99 ? '99+' : number_format_i18n( $tab_meta['count'] );
					?>
					<span class="bn-tab__count"><?php echo esc_html( $bn_count_label ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<div class="bn-feed-skeleton"
		hidden
		aria-hidden="true"
		data-bn-feed-skeleton>
		<?php for ( $sk = 0; $sk < 3; $sk++ ) : ?>
			<div class="bn-skeleton-card">
				<span class="bn-skeleton bn-skeleton-avatar"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--title"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--subtitle"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--body"></span>
				<span class="bn-skeleton bn-skeleton-line bn-skeleton-line--body-short"></span>
			</div>
		<?php endfor; ?>
	</div>

	<div class="bn-feed-error"
		role="alert"
		hidden
		data-bn-feed-error>
		<span class="bn-feed-error__icon" aria-hidden="true"><?php buddynext_icon( 'alert-triangle' ); ?></span>
		<span class="bn-feed-error__text"><?php esc_html_e( 'Could not load this view. Try again.', 'buddynext' ); ?></span>
		<button type="button" class="bn-btn" data-variant="secondary" data-size="sm" data-bn-feed-retry>
			<?php esc_html_e( 'Retry', 'buddynext' ); ?>
		</button>
	</div>

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
				<div
					class="bn-load-more"
					id="bn-infinite-trigger"
					data-bn-infinite-feed="home"
					data-bn-feed-target=".bn-feed-list"
					data-next-cursor="<?php echo esc_attr( $next_cursor ); ?>"
					data-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1/feed/home/page' ) ); ?>"
					data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
					data-filter="<?php echo esc_attr( $bn_filter ); ?>"
					data-per-page="<?php echo esc_attr( (string) $bn_per_page ); ?>"
				>
					<div class="bn-load-more__spinner" hidden aria-live="polite">
						<span class="bn-skeleton bn-load-more__spinner-line"></span>
						<span class="bn-load-more__spinner-text"><?php esc_html_e( 'Loading more posts…', 'buddynext' ); ?></span>
					</div>
					<noscript>
						<a
							href="<?php echo esc_url( add_query_arg( 'cursor', rawurlencode( $next_cursor ), PageRouter::activity_url() ) ); ?>"
							class="bn-btn bn-load-more__btn"
							data-variant="secondary"
						>
							<?php esc_html_e( 'Load more', 'buddynext' ); ?>
						</a>
					</noscript>
				</div>
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
				'url'   => home_url( '/spaces/' ),
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
