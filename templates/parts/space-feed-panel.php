<?php
/**
 * BuddyNext template part: space-feed-panel.
 *
 * Renders the feed tab body of the space-home template: composer (members),
 * guest / open-space join CTAs (non-members), pinned announcement card,
 * and either the empty state or the post-card feed itself.
 *
 * Used by: templates/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object      $space          Required. Space row (slug, type).
 * @var int         $space_id       Required. Current space ID.
 * @var int         $viewer_id      Optional. Current user ID. Default 0.
 * @var bool        $is_member      Optional. Viewer is an active member. Default false.
 * @var bool        $can_post       Optional. Viewer's role satisfies the "Who can post" gate. Default = is_member.
 * @var bool        $is_guest       Optional. Viewer is logged out. Default false.
 * @var bool        $is_pending     Optional. Viewer has pending join request. Default false.
 * @var bool        $is_archived    Optional. Space is archived (read-only) — shows a notice. Default false.
 * @var array       $posts          Optional. List of post arrays for the feed. Default [].
 * @var array        $pinned_posts   Optional. Hydrated pinned post rows (arrays), newest first.
 * @var WP_User|null $current_user  Optional. Current WP_User object (for composer guard).
 * @var string       $search_query  Optional. Active in-space search term. When non-empty, $posts holds matches, not the feed. Default ''.
 * @var int          $search_total  Optional. Number of visible matches for $search_query. Default 0.
 * @var bool         $can_search    Optional. Viewer may read this space's content, so the search box is offered. Default true.
 * @var array       $classes        Optional. Extra CSS classes appended to the wrapper.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_feed_panel_before', $args )
 *   - do_action( 'buddynext_part_space_feed_panel_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_feed_panel_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_feed_panel_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'space'        => isset( $space ) ? $space : null,
	'space_id'     => isset( $space_id ) ? (int) $space_id : 0,
	'viewer_id'    => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'is_member'    => isset( $is_member ) ? (bool) $is_member : false,
	// Default can_post to is_member so callers that don't pass it keep the
	// pre-existing behaviour (every active member sees the composer).
	'can_post'     => isset( $can_post ) ? (bool) $can_post : ( isset( $is_member ) ? (bool) $is_member : false ),
	'is_guest'     => isset( $is_guest ) ? (bool) $is_guest : false,
	'is_pending'   => isset( $is_pending ) ? (bool) $is_pending : false,
	'is_archived'  => isset( $is_archived ) ? (bool) $is_archived : false,
	'posts'        => isset( $posts ) && is_array( $posts ) ? $posts : array(),
	'pinned_posts' => isset( $pinned_posts ) ? (array) $pinned_posts : array(),
	'current_user' => isset( $current_user ) ? $current_user : null,
	'classes'      => isset( $classes ) ? (array) $classes : array(),
	// In-space search. When non-empty, $posts holds the matches for this term
	// rather than the chronological feed, and the panel says so.
	'search_query' => isset( $search_query ) ? sanitize_text_field( (string) $search_query ) : '',
	'search_total' => isset( $search_total ) ? (int) $search_total : 0,
	// Whether the viewer may read this space's content at all. False on a
	// private space the viewer has not joined, where the panel renders a join
	// CTA and a search box would be a control that can only answer "0 results".
	'can_search'   => isset( $can_search ) ? (bool) $can_search : true,
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_feed_panel_args', $args );

if ( null === $args['space'] || $args['space_id'] <= 0 ) {
	return;
}

$bn_classes = array_filter( (array) $args['classes'], 'is_string' );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_feed_panel_classes', $bn_classes, $args );

$bn_space        = $args['space'];
$bn_space_id     = (int) $args['space_id'];
$bn_viewer_id    = (int) $args['viewer_id'];
$bn_is_member    = (bool) $args['is_member'];
$bn_can_post     = (bool) $args['can_post'];
$bn_is_guest     = (bool) $args['is_guest'];
$bn_is_pending   = (bool) $args['is_pending'];
$bn_is_archived  = (bool) $args['is_archived'];
$bn_posts        = (array) $args['posts'];
$bn_pinned_posts = (array) $args['pinned_posts'];
$bn_user         = $args['current_user'];
$bn_search_query = (string) $args['search_query'];
$bn_search_total = (int) $args['search_total'];
$bn_is_searching = '' !== $bn_search_query;
$bn_can_search   = (bool) $args['can_search'];

// Base URL for the search form and the clear link: the current space tab with
// our own params stripped, so submitting never stacks duplicates and clearing
// returns to the plain feed rather than to a differently-filtered one.
$bn_search_base = remove_query_arg( array( 'bn_sf_q', 'paged' ) );

$bn_wrap_class = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

do_action( 'buddynext_part_space_feed_panel_before', $args );

if ( '' !== $bn_wrap_class ) {
	echo '<div class="' . esc_attr( $bn_wrap_class ) . '">';
}
?>

<?php if ( $bn_is_archived ) : ?>
	<?php
	/*
	 * Someone who can reopen the space should be told so here, at the point they
	 * notice it is frozen — not left to hunt for it. Archive is reversible, and
	 * the notice used to read like a dead end.
	 */
	$bn_can_restore = $bn_viewer_id > 0 && buddynext_can(
		$bn_viewer_id,
		'buddynext-spaces/manage-settings',
		array( 'space_id' => $bn_space_id )
	);
	?>
	<div class="bn-notice" role="status">
		<?php esc_html_e( 'This space is archived. You can still read past activity, but new posts, comments, and joins are disabled.', 'buddynext' ); ?>
		<?php if ( $bn_can_restore ) : ?>
			<?php
			$bn_space_slug = is_array( $args['space'] )
				? (string) ( $args['space']['slug'] ?? '' )
				: (string) ( $args['space']->slug ?? '' );
			?>
			<?php if ( '' !== $bn_space_slug ) : ?>
				<?php
				// The settings screen picks its tab from the `bn_stab` QUERY PARAM (settings.php:111),
				// not from a hash. `settings/#danger` therefore selected nothing and dropped the owner
				// on the General tab, with no Restore button anywhere in sight - on the one CTA whose
				// entire job is to get them to it. Same helper + param every other tab link uses.
				?>
				<a class="bn-notice__cta" href="<?php echo esc_url( buddynext_space_settings_url( $bn_space_slug ) . '?bn_stab=danger' ); ?>">
					<?php esc_html_e( 'Restore this space', 'buddynext' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php
/*
 * In-space search. A plain GET form, deliberately: it is the same pattern the
 * space Members tab already uses (bn_sm_q), it survives with JavaScript off, the
 * result is a real URL a member can share or bookmark, and the back button
 * returns to the feed. No typeahead in v1 — a keystroke-per-request search over
 * a space's whole history is a load profile to take on once, on purpose, not as
 * a side effect of adding a box.
 *
 * Rendered above the feed so it is reachable without scrolling past it, and only
 * where there is something to search: a viewer who cannot read the feed sees the
 * join CTA below instead.
 */
?>
<?php if ( $bn_can_search ) : ?>
	<form method="get" action="<?php echo esc_url( $bn_search_base ); ?>" class="bn-card bn-space-search" role="search">
		<?php
		// Preserve the path-routing query vars this form does not own, so
		// submitting from a filtered/paged view does not silently drop them.
		foreach ( $_GET as $bn_q_key => $bn_q_val ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $bn_q_key, array( 'bn_sf_q', 'paged' ), true ) || ! is_scalar( $bn_q_val ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( sanitize_key( $bn_q_key ) ),
				esc_attr( sanitize_text_field( wp_unslash( (string) $bn_q_val ) ) )
			);
		}
		?>
		<label class="bn-sr-only" for="bn_sf_q"><?php esc_html_e( 'Search posts in this space', 'buddynext' ); ?></label>
		<span class="bn-space-search__icon" aria-hidden="true"><?php buddynext_icon( 'search' ); ?></span>
		<input
			type="search"
			id="bn_sf_q"
			name="bn_sf_q"
			class="bn-input bn-space-search__input"
			placeholder="<?php esc_attr_e( 'Search posts in this space…', 'buddynext' ); ?>"
			value="<?php echo esc_attr( $bn_search_query ); ?>"
		>
		<button type="submit" class="bn-btn" data-variant="primary" data-size="md">
			<?php esc_html_e( 'Search', 'buddynext' ); ?>
		</button>
		<?php if ( $bn_is_searching ) : ?>
			<a href="<?php echo esc_url( $bn_search_base ); ?>" class="bn-btn" data-variant="ghost" data-size="md">
				<?php esc_html_e( 'Clear', 'buddynext' ); ?>
			</a>
		<?php endif; ?>
	</form>
<?php endif; ?>

<?php if ( $bn_is_searching ) : ?>
	<p class="bn-space-search__summary" role="status">
		<?php
		printf(
			/* translators: 1: number of matching posts, 2: the search term. */
			esc_html( _n( '%1$s post matching “%2$s”', '%1$s posts matching “%2$s”', $bn_search_total, 'buddynext' ) ),
			esc_html( number_format_i18n( $bn_search_total ) ),
			esc_html( $bn_search_query )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( $bn_is_member && $bn_user && $bn_can_post && ! $bn_is_searching ) : ?>
	<?php
	buddynext_get_template(
		'partials/composer.php',
		array(
			'space_id'        => $bn_space_id,
			'current_user_id' => $bn_viewer_id,
		)
	);
	?>
<?php elseif ( $bn_is_member && ! $bn_can_post ) : ?>
	<div class="bn-card bn-sh-guest-cta">
		<div class="bn-sh-guest-cta__icon" aria-hidden="true"><?php buddynext_icon( 'lock' ); ?></div>
		<div class="bn-sh-guest-cta__copy">
			<p class="bn-sh-guest-cta__title"><?php esc_html_e( 'Posting is restricted', 'buddynext' ); ?></p>
			<p class="bn-sh-guest-cta__lede"><?php esc_html_e( 'Only the space owner and moderators can post here. You can still react and reply.', 'buddynext' ); ?></p>
		</div>
	</div>
<?php elseif ( $bn_is_guest ) : ?>
	<div class="bn-card bn-sh-guest-cta">
		<div class="bn-sh-guest-cta__icon" aria-hidden="true"><?php buddynext_icon( 'log-in' ); ?></div>
		<div class="bn-sh-guest-cta__copy">
			<p class="bn-sh-guest-cta__title"><?php esc_html_e( 'Join to participate', 'buddynext' ); ?></p>
			<p class="bn-sh-guest-cta__lede"><?php esc_html_e( 'Sign in to post, react, and reply in this space.', 'buddynext' ); ?></p>
		</div>
		<a
			href="<?php echo esc_url( PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $bn_space->slug ) ) ); ?>"
			class="bn-btn"
			data-variant="primary"
			data-size="md"
		><?php esc_html_e( 'Log in', 'buddynext' ); ?></a>
	</div>
<?php elseif ( ! $bn_is_member && ! $bn_is_pending && 'open' === $bn_space->type && buddynext_service( 'space_members' )->can_join( $bn_space, get_current_user_id() ) ) : ?>
	<?php // can_join() asks the same gate the join itself runs, so this card is only offered when the invitation is real. See space-hero.php. ?>
	<div class="bn-card bn-sh-guest-cta">
		<div class="bn-sh-guest-cta__icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
		<div class="bn-sh-guest-cta__copy">
			<p class="bn-sh-guest-cta__title"><?php esc_html_e( 'Join the conversation', 'buddynext' ); ?></p>
			<p class="bn-sh-guest-cta__lede"><?php esc_html_e( 'Join the space to post and reply.', 'buddynext' ); ?></p>
		</div>
		<button
			class="bn-btn"
			data-variant="primary"
			data-size="md"
			data-current-state="join"
			data-wp-on--click="actions.joinSpace"
		><?php esc_html_e( 'Join space', 'buddynext' ); ?></button>
	</div>
<?php endif; ?>

<?php
if ( ! empty( $bn_pinned_posts ) ) :
	// Bounded pinned strip: show the first few inline, fold the rest behind a
	// native <details> so a space with up to 10 pins never buries fresh posts.
	$bn_pinned_total   = count( $bn_pinned_posts );
	$bn_pinned_visible = 3;

	// Who can unpin from the strip, mirroring the full post card's Pin/Unpin
	// affordance (post-card.php: own post OR site admin). Without this the pinned
	// strip was a dead end — a pinned post was pulled out of the feed (so its
	// normal card, which carries the kebab Unpin, never rendered) and the compact
	// card had no controls, leaving no way to unpin from the UI.
	$bn_pin_viewer = get_current_user_id();

	/**
	 * Render one pinned post - as a REAL post card.
	 *
	 * This used to hand-roll a compact stub: the content trimmed to 24 words, a "Pinned by X"
	 * line, and an unpin button. Nothing else. No React, no Comment, no Share, no Save - because
	 * none of that markup lives here, it lives in partials/post-card.php.
	 *
	 * And the pinned post is DROPPED from the chronological list right below (so it is not shown
	 * twice), so the stub was the ONLY place it appeared. Pinning a post therefore stripped every
	 * form of engagement from it: an owner pins the announcement they most want people to respond
	 * to, and nobody can react or comment on it anywhere in the space.
	 *
	 * space_pinned_posts() already returns rows through PostService::hydrate(), i.e. exactly the
	 * shape post-card.php consumes, and post-card.php already understands is_pinned (it adds the
	 * bn-post-card--pinned class). It was built for this. So render the real card and inherit the
	 * toolbar, the kebab (which carries Unpin), comments and reactions for free - rather than
	 * maintaining a second, poorer copy of a post card.
	 *
	 * @param array<string,mixed> $bn_pinned Hydrated pinned post.
	 * @return void
	 */
	$bn_render_pinned = static function ( array $bn_pinned ) use ( $bn_pin_viewer ): void {
		if ( isset( $bn_pinned['media_ids'] ) && is_string( $bn_pinned['media_ids'] ) ) {
			$bn_pinned['media_ids'] = json_decode( $bn_pinned['media_ids'], true );
		}

		buddynext_get_template(
			'partials/post-card.php',
			array(
				'post'            => $bn_pinned,
				'current_user_id' => $bn_pin_viewer,
				'context'         => 'space',
			)
		);
	};
	?>
	<div class="bn-sh-pinned-strip" aria-label="<?php esc_attr_e( 'Pinned posts', 'buddynext' ); ?>">
		<?php
		foreach ( array_slice( $bn_pinned_posts, 0, $bn_pinned_visible ) as $bn_pinned ) {
			$bn_render_pinned( $bn_pinned );
		}
		?>
		<?php if ( $bn_pinned_total > $bn_pinned_visible ) : ?>
			<details class="bn-sh-pinned-strip__more">
				<summary>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of additional pinned posts. */
							_n( 'Show %d more pinned post', 'Show %d more pinned posts', $bn_pinned_total - $bn_pinned_visible, 'buddynext' ),
							$bn_pinned_total - $bn_pinned_visible
						)
					);
					?>
				</summary>
				<?php
				foreach ( array_slice( $bn_pinned_posts, $bn_pinned_visible ) as $bn_pinned ) {
					$bn_render_pinned( $bn_pinned );
				}
				?>
			</details>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( empty( $bn_posts ) && $bn_is_searching ) : ?>
	<?php
	// A search that matched nothing is not an empty space. Telling a member to
	// "be the first to post" when the space has 3000 posts they simply could not
	// match reads as broken, so the no-results state is its own.
	buddynext_get_template(
		'parts/empty-state.php',
		array(
			'icon'  => 'search',
			'title' => __( 'No matching posts', 'buddynext' ),
			'body'  => __( 'Nothing in this space matches that search. Try a different or shorter term.', 'buddynext' ),
		)
	);
	?>
<?php elseif ( empty( $bn_posts ) ) : ?>
	<?php
	buddynext_get_template(
		'parts/empty-state.php',
		array(
			'icon'  => 'message-circle',
			'title' => __( 'No posts yet', 'buddynext' ),
			'body'  => __( 'Be the first to post in this space.', 'buddynext' ),
		)
	);
	?>
<?php else : ?>
	<div class="bn-sh-feed" role="feed" aria-label="<?php esc_attr_e( 'Space feed', 'buddynext' ); ?>">
		<?php
		foreach ( $bn_posts as $post_arr ) {
			if ( isset( $post_arr['media_ids'] ) && is_string( $post_arr['media_ids'] ) ) {
				$post_arr['media_ids'] = json_decode( $post_arr['media_ids'], true );
			}
			buddynext_get_template(
				'partials/post-card.php',
				array(
					'post'            => $post_arr,
					'current_user_id' => $bn_viewer_id,
					'context'         => 'space',
				)
			);
		}
		?>
	</div>
<?php endif; ?>

<?php
if ( '' !== $bn_wrap_class ) {
	echo '</div>';
}

do_action( 'buddynext_part_space_feed_panel_after', $args );
