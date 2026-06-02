<?php
/**
 * BuddyNext template part: profile-tab-panel.
 *
 * Renders the tab-content container for the profile-view page. The
 * existing behavior renders all known panel shells (`posts`, `replies`,
 * `media`, `likes`, `discussions`) and lets the Interactivity API show
 * the active one via the `hidden` attribute on `[data-tab-panel]` nodes.
 * The seam for bridge/Pro additions is `buddynext_part_profile_tab_panel_after`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $active_tab        Required. Slug of the active tab. Default `posts`.
 * @var int    $profile_user_id   Required. ID of the profile being viewed.
 * @var int    $viewer_id         Optional. ID of the current viewer.
 * @var bool   $is_owner          Optional. Whether viewer owns this profile.
 * @var string $display_name      Optional. Profile display name (used in empty states).
 * @var array  $recent_posts      Optional. Rows for the Posts panel.
 * @var array  $user_replies      Optional. Rows for the Replies panel.
 * @var array  $user_media        Optional. Rows for the Media panel.
 * @var array  $user_likes        Optional. Rows for the Likes panel.
 * @var array  $jt_discussions    Optional. Rows for the Jetonomy Discussions panel.
 * @var bool   $show_discussions  Optional. Whether the Discussions panel is rendered.
 * @var array  $classes           Optional. Extra CSS classes appended to `.bn-pf-tab-content`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_tab_panel_before', $args )
 *   - do_action( 'buddynext_part_profile_tab_panel_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_tab_panel_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_tab_panel_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'active_tab'       => isset( $active_tab ) ? (string) $active_tab : 'posts',
	'profile_user_id'  => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'viewer_id'        => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'is_owner'         => isset( $is_owner ) ? (bool) $is_owner : false,
	'display_name'     => isset( $display_name ) ? (string) $display_name : '',
	'recent_posts'     => isset( $recent_posts ) && is_array( $recent_posts ) ? $recent_posts : array(),
	'user_replies'     => isset( $user_replies ) && is_array( $user_replies ) ? $user_replies : array(),
	'user_media'       => isset( $user_media ) && is_array( $user_media ) ? $user_media : array(),
	'user_likes'       => isset( $user_likes ) && is_array( $user_likes ) ? $user_likes : array(),
	'jt_discussions'   => isset( $jt_discussions ) && is_array( $jt_discussions ) ? $jt_discussions : array(),
	'show_discussions' => isset( $show_discussions ) ? (bool) $show_discussions : false,
	'classes'          => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_tab_panel_args', $args );

$bn_classes = array_merge( array( 'bn-pf-tab-content' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_tab_panel_classes', $bn_classes, $args );
$bn_class   = trim(
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

$bn_pf_uid       = (int) $args['profile_user_id'];
$bn_pf_viewer    = (int) $args['viewer_id'];
$bn_pf_is_owner  = (bool) $args['is_owner'];
$bn_pf_name      = (string) $args['display_name'];
$bn_recent_posts = (array) $args['recent_posts'];
$bn_user_replies = (array) $args['user_replies'];
$bn_user_media   = (array) $args['user_media'];
$bn_user_likes   = (array) $args['user_likes'];
$bn_jt_disc      = (array) $args['jt_discussions'];

do_action( 'buddynext_part_profile_tab_panel_before', $args );
?>
		<!-- Tab panels container -->
		<div class="<?php echo esc_attr( $bn_class ); ?>">

			<!-- Posts list (default tab) -->
			<div class="bn-profile-posts-panel" data-tab-panel="posts">
			<?php if ( $bn_recent_posts ) : ?>
				<?php
				foreach ( $bn_recent_posts as $post_arr ) {
					// Decode media_ids JSON string for the post-card partial.
					if ( isset( $post_arr['media_ids'] ) && is_string( $post_arr['media_ids'] ) ) {
						$post_arr['media_ids'] = json_decode( $post_arr['media_ids'], true );
					}
					buddynext_get_template(
						'partials/post-card.php',
						array(
							'post'            => $post_arr,
							'current_user_id' => $bn_pf_viewer,
							'context'         => 'profile',
						)
					);
				}
				?>
			<?php else : ?>
				<div class="bn-empty-state">
					<?php
					echo esc_html(
						$bn_pf_is_owner
							? __( 'You have not posted anything yet.', 'buddynext' )
							: sprintf(
								/* translators: %s: member display name */
								__( '%s has not posted anything yet.', 'buddynext' ),
								$bn_pf_name
							)
					);
					?>
				</div>
			<?php endif; ?>
			</div><!-- /.bn-profile-posts-panel -->

			<!-- Replies tab content -->
			<div class="bn-profile-tab-panel" data-tab-panel="replies" hidden>
				<?php if ( $bn_user_replies ) : ?>
					<?php foreach ( $bn_user_replies as $reply ) : ?>
					<div class="bn-reply-card">
						<div class="bn-reply-card__meta">
							<?php buddynext_icon( 'message-circle' ); ?>
							<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Replied to %s', 'buddynext' ), $reply->post_author_name ) ); ?></span>
							<span class="bn-reply-card__time"><?php echo esc_html( human_time_diff( strtotime( $reply->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
						</div>
						<div class="bn-reply-card__content"><?php echo wp_kses_post( wp_trim_words( $reply->content, 30 ) ); ?></div>
						<div class="bn-reply-card__context"><?php echo wp_kses_post( wp_trim_words( $reply->post_content, 15 ) ); ?></div>
					</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bn-empty-state"><?php esc_html_e( 'No replies yet.', 'buddynext' ); ?></div>
				<?php endif; ?>
			</div>

			<!-- Media tab content -->
			<div class="bn-profile-tab-panel" data-tab-panel="media" hidden>
				<?php
				// BN-native gallery. $bn_user_media is an ordered list of
				// WPMediaVerse media ids (privacy already applied upstream);
				// MediaRenderer::gallery() emits lightbox-bound tiles, video,
				// and audio. No WPMediaVerse markup/JS — BuddyNext owns the UX.
				if ( ! empty( $bn_user_media ) ) {
					echo \BuddyNext\Media\MediaRenderer::gallery( array_map( 'absint', (array) $bn_user_media ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
				} else {
					?>
					<div class="bn-empty-state"><?php esc_html_e( 'No media uploaded yet.', 'buddynext' ); ?></div>
					<?php
				}
				?>
			</div>

			<!-- Likes tab content -->
			<div class="bn-profile-tab-panel" data-tab-panel="likes" hidden>
				<?php if ( $bn_user_likes ) : ?>
					<?php foreach ( $bn_user_likes as $liked ) : ?>
					<div class="bn-like-card">
						<div class="bn-like-card__meta">
							<?php buddynext_icon( 'heart' ); ?>
							<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Liked %s\'s post', 'buddynext' ), $liked->post_author_name ) ); ?></span>
							<span class="bn-like-card__time"><?php echo esc_html( human_time_diff( strtotime( $liked->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
						</div>
						<div class="bn-like-card__content"><?php echo wp_kses_post( wp_trim_words( $liked->content, 30 ) ); ?></div>
					</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bn-empty-state"><?php esc_html_e( 'No liked posts yet.', 'buddynext' ); ?></div>
				<?php endif; ?>
			</div>

			<!-- Discussions tab content (Jetonomy) -->
			<?php if ( (bool) $args['show_discussions'] ) : ?>
			<div class="bn-profile-tab-panel" data-tab-panel="discussions" hidden>
				<?php if ( $bn_jt_disc ) : ?>
					<?php
					foreach ( $bn_jt_disc as $disc ) :
						$disc_space_slug = '' !== (string) $disc->space_slug ? (string) $disc->space_slug : 'general';
						$disc_space_name = '' !== (string) $disc->space_name ? (string) $disc->space_name : __( 'General', 'buddynext' );
						?>
					<a href="<?php echo esc_url( home_url( '/community/s/' . $disc_space_slug . '/t/' . $disc->slug . '/' ) ); ?>" class="bn-reply-card bn-reply-card--link">
						<div class="bn-reply-card__meta">
							<?php buddynext_icon( 'message-circle' ); ?>
							<span><?php echo esc_html( $disc_space_name ); ?></span>
							<span class="bn-reply-card__time"><?php echo esc_html( human_time_diff( strtotime( $disc->created_at ) ) . ' ' . __( 'ago', 'buddynext' ) ); ?></span>
						</div>
						<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( $disc->title ); ?></div>
						<div class="bn-reply-card__context">
							<?php echo esc_html( (string) $disc->reply_count ); ?> <?php esc_html_e( 'replies', 'buddynext' ); ?>
							<span aria-hidden="true">&middot;</span>
							<?php echo esc_html( (string) $disc->vote_score ); ?> <?php esc_html_e( 'votes', 'buddynext' ); ?>
						</div>
					</a>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bn-empty-state"><?php esc_html_e( 'No discussions yet.', 'buddynext' ); ?></div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div><!-- /.bn-pf-tab-content -->
<?php
do_action( 'buddynext_part_profile_tab_panel_after', $args );
