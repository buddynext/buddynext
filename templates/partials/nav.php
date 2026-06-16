<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Shared nav partial.
 *
 * Rendered by `templates/shell/hub-shell.php` on every BN hub so the
 * mobile bottom-bar navigation appears uniformly without each hub
 * template needing to remember to include it.
 *
 * Historically this partial rendered the sticky `.bn-subnav` global
 * navigation, plus inline `<style>` / `<script>` blocks for font-scale,
 * search overlay, hover card, and toast helpers. As of the hub-shell
 * takeover (see templates/shell/hub-shell.php) the rail lives inside
 * `.bn-app`, the inline assets have moved to assets/css/bn-shell.css +
 * assets/js/shell/{font-scale,extras}.js, and the BN topbar was removed
 * entirely (the active theme's `get_header()` is the top navigation).
 *
 * What this partial now renders:
 *   1. The Level-2 `.bn-context-nav` filtered bar (when items are present).
 *   2. The mobile bottom-bar `.bn-mobile-nav` (below 640px the rail hides
 *      and this 5-item Feed / Spaces / + / Alerts / Profile tab bar takes
 *      over — see docs/v2 Plans/v2/mobile.html).
 *
 * Context variables (all optional — safe defaults apply):
 *   $bn_nav_active  string  Key of the active section: 'feed'|'explore'|
 *                           'members'|'spaces'|'notifications'|'messages'.
 *                           Default: auto-detected from bn_hub query var.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

use BuddyNext\Core\PageRouter;

// ── URL resolution (delegates to PageRouter static builders) ─────────────────
$bn_nav_urls = array(
	'feed'          => PageRouter::activity_url(),
	'explore'       => PageRouter::explore_url(),
	'members'       => PageRouter::people_url(),
	'spaces'        => PageRouter::spaces_url(),
	'notifications' => PageRouter::notifications_url(),
	'messages'      => PageRouter::messages_url(),
);

// ── Active item detection ───────────────────────────────────────────────────
if ( empty( $bn_nav_active ) ) {
	$bn_hub_var    = (string) get_query_var( 'bn_hub', '' );
	$bn_map        = array(
		'feed'          => 'feed',
		'people'        => 'members',
		'spaces'        => 'spaces',
		'notifications' => 'notifications',
		'messages'      => 'messages',
	);
	$bn_nav_active = isset( $bn_map[ $bn_hub_var ] ) ? $bn_map[ $bn_hub_var ] : '';
}

// ── Unread notifications count (cached 60 s per user) ───────────────────────
$bn_nav_current_user = get_current_user_id();
$bn_unread_notifs    = 0;

if ( $bn_nav_current_user ) {
	global $wpdb;
	$notif_cache_key = "bn_unread_notifs_{$bn_nav_current_user}";
	$cached_notifs   = wp_cache_get( $notif_cache_key, 'buddynext_nav' );
	if ( false === $cached_notifs ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cached_notifs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND is_read = 0",
				$bn_nav_current_user
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( $notif_cache_key, $cached_notifs, 'buddynext_nav', 60 );
	}
	$bn_unread_notifs = (int) $cached_notifs;
}

/**
 * Level 2 Context Nav — per-section sub-navigation.
 *
 * Plugins and bridges inject items via the buddynext_context_nav filter.
 * Each item: array( 'label' => string, 'url' => string, 'active' => bool ).
 * The bar only renders when items are present.
 *
 * @param array  $items      Sub-navigation items (empty by default).
 * @param string $bn_section Current active section from the main nav.
 */
$bn_context_items = apply_filters( 'buddynext_context_nav', array(), $bn_nav_active );
if ( ! empty( $bn_context_items ) ) :
	?>
<nav class="bn-context-nav" aria-label="<?php esc_attr_e( 'Section navigation', 'buddynext' ); ?>">
	<div class="bn-context-nav__inner">
		<?php foreach ( $bn_context_items as $ctx_item ) : ?>
			<a href="<?php echo esc_url( $ctx_item['url'] ); ?>"
				class="bn-context-nav__item<?php echo ! empty( $ctx_item['active'] ) ? ' bn-context-nav__item--active' : ''; ?>"
				<?php echo ! empty( $ctx_item['active'] ) ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $ctx_item['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</nav>
<?php endif; ?>

<?php
if ( $bn_nav_current_user ) :
	$bn_badge_label = $bn_unread_notifs > 99 ? '99+' : (string) $bn_unread_notifs;
	$bn_nav_context = wp_json_encode(
		array(
			'unreadCount' => (int) $bn_unread_notifs,
			'unreadLabel' => $bn_badge_label,
			'restUrl'     => rest_url( 'buddynext/v1/me/notifications' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
		)
	);
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
	$bn_spaces_enabled = ! function_exists( 'buddynext_service' )
		|| ! is_object( buddynext_service( 'features' ) )
		|| buddynext_service( 'features' )->is_enabled( 'spaces' );

	$bn_mobile_items = array(
		array(
			'key'   => 'feed',
			'url'   => $bn_nav_urls['feed'],
			'icon'  => 'home',
			'label' => __( 'Feed', 'buddynext' ),
			'show'  => true,
		),
		array(
			'key'   => 'spaces',
			'url'   => $bn_nav_urls['spaces'],
			'icon'  => 'hash',
			'label' => __( 'Spaces', 'buddynext' ),
			'show'  => $bn_spaces_enabled,
		),
		array(
			'key'   => 'create',
			'url'   => $bn_nav_urls['feed'] . '?compose=1',
			'icon'  => 'plus',
			'label' => __( 'Create post', 'buddynext' ),
			'show'  => true,
			'type'  => 'create',
		),
		array(
			'key'   => 'notifications',
			'url'   => $bn_nav_urls['notifications'],
			'icon'  => 'bell',
			'label' => __( 'Alerts', 'buddynext' ),
			'show'  => true,
			'badge' => true,
		),
		array(
			'key'   => 'profile',
			'url'   => PageRouter::profile_url( $bn_nav_current_user ),
			'icon'  => 'user',
			'label' => __( 'Profile', 'buddynext' ),
			'show'  => true,
		),
	);

	/**
	 * Filter the mobile bottom-bar items.
	 *
	 * @param array<int,array<string,mixed>> $items  Bar item definitions.
	 * @param string                         $active Active section key.
	 */
	$bn_mobile_items = (array) apply_filters( 'buddynext_mobile_nav_items', $bn_mobile_items, $bn_nav_active );
	?>
<nav class="bn-mobile-nav"
	aria-label="<?php esc_attr_e( 'Mobile navigation', 'buddynext' ); ?>"
	data-wp-interactive="buddynext/notifications"
	data-wp-context='<?php echo esc_attr( (string) $bn_nav_context ); ?>'>
	<?php
	foreach ( $bn_mobile_items as $bn_m_item ) :
		if ( ! is_array( $bn_m_item ) || empty( $bn_m_item['show'] ) ) {
			continue;
		}
		$bn_m_key    = (string) ( $bn_m_item['key'] ?? '' );
		$bn_m_create = isset( $bn_m_item['type'] ) && 'create' === $bn_m_item['type'];
		$bn_m_badge  = ! empty( $bn_m_item['badge'] );
		$bn_m_active = ! $bn_m_create && '' !== $bn_m_key && $bn_m_key === $bn_nav_active;
		$bn_m_class  = 'bn-mobile-nav__item'
			. ( $bn_m_create ? ' bn-mobile-nav__item--create' : '' )
			. ( $bn_m_active ? ' bn-mobile-nav__item--active' : '' );
		?>
		<a href="<?php echo esc_url( (string) ( $bn_m_item['url'] ?? '#' ) ); ?>"
			class="<?php echo esc_attr( $bn_m_class ); ?>"
			<?php echo $bn_m_create ? 'aria-label="' . esc_attr( (string) $bn_m_item['label'] ) . '"' : ''; ?>>
			<?php buddynext_icon( (string) ( $bn_m_item['icon'] ?? 'home' ) ); ?>
			<?php if ( $bn_m_badge ) : ?>
				<span class="bn-mobile-nav__badge"
					data-wp-bind--hidden="state.badgeHidden"
					data-wp-text="state.unreadLabel"
					<?php echo 0 === $bn_unread_notifs ? 'hidden' : ''; ?>>
					<?php echo esc_html( $bn_badge_label ); ?>
				</span>
			<?php endif; ?>
			<?php if ( ! $bn_m_create ) : ?>
				<span><?php echo esc_html( (string) ( $bn_m_item['label'] ?? '' ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>
