<?php
/**
 * BuddyNext template part: space-hero.
 *
 * Renders the space hero card: cover image, emblem + identity head
 * (name, privacy badge, category handle), the viewer action cluster
 * (notification popover, invite/settings/join/leave/request CTAs), the
 * four-tile stats band (delegated to `parts/space-stats-strip.php`), and
 * the tab navigation strip (delegated to `parts/space-tab-bar.php`).
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
 * @var array  $tabs             Required. Tab map for space-tab-bar.
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
	'is_guest'        => isset( $is_guest ) ? (bool) $is_guest : false,
	'privacy_label'   => isset( $privacy_label ) ? (string) $privacy_label : '',
	'privacy_tone'    => isset( $privacy_tone ) ? (string) $privacy_tone : 'info',
	'notif_pref'      => isset( $notif_pref ) ? (string) $notif_pref : 'all',
	'stats'           => isset( $stats ) && is_array( $stats ) ? $stats : array(),
	'active_tab'      => isset( $active_tab ) ? (string) $active_tab : 'feed',
	'tabs'            => isset( $tabs ) && is_array( $tabs ) ? $tabs : array(),
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
$bn_is_guest      = (bool) $args['is_guest'];
$bn_privacy_label = (string) $args['privacy_label'];
$bn_privacy_tone  = (string) $args['privacy_tone'];
$bn_notif_pref    = (string) $args['notif_pref'];

do_action( 'buddynext_part_space_hero_before', $args );
?>
<section class="<?php echo esc_attr( $bn_class ); ?>">
	<div class="bn-sh-hero__cover"<?php echo empty( $bn_space->cover_image_url ) ? '' : ' style="background-image:url(' . esc_url( $bn_space->cover_image_url ) . ');background-size:cover;background-position:center;"'; ?>>
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

			<?php if ( $bn_is_owner ) : ?>
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
					data-variant="ghost"
					data-size="sm"
					data-current-state="pending"
					data-wp-on--click="actions.cancelJoinRequest"
				><?php esc_html_e( 'Request pending', 'buddynext' ); ?></button>

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
		'parts/space-tab-bar.php',
		array(
			'space_id'   => $bn_space_id,
			'active_tab' => (string) $args['active_tab'],
			'tabs'       => (array) $args['tabs'],
		)
	);
	?>
</section>
<?php
do_action( 'buddynext_part_space_hero_after', $args );
