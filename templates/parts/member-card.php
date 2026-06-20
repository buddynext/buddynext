<?php
/**
 * BuddyNext template part: member-card.
 *
 * Renders a single member card row (avatar, name, handle, type badge, bio,
 * mutual-connection count, and the per-card action cluster — Follow,
 * 5-state Connect, Message, kebab menu with Mute / Block / Report). The
 * card is the high-value reusable unit shared by the member directory,
 * search results, and space-members panels.
 *
 * Used by: templates/directory/members.php (directory grid),
 *          future search-results-members + space-members panels.
 *
 * @package BuddyNext
 *
 * @var WP_User|object $member             Required. User object (must expose ID, display_name, user_login).
 * @var int            $viewer_id          Optional. Currently-viewing user ID. Default 0.
 * @var bool           $is_following       Optional. Whether viewer follows the member. Default false.
 * @var string         $connection_state   Optional. One of none|pending-sent|pending-received|accepted. Default 'none'.
 * @var string         $connection_status  Optional. Raw status (none|pending|accepted) used for Message link. Default 'none'.
 * @var bool           $is_muted           Optional. Whether viewer mutes the member. Default false.
 * @var int            $mutual_count       Optional. Mutual-connection count. Default 0.
 * @var array          $mutual_avatars     Optional. Up to ~3 mutual descriptors [ 'name', 'avatar_url' ] for the pile. Default [].
 * @var string         $presence           Optional. 'online' or 'offline'. Default 'offline'.
 * @var string         $member_type_label  Optional. Member-type display name. Default ''.
 * @var string         $avatar_tone        Optional. Avatar tone slot. Default 'accent'.
 * @var string         $bio                Optional. Pre-resolved bio string. Default ''.
 * @var string         $profile_url        Optional. Profile permalink. Default ''.
 * @var string         $avatar_url         Optional. Avatar URL. Default ''.
 * @var string         $initials           Optional. Initials fallback. Default ''.
 * @var string         $messages_url       Optional. Pre-built per-member messages URL. Default ''.
 * @var array          $classes            Optional. Extra CSS classes appended to `.bn-md-card`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_member_card_before', $args )
 *   - do_action( 'buddynext_part_member_card_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_member_card_args',    array $args )
 *   - apply_filters( 'buddynext_part_member_card_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'member'            => isset( $member ) ? $member : null,
	'viewer_id'         => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'is_following'      => isset( $is_following ) ? (bool) $is_following : false,
	'connection_state'  => isset( $connection_state ) ? (string) $connection_state : 'none',
	'connection_status' => isset( $connection_status ) ? (string) $connection_status : 'none',
	'is_muted'          => isset( $is_muted ) ? (bool) $is_muted : false,
	'mutual_count'      => isset( $mutual_count ) ? (int) $mutual_count : 0,
	'mutual_avatars'    => isset( $mutual_avatars ) && is_array( $mutual_avatars ) ? $mutual_avatars : array(),
	'degree'            => isset( $degree ) ? (int) $degree : 0,
	'presence'          => isset( $presence ) ? (string) $presence : 'offline',
	'member_type_label' => isset( $member_type_label ) ? (string) $member_type_label : '',
	'member_type_icon'  => isset( $member_type_icon ) ? (string) $member_type_icon : '',
	'avatar_tone'       => isset( $avatar_tone ) ? (string) $avatar_tone : 'accent',
	'bio'               => isset( $bio ) ? (string) $bio : '',
	'profile_url'       => isset( $profile_url ) ? (string) $profile_url : '',
	'cover_url'         => isset( $cover_url ) ? (string) $cover_url : '',
	'avatar_url'        => isset( $avatar_url ) ? (string) $avatar_url : '',
	'initials'          => isset( $initials ) ? (string) $initials : '',
	'messages_url'      => isset( $messages_url ) ? (string) $messages_url : '',
	'classes'           => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_member_card_args', $args );

if ( null === $args['member'] || ! isset( $args['member']->ID ) ) {
	return;
}

$bn_classes = array_merge( array( 'bn-card', 'bn-md-card' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_member_card_classes', $bn_classes, $args );
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

$bn_member        = $args['member'];
$bn_member_id     = (int) $bn_member->ID;
$bn_display_name  = (string) $bn_member->display_name;
$bn_member_login  = (string) $bn_member->user_login;
$bn_viewer_id     = (int) $args['viewer_id'];
$bn_is_following  = (bool) $args['is_following'];
$bn_conn_state    = (string) $args['connection_state'];
$bn_conn_status   = (string) $args['connection_status'];
$bn_is_muted      = (bool) $args['is_muted'];
$bn_mutual        = (int) $args['mutual_count'];
$bn_presence_attr = (string) $args['presence'];
$bn_type_label    = (string) $args['member_type_label'];
$bn_type_icon     = \BuddyNext\MemberTypes\MemberTypeService::render_icon_svg( (string) $args['member_type_icon'] );
$bn_avatar_tone   = (string) $args['avatar_tone'];
$bn_bio           = (string) $args['bio'];
$bn_profile_url   = (string) $args['profile_url'];
$bn_cover_url     = (string) $args['cover_url'];

// Honour the member's who_can_follow preference so the Follow button is hidden
// when they accept no follows (and the viewer is not already a follower).
// Directory scale: this checks the cached who_can_follow usermeta (primed by the
// member query) rather than the full PrivacyService::can_follow(), which would
// add a per-card bn_blocks query (N+1 across a large directory). Block
// enforcement stays server-side in FollowService::follow(); the single-render
// profile hero + follow-button partial use the full can_follow().
$bn_can_follow = true;
// Mirror who_can_connect (everyone | followers | nobody) so the Connect CTA is
// not offered when the target forbids it. Reads the page-primed usermeta like
// the follow gate above (no extra query); 'followers' resolves via the viewer's
// existing follow relationship ($bn_is_following). Block enforcement + the full
// PrivacyService::can_connect() still run server-side in ConnectionService.
$bn_can_connect = true;
if ( $bn_viewer_id > 0 && $bn_viewer_id !== $bn_member_id ) {
	$bn_privacy    = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
	$bn_can_follow = ! $bn_privacy || ! method_exists( $bn_privacy, 'get_preference' )
		|| 'everyone' === $bn_privacy->get_preference( $bn_member_id, 'who_can_follow' );
	if ( $bn_privacy && method_exists( $bn_privacy, 'get_preference' ) ) {
		$bn_connect_pref = $bn_privacy->get_preference( $bn_member_id, 'who_can_connect' );
		$bn_can_connect  = 'everyone' === $bn_connect_pref
			|| ( 'followers' === $bn_connect_pref && $bn_is_following );
	}
}
$bn_avatar_url = (string) $args['avatar_url'];

// Cover tone — same brand-safe blue→green→warm gradient set the space cards
// use. Deterministic per member; filterable so a site can force a uniform
// cover or its own scheme later. The member's uploaded cover image overrides.
$bn_card_tones = array( 'sky', 'cyan', 'emerald', 'lime', 'amber', 'coral' );
$bn_card_tone  = $bn_card_tones[ $bn_member_id % count( $bn_card_tones ) ];
/**
 * Filter the member-card cover tone (sky|cyan|emerald|lime|amber|coral).
 *
 * @param string $tone      Deterministic default tone.
 * @param int    $member_id Member ID.
 */
$bn_card_tone     = (string) apply_filters( 'buddynext_member_card_cover_tone', $bn_card_tone, $bn_member_id );
$bn_initials_text = (string) $args['initials'];
$bn_messages_url  = (string) $args['messages_url'];

$bn_card_ctx = wp_json_encode(
	array(
		'userId'      => $bn_member_id,
		'displayName' => $bn_display_name,
		'isFollowing' => $bn_is_following,
		'connection'  => $bn_conn_state,
		'menuOpen'    => false,
		'isMuted'     => $bn_is_muted,
	)
);
if ( false === $bn_card_ctx ) {
	$bn_card_ctx = '{}';
}

do_action( 'buddynext_part_member_card_before', $args );
?>
<article
	class="<?php echo esc_attr( $bn_class ); ?>"
	data-interactive
	role="listitem"
	data-user-id="<?php echo esc_attr( (string) $bn_member_id ); ?>"
	data-wp-context="<?php echo esc_attr( (string) $bn_card_ctx ); ?>"
	data-wp-on-document--click="actions.closeCardMenuOnOutside"
>

	<?php // Secondary actions — kebab menu pinned top-right (Message / Mute / Block / Report). ?>
	<?php if ( $bn_viewer_id > 0 && $bn_viewer_id !== $bn_member_id ) : ?>
		<div class="bn-md-card__menu-wrap">
			<button
				type="button"
				class="bn-md-card__menu"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member display name */ __( 'More actions for %s', 'buddynext' ), $bn_display_name ) ); ?>"
				aria-haspopup="true"
				aria-expanded="false"
				data-wp-on--click="actions.toggleCardMenu"
				data-wp-bind--aria-expanded="state.cardMenuExpanded"
			><?php buddynext_icon( 'more-horizontal' ); ?></button>
			<div
				class="bn-md-card__menu-pop"
				role="menu"
				data-wp-bind--hidden="!state.cardMenuOpen"
				hidden
			>
				<?php if ( 'accepted' === $bn_conn_status && '' !== $bn_messages_url && \BuddyNext\Messages\MessagesData::entry_enabled() ) : ?>
					<a
						class="bn-md-card__menu-item"
						role="menuitem"
						href="<?php echo esc_url( $bn_messages_url ); ?>"
					><?php esc_html_e( 'Message', 'buddynext' ); ?></a>
				<?php endif; ?>
				<button
					type="button"
					class="bn-md-card__menu-item"
					role="menuitem"
					data-wp-on--click="actions.toggleMute"
					data-wp-text="state.cardMuteLabel"
				><?php echo esc_html( $bn_is_muted ? __( 'Unmute', 'buddynext' ) : __( 'Mute', 'buddynext' ) ); ?></button>
				<button
					type="button"
					class="bn-md-card__menu-item bn-md-card__menu-item--danger"
					role="menuitem"
					data-wp-on--click="actions.openBlock"
				><?php esc_html_e( 'Block', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-md-card__menu-item bn-md-card__menu-item--danger"
					role="menuitem"
					data-wp-on--click="actions.openReport"
				><?php esc_html_e( 'Report', 'buddynext' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<div class="bn-md-card__cover" data-tone="<?php echo esc_attr( $bn_card_tone ); ?>"<?php echo '' !== $bn_cover_url ? ' style="background-image:url(\'' . esc_url( $bn_cover_url ) . '\')"' : ''; ?> aria-hidden="true"></div>

	<a href="<?php echo esc_url( $bn_profile_url ); ?>" class="bn-md-card__avatar-link" tabindex="-1" aria-hidden="true">
		<span
			class="bn-avatar bn-md-card__avatar"
			data-size="xl"
			data-presence="<?php echo esc_attr( $bn_presence_attr ); ?>"
			data-tone="<?php echo esc_attr( $bn_avatar_tone ); ?>"
		>
			<?php if ( '' !== $bn_avatar_url ) : ?>
				<img
					src="<?php echo esc_url( $bn_avatar_url ); ?>"
					alt=""
					width="72"
					height="72"
					loading="lazy"
					decoding="async"
				>
			<?php else : ?>
				<?php echo esc_html( $bn_initials_text ); ?>
			<?php endif; ?>
			<?php
			$bn_md_avatar_overlay = (string) apply_filters( 'buddynext_avatar_overlay_html', '', $bn_member_id, 'xl' );
			if ( '' !== $bn_md_avatar_overlay ) {
				echo $bn_md_avatar_overlay; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract
			}
			?>
		</span>
	</a>

	<div class="bn-md-card__body">

	<?php // Identity group: `display:contents` in grid (no layout effect), a flex column in list view. ?>
	<div class="bn-md-card__identity">
		<h3 class="bn-md-card__name">
			<a href="<?php echo esc_url( $bn_profile_url ); ?>">
				<?php echo esc_html( $bn_display_name ); ?>
			</a>
			<?php
			$bn_md_degree = (int) $args['degree'];
			if ( $bn_md_degree > 0 && $bn_md_degree <= 2 ) :
				$bn_md_degree_label = 1 === $bn_md_degree ? __( '1st', 'buddynext' ) : __( '2nd', 'buddynext' );
				?>
				<span class="bn-md-card__degree" data-degree="<?php echo esc_attr( (string) $bn_md_degree ); ?>"><?php echo esc_html( $bn_md_degree_label ); ?></span>
			<?php endif; ?>
		</h3>

		<p class="bn-md-card__handle">@<?php echo esc_html( $bn_member_login ); ?></p>

		<?php
		// Profession/headline tagline — the single most identifying line on a
		// member card (who this person is), mirroring the profile hero. Read from
		// the bn_headline usermeta that ProfileService::save_profile() keeps in
		// lockstep with the canonical value, so directory browsing isn't a wall of
		// names with no context.
		$bn_md_headline = (string) get_user_meta( $bn_member_id, 'bn_headline', true );
		if ( '' !== $bn_md_headline ) :
			?>
			<p class="bn-md-card__headline"><?php echo esc_html( $bn_md_headline ); ?></p>
		<?php endif; ?>
	</div>

	<?php
	$bn_md_meta = (string) apply_filters( 'buddynext_member_card_meta_html', '', $bn_member_id, $args );
	if ( '' !== $bn_md_meta ) :
		?>
		<div class="bn-md-card__meta-overlay"><?php echo $bn_md_meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by hooked plugin per filter contract ?></div>
		<?php
	endif;
	?>

	<?php if ( '' !== $bn_type_label ) : ?>
		<span class="bn-badge bn-md-card__type" data-tone="accent">
			<?php if ( '' !== $bn_type_icon ) : ?>
				<span class="bn-type-badge__icon" aria-hidden="true"><?php echo $bn_type_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses-sanitized by MemberTypeService::render_icon_svg(). ?></span>
			<?php endif; ?>
			<?php echo esc_html( $bn_type_label ); ?>
		</span>
	<?php endif; ?>

	<?php if ( '' !== $bn_bio ) : ?>
		<p class="bn-md-card__bio"><?php echo esc_html( wp_trim_words( $bn_bio, 18 ) ); ?></p>
	<?php endif; ?>

	<?php
	if ( $bn_mutual > 0 ) :
		$bn_mutual_avatars = array_slice(
			array_filter( (array) $args['mutual_avatars'], 'is_array' ),
			0,
			3
		);
		?>
		<p class="bn-md-card__mutual">
			<?php if ( ! empty( $bn_mutual_avatars ) ) : ?>
				<span class="bn-md-card__mutual-pile" aria-hidden="true">
					<?php foreach ( $bn_mutual_avatars as $bn_mu ) : ?>
						<?php
						$bn_mu_avatar = isset( $bn_mu['avatar_url'] ) ? (string) $bn_mu['avatar_url'] : '';
						$bn_mu_name   = isset( $bn_mu['name'] ) ? (string) $bn_mu['name'] : '';
						if ( '' === $bn_mu_avatar ) {
							continue;
						}
						?>
						<img
							class="bn-md-card__mutual-pile-avatar"
							src="<?php echo esc_url( $bn_mu_avatar ); ?>"
							alt=""
							title="<?php echo esc_attr( $bn_mu_name ); ?>"
							width="20"
							height="20"
							loading="lazy"
						>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
			<span class="bn-md-card__mutual-text">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of mutual connections */
						_n( '%d mutual connection', '%d mutual connections', $bn_mutual, 'buddynext' ),
						$bn_mutual
					)
				);
				?>
			</span>
		</p>
	<?php endif; ?>

	<div class="bn-md-card__actions">
		<?php if ( 0 === $bn_viewer_id ) : ?>
			<a
				class="bn-btn"
				data-variant="primary"
				data-size="sm"
				href="<?php echo esc_url( wp_login_url( $bn_profile_url ) ); ?>"
			>
				<?php esc_html_e( 'View profile', 'buddynext' ); ?>
			</a>
		<?php elseif ( $bn_viewer_id === $bn_member_id ) : ?>
			<a
				class="bn-btn"
				data-variant="secondary"
				data-size="sm"
				href="<?php echo esc_url( \BuddyNext\Core\PageRouter::edit_profile_url( $bn_member_id ) ); ?>"
			>
				<?php esc_html_e( 'Edit profile', 'buddynext' ); ?>
			</a>
		<?php else : ?>
			<?php if ( $bn_can_follow || $bn_is_following ) : ?>
			<button
				type="button"
				class="bn-btn bn-md-card__follow"
				data-size="sm"
				data-wp-bind--data-variant="state.cardFollowVariant"
				data-wp-bind--data-state="state.cardFollowState"
				data-wp-text="state.cardFollowLabel"
				data-wp-on--click="actions.toggleFollow"
				data-wp-bind--aria-busy="context.busy"
				data-wp-bind--disabled="context.busy"
			><?php echo $bn_is_following ? esc_html__( 'Following', 'buddynext' ) : esc_html__( 'Follow', 'buddynext' ); ?></button>
			<?php endif; ?>

			<?php // Connection control — 5-state, reactive. ?>
			<?php if ( $bn_can_connect || in_array( $bn_conn_state, array( 'pending-sent', 'accepted' ), true ) ) : ?>
			<button
				type="button"
				class="bn-btn bn-md-card__connect-primary"
				data-size="sm"
				data-wp-bind--hidden="!state.cardShowConnect"
				data-wp-bind--data-variant="state.cardConnectVariant"
				data-wp-bind--data-state="state.cardConnectState"
				data-wp-text="state.cardConnectLabel"
				data-wp-on--click="actions.toggleConnection"
				data-wp-bind--aria-busy="context.busy"
				data-wp-bind--disabled="context.busy"
				<?php echo in_array( $bn_conn_state, array( 'none', 'pending-sent', 'accepted' ), true ) ? '' : 'hidden'; ?>
			>
				<?php
				if ( 'accepted' === $bn_conn_state ) {
					esc_html_e( 'Connected', 'buddynext' );
				} elseif ( 'pending-sent' === $bn_conn_state ) {
					esc_html_e( 'Requested', 'buddynext' );
				} else {
					esc_html_e( 'Connect', 'buddynext' );
				}
				?>
			</button>
			<?php endif; ?>

			<span
				class="bn-md-card__connect-decide"
				data-wp-bind--hidden="!state.cardShowReceived"
				<?php echo 'pending-received' === $bn_conn_state ? '' : 'hidden'; ?>
			>
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-wp-on--click="actions.acceptConnection"
					data-wp-bind--aria-busy="context.busy"
					data-wp-bind--disabled="context.busy"
				><?php esc_html_e( 'Accept', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="sm"
					data-wp-on--click="actions.declineConnection"
					data-wp-bind--aria-busy="context.busy"
					data-wp-bind--disabled="context.busy"
				><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
			</span>

		<?php endif; ?>
	</div>

	</div><!-- /.bn-md-card__body -->

</article>
<?php
do_action( 'buddynext_part_member_card_after', $args );
