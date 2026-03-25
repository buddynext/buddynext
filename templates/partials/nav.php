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

	// Unread messages count (from WPMediaVerse conversations).
	if ( class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
		$msg_cache_key = "bn_unread_msgs_{$bn_nav_current_user}";
		$cached_msgs   = wp_cache_get( $msg_cache_key, 'buddynext_nav' );
		if ( false === $cached_msgs ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cached_msgs = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_conversations c
					 INNER JOIN {$wpdb->prefix}mvs_conversation_participants cp
					   ON cp.conversation_id = c.id AND cp.user_id = %d AND cp.status = 'active'
					 WHERE c.last_activity_at > COALESCE(cp.last_read_at, '1970-01-01')",
					$bn_nav_current_user
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_set( $msg_cache_key, $cached_msgs, 'buddynext_nav', 60 );
		}
		$bn_unread_messages = (int) $cached_msgs;
	}
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
	max-width: var(--bn-container, 1100px);
	margin: 0 auto;
	display: flex;
	align-items: center;
	height: 42px;
	gap: 0;
	overflow-x: auto;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.bn-subnav-inner::-webkit-scrollbar { display: none; }
.bn-nav-item {
	display: flex;
	align-items: center;
	gap: 5px;
	padding: 6px 10px;
	border-radius: 6px;
	font-size: 12.5px;
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
	bottom: -11px;
	left: 50%;
	transform: translateX(-50%);
	width: 80%;
	height: 2.5px;
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
/* Notification dropdown */
.bn-nav-notif-wrap { position: relative; }
.bn-nav-notif-wrap button.bn-nav-item { background: none; border: none; cursor: pointer; }
.bn-notif-dropdown {
	position: absolute;
	top: calc(100% + 8px);
	right: 0;
	width: 360px;
	max-height: 440px;
	background: var(--bg, #fff);
	border: 1px solid var(--border, #e8e8e5);
	border-radius: 12px;
	box-shadow: 0 8px 32px rgba(0,0,0,0.12);
	z-index: 200;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	animation: bn-dropdown-in 0.15s ease;
}
@keyframes bn-dropdown-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
.bn-notif-dropdown__header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 12px 16px; border-bottom: 1px solid var(--border-soft, #f1f1ee);
}
.bn-notif-dropdown__title { font-weight: 700; font-size: 14px; color: var(--text-1, #37352f); }
.bn-notif-dropdown__mark-read { background: none; border: none; color: var(--brand, #0073aa); font-size: 12px; font-weight: 600; cursor: pointer; }
.bn-notif-dropdown__mark-read:hover { text-decoration: underline; }
.bn-notif-dropdown__list { flex: 1; overflow-y: auto; }
.bn-notif-dropdown__loading { padding: 24px; text-align: center; color: var(--text-3); font-size: 13px; }
.bn-notif-dropdown__item {
	display: flex; gap: 10px; padding: 10px 16px; cursor: pointer;
	transition: background 0.1s;
}
.bn-notif-dropdown__item:hover { background: var(--bg-hover, #f1f1f0); }
.bn-notif-dropdown__item--unread { background: var(--brand-light, #e8f4fb); }
.bn-notif-dropdown__avatar {
	width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
	background: var(--brand-light); color: var(--brand); font-size: 13px; font-weight: 700;
	display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.bn-notif-dropdown__avatar img { width: 100%; height: 100%; object-fit: cover; }
.bn-notif-dropdown__body { flex: 1; min-width: 0; }
.bn-notif-dropdown__text { font-size: 13px; color: var(--text-1); line-height: 1.4; }
.bn-notif-dropdown__text strong { font-weight: 600; }
.bn-notif-dropdown__time { font-size: 11px; color: var(--text-3); margin-top: 2px; }
.bn-notif-dropdown__see-all {
	display: block; text-align: center; padding: 10px; font-size: 13px; font-weight: 600;
	color: var(--brand); text-decoration: none; border-top: 1px solid var(--border-soft);
}
.bn-notif-dropdown__see-all:hover { background: var(--bg-hover); }
@media (max-width: 640px) {
	.bn-notif-dropdown { width: calc(100vw - 16px); right: -60px; }
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

	// Apply scale immediately (prevents FOUC — html element already exists).
	var saved = '100';
	try { saved = localStorage.getItem('bn_font_scale') || '100'; } catch (_) {}
	if (scales.indexOf(saved) === -1) { saved = '100'; }
	document.documentElement.setAttribute('data-bn-font-scale', saved);

	// Dark mode (reads OS preference).
	var theme = '';
	try { theme = localStorage.getItem('bn_theme') || ''; } catch (_) {}
	if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
		document.documentElement.setAttribute('data-theme', 'dark');
	}

	// Toggle button active states — deferred until buttons exist in DOM.
	function syncButtons(s) {
		var btns = document.querySelectorAll('.bn-font-scale__btn');
		btns.forEach(function (b) {
			b.classList.toggle('active', b.dataset.scale === s);
		});
	}

	// Sync on DOMContentLoaded (buttons haven't been parsed when this script runs).
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { syncButtons(saved); });
	} else {
		syncButtons(saved);
	}

	window.bnSetFontScale = function (s) {
		if (scales.indexOf(s) !== -1) {
			document.documentElement.setAttribute('data-bn-font-scale', s);
			try { localStorage.setItem('bn_font_scale', s); } catch (_) {}
			syncButtons(s);
		}
	};
})();

/* ── Notification dropdown ── */
window.bnToggleNotifDropdown = function(btn) {
	var wrap = btn.closest('.bn-nav-notif-wrap');
	var dd   = wrap.querySelector('.bn-notif-dropdown');
	var open = !dd.hidden;
	dd.hidden = open;
	btn.setAttribute('aria-expanded', open ? 'false' : 'true');
	if (!open && !dd.dataset.loaded) {
		dd.dataset.loaded = '1';
		fetch('<?php echo esc_url( rest_url( 'buddynext/v1/me/notifications?per_page=5' ) ); ?>', {
			headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
		}).then(function(r) { return r.json(); }).then(function(data) {
			var list = document.getElementById('bn-notif-dropdown-list');
			if (!list) return;
			var items = data.items || data;
			if (!items.length) {
				list.textContent = '';
				var empty = document.createElement('div');
				empty.className = 'bn-notif-dropdown__loading';
				empty.textContent = 'No notifications yet';
				list.appendChild(empty);
				return;
			}
			list.textContent = '';
			items.forEach(function(n) {
				var div    = document.createElement('div');
				div.className = 'bn-notif-dropdown__item' + (n.is_read ? '' : ' bn-notif-dropdown__item--unread');
				var avatar = document.createElement('div');
				avatar.className = 'bn-notif-dropdown__avatar';
				avatar.textContent = (n.sender_name || 'U').split(' ').map(function(w){return w[0];}).join('').toUpperCase().slice(0,2);
				var body   = document.createElement('div');
				body.className = 'bn-notif-dropdown__body';
				var text   = document.createElement('div');
				text.className = 'bn-notif-dropdown__text';
				var strong = document.createElement('strong');
				strong.textContent = n.sender_name || '';
				text.appendChild(strong);
				text.appendChild(document.createTextNode(' ' + (n.message || n.type || '')));
				var time   = document.createElement('div');
				time.className = 'bn-notif-dropdown__time';
				time.textContent = n.time_ago || '';
				body.appendChild(text);
				body.appendChild(time);
				div.appendChild(avatar);
				div.appendChild(body);
				list.appendChild(div);
			});
		}).catch(function() {
			var list = document.getElementById('bn-notif-dropdown-list');
			if (list) {
				list.textContent = '';
				var err = document.createElement('div');
				err.className = 'bn-notif-dropdown__loading';
				err.textContent = 'Could not load';
				list.appendChild(err);
			}
		});
	}
};
document.addEventListener('click', function(e) {
	var wrap = document.querySelector('.bn-nav-notif-wrap');
	if (wrap && !wrap.contains(e.target)) {
		var dd = wrap.querySelector('.bn-notif-dropdown');
		if (dd) { dd.hidden = true; }
		var btn = wrap.querySelector('[aria-expanded]');
		if (btn) { btn.setAttribute('aria-expanded', 'false'); }
	}
});
window.bnMarkAllRead = function() {
	fetch('<?php echo esc_url( rest_url( 'buddynext/v1/me/notifications/read-all' ) ); ?>', {
		method: 'PUT',
		headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
	}).then(function() {
		var pill = document.querySelector('.bn-nav-notif-wrap .bn-nav-pill');
		if (pill) pill.remove();
		document.querySelectorAll('.bn-notif-dropdown__item--unread').forEach(function(el) {
			el.classList.remove('bn-notif-dropdown__item--unread');
		});
		if (window.bnToast) { window.bnToast('All notifications marked read'); }
	});
};

/* ── Search overlay (cmd+K) ── */
window.bnSearchOverlay = {
	el: null,
	init: function() {
		if (this.el) return;
		var ov = document.createElement('div');
		ov.className = 'bn-search-overlay';
		ov.hidden = true;

		var inner = document.createElement('div');
		inner.className = 'bn-search-overlay__inner';

		var inputWrap = document.createElement('div');
		inputWrap.className = 'bn-search-overlay__input-wrap';

		var icon = document.createElement('span');
		icon.className = 'bn-search-overlay__icon';
		icon.textContent = '\u2315'; // search icon

		var input = document.createElement('input');
		input.type = 'search';
		input.className = 'bn-search-overlay__input';
		input.placeholder = 'Search posts, people, spaces, discussions...';
		input.setAttribute('autocomplete', 'off');

		var kbd = document.createElement('kbd');
		kbd.className = 'bn-search-overlay__kbd';
		kbd.textContent = 'Esc';

		inputWrap.appendChild(icon);
		inputWrap.appendChild(input);
		inputWrap.appendChild(kbd);
		inner.appendChild(inputWrap);

		var results = document.createElement('div');
		results.className = 'bn-search-overlay__results';
		results.id = 'bn-search-results';
		inner.appendChild(results);

		ov.appendChild(inner);
		document.body.appendChild(ov);
		this.el = ov;

		var self = this;
		ov.addEventListener('click', function(e) { if (e.target === ov) self.close(); });
		input.addEventListener('keydown', function(e) { if (e.key === 'Escape') self.close(); });

		var timer = null;
		input.addEventListener('input', function() {
			clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 2) { results.textContent = ''; return; }
			timer = setTimeout(function() { self.search(q, results); }, 300);
		});
	},
	open: function() {
		this.init();
		this.el.hidden = false;
		this.el.querySelector('input').value = '';
		this.el.querySelector('input').focus();
		document.getElementById('bn-search-results').textContent = '';
	},
	close: function() { if (this.el) this.el.hidden = true; },
	search: function(q, resultsEl) {
		resultsEl.textContent = '';
		var loading = document.createElement('div');
		loading.className = 'bn-search-overlay__loading';
		loading.textContent = 'Searching...';
		resultsEl.appendChild(loading);

		var nonce = '<?php echo esc_js( wp_create_nonce( "wp_rest" ) ); ?>';
		var url   = '<?php echo esc_url( rest_url( "buddynext/v1/search" ) ); ?>?q=' + encodeURIComponent(q) + '&per_page=8';

		fetch(url, { headers: { 'X-WP-Nonce': nonce } })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				resultsEl.textContent = '';
				var items = data.items || data.results || data;
				if (!items || !items.length) {
					var empty = document.createElement('div');
					empty.className = 'bn-search-overlay__empty';
					empty.textContent = 'No results for "' + q + '"';
					resultsEl.appendChild(empty);
					return;
				}
				items.forEach(function(item) {
					var a = document.createElement('a');
					a.className = 'bn-search-overlay__result';
					a.href = item.url || item.permalink || '#';

					var title = document.createElement('div');
					title.className = 'bn-search-overlay__result-title';
					title.textContent = item.title || item.content || '';

					var meta = document.createElement('div');
					meta.className = 'bn-search-overlay__result-meta';
					meta.textContent = (item.type || 'post') + (item.author_name ? ' by ' + item.author_name : '');

					a.appendChild(title);
					a.appendChild(meta);
					resultsEl.appendChild(a);
				});
			})
			.catch(function() {
				resultsEl.textContent = '';
				var err = document.createElement('div');
				err.className = 'bn-search-overlay__empty';
				err.textContent = 'Search failed';
				resultsEl.appendChild(err);
			});
	}
};
// Keyboard shortcut: cmd+K or ctrl+K
document.addEventListener('keydown', function(e) {
	if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
		e.preventDefault();
		bnSearchOverlay.open();
	}
	if (e.key === '/' && !e.target.closest('input,textarea,[contenteditable]')) {
		e.preventDefault();
		bnSearchOverlay.open();
	}
});

/* ── User hover card ── */
(function() {
	var card = null;
	var hoverTimer = null;
	var leaveTimer = null;
	document.addEventListener('mouseenter', function(e) {
		var el = e.target.closest('.bn-hover-user');
		if (!el) return;
		clearTimeout(leaveTimer);
		hoverTimer = setTimeout(function() {
			var userId = el.dataset.bnUserId;
			var name   = el.dataset.bnUserName || '';
			var handle = el.dataset.bnUserHandle || '';
			if (!card) {
				card = document.createElement('div');
				card.className = 'bn-hover-card';
				document.body.appendChild(card);
				card.addEventListener('mouseenter', function() { clearTimeout(leaveTimer); });
				card.addEventListener('mouseleave', function() { card.hidden = true; });
			}
			var initials = name.split(' ').map(function(w){return w[0];}).join('').toUpperCase().slice(0,2);
			card.textContent = '';
			var header = document.createElement('div');
			header.className = 'bn-hover-card__header';
			var av = document.createElement('div');
			av.className = 'bn-hover-card__avatar';
			av.textContent = initials;
			var info = document.createElement('div');
			var nm = document.createElement('div');
			nm.className = 'bn-hover-card__name';
			nm.textContent = name;
			var hd = document.createElement('div');
			hd.className = 'bn-hover-card__handle';
			hd.textContent = handle ? '@' + handle : '';
			info.appendChild(nm);
			info.appendChild(hd);
			header.appendChild(av);
			header.appendChild(info);
			card.appendChild(header);
			var rect = el.getBoundingClientRect();
			card.style.top  = (rect.bottom + 8) + 'px';
			card.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 296)) + 'px';
			card.hidden = false;
		}, 400);
	}, true);
	document.addEventListener('mouseleave', function(e) {
		if (!e.target.closest('.bn-hover-user')) return;
		clearTimeout(hoverTimer);
		leaveTimer = setTimeout(function() { if (card) card.hidden = true; }, 200);
	}, true);
})();

/* ── Toast notification helper ── */
window.bnToast = function(msg, type) {
	var c = document.querySelector('.bn-toast-container');
	if (!c) {
		c = document.createElement('div');
		c.className = 'bn-toast-container';
		document.body.appendChild(c);
	}
	var t = document.createElement('div');
	t.className = 'bn-toast' + (type ? ' bn-toast--' + type : '');
	t.textContent = msg;
	c.appendChild(t);
	setTimeout(function() { t.remove(); }, 3000);
};
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

		<div class="bn-nav-notif-wrap">
			<button type="button"
				class="bn-nav-item<?php echo 'notifications' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
				onclick="bnToggleNotifDropdown(this)"
				aria-expanded="false"
				aria-haspopup="true">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
				<?php esc_html_e( 'Notifications', 'buddynext' ); ?>
				<?php if ( $bn_unread_notifs > 0 ) : ?>
					<span class="bn-nav-pill"><?php echo esc_html( $bn_unread_notifs > 99 ? '99+' : (string) $bn_unread_notifs ); ?></span>
				<?php endif; ?>
			</button>
			<div class="bn-notif-dropdown" hidden>
				<div class="bn-notif-dropdown__header">
					<span class="bn-notif-dropdown__title"><?php esc_html_e( 'Notifications', 'buddynext' ); ?></span>
					<?php if ( $bn_unread_notifs > 0 ) : ?>
						<button type="button" class="bn-notif-dropdown__mark-read" onclick="bnMarkAllRead()"><?php esc_html_e( 'Mark all read', 'buddynext' ); ?></button>
					<?php endif; ?>
				</div>
				<div class="bn-notif-dropdown__list" id="bn-notif-dropdown-list">
					<div class="bn-notif-dropdown__loading"><?php esc_html_e( 'Loading...', 'buddynext' ); ?></div>
				</div>
				<a href="<?php echo esc_url( $bn_nav_urls['notifications'] ); ?>" class="bn-notif-dropdown__see-all"><?php esc_html_e( 'See all notifications', 'buddynext' ); ?></a>
			</div>
		</div>

		<a href="<?php echo esc_url( $bn_nav_urls['messages'] ); ?>"
			class="bn-nav-item<?php echo 'messages' === $bn_nav_active ? ' bn-nav-active' : ''; ?>"
			<?php echo 'messages' === $bn_nav_active ? 'aria-current="page"' : ''; ?>>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
			<?php esc_html_e( 'Messages', 'buddynext' ); ?>
			<?php if ( $bn_unread_messages > 0 ) : ?>
				<span class="bn-nav-pill"><?php echo esc_html( $bn_unread_messages > 99 ? '99+' : (string) $bn_unread_messages ); ?></span>
			<?php endif; ?>
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
<?php
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
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
		<span><?php esc_html_e( 'Feed', 'buddynext' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $bn_nav_urls['spaces'] ); ?>" class="bn-mobile-nav__item<?php echo 'spaces' === $bn_nav_active ? ' bn-mobile-nav__item--active' : ''; ?>">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
		<span><?php esc_html_e( 'Spaces', 'buddynext' ); ?></span>
	</a>
	<button type="button" class="bn-mobile-nav__item bn-mobile-nav__item--create" onclick="window.location='<?php echo esc_url( $bn_nav_urls['feed'] ); ?>?compose=1'">
		<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	</button>
	<a href="<?php echo esc_url( $bn_nav_urls['notifications'] ); ?>" class="bn-mobile-nav__item<?php echo 'notifications' === $bn_nav_active ? ' bn-mobile-nav__item--active' : ''; ?>">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
		<?php if ( $bn_unread_notifs > 0 ) : ?>
			<span class="bn-mobile-nav__badge"><?php echo esc_html( $bn_unread_notifs > 9 ? '9+' : (string) $bn_unread_notifs ); ?></span>
		<?php endif; ?>
		<span><?php esc_html_e( 'Alerts', 'buddynext' ); ?></span>
	</a>
	<a href="<?php echo esc_url( \BuddyNext\Core\PageRouter::profile_url( $bn_nav_current_user ) ); ?>" class="bn-mobile-nav__item">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
		<span><?php esc_html_e( 'Profile', 'buddynext' ); ?></span>
	</a>
</nav>
<?php endif; ?>
