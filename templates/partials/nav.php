<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Global community subnav partial.
 *
 * Renders the sticky `.bn-subnav` bar that appears on every BuddyNext page.
 * Outputs its own CSS block once per page load (guarded by a static flag).
 *
 * Context variables (all optional — safe defaults apply):
 *   $bn_nav_active  string  Key of the active nav item: 'feed'|'explore'|'members'|
 *                           'spaces'|'notifications'|'messages'. Default: auto-detected.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

// ── URL resolution (delegates to PageRouter static builders) ─────────────────

$bn_nav_urls = array(
	'feed'          => \BuddyNext\Core\PageRouter::activity_url(),
	'explore'       => \BuddyNext\Core\PageRouter::explore_url(),
	'members'       => \BuddyNext\Core\PageRouter::people_url(),
	'spaces'        => \BuddyNext\Core\PageRouter::spaces_url(),
	'notifications' => \BuddyNext\Core\PageRouter::notifications_url(),
	'messages'      => \BuddyNext\Core\PageRouter::messages_url(),
);

// ── Active item detection ───────────────────────────────────────────────────

if ( empty( $bn_nav_active ) ) {
	$current_page_id = (int) get_queried_object_id();
	$bn_hub_var      = (string) get_query_var( 'bn_hub', '' );

	if ( 'feed' === $bn_hub_var || (int) get_option( 'buddynext_page_activity', 0 ) === $current_page_id ) {
		$bn_nav_active = 'feed';
	} elseif ( 'people' === $bn_hub_var || (int) get_option( 'buddynext_page_people', 0 ) === $current_page_id ) {
		$bn_nav_active = 'members';
	} elseif ( 'spaces' === $bn_hub_var || (int) get_option( 'buddynext_page_spaces', 0 ) === $current_page_id ) {
		$bn_nav_active = 'spaces';
	} elseif ( 'notifications' === $bn_hub_var || (int) get_option( 'buddynext_page_notifications', 0 ) === $current_page_id ) {
		$bn_nav_active = 'notifications';
	} elseif ( 'messages' === $bn_hub_var || (int) get_option( 'buddynext_page_messages', 0 ) === $current_page_id ) {
		$bn_nav_active = 'messages';
	} else {
		$bn_nav_active = '';
	}
}

// ── Unread counts (cached 60 s per user) ────────────────────────────────────

$bn_nav_current_user = get_current_user_id();
$bn_unread_notifs    = 0;
$bn_unread_messages  = 0;

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

// ── CSS (output once per page) ──────────────────────────────────────────────

static $bn_nav_css_output = false;
if ( ! $bn_nav_css_output ) :
	$bn_nav_css_output = true;
	?>
<style id="bn-subnav-css">
.bn-subnav {
	background: var(--bg, #fff);
	border-bottom: 1px solid var(--border, #e8e8e5);
	padding: 0 var(--s8, 32px);
	position: sticky;
	top: 0;
	z-index: 190;
}
.bn-subnav-inner {
	max-width: 1200px;
	margin: 0 auto;
	display: flex;
	align-items: center;
	height: 48px;
	gap: 2px;
	overflow-x: auto;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.bn-subnav-inner::-webkit-scrollbar { display: none; }
.bn-nav-item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 500;
	color: var(--text-2, #787774);
	text-decoration: none;
	white-space: nowrap;
	position: relative;
	flex-shrink: 0;
	transition: background 0.12s, color 0.12s;
}
.bn-nav-item:hover {
	background: var(--bg-hover, #f1f1f0);
	color: var(--text-1, #37352f);
}
.bn-nav-item.bn-nav-active {
	color: var(--brand, #0073aa);
	font-weight: 600;
}
.bn-nav-item.bn-nav-active::after {
	content: '';
	position: absolute;
	bottom: -14px;
	left: 50%;
	transform: translateX(-50%);
	width: 70%;
	height: 2px;
	background: var(--brand, #0073aa);
	border-radius: 2px;
}
.bn-nav-pill {
	background: var(--red, #dc2626);
	color: #fff;
	font-size: 9px;
	font-weight: 700;
	min-width: 16px;
	height: 16px;
	padding: 0 4px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	line-height: 1;
}
.bn-subnav-right {
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: var(--s2, 8px);
	flex-shrink: 0;
	padding-left: var(--s4, 16px);
}
/* ── Font size control (A / A+ / A++) ────────────── */
.bn-font-scale {
	display: flex;
	align-items: center;
	gap: 2px;
	background: var(--bg-hover, #f1f1f0);
	border: 1px solid var(--border, #e8e8e5);
	border-radius: 6px;
	padding: 2px;
}
.bn-font-scale__btn {
	border: none;
	background: transparent;
	border-radius: 4px;
	padding: 3px 8px;
	font-size: 11px;
	font-weight: 600;
	color: var(--text-2, #787774);
	cursor: pointer;
	white-space: nowrap;
	transition: background 0.12s, color 0.12s;
	line-height: 1.4;
}
.bn-font-scale__btn:hover { color: var(--text-1, #37352f); }
.bn-font-scale__btn.active {
	background: var(--brand, #0073aa);
	color: #fff;
	border-radius: 4px;
}
@media (max-width: 640px) {
	.bn-subnav { padding: 0 var(--s2, 8px); }
	.bn-subnav-right { display: none; }
}
</style>
<script id="bn-font-scale-js">
(function () {
	var scales = ['100', '110', '120'];
	var labels = { '100': 'A', '110': 'A+', '120': 'A++' };

	function applyScale(s) {
		document.documentElement.setAttribute('data-bn-font-scale', s);
		try { localStorage.setItem('bn_font_scale', s); } catch (_) {}
		// Update active button state.
		var btns = document.querySelectorAll('.bn-font-scale__btn');
		btns.forEach(function (b) {
			b.classList.toggle('active', b.dataset.scale === s);
		});
	}

	// Apply saved preference on load (before paint).
	var saved = '100';
	try { saved = localStorage.getItem('bn_font_scale') || '100'; } catch (_) {}
	if (scales.indexOf(saved) === -1) { saved = '100'; }
	applyScale(saved);

	// Also keep dark mode support (reads OS preference).
	var theme = '';
	try { theme = localStorage.getItem('bn_theme') || ''; } catch (_) {}
	if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
		document.documentElement.setAttribute('data-theme', 'dark');
	}

	window.bnSetFontScale = function (s) {
		if (scales.indexOf(s) !== -1) { applyScale(s); }
	};
})();
</script>
<?php endif; ?>

<!-- BuddyNext Community Nav -->
<nav class="bn-subnav" aria-label="<?php esc_attr_e( 'Community navigation', 'buddynext' ); ?>">
	<div class="bn-subnav-inner">

		<a href="<?php echo esc_url( $bn_nav_urls['feed'] ); ?>"
			class="bn-nav-item<?php echo 'feed' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'feed' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
			<?php esc_html_e( 'Feed', 'buddynext' ); ?>
		</a>

		<a href="<?php echo esc_url( $bn_nav_urls['members'] ); ?>"
			class="bn-nav-item<?php echo 'members' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'members' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
			<?php esc_html_e( 'Members', 'buddynext' ); ?>
		</a>

		<a href="<?php echo esc_url( $bn_nav_urls['spaces'] ); ?>"
			class="bn-nav-item<?php echo 'spaces' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'spaces' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
			<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
		</a>

		<?php
		/**
		 * Filters the public nav items rendered after Feed / Members / Spaces.
		 *
		 * Bridge plugins use this to inject addon surface links (e.g. a Forum
		 * link pointing to Jetonomy). Auth-gated links belong in the bridge
		 * callback, not here — the nav renders every returned item unconditionally.
		 *
		 * Each item must be an array with at minimum 'label' and 'url'. Optional
		 * keys: 'icon' (raw inline SVG string) and 'active' (bool — marks the
		 * current-page item).
		 *
		 * @param array[] $items Nav item definitions. Default empty array.
		 */
		$bn_extra_nav_items = apply_filters( 'buddynext_nav_items', array() );
		foreach ( $bn_extra_nav_items as $bn_extra_item ) :
			if ( empty( $bn_extra_item['label'] ) || empty( $bn_extra_item['url'] ) ) {
				continue;
			}
			$bn_item_active = ! empty( $bn_extra_item['active'] );
			?>
			<a href="<?php echo esc_url( $bn_extra_item['url'] ); ?>"
				class="bn-nav-item<?php echo $bn_item_active ? ' bn-nav-active' : ''; ?>"
				<?php echo $bn_item_active ? 'aria-current="page"' : ''; ?>>
				<?php
				if ( ! empty( $bn_extra_item['icon'] ) ) :
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bridge SVGs are trusted internal code, same as hardcoded nav icons above.
					echo $bn_extra_item['icon'];
				endif;
				?>
				<?php echo esc_html( $bn_extra_item['label'] ); ?>
			</a>
		<?php endforeach; ?>

		<?php if ( $bn_nav_current_user ) : ?>

		<a href="<?php echo esc_url( $bn_nav_urls['notifications'] ); ?>"
			class="bn-nav-item<?php echo 'notifications' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'notifications' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
			<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
			<?php if ( $bn_unread_notifs > 0 ) : ?>
				<span class="bn-nav-pill"><?php echo esc_html( $bn_unread_notifs > 99 ? '99+' : (string) $bn_unread_notifs ); ?></span>
			<?php endif; ?>
		</a>

		<a href="<?php echo esc_url( $bn_nav_urls['messages'] ); ?>"
			class="bn-nav-item<?php echo 'messages' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'messages' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
			<?php esc_html_e( 'Messages', 'buddynext' ); ?>
		</a>

		<?php endif; ?>

		<div class="bn-subnav-right">
			<div class="bn-font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'buddynext' ); ?>">
				<button class="bn-font-scale__btn active" type="button" data-scale="100" onclick="bnSetFontScale('100')" aria-label="<?php esc_attr_e( 'Default font size', 'buddynext' ); ?>">A</button>
				<button class="bn-font-scale__btn" type="button" data-scale="110" onclick="bnSetFontScale('110')" aria-label="<?php esc_attr_e( 'Large font size', 'buddynext' ); ?>">A+</button>
				<button class="bn-font-scale__btn" type="button" data-scale="120" onclick="bnSetFontScale('120')" aria-label="<?php esc_attr_e( 'Extra large font size', 'buddynext' ); ?>">A++</button>
			</div>
		</div>

	</div>
</nav>
