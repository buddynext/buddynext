<?php
/**
 * BuddyNext template part: space-hero.
 *
 * Renders the space hero card: cover image, emblem + identity head
 * (name, privacy badge, category handle), the viewer action cluster
 * (notification popover, invite/settings/join/leave/request CTAs), the
 * four-tile stats band (delegated to `parts/space-stats-strip.php`), and
 * the tab navigation strip (the shared `parts/nav-bar.php`, fed by the unified
 * Nav registry — same component the member profile uses).
 *
 * Used by: templates/spaces/home.php.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var object $space            Required. Space row (with category_slug,
 *                               category_name, cover_image_url, name,
 *                               type, slug, member_count, created_at).
 * @var int    $space_id         Required. Current space ID.
 * @var int    $current_user_id  Optional. Viewing user ID. Default 0.
 * @var bool   $is_member        Optional. Viewer is an active member. Default false.
 * @var bool   $is_owner         Optional. Viewer is owner/moderator. Default false.
 * @var bool   $is_pending       Optional. Viewer has a pending join request. Default false.
 * @var bool   $is_guest         Optional. Viewer is logged out. Default false.
 * @var string $privacy_label    Required. Localised privacy label.
 * @var string $privacy_tone     Required. Privacy badge tone (info|warn|danger).
 * @var string $notif_pref       Optional. Per-space notification pref. Default 'all'.
 * @var array  $stats            Required. List of stat-tile descriptors for the
 *                               stats band. Passed straight to space-stats-strip.
 * @var string $active_tab       Optional. Slug of the active tab. Default 'feed'.
 * @var array  $nav_items        Required. Resolved Nav primary items (NavItem[])
 *                               for the shared parts/nav-bar.php tab bar.
 * @var array  $classes          Optional. Extra CSS classes appended to `.bn-sh-hero`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_space_hero_before', $args )
 *   - do_action( 'buddynext_part_space_hero_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_space_hero_args',    array $args )
 *   - apply_filters( 'buddynext_part_space_hero_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

$args = array(
	'space'           => isset( $space ) ? $space : null,
	'space_id'        => isset( $space_id ) ? (int) $space_id : 0,
	'current_user_id' => isset( $current_user_id ) ? (int) $current_user_id : 0,
	'is_member'       => isset( $is_member ) ? (bool) $is_member : false,
	'is_owner'        => isset( $is_owner ) ? (bool) $is_owner : false,
	'is_pending'      => isset( $is_pending ) ? (bool) $is_pending : false,
	'is_invited'      => isset( $is_invited ) ? (bool) $is_invited : false,
	'is_guest'        => isset( $is_guest ) ? (bool) $is_guest : false,
	'privacy_label'   => isset( $privacy_label ) ? (string) $privacy_label : '',
	'privacy_tone'    => isset( $privacy_tone ) ? (string) $privacy_tone : 'info',
	'notif_pref'      => isset( $notif_pref ) ? (string) $notif_pref : 'all',
	'stats'           => isset( $stats ) && is_array( $stats ) ? $stats : array(),
	'active_tab'      => isset( $active_tab ) ? (string) $active_tab : 'feed',
	'nav_items'       => isset( $nav_items ) && is_array( $nav_items ) ? $nav_items : array(),
	'classes'         => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_space_hero_args', $args );

if ( null === $args['space'] || $args['space_id'] <= 0 ) {
	return;
}

$bn_classes = array_merge( array( 'bn-sh-hero' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_space_hero_classes', $bn_classes, $args );
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

$bn_space         = $args['space'];
$bn_space_id      = (int) $args['space_id'];
$bn_is_member     = (bool) $args['is_member'];
$bn_is_owner      = (bool) $args['is_owner'];
$bn_is_pending    = (bool) $args['is_pending'];
$bn_is_invited    = (bool) $args['is_invited'];
$bn_is_guest      = (bool) $args['is_guest'];
$bn_privacy_label = (string) $args['privacy_label'];
$bn_privacy_tone  = (string) $args['privacy_tone'];
$bn_notif_pref    = (string) $args['notif_pref'];

// Owner-set brand colour (the registered `color` space field). When present it
// drives the hero accent through a CSS custom property; when empty the stylesheet
// falls back to the id-derived tone it has always used. Read once, applied on the
// hero root so both the cover overlay and the emblem can pick it up.
$bn_brand_color = (string) buddynext_get_space_field( $bn_space_id, 'brand_color' );

do_action( 'buddynext_part_space_hero_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>"<?php echo '' !== $bn_brand_color ? ' style="--bn-space-brand:' . esc_attr( $bn_brand_color ) . ';"' : ''; ?>>
	<?php
	// Cover framing. The image is rendered as an <img> (not a background) so the
	// owner's focal point pans and zooms it exactly the way a member cover does -
	// object-position for the pan, transform:scale for the zoom. Values re-clamped
	// here (defence in depth) and mirror templates/parts/profile-hero.php. The
	// gradient on .bn-sh-hero__cover remains the fallback when no image is set.
	$bn_sh_cover_style = '';
	if ( ! empty( $bn_space->cover_image_url ) ) {
		$bn_sh_focal       = (array) get_space_meta( $bn_space_id, 'buddynext_cover_focal', true );
		$bn_sh_fx          = isset( $bn_sh_focal['x'] ) ? max( 0.0, min( 100.0, (float) $bn_sh_focal['x'] ) ) : 50.0;
		$bn_sh_fy          = isset( $bn_sh_focal['y'] ) ? max( 0.0, min( 100.0, (float) $bn_sh_focal['y'] ) ) : 50.0;
		$bn_sh_zoom        = isset( $bn_sh_focal['zoom'] ) ? max( 1.0, min( 3.0, (float) $bn_sh_focal['zoom'] ) ) : 1.0;
		$bn_sh_cover_style = sprintf(
			'object-position:%s%% %s%%;transform:scale(%s);',
			esc_attr( (string) $bn_sh_fx ),
			esc_attr( (string) $bn_sh_fy ),
			esc_attr( (string) $bn_sh_zoom )
		);
	}
	?>
	<div class="bn-sh-hero__cover<?php echo empty( $bn_space->cover_image_url ) ? '' : ' bn-sh-hero__cover--has-image'; ?>">
		<?php if ( ! empty( $bn_space->cover_image_url ) ) : ?>
			<img class="bn-sh-hero__cover-img" src="<?php echo esc_url( (string) $bn_space->cover_image_url ); ?>" alt="" aria-hidden="true" style="<?php echo esc_attr( $bn_sh_cover_style ); ?>">
		<?php endif; ?>
		<span class="bn-sh-hero__cover-tone" aria-hidden="true"></span>
	</div>

	<div class="bn-sh-hero__head">
		<?php
		// Resolve emblem content. If the space has an avatar_url, render
		// the image. Otherwise prefer the category icon. If neither is
		// available, fall back to the first letter of the space name so
		// the emblem slot is never visually empty.
		$bn_sh_emblem = '';
		if ( ! empty( $bn_space->avatar_url ) ) {
			$bn_sh_emblem = sprintf(
				'<img src="%s" alt="" loading="lazy">',
				esc_url( $bn_space->avatar_url )
			);
		} elseif ( ! empty( $bn_space->category_slug ) ) {
			$bn_sh_emblem = wp_kses(
				bn_space_category_icon( $bn_space->category_slug ?? '' ),
				\BuddyNext\Core\IconService::allowed_tags()
			);
		} else {
			$bn_sh_emblem = sprintf(
				'<span class="bn-sh-hero__emblem-letter">%s</span>',
				esc_html( mb_strtoupper( mb_substr( (string) $bn_space->name, 0, 1 ) ) )
			);
		}
		?>
		<div class="bn-sh-hero__emblem" aria-hidden="true">
			<?php echo $bn_sh_emblem; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- branches above each escape their content. ?>
		</div>

		<div class="bn-sh-hero__info">
			<h1 class="bn-sh-hero__name"
				aria-label="<?php echo esc_attr( sprintf( '%s (%s)', $bn_space->name, $bn_privacy_label ) ); ?>"
			><?php echo esc_html( $bn_space->name ); ?><span class="bn-badge" data-tone="<?php echo esc_attr( $bn_privacy_tone ); ?>"><?php echo esc_html( $bn_privacy_label ); ?></span></h1>
			<?php
			// Breadcrumb — only on a sub-space, giving the parent context a member
			// expects (Slack/Notion-style "Parent > This space"). Placed BELOW the
			// title so the title sits at the same position as on a root space (no
			// vertical drift). parent_summary() is viewer-scoped, so a secret parent
			// the viewer cannot see resolves to null and the crumb is omitted.
			$bn_sh_parent = ! empty( $bn_space->parent_id )
				? ( new \BuddyNext\Spaces\SpaceService() )->parent_summary( (int) $bn_space->parent_id, get_current_user_id() )
				: null;
			if ( is_array( $bn_sh_parent ) && ! empty( $bn_sh_parent['slug'] ) ) :
				?>
				<nav class="bn-sh-hero__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'buddynext' ); ?>">
					<a class="bn-sh-hero__crumb" href="<?php echo esc_url( buddynext_space_url( (string) $bn_sh_parent['slug'] ) ); ?>">
						<?php echo esc_html( (string) $bn_sh_parent['name'] ); ?>
					</a>
					<span class="bn-sh-hero__crumb-sep" aria-hidden="true"><?php buddynext_icon( 'chevron-right' ); ?></span>
					<span class="bn-sh-hero__crumb bn-sh-hero__crumb--current" aria-current="page">
						<?php echo esc_html( (string) $bn_space->name ); ?>
					</span>
				</nav>
			<?php endif; ?>
			<?php if ( ! empty( $bn_space->category_name ) ) : ?>
				<div class="bn-sh-hero__handle">
					<?php buddynext_icon( 'hash' ); ?>
					<?php echo esc_html( $bn_space->category_name ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="bn-sh-hero__actions" data-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>">
			<?php if ( $bn_is_guest ) : ?>
				<a
					href="<?php echo esc_url( PageRouter::auth_url() . '?redirect_to=' . rawurlencode( buddynext_space_url( $bn_space->slug ) ) ); ?>"
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
				><?php esc_html_e( 'Log in to join', 'buddynext' ); ?></a>
			<?php elseif ( $bn_is_member ) : ?>
				<div class="bn-sh-notif" data-bn-notif-popover>
					<button
						type="button"
						class="bn-btn"
						data-variant="ghost"
						data-size="sm"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Notification preferences', 'buddynext' ); ?>"
						data-bn-notif-trigger
						data-wp-on--click="actions.toggleNotifPopover"
					><?php buddynext_icon( 'bell' ); ?></button>
					<ul class="bn-sh-notif__list" role="listbox" hidden data-bn-notif-list>
						<?php
						$bn_notif_options = array(
							'all'           => __( 'All activity', 'buddynext' ),
							'mentions_only' => __( 'Mentions only', 'buddynext' ),
							'none'          => __( 'None', 'buddynext' ),
						);
						foreach ( $bn_notif_options as $bn_pref_val => $bn_pref_label ) :
							?>
							<li>
								<button
									type="button"
									class="bn-sh-notif__option"
									role="option"
									aria-selected="<?php echo ( $bn_notif_pref === $bn_pref_val ) ? 'true' : 'false'; ?>"
									data-bn-notif-pref="<?php echo esc_attr( $bn_pref_val ); ?>"
									data-wp-on--click="actions.setNotificationPref"
								><?php echo esc_html( $bn_pref_label ); ?></button>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $bn_is_guest ) : ?>
				<?php // Guests already get the "Log in to join" CTA from the first chain above; show no join/request action here, otherwise both buttons render at once. ?>

			<?php elseif ( $bn_is_owner ) : ?>
				<button
					type="button"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-wp-on--click="actions.openInviteModal"
				><?php buddynext_icon( 'user-plus' ); ?> <?php esc_html_e( 'Invite', 'buddynext' ); ?></button>
				<a
					href="<?php echo esc_url( buddynext_space_settings_url( $bn_space->slug ) ); ?>"
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
				><?php buddynext_icon( 'settings' ); ?> <?php esc_html_e( 'Settings', 'buddynext' ); ?></a>

			<?php elseif ( $bn_is_member ) : ?>
				<button
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-current-state="joined"
					data-wp-on--click="actions.leaveSpace"
					aria-label="<?php esc_attr_e( 'Joined - click to leave', 'buddynext' ); ?>"
				><?php buddynext_icon( 'check' ); ?> <?php esc_html_e( 'Joined', 'buddynext' ); ?></button>

			<?php elseif ( $bn_is_pending ) : ?>
				<button
					class="bn-btn"
					data-variant="secondary"
					data-size="sm"
					data-current-state="pending"
					data-wp-on--click="actions.cancelJoinRequest"
				><?php esc_html_e( 'Request pending', 'buddynext' ); ?></button>

			<?php elseif ( $bn_is_invited ) : ?>
				<?php // Invited: the invitation banner on the space home owns Accept/Decline, so the hero shows no join CTA. ?>

			<?php elseif ( ! buddynext_service( 'space_members' )->can_join( $bn_space, get_current_user_id() ) ) : ?>
				<?php
				// The gate refuses this member, so NEITHER join CTA belongs here — the
				// paywall beside it is the call to action.
				//
				// can_join() runs the buddynext_can_join_space filter, which is the
				// plan gate and not the open/private distinction: a plain private
				// space answers true and still reaches "Request to join" below. It is
				// false only when something will actually refuse the attempt.
				//
				// Checking it on the 'open' branch alone was not enough. A plan-gated
				// OPEN space failed that test and fell through to the else, so the
				// member was offered "Request to join" instead — a second wrong button
				// in place of the first, and one that also describes the space as
				// private when it is open.
				?>

			<?php elseif ( 'open' === $bn_space->type ) : ?>
				<button
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-current-state="join"
					data-wp-on--click="actions.joinSpace"
				><?php esc_html_e( 'Join space', 'buddynext' ); ?></button>

			<?php else : ?>
				<button
					class="bn-btn"
					data-variant="primary"
					data-size="sm"
					data-current-state="request"
					data-wp-on--click="actions.requestJoin"
				><?php esc_html_e( 'Request to join', 'buddynext' ); ?></button>
			<?php endif; ?>
		</div>
	</div>

	<?php
	buddynext_get_template(
		'parts/space-stats-strip.php',
		array(
			'stats'    => (array) $args['stats'],
			'space_id' => $bn_space_id,
		)
	);

	buddynext_get_template(
		'parts/nav-bar.php',
		array(
			'items'         => (array) $args['nav_items'],
			'active'        => (string) $args['active_tab'],
			'tablist_label' => __( 'Space sections', 'buddynext' ),
		)
	);
	?>
</section>

<?php if ( $bn_is_owner ) : ?>
	<?php
	/*
	 * Invite-member modal. The hero "Invite" button dispatches
	 * actions.openInviteModal -> openSpaceModal('invite-member'), which needs a
	 * [data-bn-modal="invite-member"] element on the page or it silently no-ops.
	 * Owner/mod-only, matching the button's own gate above. Submits to
	 * POST /buddynext/v1/spaces/{id}/invite with a username/email identifier,
	 * mirroring the settings-panel invite form.
	 */
	?>
	<div
		class="bn-modal-backdrop"
		role="dialog"
		aria-modal="true"
		aria-labelledby="bn-invite-member-title"
		hidden
		data-bn-modal="invite-member"
		data-bn-space-id="<?php echo esc_attr( (string) $bn_space_id ); ?>"
	>
		<div class="bn-modal__panel" data-size="sm">
			<header class="bn-modal__head">
				<h2 class="bn-modal__title" id="bn-invite-member-title">
					<?php esc_html_e( 'Invite a member', 'buddynext' ); ?>
				</h2>
				<button
					type="button"
					class="bn-modal__close"
					aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
					data-bn-modal-close
				><?php buddynext_icon( 'x' ); ?></button>
			</header>

			<div class="bn-modal__body">
				<p><?php esc_html_e( 'Enter a username or email address to send an invitation to join this space.', 'buddynext' ); ?></p>
				<label class="bn-sr-only" for="bn-invite-identifier">
					<?php esc_html_e( 'Username or email address', 'buddynext' ); ?>
				</label>
				<input
					type="text"
					id="bn-invite-identifier"
					class="bn-input"
					autocomplete="off"
					placeholder="<?php esc_attr_e( 'Username or email address', 'buddynext' ); ?>"
					data-bn-invite-identifier
					data-wp-on--keydown="actions.inviteMemberKeydown"
				>
				<p class="bn-modal__error" data-bn-invite-error hidden></p>
			</div>

			<div class="bn-modal__foot">
				<button
					type="button"
					class="bn-btn"
					data-variant="ghost"
					data-size="md"
					data-bn-modal-close
				><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
				<button
					type="button"
					class="bn-btn"
					data-variant="primary"
					data-size="md"
					data-bn-invite-submit
					data-wp-on--click="actions.inviteMember"
				><?php esc_html_e( 'Send invite', 'buddynext' ); ?></button>
			</div>
		</div>
	</div>
<?php endif; ?>
<?php
do_action( 'buddynext_part_space_hero_after', $args );
