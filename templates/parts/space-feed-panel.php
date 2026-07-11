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
 * @var array        $pinned_posts   Optional. Pinned post rows (objects), newest first.
 * @var WP_User|null $current_user  Optional. Current WP_User object (for composer guard).
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
				<a class="bn-notice__cta" href="<?php echo esc_url( buddynext_space_url( $bn_space_slug ) . 'settings/#danger' ); ?>">
					<?php esc_html_e( 'Restore this space', 'buddynext' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $bn_is_member && $bn_user && $bn_can_post ) : ?>
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
<?php elseif ( ! $bn_is_member && ! $bn_is_pending && 'open' === $bn_space->type ) : ?>
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
	$bn_pin_viewer   = get_current_user_id();
	$bn_pin_is_admin = $bn_pin_viewer > 0 && user_can( $bn_pin_viewer, 'manage_options' );
	$bn_pin_nonce    = $bn_pin_viewer > 0 ? wp_create_nonce( 'wp_rest' ) : '';

	/**
	 * Render one compact pinned-post card.
	 *
	 * @param object $bn_pinned Hydrated pinned post (with author_name).
	 * @return void
	 */
	$bn_render_pinned = static function ( $bn_pinned ) use ( $bn_pin_viewer, $bn_pin_is_admin, $bn_pin_nonce ): void {
		$bn_pid       = (int) ( $bn_pinned->id ?? 0 );
		$bn_can_unpin = $bn_pin_viewer > 0 && $bn_pid > 0
			&& ( (int) ( $bn_pinned->user_id ?? 0 ) === $bn_pin_viewer || $bn_pin_is_admin );
		// The unpin button reuses the post-card store's REST unpin + reloads so the
		// card leaves the strip and reappears in the feed. Each eligible card is its
		// own Interactivity island carrying the post id + REST nonce.
		$bn_pin_ctx = (string) wp_json_encode(
			array(
				'postId'     => $bn_pid,
				'reactNonce' => $bn_pin_nonce,
			)
		);
		?>
		<div class="bn-card bn-sh-pinned"
			<?php if ( $bn_can_unpin ) : ?>
			data-wp-interactive="buddynext/post-card" data-wp-context='<?php echo esc_attr( $bn_pin_ctx ); ?>'
			<?php endif; ?>
		>
			<div class="bn-sh-pinned__label">
				<?php buddynext_icon( 'bookmark' ); ?>
				<?php esc_html_e( 'Pinned', 'buddynext' ); ?>
				<?php if ( $bn_can_unpin ) : ?>
					<button type="button" class="bn-sh-pinned__unpin" data-wp-on--click="actions.unpinPinnedFromStrip" aria-label="<?php esc_attr_e( 'Unpin this post', 'buddynext' ); ?>">
						<?php buddynext_icon( 'x' ); ?><span><?php esc_html_e( 'Unpin', 'buddynext' ); ?></span>
					</button>
				<?php endif; ?>
			</div>
			<p class="bn-sh-pinned__title"><?php echo esc_html( wp_trim_words( $bn_pinned->content ?? '', 24 ) ); ?></p>
			<p class="bn-sh-pinned__meta">
				<?php
				printf(
					/* translators: 1: author display name, 2: time ago label. */
					esc_html__( 'Pinned by %1$s · %2$s', 'buddynext' ),
					esc_html( $bn_pinned->author_name ?? __( 'Admin', 'buddynext' ) ),
					esc_html( buddynext_time_ago( (string) $bn_pinned->created_at ) )
				);
				?>
			</p>
		</div>
		<?php
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

<?php if ( empty( $bn_posts ) ) : ?>
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
