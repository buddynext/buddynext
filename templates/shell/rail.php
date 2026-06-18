<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Hub shell left navigation rail.
 *
 * Renders the persistent vertical navigation column inside .bn-app__shell.
 * The mobile bottom-bar nav (.bn-mobile-nav) lives in partials/nav.php
 * and is rendered by hub-shell.php on every BN hub — at <= 768px the
 * rail hides and the mobile bottom tab bar takes over (matching the
 * Home / Spaces / + / Alerts / Profile pattern in
 * docs/v2 Plans/v2/mobile.html).
 *
 * Context variables (all optional):
 *   $hub  string  Current hub slug (feed / people / spaces / messages / …).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

use BuddyNext\Core\PageRouter;

if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}

$bn_rail_current_user = get_current_user_id();
$bn_unread_notifs     = 0;
$bn_unread_messages   = 0;

if ( $bn_rail_current_user ) {
	global $wpdb;

	// Single source of truth for the unread count. NotificationService caches
	// the result, so the rail and the notification bell share one query instead
	// of running parallel raw-SQL and service paths.
	$bn_unread_notifs = (int) buddynext_service( 'notifications' )->unread_count( $bn_rail_current_user );

	if ( class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) {
		$msg_cache_key = "bn_unread_msgs_{$bn_rail_current_user}";
		$cached_msgs   = wp_cache_get( $msg_cache_key, 'buddynext_nav' );
		if ( false === $cached_msgs ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cached_msgs = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_conversations c
					 INNER JOIN {$wpdb->prefix}mvs_conversation_participants cp
					   ON cp.conversation_id = c.id AND cp.user_id = %d AND cp.status = 'active'
					 WHERE c.last_activity_at > COALESCE(cp.last_read_at, '1970-01-01')",
					$bn_rail_current_user
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_set( $msg_cache_key, $cached_msgs, 'buddynext_nav', 60 );
		}
		$bn_unread_messages = (int) $cached_msgs;
	}
}

// The Spaces page itself is already guarded (PageRouter redirects when the
// feature is off); hide its rail entry too so the nav doesn't link to a
// disabled surface.
$bn_spaces_enabled = ! function_exists( 'buddynext_service' )
	|| ! is_object( buddynext_service( 'features' ) )
	|| buddynext_service( 'features' )->is_enabled( 'spaces' );

$bn_rail_items = array(
	array(
		'key'   => 'feed',
		'label' => __( 'Feed', 'buddynext' ),
		'url'   => PageRouter::activity_url(),
		'icon'  => 'home',
		'show'  => true,
	),
	array(
		'key'   => 'explore',
		'label' => __( 'Explore', 'buddynext' ),
		'url'   => PageRouter::explore_url(),
		'icon'  => 'globe',
		'show'  => true,
	),
	array(
		'key'   => 'people',
		'label' => __( 'Members', 'buddynext' ),
		'url'   => PageRouter::people_url(),
		'icon'  => 'users',
		'show'  => true,
	),
	array(
		'key'   => 'spaces',
		'label' => __( 'Spaces', 'buddynext' ),
		'url'   => PageRouter::spaces_url(),
		'icon'  => 'hash',
		'show'  => $bn_spaces_enabled,
	),
	array(
		'key'   => 'notifications',
		'label' => __( 'Notifications', 'buddynext' ),
		'url'   => PageRouter::notifications_url(),
		'icon'  => 'bell',
		'badge' => $bn_unread_notifs,
		'show'  => (bool) $bn_rail_current_user,
	),
	array(
		'key'   => 'messages',
		'label' => __( 'Messages', 'buddynext' ),
		'url'   => PageRouter::messages_url(),
		'icon'  => 'message-circle',
		'badge' => $bn_unread_messages,
		'show'  => (bool) $bn_rail_current_user && \BuddyNext\Messages\MessagesData::entry_enabled(),
	),
);

// Personal "You" group — Profile / Edit Profile / Bookmarks / Settings. These
// render under the "You" heading at the foot of the rail and, like the community
// items above, are part of $bn_rail_items so the Navigation admin governs them
// too (hide / relabel / reorder / cap-gate via Nav\NavOverrides::apply_rail).
// The `group` key keeps them in their own visual section after the override sort;
// `order` 200+ keeps them below the community items and any bridge-injected tab.
if ( $bn_rail_current_user ) {
	$bn_bookmarks_active = ( 'feed' === $hub && 'bookmarks' === (string) get_query_var( 'bn_feed_section', '' ) );
	$bn_settings_active  = ( 'settings' === $hub || ( 'notifications' === $hub && 'prefs' === (string) get_query_var( 'bn_notif_section', '' ) ) );

	$bn_rail_items[] = array(
		'key'   => 'profile',
		'label' => __( 'Profile', 'buddynext' ),
		'url'   => PageRouter::profile_url( $bn_rail_current_user ),
		'icon'  => 'user',
		'show'  => true,
		'group' => 'you',
		'order' => 200,
	);
	$bn_rail_items[] = array(
		'key'   => 'edit-profile',
		'label' => __( 'Edit Profile', 'buddynext' ),
		'url'   => PageRouter::edit_profile_url( $bn_rail_current_user ),
		'icon'  => 'edit',
		'show'  => true,
		'group' => 'you',
		'order' => 210,
	);
	$bn_rail_items[] = array(
		'key'    => 'bookmarks',
		'label'  => __( 'Bookmarks', 'buddynext' ),
		'url'    => PageRouter::bookmarks_url(),
		'icon'   => 'bookmark',
		'show'   => true,
		'group'  => 'you',
		'order'  => 220,
		'active' => $bn_bookmarks_active,
	);
	$bn_rail_items[] = array(
		'key'    => 'settings',
		'label'  => __( 'Settings', 'buddynext' ),
		'url'    => PageRouter::settings_url(),
		'icon'   => 'settings',
		'show'   => true,
		'group'  => 'you',
		'order'  => 230,
		'active' => $bn_settings_active,
	);
}

/**
 * Filter the left-rail navigation items.
 *
 * Bridge plugins use this to inject extra surface links. Each item is an
 * array with keys: key (string id), label (string), url (string), icon
 * (BuddyNext icon slug), show (bool), badge (int optional), active (bool
 * optional — set true to force the highlighted state when the link points
 * at a surface outside BuddyNext's own hubs, e.g. a bridged forum).
 *
 * @param array<int,array<string,mixed>> $items Rail item definitions.
 * @param string                         $hub   Current hub slug.
 */
$bn_rail_items = apply_filters( 'buddynext_rail_items', $bn_rail_items, $hub );

// Active item — match current hub.
$bn_rail_active = '';
foreach ( $bn_rail_items as $bn_item ) {
	if ( ! empty( $bn_item['key'] ) && $bn_item['key'] === $hub ) {
		$bn_rail_active = $bn_item['key'];
		break;
	}
}
if ( '' === $bn_rail_active && 'feed' === $hub ) {
	$bn_rail_active = 'feed';
}
// Explore shares the feed hub but is the public/discovery feed (distinct from the
// personal Activity feed), so it owns the rail's active state on /activity/explore/
// instead of the Activity row.
if ( 'feed' === $hub && 'explore' === (string) get_query_var( 'bn_activity_action', '' ) ) {
	$bn_rail_active = 'explore';
}

// Single renderer for one rail link, shared by the community group and the "You"
// group below so both stay byte-identical. An item is active when it carries an
// explicit `active` flag (bridged surfaces, bookmarks/settings sub-routes) or its
// key matches the resolved active hub.
$bn_render_rail_item = static function ( array $bn_item ) use ( $bn_rail_active ): void {
	$bn_is_active = ! empty( $bn_item['active'] ) || ( ! empty( $bn_item['key'] ) && $bn_item['key'] === $bn_rail_active );
	$bn_icon_slug = ! empty( $bn_item['icon'] ) ? (string) $bn_item['icon'] : 'home';
	$bn_badge     = isset( $bn_item['badge'] ) ? (int) $bn_item['badge'] : 0;
	?>
	<a
		href="<?php echo esc_url( (string) $bn_item['url'] ); ?>"
		class="bn-rail__item"
		title="<?php echo esc_attr( (string) $bn_item['label'] ); ?>"
		<?php echo $bn_is_active ? 'aria-current="page"' : ''; ?>
	>
		<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( $bn_icon_slug ); ?></span>
		<span class="bn-rail__label"><?php echo esc_html( (string) $bn_item['label'] ); ?></span>
		<?php if ( $bn_badge > 0 ) : ?>
			<span class="bn-rail__badge"><?php echo esc_html( $bn_badge > 99 ? '99+' : (string) $bn_badge ); ?></span>
		<?php endif; ?>
	</a>
	<?php
};

// Split the override-applied items into the community group (top, no heading) and
// the personal "You" group (bottom, under its heading). Hidden items (show=false,
// set by the admin override) drop out here.
$bn_main_items = array();
$bn_you_items  = array();
foreach ( $bn_rail_items as $bn_item ) {
	if ( empty( $bn_item['show'] ) ) {
		continue;
	}
	if ( 'you' === (string) ( $bn_item['group'] ?? '' ) ) {
		$bn_you_items[] = $bn_item;
	} else {
		$bn_main_items[] = $bn_item;
	}
}
?>
<nav class="bn-app__rail" aria-label="<?php esc_attr_e( 'Community navigation', 'buddynext' ); ?>">
	<?php
	// Community logo (Settings → Appearance → buddynext_logo_url). Rendered at
	// the top of the rail, linking home, so an uploaded logo actually appears in
	// the site navigation — previously the option was only consumed by the auth
	// page and emails.
	$bn_rail_logo = (string) get_option( 'buddynext_logo_url', '' );
	// Header row: optional community logo on the left, compact collapse toggle on
	// the right. Grouping them in one flex row keeps spacing consistent and stops
	// the logo and the control from overlapping. The collapse toggle shrinks the
	// rail to an icon-only panel; state is persisted in localStorage and stamped
	// on <html data-bn-rail> before paint by assets/js/shell/font-scale.js.
	?>
	<div class="bn-rail__head">
		<?php if ( '' !== $bn_rail_logo ) : ?>
			<a class="bn-rail__logo" href="<?php echo esc_url( PageRouter::activity_url() ); ?>" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<img src="<?php echo esc_url( $bn_rail_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			</a>
		<?php endif; ?>
		<button
			type="button"
			class="bn-rail__toggle"
			data-bn-action="toggle-rail"
			aria-label="<?php esc_attr_e( 'Collapse navigation', 'buddynext' ); ?>"
			title="<?php esc_attr_e( 'Collapse navigation', 'buddynext' ); ?>"
		>
			<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'chevron-left' ); ?></span>
		</button>
	</div>
	<div class="bn-rail__group">
		<?php foreach ( $bn_main_items as $bn_item ) : ?>
			<?php $bn_render_rail_item( $bn_item ); ?>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $bn_you_items ) ) : ?>
		<div class="bn-rail__group">
			<div class="bn-rail__heading"><?php esc_html_e( 'You', 'buddynext' ); ?></div>
			<?php foreach ( $bn_you_items as $bn_item ) : ?>
				<?php $bn_render_rail_item( $bn_item ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</nav>
