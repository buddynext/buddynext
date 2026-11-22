<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext - mobile bottom bar (.bn-mobile-nav) and its More sheet.
 *
 * Included by partials/nav.php on every hub, and on its own by the Jetonomy bridge on
 * Jetonomy pages, so a phone member has the same bar everywhere under BuddyNext.
 *
 * Context variables (all optional, resolved here when missing):
 *   $bn_nav_urls         array   Section URLs keyed feed/explore/members/spaces/notifications/messages.
 *   $bn_nav_active       string  Main-nav key of the active section.
 *   $bn_nav_current_user int     Viewer id.
 *   $bn_unread_notifs    int     Unseen notifications count for the Alerts badge.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Core\PageRouter;

if ( ! isset( $bn_nav_urls ) || ! is_array( $bn_nav_urls ) ) {
	$bn_nav_urls = array(
		'feed'          => PageRouter::activity_url(),
		'explore'       => PageRouter::explore_url(),
		'members'       => PageRouter::people_url(),
		'spaces'        => PageRouter::spaces_url(),
		'notifications' => PageRouter::notifications_url(),
		'messages'      => PageRouter::messages_url(),
	);
}
$bn_nav_active       = isset( $bn_nav_active ) ? (string) $bn_nav_active : '';
$bn_nav_current_user = isset( $bn_nav_current_user ) ? (int) $bn_nav_current_user : get_current_user_id();
if ( ! isset( $bn_unread_notifs ) ) {
	$bn_unread_notifs = $bn_nav_current_user ? (int) buddynext_service( 'notifications' )->unseen_count( $bn_nav_current_user ) : 0;
}

// The bar renders for EVERY visitor, not only members.
//
// It used to be wrapped in `if ( $bn_nav_current_user )`. Combined with
// bn-shell.css hiding `.bn-app__rail` at `max-width: 768px`, that left a
// logged-out visitor on a phone or an iPad in portrait with no BuddyNext
// navigation on screen at all — only the host theme's hamburger. The rail is
// the desktop nav and the bar is its mobile replacement, so gating the
// replacement on login removed the only nav a guest had.
//
// Membership is now decided per SLOT below (Create / Alerts / Profile need an
// account; Feed / Spaces do not), which is the level the distinction actually
// belongs at. The `--bn-mobile-nav-clearance` padding in bn-shell.css was
// already applied for guests, so this also removes the band of dead space that
// was being reserved for a bar that never rendered.
?>
	<?php
	// Curated 5-slot bottom bar. Kept data-driven so Settings → Navigation
	// (mobile scope) can hide/relabel the slots whose slug it controls
	// (feed/spaces/notifications) via the buddynext_mobile_nav_items filter —
	// Nav\NavOverrides::apply_mobile_items(). The centre Create button and the
	// Profile shortcut are not nav tabs, so they are never overridable, and the
	// slot order is fixed (the centre Create button must stay centred).
	// Hide the Spaces slot when the feature is off (the Spaces page itself is
	// already guarded by PageRouter), so the bottom bar never links to a
	// disabled surface.
	//
	// EXACTLY FIVE SLOTS — do not add a sixth. Create is centred by arithmetic,
	// not by a CSS offset: it is `flex: 0 0 44px` between two `flex: 1` items on
	// each side, so with 5 slots it lands on the viewport centre on its own. A
	// sixth slot silently breaks that — Messages was previously a 6th item, which
	// pushed Create 35px left of centre at 390px and squeezed every label to 69px.
	// Messages is NOT in the bar: it is in the More sheet, with every other rail
	// destination. If a slot must be added, one has to come out.
	$bn_spaces_enabled = ! function_exists( 'buddynext_service' )
		|| ! is_object( buddynext_service( 'features' ) )
		|| buddynext_service( 'features' )->is_enabled( 'spaces' );

	$bn_mobile_items = array(
		array(
			'key'   => 'feed',
			'url'   => $bn_nav_urls['feed'],
			'icon'  => 'home',
			'label' => __( 'Activity', 'buddynext' ),
			'show'  => true,
		),
		array(
			'key'   => 'spaces',
			'url'   => $bn_nav_urls['spaces'],
			'icon'  => 'hash',
			'label' => __( 'Spaces', 'buddynext' ),
			'show'  => $bn_spaces_enabled,
		),
		// Composing, alerts and a profile all require an account. Shown only to
		// members; a guest gets the public slots plus a Log in action instead, so
		// the bar never offers a control that fails at the click (the same rule
		// that removed the guest-visible "Create a space" button).
		array(
			'key'   => 'create',
			'url'   => $bn_nav_urls['feed'] . '?compose=1',
			'icon'  => 'plus',
			'label' => __( 'Create post', 'buddynext' ),
			'show'  => $bn_nav_current_user > 0,
			'type'  => 'create',
		),
		array(
			'key'         => 'notifications',
			'url'         => $bn_nav_urls['notifications'],
			'icon'        => 'bell',
			'label'       => __( 'Notifications', 'buddynext' ),
			'show'        => $bn_nav_current_user > 0,
			'badge'       => true,
			'badge_count' => $bn_unread_notifs,
		),
		array(
			'key'   => 'members',
			'url'   => $bn_nav_urls['members'],
			'icon'  => 'users',
			'label' => __( 'Members', 'buddynext' ),
			// Guests only: takes the slot Create vacates so the bar does not
			// collapse to two items. Members reach the directory from the More sheet.
			'show'  => 0 === $bn_nav_current_user,
		),
		array(
			'key'   => 'profile',
			'url'   => PageRouter::profile_url( $bn_nav_current_user ),
			'icon'  => 'user',
			'label' => __( 'Profile', 'buddynext' ),
			'show'  => $bn_nav_current_user > 0,
		),
		array(
			'key'   => 'login',
			'url'   => PageRouter::login_url(),
			'icon'  => 'log-in',
			'label' => __( 'Log in', 'buddynext' ),
			'show'  => 0 === $bn_nav_current_user,
		),
	);

	// The mobile bar has its own key space (feed/spaces/create/notifications/
	// profile); $bn_nav_active uses the main-nav keys (feed/members/spaces/
	// notifications/messages). Map onto the mobile keys so both the active-item
	// highlight below AND the buddynext_mobile_nav_items filter receive a
	// mobile-correct value — the filter previously got the main-nav key (e.g.
	// 'members'), which no mobile slot uses.
	$bn_mobile_active_map = array(
		'feed'          => 'feed',
		'spaces'        => 'spaces',
		'notifications' => 'notifications',
		'messages'      => 'messages',
	);
	$bn_mobile_active     = isset( $bn_mobile_active_map[ $bn_nav_active ] ) ? $bn_mobile_active_map[ $bn_nav_active ] : '';

	/**
	 * Filter the mobile bottom-bar items.
	 *
	 * @param array<int,array<string,mixed>> $items  Bar item definitions. An item may set 'active' => true to mark itself current.
	 * @param string                         $active Active mobile-bar key (feed / spaces / notifications / profile / '').
	 */
	$bn_mobile_items = (array) apply_filters( 'buddynext_mobile_nav_items', $bn_mobile_items, $bn_mobile_active );

	// Split the visible bar slots from any "overflow" entries (admin-created custom
	// tabs flagged by NavOverrides::apply_mobile_items). The bar is a fixed 5-slot
	// strip with a centred Create button, so nothing else gets a slot: the Profile
	// slot folds into a "More" sheet and a "More" toggle takes the 5th slot.
	$bn_bar_items      = array();
	$bn_custom_items   = array();
	$bn_slot_keys      = array();
	$bn_overflow_items = array();
	foreach ( $bn_mobile_items as $bn_m_item ) {
		if ( ! is_array( $bn_m_item ) ) {
			continue;
		}
		$bn_slot_keys[] = (string) ( $bn_m_item['key'] ?? '' );
		if ( empty( $bn_m_item['show'] ) ) {
			continue;
		}
		if ( ! empty( $bn_m_item['overflow'] ) ) {
			$bn_custom_items[] = $bn_m_item;
		} else {
			$bn_bar_items[] = $bn_m_item;
		}
	}

	// The rest of the sheet is the rail's own resolved list, the same one its owner
	// overrides, feature switches and bridges write to, so the two cannot drift. A key
	// with a slot stays out even when the owner hid that slot on mobile. When the bar
	// shows the Members slot (a guest), the rail's `people` item stays out too.
	$bn_bar_keys = array_column( $bn_bar_items, 'key' );
	foreach ( buddynext_service( 'shell_nav' )->resolve( $bn_nav_current_user, (string) get_query_var( 'bn_hub', '' ) )['items'] as $bn_rail_item ) {
		$bn_rail_key = $bn_rail_item['key'];
		if ( ! in_array( $bn_rail_key, $bn_slot_keys, true ) && ( 'people' !== $bn_rail_key || ! in_array( 'members', $bn_bar_keys, true ) ) ) {
			$bn_overflow_items[] = $bn_rail_item;
		}
	}
	$bn_rail_keys = array_column( $bn_overflow_items, 'key' );
	foreach ( $bn_custom_items as $bn_custom_item ) {
		if ( ! in_array( (string) ( $bn_custom_item['key'] ?? '' ), $bn_rail_keys, true ) ) {
			$bn_overflow_items[] = $bn_custom_item;
		}
	}

	if ( $bn_overflow_items ) {
		foreach ( $bn_bar_items as $bn_i => $bn_it ) {
			if ( 'profile' === (string) ( $bn_it['key'] ?? '' ) ) {
				array_unshift( $bn_overflow_items, $bn_it );
				unset( $bn_bar_items[ $bn_i ] );
				break;
			}
		}
		$bn_bar_items   = array_values( $bn_bar_items );
		$bn_bar_items[] = array(
			'key'   => 'more',
			'type'  => 'more',
			'icon'  => 'more-horizontal',
			'label' => __( 'More', 'buddynext' ),
			'show'  => true,
			'badge' => array_sum( array_map( 'intval', array_column( $bn_overflow_items, 'badge' ) ) ),
		);
	}
	?>
<nav class="bn-mobile-nav" data-bn-nav
	aria-label="<?php esc_attr_e( 'Mobile navigation', 'buddynext' ); ?>">
	<?php
	foreach ( $bn_bar_items as $bn_m_item ) :
		$bn_m_key    = (string) ( $bn_m_item['key'] ?? '' );
		$bn_m_create = isset( $bn_m_item['type'] ) && 'create' === $bn_m_item['type'];
		$bn_m_more   = isset( $bn_m_item['type'] ) && 'more' === $bn_m_item['type'];
		$bn_m_badge  = ! empty( $bn_m_item['badge'] );
		// Active state rides aria-current="page" (not a bespoke class), so the one
		// generic nav-API active-sync in navigate.js can re-mark it after a client
		// nav by reading [data-bn-nav] — no mobile-specific selector in the JS.
		$bn_m_active = ! $bn_m_create && ! $bn_m_more && '' !== $bn_m_key && ( $bn_m_key === $bn_mobile_active || ! empty( $bn_m_item['active'] ) );
		$bn_m_class  = 'bn-mobile-nav__item'
			. ( $bn_m_create ? ' bn-mobile-nav__item--create' : '' )
			. ( $bn_m_more ? ' bn-mobile-nav__item--more' : '' );

		if ( $bn_m_more ) :
			?>
			<button type="button"
				class="<?php echo esc_attr( $bn_m_class ); ?>"
				aria-haspopup="true"
				aria-expanded="false"
				aria-controls="bn-mobile-more"
				data-bn-more-toggle>
				<?php buddynext_icon( 'more-horizontal' ); ?>
				<?php if ( $bn_m_item['badge'] > 0 ) : ?>
					<span class="bn-mobile-nav__badge"><?php echo esc_html( $bn_m_item['badge'] > 99 ? '99+' : (string) $bn_m_item['badge'] ); ?></span>
				<?php endif; ?>
				<span><?php echo esc_html( (string) $bn_m_item['label'] ); ?></span>
			</button>
			<?php
		else :
			?>
			<a href="<?php echo esc_url( (string) ( $bn_m_item['url'] ?? '#' ) ); ?>"
				class="<?php echo esc_attr( $bn_m_class ); ?>"
				<?php echo $bn_m_active ? 'aria-current="page"' : ''; ?>
				<?php echo $bn_m_create ? 'aria-label="' . esc_attr( (string) $bn_m_item['label'] ) . '"' : ''; ?>>
				<?php buddynext_icon( (string) ( $bn_m_item['icon'] ?? 'home' ) ); ?>
				<?php if ( $bn_m_badge ) : ?>
					<?php
					// Server-rendered per-slot count (Alerts + Messages), matching the
					// header bell + rail badges. The mobile nav has no Interactivity
					// store on feed/spaces/profile, so a static count re-renders
					// correctly on every hub.
					$bn_m_count = (int) ( $bn_m_item['badge_count'] ?? 0 );
					?>
					<span class="bn-mobile-nav__badge"
						<?php echo 0 === $bn_m_count ? 'hidden' : ''; ?>>
						<?php echo esc_html( $bn_m_count > 99 ? '99+' : (string) $bn_m_count ); ?>
					</span>
				<?php endif; ?>
				<?php if ( ! $bn_m_create ) : ?>
					<span><?php echo esc_html( (string) ( $bn_m_item['label'] ?? '' ) ); ?></span>
				<?php endif; ?>
			</a>
			<?php
		endif;
	endforeach;
	?>
</nav>
	<?php if ( $bn_overflow_items ) : ?>
	<div class="bn-mobile-more-backdrop" data-bn-more-close hidden></div>
	<div class="bn-mobile-more" id="bn-mobile-more" role="dialog" aria-modal="true"
		aria-label="<?php esc_attr_e( 'More navigation', 'buddynext' ); ?>" hidden>
		<div class="bn-mobile-more__head">
			<span class="bn-mobile-more__title"><?php esc_html_e( 'More', 'buddynext' ); ?></span>
			<button type="button" class="bn-mobile-more__close"
				aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>" data-bn-more-close>
				<?php buddynext_icon( 'x' ); ?>
			</button>
		</div>
		<ul class="bn-mobile-more__list">
			<?php foreach ( $bn_overflow_items as $bn_ov ) : ?>
				<?php $bn_ov_badge = (int) ( $bn_ov['badge'] ?? 0 ); ?>
				<li>
					<a class="bn-mobile-more__link" href="<?php echo esc_url( (string) ( $bn_ov['url'] ?? '#' ) ); ?>"
						<?php echo (string) ( $bn_ov['key'] ?? '' ) === $bn_mobile_active ? 'aria-current="page"' : ''; ?>>
						<?php buddynext_icon( (string) ( $bn_ov['icon'] ?? 'link' ) ); ?>
						<span><?php echo esc_html( (string) ( $bn_ov['label'] ?? '' ) ); ?></span>
						<?php if ( $bn_ov_badge > 0 ) : ?>
							<span class="bn-badge bn-mobile-more__badge" data-tone="danger"><?php echo esc_html( $bn_ov_badge > 99 ? '99+' : (string) $bn_ov_badge ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>
