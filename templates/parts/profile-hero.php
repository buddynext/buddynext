<?php
/**
 * BuddyNext template part: profile-hero.
 *
 * Renders the profile hero card: cover image + identity head (avatar,
 * name, badges, handle, headline, bio, meta row, social chips) and the
 * viewer action cluster (follow/connect/message + share + more-options
 * popovers). Used by `templates/profile/view.php`.
 *
 * Layout (logical properties only):
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │  <cover>                                                         │
 *   │  ┌─────────┐                                                     │
 *   │  │ avatar  │  <name + badges>            <action buttons>        │
 *   │  └─────────┘  <handle · pronouns · headline>                     │
 *   │               <bio>                                              │
 *   │               <meta row>                                         │
 *   │               <social chips>                                     │
 *   └──────────────────────────────────────────────────────────────────┘
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var int    $profile_user_id     Required. ID of the profile being viewed.
 * @var int    $viewer_id           Required. ID of the current viewer (0 when anonymous).
 * @var string $display_name        Required. Profile display name.
 * @var string $username            Optional. URL-safe profile slug used after `@`.
 * @var string $avatar_url          Required. Avatar URL (already resolved).
 * @var string $cover_url           Optional. Cover image URL.
 * @var string $bio                 Optional. Bio HTML (rendered via wp_kses_post).
 * @var string $headline            Optional. Tagline shown next to the handle.
 * @var string $pronouns            Optional. Pronouns shown in parens after the handle.
 * @var string $location            Optional. Location string for the meta row.
 * @var string $website             Optional. Website URL for the meta row.
 * @var string $joined              Optional. Pre-formatted "joined" date.
 * @var int    $mutual_count        Optional. Mutual connection count.
 * @var string $degree_badge        Optional. Degree badge text (e.g. "1st").
 * @var array  $member_type         Optional. ['name','color','text_color'].
 * @var array  $social_links        Optional. Filtered social-link fields.
 * @var bool   $is_owner            Required. Whether viewer owns this profile.
 * @var bool   $is_online           Optional. Online presence flag.
 * @var bool   $is_following        Optional. Viewer follows this profile.
 * @var bool   $is_connected        Optional. Viewer is connected to this profile.
 * @var bool   $connection_pending  Optional. Viewer has a pending outbound request.
 * @var bool   $connection_received Optional. Viewer has a pending inbound request.
 * @var array  $metric_items        Optional. Resolved Nav metric items (NavItem[])
 *                                  rendered as the count-pill row (parts/nav-metrics.php)
 *                                  inside the hero `<section>` — preserves the original
 *                                  hero layout where the metrics are the bottom band.
 * @var array  $classes             Optional. Extra CSS classes appended to `.bn-pf-hero`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_hero_before', $args )
 *   - do_action( 'buddynext_part_profile_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'profile_user_id'     => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'viewer_id'           => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'display_name'        => isset( $display_name ) ? (string) $display_name : '',
	'username'            => isset( $username ) ? (string) $username : '',
	'avatar_url'          => isset( $avatar_url ) ? (string) $avatar_url : '',
	'cover_url'           => isset( $cover_url ) ? (string) $cover_url : '',
	'bio'                 => isset( $bio ) ? (string) $bio : '',
	'headline'            => isset( $headline ) ? (string) $headline : '',
	'pronouns'            => isset( $pronouns ) ? (string) $pronouns : '',
	'location'            => isset( $location ) ? (string) $location : '',
	'website'             => isset( $website ) ? (string) $website : '',
	'joined'              => isset( $joined ) ? (string) $joined : '',
	'mutual_count'        => isset( $mutual_count ) ? (int) $mutual_count : 0,
	'degree_badge'        => isset( $degree_badge ) ? (string) $degree_badge : '',
	'member_type'         => isset( $member_type ) && is_array( $member_type ) ? $member_type : array(),
	'social_links'        => isset( $social_links ) && is_array( $social_links ) ? $social_links : array(),
	'is_owner'            => isset( $is_owner ) ? (bool) $is_owner : false,
	'can_edit_any'        => isset( $can_edit_any ) ? (bool) $can_edit_any : false,
	'is_online'           => isset( $is_online ) ? (bool) $is_online : false,
	'is_following'        => isset( $is_following ) ? (bool) $is_following : false,
	'is_connected'        => isset( $is_connected ) ? (bool) $is_connected : false,
	'connection_pending'  => isset( $connection_pending ) ? (bool) $connection_pending : false,
	'connection_received' => isset( $connection_received ) ? (bool) $connection_received : false,
	'metric_items'        => isset( $metric_items ) && is_array( $metric_items ) ? $metric_items : array(),
	'classes'             => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_hero_args', $args );

if ( $args['profile_user_id'] <= 0 || '' === (string) $args['display_name'] ) {
	return;
}

$bn_classes = array_merge( array( 'bn-pf-hero', 'bn-card' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_hero_classes', $bn_classes, $args );
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

$bn_pf_uid           = (int) $args['profile_user_id'];
$bn_pf_viewer        = (int) $args['viewer_id'];
$bn_pf_cover         = (string) $args['cover_url'];
$bn_pf_avatar        = (string) $args['avatar_url'];
$bn_pf_name          = (string) $args['display_name'];
$bn_pf_slug          = (string) $args['username'];
$bn_pf_pronouns      = (string) $args['pronouns'];
$bn_pf_headline      = (string) $args['headline'];
$bn_pf_bio           = (string) $args['bio'];
$bn_pf_location      = (string) $args['location'];
$bn_pf_website       = (string) $args['website'];
$bn_pf_joined        = (string) $args['joined'];
$bn_pf_mutual        = (int) $args['mutual_count'];
$bn_pf_degree        = (string) $args['degree_badge'];
$bn_pf_member_type   = (array) $args['member_type'];
$bn_pf_social        = (array) $args['social_links'];
$bn_pf_is_owner      = (bool) $args['is_owner'];
$bn_pf_can_edit      = (bool) $args['can_edit_any'];
$bn_pf_is_online     = (bool) $args['is_online'];
$bn_pf_is_following  = (bool) $args['is_following'];
$bn_pf_is_connected  = (bool) $args['is_connected'];
$bn_pf_conn_pending  = (bool) $args['connection_pending'];
$bn_pf_conn_received = (bool) $args['connection_received'];

// Honour the target's who_can_follow preference: the Follow toggle is hidden
// when the viewer may not follow (and is not already a follower). FollowService
// enforces the same rule server-side; gating the button keeps it from appearing
// only to 403 on click. Already-following viewers keep the toggle so they can
// still unfollow.
$bn_pf_can_follow = true;
// Same rule for connection requests (who_can_connect): hide the Connect CTA when
// the viewer may not send a request, unless a pending/accepted relationship
// already exists. ConnectionService enforces this server-side too.
$bn_pf_can_connect = true;
if ( $bn_pf_viewer && ! $bn_pf_is_owner ) {
	$bn_pf_privacy     = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
	$bn_pf_can_follow  = ! $bn_pf_privacy || ! method_exists( $bn_pf_privacy, 'can_follow' )
		|| (bool) $bn_pf_privacy->can_follow( $bn_pf_viewer, $bn_pf_uid );
	$bn_pf_can_connect = ! $bn_pf_privacy || ! method_exists( $bn_pf_privacy, 'can_connect' )
		|| (bool) $bn_pf_privacy->can_connect( $bn_pf_viewer, $bn_pf_uid );
}

do_action( 'buddynext_part_profile_hero_before', $args );
?>
	<!-- Hero card: cover + identity + stats -->
	<section class="<?php echo esc_attr( $bn_class ); ?>">
		<!-- Cover -->
		<?php
		// Apply the stored reposition (set by the cover-upload modal): pan via
		// object-position and zoom via transform:scale on the cover <img>. This
		// is non-destructive — the source image stays sharp and the cover stays
		// responsive at any viewport width (the hero height is fixed, so a baked
		// fixed-ratio crop would mis-fit; object-fit:cover adapts).
		$bn_pf_focal     = (array) get_user_meta( $bn_pf_uid, 'buddynext_cover_focal', true );
		$bn_pf_fx        = isset( $bn_pf_focal['x'] ) ? max( 0.0, min( 100.0, (float) $bn_pf_focal['x'] ) ) : 50.0;
		$bn_pf_fy        = isset( $bn_pf_focal['y'] ) ? max( 0.0, min( 100.0, (float) $bn_pf_focal['y'] ) ) : 50.0;
		$bn_pf_zoom      = isset( $bn_pf_focal['zoom'] ) ? max( 1.0, min( 3.0, (float) $bn_pf_focal['zoom'] ) ) : 1.0;
		$bn_pf_img_style = sprintf(
			'object-position:%s%% %s%%;transform:scale(%s);',
			esc_attr( (string) $bn_pf_fx ),
			esc_attr( (string) $bn_pf_fy ),
			esc_attr( (string) $bn_pf_zoom )
		);
		?>
		<div class="bn-pf-cover<?php echo '' !== $bn_pf_cover ? ' bn-pf-cover--has-image' : ''; ?>">
			<?php if ( '' !== $bn_pf_cover ) : ?>
				<img class="bn-pf-cover__img"
					src="<?php echo esc_url( $bn_pf_cover ); ?>"
					alt=""
					style="<?php echo esc_attr( $bn_pf_img_style ); ?>" />
			<?php endif; ?>
			<?php if ( $bn_pf_is_owner ) : ?>
				<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
					class="bn-pf-cover__edit"
					aria-label="<?php esc_attr_e( 'Edit cover photo', 'buddynext' ); ?>">
					<?php buddynext_icon( 'edit' ); ?>
					<span><?php esc_html_e( 'Edit cover', 'buddynext' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<!-- Identity head: avatar + id block + actions -->
		<div class="bn-pf-head">

			<!-- Avatar -->
			<div class="bn-pf-avatar-wrap">
				<span class="bn-avatar"
					data-size="2xl"
					<?php echo $bn_pf_is_online ? 'data-presence="online"' : ''; ?>
				>
					<img src="<?php echo esc_url( $bn_pf_avatar ); ?>"
						alt="<?php echo esc_attr( $bn_pf_name ); ?>"
						width="96"
						height="96"
						loading="eager"
						decoding="async"
					/>
					<?php
					$bn_pf_avatar_overlay = (string) apply_filters( 'buddynext_avatar_overlay_html', '', $bn_pf_uid, '2xl' );
					if ( '' !== $bn_pf_avatar_overlay ) {
						echo $bn_pf_avatar_overlay; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract
					}
					?>
				</span>
				<?php if ( $bn_pf_is_owner ) : ?>
					<a class="bn-pf-avatar-edit"
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() . '#avatar' ); ?>"
						aria-label="<?php esc_attr_e( 'Edit avatar', 'buddynext' ); ?>"
						title="<?php esc_attr_e( 'Edit avatar', 'buddynext' ); ?>">
						<?php buddynext_icon( 'edit' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<!-- Identity block -->
			<div class="bn-pf-id">
				<div class="bn-pf-name-row">
					<h1 class="bn-pf-name"><?php echo esc_html( $bn_pf_name ); ?></h1>
					<?php
					if ( $bn_pf_degree ) :
						$bn_pf_degree_title = '';
						if ( '1st' === $bn_pf_degree ) {
							$bn_pf_degree_title = __( 'Directly connected to you.', 'buddynext' );
						} elseif ( '2nd' === $bn_pf_degree && $bn_pf_mutual > 0 ) {
							$bn_pf_degree_title = sprintf(
								/* translators: %d: mutual connection count */
								_n( '%d mutual connection.', '%d mutual connections.', $bn_pf_mutual, 'buddynext' ),
								$bn_pf_mutual
							);
						} elseif ( '2nd' === $bn_pf_degree ) {
							$bn_pf_degree_title = __( 'Connected through a mutual contact.', 'buddynext' );
						} else {
							$bn_pf_degree_title = __( 'No direct or mutual connection yet.', 'buddynext' );
						}
						?>
						<span class="bn-badge bn-pf-degree" data-tone="accent" title="<?php echo esc_attr( $bn_pf_degree_title ); ?>">
							<?php echo esc_html( $bn_pf_degree ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $bn_pf_member_type ) : ?>
						<?php $bn_pf_type_icon = \BuddyNext\MemberTypes\MemberTypeService::render_icon_svg( (string) ( $bn_pf_member_type['icon_svg'] ?? '' ) ); ?>
						<span
							class="bn-badge bn-pf-type-badge"
							data-tone="accent"
							style="background:<?php echo esc_attr( $bn_pf_member_type['color'] ); ?>;color:<?php echo esc_attr( $bn_pf_member_type['text_color'] ); ?>;"
						>
						<?php
						if ( '' !== $bn_pf_type_icon ) :
							?>
							<span class="bn-type-badge__icon" aria-hidden="true"><?php echo $bn_pf_type_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses-sanitized by MemberTypeService::render_icon_svg(). ?></span>
							<?php
						endif;
						echo esc_html( $bn_pf_member_type['name'] );
						?>
						</span>
					<?php endif; ?>
					<?php
					$bn_pf_is_verified = (bool) get_user_meta( $bn_pf_uid, 'buddynext_email_verified', true );
					if ( $bn_pf_is_verified ) :
						?>
						<span class="bn-pf-verified" title="<?php esc_attr_e( 'Verified account', 'buddynext' ); ?>" aria-label="<?php esc_attr_e( 'Verified account', 'buddynext' ); ?>">
							<?php buddynext_icon( 'check' ); ?>
						</span>
						<?php
					endif;

					$bn_pf_badges = (string) apply_filters( 'buddynext_profile_hero_badges_html', '', $bn_pf_uid );
					if ( '' !== $bn_pf_badges ) {
						echo $bn_pf_badges; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract
					}
					?>
				</div>

				<div class="bn-pf-handle">
					@<?php echo esc_html( '' !== $bn_pf_slug ? $bn_pf_slug : 'user-' . $bn_pf_uid ); ?>
					<?php if ( $bn_pf_pronouns ) : ?>
						<span class="bn-pf-pronouns">(<?php echo esc_html( $bn_pf_pronouns ); ?>)</span>
					<?php endif; ?>
				</div>

				<?php
				// Headline = the member's professional tagline (LinkedIn/X style),
				// shown directly under the handle. It is editable in profile edit
				// AND counts toward the profile-strength "Add a tagline" task, so it
				// MUST render here — otherwise a member fills it in and it appears
				// nowhere, which reads as a bug. Skipped in the generic About-field
				// renderer (bn_pf_hero_keys) so it is not duplicated lower down.
				?>
				<?php if ( '' !== $bn_pf_headline ) : ?>
					<div class="bn-pf-headline"><?php echo esc_html( $bn_pf_headline ); ?></div>
				<?php endif; ?>

				<?php if ( $bn_pf_bio ) : ?>
					<div class="bn-pf-bio"><?php echo wp_kses_post( $bn_pf_bio ); ?></div>
				<?php endif; ?>

				<div class="bn-pf-meta">
					<?php if ( $bn_pf_location ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'map-pin' ); ?>
							<span><?php echo esc_html( $bn_pf_location ); ?></span>
						</span>
					<?php endif; ?>
					<?php if ( $bn_pf_website ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'link' ); ?>
							<a href="<?php echo esc_url( $bn_pf_website ); ?>" target="_blank" rel="nofollow noopener noreferrer ugc">
								<?php
								$parsed_host = wp_parse_url( $bn_pf_website, PHP_URL_HOST );
								echo esc_html( $parsed_host ? $parsed_host : $bn_pf_website );
								?>
							</a>
						</span>
					<?php endif; ?>
					<span class="bn-pf-meta__item">
						<?php buddynext_icon( 'calendar' ); ?>
						<span>
						<?php
						/* translators: %s: month and year the member joined */
						echo esc_html( sprintf( __( 'Joined %s', 'buddynext' ), $bn_pf_joined ) );
						?>
						</span>
					</span>
					<?php if ( $bn_pf_mutual > 0 ) : ?>
						<span class="bn-pf-meta__item">
							<?php buddynext_icon( 'users' ); ?>
							<span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of mutual connections */
									_n( '%d mutual connection', '%d mutual connections', $bn_pf_mutual, 'buddynext' ),
									$bn_pf_mutual
								)
							);
							?>
							</span>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $bn_pf_social ) ) : ?>
				<div class="bn-pf-social-chips" aria-label="<?php esc_attr_e( 'Social links', 'buddynext' ); ?>">
					<?php
					$social_icon_map = array(
						'social_twitter'   => 'at-sign',
						'social_linkedin'  => 'link',
						'social_github'    => 'code',
						'social_instagram' => 'camera',
						'social_youtube'   => 'play-circle',
					);
					foreach ( $bn_pf_social as $sl_field ) :
						$sl_key   = (string) ( $sl_field['field_key'] ?? '' );
						$sl_url   = (string) ( $sl_field['value'] ?? '' );
						$sl_label = (string) ( $sl_field['label'] ?? $sl_key );
						$sl_icon  = $social_icon_map[ $sl_key ] ?? 'link';
						if ( '' === $sl_url ) {
							continue;
						}
						?>
						<a class="bn-pf-social-chip"
							data-social="<?php echo esc_attr( $sl_key ); ?>"
							href="<?php echo esc_url( $sl_url ); ?>"
							target="_blank"
							rel="nofollow noopener noreferrer ugc"
							aria-label="<?php echo esc_attr( $sl_label ); ?>">
							<?php buddynext_icon( $sl_icon ); ?>
							<span><?php echo esc_html( $sl_label ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<!-- Owner action buttons — Edit profile + Share profile +
				completeness chip. The chip is shown only to the owner
				until completion reaches 100 % so others don't see a
				scoring meter on a profile they're visiting. -->
			<?php if ( $bn_pf_is_owner ) : ?>
				<?php
				// Prefer the caller-supplied 6-task strength percentage (the same set
				// the strength widget/checklist shows) so the chip matches the
				// sidebar ring. Fall back to the field-wide completion score only
				// when a caller renders the hero without it.
				if ( isset( $strength_pct ) ) {
					$bn_pf_completion_pct = (int) $strength_pct;
				} else {
					$bn_pf_completion_pct = 0;
					if ( function_exists( 'buddynext_service' ) ) {
						$bn_pf_completion     = (array) buddynext_service( 'profiles' )->get_completion_score( $bn_pf_uid );
						$bn_pf_completion_pct = (int) ( $bn_pf_completion['percent'] ?? 0 );
					}
				}
				?>
				<div class="bn-pf-actions">
					<a class="bn-btn" data-variant="primary" data-size="sm"
						href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>">
						<?php buddynext_icon( 'edit' ); ?>
						<span><?php esc_html_e( 'Edit profile', 'buddynext' ); ?></span>
					</a>
					<?php
					$bn_pf_share_url = home_url( '/members/' . rawurlencode( $bn_pf_slug ) . '/' );
					?>
					<button type="button"
						class="bn-btn"
						data-variant="secondary"
						data-size="sm"
						data-wp-on--click="actions.shareProfile"
						data-share-url="<?php echo esc_attr( $bn_pf_share_url ); ?>"
						aria-label="<?php esc_attr_e( 'Share profile link', 'buddynext' ); ?>">
						<?php buddynext_icon( 'share' ); ?>
						<span><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
					</button>

					<?php if ( $bn_pf_completion_pct < 100 ) : ?>
						<a class="bn-pf-completeness"
							href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url() ); ?>"
							style="--bn-pf-pct: <?php echo esc_attr( (string) $bn_pf_completion_pct ); ?>%"
							aria-label="
							<?php
							echo esc_attr(
								sprintf(
								/* translators: %d: profile completion percentage. */
									__( 'Profile %d%% complete — finish to make it discoverable', 'buddynext' ),
									$bn_pf_completion_pct
								)
							);
							?>
							"
						>
							<span class="bn-pf-completeness__ring" aria-hidden="true">
								<span class="bn-pf-completeness__ring-fill"></span>
							</span>
							<span class="bn-pf-completeness__label">
								<?php
								echo esc_html(
									sprintf(
									/* translators: %d: profile completion percentage. */
										__( '%d%% complete', 'buddynext' ),
										$bn_pf_completion_pct
									)
								);
								?>
							</span>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Action buttons — shown for other users only; owners see the bar above -->
			<?php if ( ! $bn_pf_is_owner && $bn_pf_viewer ) : ?>
			<div class="bn-pf-actions">
				<?php if ( $bn_pf_can_follow || $bn_pf_is_following ) : ?>
				<button class="bn-btn" data-variant="primary" data-size="sm"
					data-wp-on--click="actions.follow"
					data-wp-bind--hidden="context.isFollowing"
					<?php echo $bn_pf_is_following ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Follow', 'buddynext' ); ?>
				</button>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.unfollow"
					data-wp-bind--hidden="!context.isFollowing"
					<?php echo $bn_pf_is_following ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Following', 'buddynext' ); ?>
				</button>
				<?php endif; ?>

				<?php if ( $bn_pf_can_connect || $bn_pf_is_connected || $bn_pf_conn_pending || $bn_pf_conn_received ) : ?>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.connect"
					data-wp-bind--hidden="!context.showConnect"
					<?php echo ( $bn_pf_is_connected || $bn_pf_conn_pending || $bn_pf_conn_received ) ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'Connect', 'buddynext' ); ?>
				</button>
				<?php endif; ?>
				<button class="bn-btn" data-variant="secondary" data-size="sm"
					data-wp-on--click="actions.withdrawRequest"
					data-wp-bind--hidden="!context.connectionPending"
					<?php echo $bn_pf_conn_pending ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Pending', 'buddynext' ); ?>
				</button>
				<span class="bn-pf-actions__group"
					data-wp-bind--hidden="!context.connectionReceived"
					<?php echo $bn_pf_conn_received ? '' : 'hidden'; ?>>
					<button class="bn-btn" data-variant="primary" data-size="sm"
						data-wp-on--click="actions.acceptRequest">
						<?php esc_html_e( 'Accept', 'buddynext' ); ?>
					</button>
					<button class="bn-btn" data-variant="ghost" data-size="sm"
						data-wp-on--click="actions.declineRequest">
						<?php esc_html_e( 'Decline', 'buddynext' ); ?>
					</button>
				</span>
				<button class="bn-btn bn-pf-connected" data-variant="secondary" data-state="connected" data-size="sm"
					data-wp-on--click="actions.disconnectUser"
					data-wp-bind--hidden="!context.isConnected"
					<?php echo $bn_pf_is_connected ? '' : 'hidden'; ?>>
					<?php buddynext_icon( 'check' ); ?>
					<span><?php esc_html_e( 'Connected', 'buddynext' ); ?></span>
				</button>

				<?php if ( \BuddyNext\Messages\MessagesData::entry_enabled() ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'with', $bn_pf_uid, \BuddyNext\Core\PageRouter::messages_url() ) ); ?>"
						class="bn-btn" data-variant="secondary" data-size="sm">
						<?php buddynext_icon( 'message-circle' ); ?>
						<span><?php esc_html_e( 'Message', 'buddynext' ); ?></span>
					</a>
				<?php endif; ?>

				<!-- Share profile popover -->
				<div class="bn-share-menu-wrap" data-wp-class--is-open="context.shareMenuOpen">
					<button class="bn-btn" data-variant="secondary" data-size="sm"
						aria-haspopup="menu"
						aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Share profile', 'buddynext' ); ?>"
						data-wp-on--click="actions.toggleShareMenu"
						data-wp-bind--aria-expanded="context.shareMenuOpen">
						<?php buddynext_icon( 'share-2' ); ?>
						<span><?php esc_html_e( 'Share', 'buddynext' ); ?></span>
					</button>
					<div class="bn-share-menu bn-more-menu" role="menu">
						<button class="bn-more-menu-item"
							type="button"
							role="menuitem"
							data-share-url="<?php echo esc_attr( \BuddyNext\Core\PageRouter::profile_url( $bn_pf_uid ) ); ?>"
							data-wp-on--click="actions.copyProfileLink">
							<?php buddynext_icon( 'link' ); ?>
							<span><?php esc_html_e( 'Copy link', 'buddynext' ); ?></span>
						</button>
						<a class="bn-more-menu-item"
							role="menuitem"
							href="<?php echo esc_url( add_query_arg( 'mention', rawurlencode( $bn_pf_slug ), \BuddyNext\Core\PageRouter::activity_url() ) ); ?>">
							<?php buddynext_icon( 'message-circle' ); ?>
							<span><?php esc_html_e( 'Share to feed', 'buddynext' ); ?></span>
						</a>
					</div>
				</div>

				<!-- More options dropdown -->
				<div class="bn-more-menu-wrap" data-wp-class--is-open="context.moreMenuOpen">
					<button class="bn-btn bn-pf-more-trigger"
						data-variant="ghost"
						data-size="sm"
						aria-label="<?php esc_attr_e( 'More options', 'buddynext' ); ?>"
						aria-expanded="false"
						data-wp-on--click="actions.toggleMoreMenu"
						data-wp-bind--aria-expanded="context.moreMenuOpen"><?php buddynext_icon( 'more-horizontal' ); ?></button>
					<div class="bn-more-menu" role="menu">
							<?php // Edit this member's profile - shown to holders of the "Edit anyone's profile" capability (buddynext-profile/edit-any). ?>
							<?php if ( $bn_pf_can_edit ) : ?>
								<a class="bn-more-menu-item" role="menuitem" href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url( $bn_pf_uid ) ); ?>">
									<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
								</a>
							<?php endif; ?>
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.toggleMute"
							data-wp-text="state.muteLabel">
							<?php esc_html_e( 'Mute', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.toggleRestrict"
							data-wp-text="state.restrictLabel">
							<?php esc_html_e( 'Restrict', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item bn-more-menu-item--danger"
							role="menuitem"
							data-wp-on--click="actions.toggleBlock"
							data-wp-text="state.blockLabel">
							<?php esc_html_e( 'Block', 'buddynext' ); ?>
						</button>
						<button class="bn-more-menu-item"
							role="menuitem"
							data-wp-on--click="actions.openReport">
							<?php esc_html_e( 'Report', 'buddynext' ); ?>
						</button>
					</div>
				</div>
			</div>
			<?php elseif ( ! $bn_pf_is_owner ) : ?>
				<?php // Logged-out guest: a single Follow CTA routed through login that returns to this profile (mirrors the member-directory guest pattern) so the hero is never action-less. ?>
			<div class="bn-pf-actions">
				<a class="bn-btn" data-variant="primary" data-size="sm"
					href="<?php echo esc_url( add_query_arg( 'redirect_to', \BuddyNext\Core\PageRouter::profile_url( $bn_pf_uid ), \BuddyNext\Core\PageRouter::auth_url() ) ); ?>">
					<?php esc_html_e( 'Follow', 'buddynext' ); ?>
				</a>
			</div>
			<?php endif; ?>

		</div><!-- /.bn-pf-head -->

		<?php
		// Metric row lives inside the hero `<section>` to preserve the original
		// card-with-bottom-band layout. Rendered from the unified Nav registry's
		// metric layer via the shared part (same row the space surface uses).
		buddynext_get_template(
			'parts/nav-metrics.php',
			array(
				'items'       => (array) $args['metric_items'],
				'extra_class' => 'bn-pf-metricrow',
			)
		);
		?>

	</section><!-- /.bn-pf-hero -->
<?php
do_action( 'buddynext_part_profile_hero_after', $args );
