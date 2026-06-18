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

// ── Unread notifications count ──────────────────────────────────────────────
// Read through NotificationService::unread_count() — the one cache-backed
// source the bell / rail / title also use. nav.php previously ran its own raw
// COUNT under a separate cache key (buddynext_nav), so the unread count was
// queried twice on every hub render.
$bn_nav_current_user = get_current_user_id();
$bn_unread_notifs    = $bn_nav_current_user
	? (int) buddynext_service( 'notifications' )->unread_count( $bn_nav_current_user )
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
	);
	$bn_mobile_active = isset( $bn_mobile_active_map[ $bn_nav_active ] ) ? $bn_mobile_active_map[ $bn_nav_active ] : '';

	/**
	 * Filter the mobile bottom-bar items.
	 *
	 * @param array<int,array<string,mixed>> $items  Bar item definitions.
	 * @param string                         $active Active mobile-bar key (feed / spaces / notifications / profile / '').
	 */
	$bn_mobile_items = (array) apply_filters( 'buddynext_mobile_nav_items', $bn_mobile_items, $bn_mobile_active );

	// Split the visible bar slots from any "overflow" entries (admin-created
	// custom tabs flagged by NavOverrides::apply_mobile_items). The bar is a fixed
	// 5-slot strip with a centred Create button, so custom tabs never get their own
	// slot: when present, the Profile slot folds into a "More" sheet and a "More"
	// toggle takes the 5th slot. With no custom tabs the bar is unchanged.
	$bn_bar_items      = array();
	$bn_overflow_items = array();
	foreach ( $bn_mobile_items as $bn_m_item ) {
		if ( ! is_array( $bn_m_item ) || empty( $bn_m_item['show'] ) ) {
			continue;
		}
		if ( ! empty( $bn_m_item['overflow'] ) ) {
			$bn_overflow_items[] = $bn_m_item;
		} else {
			$bn_bar_items[] = $bn_m_item;
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
		);
	}
	?>
<nav class="bn-mobile-nav"
	aria-label="<?php esc_attr_e( 'Mobile navigation', 'buddynext' ); ?>">
	<?php
	foreach ( $bn_bar_items as $bn_m_item ) :
		$bn_m_key    = (string) ( $bn_m_item['key'] ?? '' );
		$bn_m_create = isset( $bn_m_item['type'] ) && 'create' === $bn_m_item['type'];
		$bn_m_more   = isset( $bn_m_item['type'] ) && 'more' === $bn_m_item['type'];
		$bn_m_badge  = ! empty( $bn_m_item['badge'] );
		$bn_m_active = ! $bn_m_create && ! $bn_m_more && '' !== $bn_m_key && $bn_m_key === $bn_mobile_active;
		$bn_m_class  = 'bn-mobile-nav__item'
			. ( $bn_m_create ? ' bn-mobile-nav__item--create' : '' )
			. ( $bn_m_more ? ' bn-mobile-nav__item--more' : '' )
			. ( $bn_m_active ? ' bn-mobile-nav__item--active' : '' );

		if ( $bn_m_more ) :
			?>
			<button type="button"
				class="<?php echo esc_attr( $bn_m_class ); ?>"
				aria-haspopup="true"
				aria-expanded="false"
				aria-controls="bn-mobile-more"
				data-bn-more-toggle>
				<?php buddynext_icon( 'more-horizontal' ); ?>
				<span><?php echo esc_html( (string) $bn_m_item['label'] ); ?></span>
			</button>
			<?php
		else :
			?>
			<a href="<?php echo esc_url( (string) ( $bn_m_item['url'] ?? '#' ) ); ?>"
				class="<?php echo esc_attr( $bn_m_class ); ?>"
				<?php echo $bn_m_create ? 'aria-label="' . esc_attr( (string) $bn_m_item['label'] ) . '"' : ''; ?>>
				<?php buddynext_icon( (string) ( $bn_m_item['icon'] ?? 'home' ) ); ?>
				<?php if ( $bn_m_badge ) : ?>
					<?php // Server-rendered count, matching the header bell + rail badges. The mobile nav has no notifications Interactivity store loaded on feed/spaces/profile, so binding to state.unreadLabel there wiped the count — the static count re-renders correctly on every hub. ?>
					<span class="bn-mobile-nav__badge"
						<?php echo 0 === $bn_unread_notifs ? 'hidden' : ''; ?>>
						<?php echo esc_html( $bn_badge_label ); ?>
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
				<li>
					<a class="bn-mobile-more__link" href="<?php echo esc_url( (string) ( $bn_ov['url'] ?? '#' ) ); ?>">
						<?php buddynext_icon( (string) ( $bn_ov['icon'] ?? 'link' ) ); ?>
						<span><?php echo esc_html( (string) ( $bn_ov['label'] ?? '' ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>
<?php endif; ?>
