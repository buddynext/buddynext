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
	'active_tab'           => isset( $active_tab ) ? (string) $active_tab : 'posts',
	'about_html'           => isset( $about_html ) ? (string) $about_html : '',
	'profile_user_id'      => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'viewer_id'            => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'is_owner'             => isset( $is_owner ) ? (bool) $is_owner : false,
	'display_name'         => isset( $display_name ) ? (string) $display_name : '',
	'recent_posts'         => isset( $recent_posts ) && is_array( $recent_posts ) ? $recent_posts : array(),
	'user_replies'         => isset( $user_replies ) && is_array( $user_replies ) ? $user_replies : array(),
	'user_media'           => isset( $user_media ) && is_array( $user_media ) ? $user_media : array(),
	'user_likes'           => isset( $user_likes ) && is_array( $user_likes ) ? $user_likes : array(),
	'jt_discussions'       => isset( $jt_discussions ) && is_array( $jt_discussions ) ? $jt_discussions : array(),
	'show_discussions'     => isset( $show_discussions ) ? (bool) $show_discussions : false,
	'follower_users'       => isset( $follower_users ) && is_array( $follower_users ) ? $follower_users : array(),
	'following_users'      => isset( $following_users ) && is_array( $following_users ) ? $following_users : array(),
	'connection_users'     => isset( $connection_users ) && is_array( $connection_users ) ? $connection_users : array(),
	'pending_follow_users' => isset( $pending_follow_users ) && is_array( $pending_follow_users ) ? $pending_follow_users : array(),
	'classes'              => isset( $classes ) ? (array) $classes : array(),
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

$bn_pf_uid          = (int) $args['profile_user_id'];
$bn_pf_viewer       = (int) $args['viewer_id'];
$bn_pf_is_owner     = (bool) $args['is_owner'];
$bn_pf_name         = (string) $args['display_name'];
$bn_pf_about_html   = (string) $args['about_html'];
$bn_recent_posts    = (array) $args['recent_posts'];
$bn_user_replies    = (array) $args['user_replies'];
$bn_user_media      = (array) $args['user_media'];
$bn_user_likes      = (array) $args['user_likes'];
$bn_jt_disc         = (array) $args['jt_discussions'];
$bn_followers       = (array) $args['follower_users'];
$bn_following       = (array) $args['following_users'];
$bn_connections     = (array) $args['connection_users'];
$bn_pending_follows = (array) $args['pending_follow_users'];

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

			<!-- About tab content — profile details, moved out of the always-on column. -->
			<?php if ( '' !== $bn_pf_about_html ) : ?>
			<div class="bn-profile-tab-panel bn-pf-about-panel" data-tab-panel="about" hidden>
				<?php echo $bn_pf_about_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered about-cards markup; escaped at source by the FieldType engine + esc_html(). ?>
			</div>
			<?php endif; ?>

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
					echo \BuddyNext\Media\MediaRenderer::gallery( array_map( 'absint', (array) $bn_user_media ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MediaRenderer emits pre-sanitized markup.
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

			<!-- Followers tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" data-tab-panel="followers" hidden>
				<?php if ( ! empty( $bn_pending_follows ) ) : ?>
					<section class="bn-follow-requests" aria-label="<?php esc_attr_e( 'Pending follow requests', 'buddynext' ); ?>">
						<header class="bn-follow-requests__head">
							<h3 class="bn-follow-requests__title">
								<?php
								printf(
									/* translators: %d: number of pending follow requests */
									esc_html( _n( 'Pending request', 'Pending requests', count( $bn_pending_follows ), 'buddynext' ) ) . ' <span class="bn-follow-requests__count">%d</span>',
									(int) count( $bn_pending_follows )
								);
								?>
							</h3>
							<p class="bn-follow-requests__sub"><?php esc_html_e( 'Your account is private. Approve who can follow you.', 'buddynext' ); ?></p>
						</header>
						<ul class="bn-follow-requests__list" role="list">
							<?php
							foreach ( $bn_pending_follows as $bn_req_user ) :
								if ( ! $bn_req_user instanceof WP_User ) {
									continue;
								}
								$bn_req_id  = (int) $bn_req_user->ID;
								$bn_req_url = \BuddyNext\Core\PageRouter::profile_url( $bn_req_id );
								$bn_req_ctx = wp_json_encode(
									array(
										'followerId' => $bn_req_id,
										'targetName' => $bn_req_user->user_nicename,
										'hidden'     => false,
										'busy'       => false,
										'restUrl'    => rest_url( 'buddynext/v1' ),
										'nonce'      => wp_create_nonce( 'wp_rest' ),
									)
								);
								?>
								<li class="bn-follow-requests__row" data-wp-interactive="buddynext/follow-requests" data-wp-context='<?php echo esc_attr( (string) $bn_req_ctx ); ?>' data-wp-bind--hidden="state.rowHidden">
									<a href="<?php echo esc_url( $bn_req_url ); ?>" class="bn-follow-requests__avatar" aria-hidden="true" tabindex="-1">
										<img src="<?php echo esc_url( get_avatar_url( $bn_req_id, array( 'size' => 96 ) ) ); ?>" alt="" loading="lazy" />
									</a>
									<div class="bn-follow-requests__id">
										<a href="<?php echo esc_url( $bn_req_url ); ?>" class="bn-follow-requests__name"><?php echo esc_html( $bn_req_user->display_name ); ?></a>
										<span class="bn-follow-requests__handle">@<?php echo esc_html( $bn_req_user->user_nicename ); ?></span>
									</div>
									<div class="bn-follow-requests__actions">
										<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.reject"><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
										<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.approve"><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
				<?php if ( ! empty( $bn_followers ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_followers,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'No followers yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s has no followers yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Following tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" data-tab-panel="following" hidden>
				<?php if ( ! empty( $bn_following ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_following,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'user-plus' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'You are not following anyone yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s is not following anyone yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Connections tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" data-tab-panel="connections" hidden>
				<?php if ( ! empty( $bn_connections ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_connections,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'No connections yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s has no connections yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

		</div><!-- /.bn-pf-tab-content -->
<?php
do_action( 'buddynext_part_profile_tab_panel_after', $args );
