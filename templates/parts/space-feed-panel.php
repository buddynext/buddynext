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
 * @var array       $posts          Optional. List of post arrays for the feed. Default [].
 * @var array|null  $pinned_post    Optional. Pinned post row (or null when absent).
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
	'posts'        => isset( $posts ) && is_array( $posts ) ? $posts : array(),
	'pinned_post'  => isset( $pinned_post ) ? $pinned_post : null,
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

$bn_space      = $args['space'];
$bn_space_id   = (int) $args['space_id'];
$bn_viewer_id  = (int) $args['viewer_id'];
$bn_is_member  = (bool) $args['is_member'];
$bn_can_post   = (bool) $args['can_post'];
$bn_is_guest   = (bool) $args['is_guest'];
$bn_is_pending = (bool) $args['is_pending'];
$bn_posts      = (array) $args['posts'];
$bn_pinned     = $args['pinned_post'];
$bn_user       = $args['current_user'];

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

<?php if ( $bn_pinned ) : ?>
	<div class="bn-card bn-sh-pinned">
		<div class="bn-sh-pinned__label">
			<?php buddynext_icon( 'bookmark' ); ?>
			<?php esc_html_e( 'Pinned announcement', 'buddynext' ); ?>
		</div>
		<p class="bn-sh-pinned__title"><?php echo esc_html( wp_trim_words( $bn_pinned->content ?? '', 24 ) ); ?></p>
		<p class="bn-sh-pinned__meta">
			<?php
			printf(
				/* translators: 1: author display name, 2: time ago label. */
				esc_html__( 'Pinned by %1$s · %2$s', 'buddynext' ),
				esc_html( $bn_pinned->author_name ?? __( 'Admin', 'buddynext' ) ),
				esc_html( bn_sh_time_diff( $bn_pinned->created_at ) )
			);
			?>
		</p>
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
