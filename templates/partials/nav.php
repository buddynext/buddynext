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
 *   2. The mobile bottom-bar `.bn-mobile-nav` through partials/mobile-nav.php (at
 *      768px and below the rail hides and this 5-slot tab bar takes over), with a
 *      More sheet holding everything else the rail offers.
 *
 * Context variables (all optional — safe defaults apply):
 *   $bn_nav_active  string  Key of the active section: 'feed'|'explore'|
 *                           'members'|'spaces'|'notifications'|'messages'.
 *                           Default: auto-detected from bn_hub query var.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

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

// ── Unread notifications count ──────────────────────────────────────────────
// Read through NotificationService::unread_count() — the one cache-backed
// source the bell / rail / title also use. nav.php previously ran its own raw
// COUNT under a separate cache key (buddynext_nav), so the unread count was
// queried twice on every hub render.
$bn_nav_current_user = get_current_user_id();
// Badge = UNSEEN (since the list was last viewed), not raw unread: viewing the
// list clears the badge without marking rows read.
$bn_unread_notifs = $bn_nav_current_user
	? (int) buddynext_service( 'notifications' )->unseen_count( $bn_nav_current_user )
	: 0;

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
<nav class="bn-context-nav" data-bn-nav aria-label="<?php esc_attr_e( 'Section navigation', 'buddynext' ); ?>">
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
buddynext_get_template(
	'partials/mobile-nav.php',
	array(
		'bn_nav_urls'         => $bn_nav_urls,
		'bn_nav_active'       => $bn_nav_active,
		'bn_nav_current_user' => $bn_nav_current_user,
		'bn_unread_notifs'    => $bn_unread_notifs,
	)
);
