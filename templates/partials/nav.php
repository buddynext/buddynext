<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Legacy nav partial (backwards-compatibility shim).
 *
 * Historically this partial rendered the sticky `.bn-subnav` global
 * navigation, plus inline `<style>` / `<script>` blocks for font-scale,
 * search overlay, hover card, and toast helpers. As of the hub-shell
 * takeover (see templates/shell/hub-shell.php) the rail + topbar live
 * inside `.bn-app` and the inline assets have moved to
 * assets/css/bn-shell.css + assets/js/shell/{font-scale,extras}.js.
 *
 * What this partial now renders:
 *   1. The Level-2 `.bn-context-nav` filtered bar (when items are present).
 *   2. The mobile bottom-bar `.bn-mobile-nav` (below 768px the rail hides
 *      and this bar takes over).
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

<?php if ( $bn_nav_current_user ) : ?>
<nav class="bn-mobile-nav" aria-label="<?php esc_attr_e( 'Mobile navigation', 'buddynext' ); ?>">
	<a href="<?php echo esc_url( $bn_nav_urls['feed'] ); ?>" class="bn-mobile-nav__item<?php echo 'feed' === $bn_nav_active ? ' bn-mobile-nav__item--active' : ''; ?>">
		<?php buddynext_icon( 'home' ); ?>
		<span><?php esc_html_e( 'Feed', 'buddynext' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $bn_nav_urls['spaces'] ); ?>" class="bn-mobile-nav__item<?php echo 'spaces' === $bn_nav_active ? ' bn-mobile-nav__item--active' : ''; ?>">
		<?php buddynext_icon( 'hash' ); ?>
		<span><?php esc_html_e( 'Spaces', 'buddynext' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $bn_nav_urls['feed'] ); ?>?compose=1" class="bn-mobile-nav__item bn-mobile-nav__item--create" aria-label="<?php esc_attr_e( 'Create post', 'buddynext' ); ?>">
		<?php buddynext_icon( 'plus' ); ?>
	</a>
	<a href="<?php echo esc_url( $bn_nav_urls['notifications'] ); ?>" class="bn-mobile-nav__item<?php echo 'notifications' === $bn_nav_active ? ' bn-mobile-nav__item--active' : ''; ?>">
		<?php buddynext_icon( 'bell' ); ?>
		<?php if ( $bn_unread_notifs > 0 ) : ?>
			<span class="bn-mobile-nav__badge"><?php echo esc_html( $bn_unread_notifs > 9 ? '9+' : (string) $bn_unread_notifs ); ?></span>
		<?php endif; ?>
		<span><?php esc_html_e( 'Alerts', 'buddynext' ); ?></span>
	</a>
	<a href="<?php echo esc_url( PageRouter::profile_url( $bn_nav_current_user ) ); ?>" class="bn-mobile-nav__item">
		<?php buddynext_icon( 'user' ); ?>
		<span><?php esc_html_e( 'Profile', 'buddynext' ); ?></span>
	</a>
</nav>
<?php endif; ?>
