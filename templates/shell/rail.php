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

	$notif_cache_key = "bn_unread_notifs_{$bn_rail_current_user}";
	$cached_notifs   = wp_cache_get( $notif_cache_key, 'buddynext_nav' );
	if ( false === $cached_notifs ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cached_notifs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND is_read = 0",
				$bn_rail_current_user
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( $notif_cache_key, $cached_notifs, 'buddynext_nav', 60 );
	}
	$bn_unread_notifs = (int) $cached_notifs;

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

$bn_rail_items = array(
	array(
		'key'   => 'feed',
		'label' => __( 'Activity', 'buddynext' ),
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
		'show'  => true,
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
		'show'  => (bool) $bn_rail_current_user,
	),
);

/**
 * Filter the left-rail navigation items.
 *
 * Bridge plugins use this to inject extra surface links. Each item is an
 * array with keys: key (string id), label (string), url (string), icon
 * (BuddyNext icon slug), show (bool), badge (int optional).
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
?>
<nav class="bn-app__rail" aria-label="<?php esc_attr_e( 'Community navigation', 'buddynext' ); ?>">
	<div class="bn-rail__group">
		<?php foreach ( $bn_rail_items as $bn_item ) : ?>
			<?php
			if ( empty( $bn_item['show'] ) ) {
				continue;
			}
			$bn_is_active = ! empty( $bn_item['key'] ) && $bn_item['key'] === $bn_rail_active;
			$bn_icon_slug = ! empty( $bn_item['icon'] ) ? (string) $bn_item['icon'] : 'home';
			$bn_badge     = isset( $bn_item['badge'] ) ? (int) $bn_item['badge'] : 0;
			?>
			<a
				href="<?php echo esc_url( (string) $bn_item['url'] ); ?>"
				class="bn-rail__item"
				<?php echo $bn_is_active ? 'aria-current="page"' : ''; ?>
			>
				<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( $bn_icon_slug ); ?></span>
				<span class="bn-rail__label"><?php echo esc_html( (string) $bn_item['label'] ); ?></span>
				<?php if ( $bn_badge > 0 ) : ?>
					<span class="bn-rail__badge"><?php echo esc_html( $bn_badge > 99 ? '99+' : (string) $bn_badge ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( $bn_rail_current_user ) : ?>
		<div class="bn-rail__group">
			<div class="bn-rail__heading"><?php esc_html_e( 'You', 'buddynext' ); ?></div>
			<a href="<?php echo esc_url( PageRouter::profile_url( $bn_rail_current_user ) ); ?>" class="bn-rail__item">
				<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'user' ); ?></span>
				<span class="bn-rail__label"><?php esc_html_e( 'Profile', 'buddynext' ); ?></span>
			</a>
			<?php
			$bn_bookmarks_url    = PageRouter::bookmarks_url();
			$bn_bookmarks_active = ( 'feed' === $hub && 'bookmarks' === (string) get_query_var( 'bn_feed_section', '' ) );
			?>
			<a
				href="<?php echo esc_url( $bn_bookmarks_url ); ?>"
				class="bn-rail__item"
				<?php echo $bn_bookmarks_active ? 'aria-current="page"' : ''; ?>
			>
				<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'bookmark' ); ?></span>
				<span class="bn-rail__label"><?php esc_html_e( 'Bookmarks', 'buddynext' ); ?></span>
			</a>
			<a href="<?php echo esc_url( PageRouter::edit_profile_url() ); ?>" class="bn-rail__item">
				<span class="bn-rail__icon" aria-hidden="true"><?php buddynext_icon( 'settings' ); ?></span>
				<span class="bn-rail__label"><?php esc_html_e( 'Settings', 'buddynext' ); ?></span>
			</a>
		</div>
	<?php endif; ?>
</nav>
