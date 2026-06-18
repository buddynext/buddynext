<?php
/**
 * BuddyNext Explore — the community heartbeat.
 *
 * Explore is not a post feed: it is a single "what's going on" discovery
 * surface that blends everything new across the community — new members, new
 * spaces, hot discussions, popular posts and shared media — into one masonry
 * grid. The filter row narrows the same grid by entity type
 * (All / Members / Spaces / Posts / Discussions / Media) in place; it never
 * navigates away to the directories.
 *
 * All data comes from BuddyNext\Feed\ExploreService::deck() so this first paint
 * and every infinitely-scrolled page render from one source of truth. The
 * compact, click-through cards are rendered by partials/explore-card.php.
 *
 * Overridable: copy to {theme}/buddynext/feed/explore.php
 *
 * REST endpoint (infinite scroll): GET buddynext/v1/feed/explore/page?filter=&cursor=
 *
 * @package BuddyNext
 * @since   1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Feed\ExploreService;

// ── Current user context ───────────────────────────────────────────────────
$current_user_id = get_current_user_id();
$is_guest        = ( 0 === $current_user_id );

// ── Active filter facet ────────────────────────────────────────────────────
$explore_filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $explore_filter, ExploreService::FILTERS, true ) ) {
	$explore_filter = 'all';
}

// ── First-page cursor (deep links / no-JS pagination) ──────────────────────
$explore_cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// ── Build the discovery deck (single source of truth) ──────────────────────
$bn_explore_per_page = 12;
$bn_explore_service  = new ExploreService();
$bn_deck             = $bn_explore_service->deck( $explore_filter, '' !== $explore_cursor ? $explore_cursor : null, $bn_explore_per_page );
$bn_cards            = (array) ( $bn_deck['items'] ?? array() );
$bn_next_cursor      = $bn_deck['next_cursor'] ?? null;
$bn_pulse            = $bn_explore_service->pulse();

// ── REST nonce ─────────────────────────────────────────────────────────────
$rest_nonce = wp_create_nonce( 'wp_rest' );

// ── Right sidebar: the explore-specific "heartbeat" aside ──────────────────
add_action(
	'buddynext_right_sidebar',
	static function () use ( $current_user_id ) {
		buddynext_get_template(
			'feed/parts/explore-aside.php',
			array( 'current_user_id' => $current_user_id )
		);
	}
);

/**
 * Fires before the explore feed inner content.
 *
 * @param int $current_user_id Current user ID.
 */
do_action( 'buddynext_feed_explore_before', $current_user_id );

// Filter facets — labels + the entity each surfaces.
$bn_explore_filters = array(
	'all'         => __( 'All', 'buddynext' ),
	'members'     => __( 'Members', 'buddynext' ),
	'spaces'      => __( 'Spaces', 'buddynext' ),
	'posts'       => __( 'Posts', 'buddynext' ),
	'discussions' => __( 'Discussions', 'buddynext' ),
	'media'       => __( 'Media', 'buddynext' ),
);
?>
<div class="bn-feed-stack bn-explore" data-wp-interactive="buddynext/feed">
	<div class="bn-explore-content">

		<!-- Hero: community pulse + search -->
		<section class="bn-explore-hero">
			<?php
			// Community name (Settings → General → Community Name) as the landing
			// brand line — shown only when the owner has explicitly set one. We do
			// NOT fall back to the WP site title here: that just duplicates the title
			// already in the theme header and reads as clutter. The description
			// renders as the hero title below regardless.
			$bn_community_name = trim( (string) get_option( 'buddynext_site_name', '' ) );
			if ( '' !== $bn_community_name ) :
				?>
				<div class="bn-explore-hero__brand"><?php echo esc_html( $bn_community_name ); ?></div>
				<?php
			endif;
			?>
			<div class="bn-explore-hero__eyebrow">
				<?php
				printf(
					/* translators: 1: member count, 2: space count, 3: post count. */
					esc_html__( 'Explore · %1$s members · %2$s spaces · %3$s posts', 'buddynext' ),
					esc_html( number_format_i18n( (int) $bn_pulse['members'] ) ),
					esc_html( number_format_i18n( (int) $bn_pulse['spaces'] ) ),
					esc_html( number_format_i18n( (int) $bn_pulse['posts'] ) )
				);
				?>
			</div>
			<h1 class="bn-explore-hero__title">
				<?php
				$bn_community_desc = trim( (string) get_option( 'buddynext_description', '' ) );
				if ( '' !== $bn_community_desc ) {
					echo esc_html( $bn_community_desc );
				} else {
					esc_html_e( "What's happening in the community", 'buddynext' );
				}
				?>
			</h1>
			<div class="bn-explore-hero__search" role="search">
				<span class="bn-explore-hero__search-icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
				<label for="bn-explore-search-input" class="screen-reader-text">
					<?php esc_html_e( 'Search the community', 'buddynext' ); ?>
				</label>
				<input
					id="bn-explore-search-input"
					class="bn-explore-hero__search-input"
					type="search"
					placeholder="<?php esc_attr_e( 'Search posts, people, spaces, hashtags…', 'buddynext' ); ?>"
					autocomplete="off"
				>
			</div>
		</section>

		<!-- Guest join banner -->
		<?php if ( $is_guest ) : ?>
			<div class="bn-guest-banner" role="banner">
				<h3><?php esc_html_e( 'Join the community', 'buddynext' ); ?></h3>
				<p>
					<?php esc_html_e( "You're browsing as a guest. Create an account to post, follow people, and join spaces.", 'buddynext' ); ?>
				</p>
				<div class="bn-banner-btns">
					<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Sign up free', 'buddynext' ); ?></a>
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="bn-btn" data-variant="ghost"><?php esc_html_e( 'Log in', 'buddynext' ); ?></a>
				</div>
			</div>
		<?php endif; ?>

		<!-- Filter row: in-page entity-type filters -->
		<div class="bn-explore-filters" role="group" aria-label="<?php esc_attr_e( 'Filter what to explore', 'buddynext' ); ?>">
			<?php
			foreach ( $bn_explore_filters as $bn_fkey => $bn_flabel ) :
				$bn_active = ( $explore_filter === $bn_fkey );
				$bn_furl   = 'all' === $bn_fkey
					? esc_url( remove_query_arg( array( 'filter', 'cursor' ) ) )
					: esc_url( add_query_arg( 'filter', $bn_fkey, remove_query_arg( 'cursor' ) ) );
				?>
				<a
					class="bn-explore-filter<?php echo $bn_active ? ' is-active' : ''; ?>"
					href="<?php echo $bn_furl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above. ?>"
					<?php echo $bn_active ? 'aria-current="page"' : ''; ?>
				><?php echo esc_html( $bn_flabel ); ?></a>
			<?php endforeach; ?>
		</div>

		<!-- Masonry discovery grid -->
		<div class="bn-explore-grid" role="feed" aria-label="<?php esc_attr_e( 'Explore', 'buddynext' ); ?>">
			<?php if ( ! empty( $bn_cards ) ) : ?>
				<?php
				foreach ( $bn_cards as $bn_card ) :
					buddynext_get_template(
						'partials/explore-card.php',
						array(
							'card'            => $bn_card,
							'current_user_id' => $current_user_id,
						)
					);
				endforeach;

				/**
				 * Fires after the explore grid cards on the first paint.
				 *
				 * Pro can append community-pulse / AI-digest cards here without
				 * the free build fabricating data.
				 *
				 * @since 1.6.0
				 *
				 * @param string $explore_filter Active filter facet.
				 * @param int    $current_user_id Viewing user ID.
				 */
				do_action( 'buddynext_explore_grid_cards', $explore_filter, $current_user_id );
				?>
			<?php else : ?>
				<div class="bn-feed-empty bn-explore-empty" role="status">
					<div class="bn-feed-empty__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></div>
					<div class="bn-feed-empty__title">
						<?php
						switch ( $explore_filter ) {
							case 'members':
								esc_html_e( 'No new members to show yet', 'buddynext' );
								break;
							case 'spaces':
								esc_html_e( 'No spaces to show yet', 'buddynext' );
								break;
							case 'discussions':
								esc_html_e( 'No discussions yet', 'buddynext' );
								break;
							case 'media':
								esc_html_e( 'No media shared yet', 'buddynext' );
								break;
							default:
								esc_html_e( 'Nothing here yet', 'buddynext' );
								break;
						}
						?>
					</div>
					<p class="bn-feed-empty__text">
						<?php esc_html_e( 'Be the first to post and start the conversation.', 'buddynext' ); ?>
					</p>
					<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::activity_url() ); ?>" class="bn-btn bn-feed-empty__cta" data-variant="primary">
						<?php esc_html_e( 'Go to your feed', 'buddynext' ); ?>
						<span aria-hidden="true">&rarr;</span>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<!-- Infinite scroll / load more -->
		<?php if ( $bn_next_cursor ) : ?>
			<div
				class="bn-load-more"
				data-bn-infinite-feed="explore"
				data-bn-feed-target=".bn-explore-grid"
				data-next-cursor="<?php echo esc_attr( (string) $bn_next_cursor ); ?>"
				data-filter="<?php echo esc_attr( $explore_filter ); ?>"
				data-rest-url="<?php echo esc_url( rest_url( 'buddynext/v1/feed/explore/page' ) ); ?>"
				data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>"
				data-per-page="<?php echo esc_attr( (string) $bn_explore_per_page ); ?>"
			>
				<div class="bn-load-more__spinner" hidden aria-live="polite">
					<span class="bn-skeleton bn-load-more__spinner-line"></span>
					<span class="bn-load-more__spinner-text"><?php esc_html_e( 'Loading more…', 'buddynext' ); ?></span>
				</div>
				<noscript>
					<a href="<?php echo esc_url( add_query_arg( 'cursor', (string) $bn_next_cursor ) ); ?>" class="bn-btn bn-load-more__btn" data-variant="secondary">
						<?php esc_html_e( 'Load more', 'buddynext' ); ?>
					</a>
				</noscript>
			</div>
		<?php elseif ( ! empty( $bn_cards ) ) : ?>
			<div class="bn-feed-end" role="status">
				<span class="bn-feed-end__text"><?php esc_html_e( "You've reached the end.", 'buddynext' ); ?></span>
			</div>
		<?php endif; ?>

	</div><!-- /.bn-explore-content -->

	<?php
	// Share modal is referenced by post cards in other contexts; explore cards
	// are click-through teasers, so it is intentionally omitted here.

	/**
	 * Fires after the explore feed inner content.
	 *
	 * @param int $current_user_id Current user ID.
	 */
	do_action( 'buddynext_feed_explore_after', $current_user_id );
	?>
</div>
