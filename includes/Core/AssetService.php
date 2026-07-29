<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Frontend and admin asset registration and enqueueing.
 *
 * Registers all BuddyNext CSS and JS handles on wp_enqueue_scripts.
 * Individual handles are enqueued lazily by the ShortcodeService,
 * template partials, or the Interactivity API stores.
 *
 * Admin assets (bn-admin.css) are enqueued on admin_enqueue_scripts
 * for any BuddyNext submenu page.
 *
 * Enqueue handles from any template:
 *   wp_enqueue_style( 'bn-feed' );
 *   wp_enqueue_script( 'bn-feed' );
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Registers and enqueues BuddyNext frontend assets.
 */
class AssetService {

	/**
	 * Plugin version string — used as cache-buster.
	 */
	private const VERSION = BUDDYNEXT_VERSION;

	/**
	 * Base URL for plugin assets (with trailing slash).
	 *
	 * @var string
	 */
	private string $assets_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->assets_url = BUDDYNEXT_URL . 'assets/';
	}

	/**
	 * Register assets and hook into wp_enqueue_scripts and admin_enqueue_scripts.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_script_modules' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'inject_interactivity_i18n' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// BuddyNext is the style-guide boss: always load bn-base (fonts + tokens)
		// on every frontend page so Jetonomy and WPMediaVerse pick up the design
		// system via their var() token chains regardless of which plugin's page
		// the visitor is on.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_global_tokens' ), 15 );

		// Cache-bust every BuddyNext asset by its file mtime so CSS/JS edits
		// always reach the browser (the plugin version string stays fixed
		// pre-release). One filter covers all BN handles — front-end + admin.
		add_filter( 'style_loader_src', array( $this, 'version_by_mtime' ), 20 );
		add_filter( 'script_loader_src', array( $this, 'version_by_mtime' ), 20 );
	}

	/**
	 * Stamp BuddyNext asset URLs with their file mtime as the `ver` query arg.
	 *
	 * Only rewrites URLs under the BuddyNext plugin directory; everything else
	 * passes through untouched.
	 *
	 * @param string $src Asset source URL.
	 * @return string
	 */
	public function version_by_mtime( string $src ): string {
		if ( ! defined( 'BUDDYNEXT_URL' ) || ! defined( 'BUDDYNEXT_DIR' ) ) {
			return $src;
		}
		$base = (string) constant( 'BUDDYNEXT_URL' );
		if ( 0 !== strpos( $src, $base ) ) {
			return $src;
		}
		$path = constant( 'BUDDYNEXT_DIR' ) . substr( strtok( $src, '?' ), strlen( $base ) );
		if ( is_file( $path ) ) {
			$base_ver = defined( 'BUDDYNEXT_VERSION' ) ? (string) BUDDYNEXT_VERSION : '';
			$src      = add_query_arg( 'ver', $base_ver . '.' . (string) filemtime( $path ), $src );
		}
		return $src;
	}

	/**
	 * Enqueue the base stylesheet (fonts + token attachment point) globally.
	 *
	 * Fires at priority 15 — after register_assets() (10) registers the handles,
	 * before TokenService::attach_tokens() (20) injects the inline CSS.
	 */
	public function enqueue_global_tokens(): void {
		if ( ! is_admin() ) {
			wp_enqueue_style( 'bn-base' );

			// Header user section (bell + messages + avatar dropdown) is chrome
			// that can render in ANY theme's header — via the block, the shortcode,
			// or a per-theme auto-place shim — so its CSS loads site-wide, but
			// only for logged-in visitors (the section renders nothing otherwise).
			if ( is_user_logged_in() ) {
				wp_enqueue_style( 'bn-header' );
				// The account menu's open state is a real toggle, so its script
				// travels with the section's CSS rather than with a hub bundle.
				wp_enqueue_script( 'bn-header-user-menu' );
			}
		}
	}

	/**
	 * Enqueue BuddyNext admin CSS on BuddyNext admin pages.
	 *
	 * Only fires when the current admin page slug contains 'buddynext'
	 * (covers both the top-level page and all submenu pages).
	 *
	 * @param string $hook_suffix The hook suffix for the current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'buddynext' ) ) {
			return;
		}

		wp_register_style(
			'bn-fonts',
			$this->assets_url . 'css/bn-fonts.css',
			array(),
			self::VERSION
		);

		// bn-admin.css depends on bn-base.css for the v2 --bn-* token
		// source (canvas, ink, accent ramp, etc.). Without this, the admin
		// surface renders against unresolved aliases.
		wp_register_style(
			'bn-base',
			$this->assets_url . 'css/bn-base.css',
			array( 'bn-fonts' ),
			self::VERSION
		);

		// bn-admin.css holds tokens, shared components, and shared primitives.
		// Page-specific blocks (members, email editor, nav manager) used to
		// live in this file and have been split out below. Each is enqueued
		// only on the page it serves so we don't ship 1.5k lines of Members
		// CSS to the Email editor and vice versa.
		wp_enqueue_style(
			'bn-admin',
			$this->assets_url . 'css/bn-admin.css',
			array( 'bn-fonts', 'bn-base' ),
			self::VERSION
		);

		// Shared confirm-modal + toast helper — replaces browser
		// confirm()/alert() across every BN admin surface. Loaded site-wide
		// on BN admin so `data-bn-confirm` works regardless of which tab
		// rendered the link/button/form.
		wp_enqueue_style(
			'bn-admin-dialogs',
			$this->assets_url . 'css/bn-admin-dialogs.css',
			array( 'bn-admin' ),
			self::VERSION
		);
		wp_enqueue_script(
			'bn-admin-dialogs',
			$this->assets_url . 'js/admin/bn-admin-dialogs.js',
			array( 'wp-i18n' ),
			self::VERSION,
			true
		);
		wp_set_script_translations( 'bn-admin-dialogs', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// Members admin (Members + Member Types + Profile Fields + Avatar
		// Settings + Member Type Field). Lives at ?page=buddynext-members.
		if ( false !== strpos( $hook_suffix, 'buddynext-members' ) ) {
			wp_enqueue_style(
				'bn-admin-members',
				$this->assets_url . 'css/bn-admin-members.css',
				array( 'bn-admin' ),
				self::VERSION
			);
		}

		// Shared taxonomy editor (Member Types tab + Spaces > Categories subtab).
		// Both surfaces render templates/parts/taxonomy-editor.php, so the editor
		// CSS + live-preview JS load on either page.
		if ( false !== strpos( $hook_suffix, 'buddynext-members' )
			|| false !== strpos( $hook_suffix, 'buddynext-spaces' ) ) {
			wp_enqueue_style(
				'bn-admin-taxonomy',
				$this->assets_url . 'css/bn-admin-taxonomy.css',
				array( 'bn-admin' ),
				self::VERSION
			);
			wp_enqueue_script(
				'bn-admin-taxonomy',
				$this->assets_url . 'js/admin/taxonomy-editor.js',
				array( 'wp-i18n' ),
				self::VERSION,
				true
			);
			wp_set_script_translations( 'bn-admin-taxonomy', 'buddynext', BUDDYNEXT_DIR . 'languages' );
		}

		// Shared media-library picker for single-image fields
		// (AdminPageBase::render_media_row). Registered on every BN admin page
		// so any consumer — including Pro's White-label tab — enqueues the same
		// handle instead of rolling its own; enqueued below only where free
		// renders a media row.
		wp_register_script(
			'bn-admin-media',
			$this->assets_url . 'js/admin/media-picker.js',
			array( 'wp-i18n' ),
			self::VERSION,
			true
		);
		wp_set_script_translations( 'bn-admin-media', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// Settings → Appearance: the media picker drives the Logo field, and the
		// core code editor (CodeMirror) upgrades the Custom CSS textarea. Both
		// fall back gracefully — plain URL input / plain textarea — when
		// unavailable (user disabled syntax highlighting, blocked JS).
		if ( \BuddyNext\Admin\AdminHub::is_tab_active( 'appearance' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'bn-admin-media' );

			$bn_editor = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
			if ( false !== $bn_editor ) {
				wp_add_inline_script(
					'code-editor',
					sprintf(
						'( function() { var bnInitCssEditor = function() { if ( window.wp && wp.codeEditor && document.getElementById( "bn-custom-css" ) ) { wp.codeEditor.initialize( "bn-custom-css", %s ); } }; if ( "loading" === document.readyState ) { document.addEventListener( "DOMContentLoaded", bnInitCssEditor ); } else { bnInitCssEditor(); } } )();',
						wp_json_encode( $bn_editor )
					)
				);
			}
		}

		// Email Templates editor — wherever the central placement map routes the
		// 'templates' tab (Notifications section). Gating on the tab slug keeps
		// its assets attached no matter which section owns it.
		if ( \BuddyNext\Admin\AdminHub::is_tab_active( 'templates' ) ) {
			wp_enqueue_style(
				'bn-admin-email',
				$this->assets_url . 'css/bn-admin-email.css',
				array( 'bn-admin' ),
				self::VERSION
			);
			wp_enqueue_script(
				'bn-email-editor',
				$this->assets_url . 'js/admin/email-editor.js',
				array( 'wp-i18n' ),
				self::VERSION,
				true
			);
			wp_set_script_translations( 'bn-email-editor', 'buddynext', BUDDYNEXT_DIR . 'languages' );

			// Pass the REAL branded shell + sample merge-tag values so the editor
			// preview is byte-identical to a genuine send (one uniform header +
			// footer) and never shows raw {{tokens}}.
			wp_localize_script(
				'bn-email-editor',
				'bnEmailEditorPreview',
				array(
					'shell'  => \BuddyNext\Notifications\EmailSender::brand_wrap( '{{BNBODY}}', '{{BNSUBJECT}}' ),
					'sample' => array(
						'site_name'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
						'site_url'        => home_url( '/' ),
						'user_name'       => __( 'Alex Rivera', 'buddynext' ),
						'first_name'      => __( 'Alex', 'buddynext' ),
						'user_email'      => 'alex@example.com',
						'actor_name'      => __( 'Jordan Lee', 'buddynext' ),
						'follower_name'   => __( 'Jordan Lee', 'buddynext' ),
						'connector_name'  => __( 'Jordan Lee', 'buddynext' ),
						'mentioner_name'  => __( 'Jordan Lee', 'buddynext' ),
						'reactor_name'    => __( 'Jordan Lee', 'buddynext' ),
						'commenter_name'  => __( 'Jordan Lee', 'buddynext' ),
						'sharer_name'     => __( 'Jordan Lee', 'buddynext' ),
						'login_url'       => class_exists( \BuddyNext\Core\PageRouter::class ) ? \BuddyNext\Core\PageRouter::auth_url() : home_url( '/' ),
						'action_url'      => home_url( '/' ),
						'unsubscribe_url' => '#',
						'current_year'    => gmdate( 'Y' ),
					),
				)
			);
		}

		// Navigation Manager — wherever the 'navigation' tab is routed.
		if ( \BuddyNext\Admin\AdminHub::is_tab_active( 'navigation' ) ) {
			wp_enqueue_style(
				'bn-admin-nav',
				$this->assets_url . 'css/bn-admin-nav.css',
				array( 'bn-admin' ),
				self::VERSION
			);
		}

		// Stamp v2 theme + density attributes on the admin <html> so the
		// [data-bn-*] selectors fire on every BuddyNext admin page.
		add_filter(
			'language_attributes',
			static function ( string $output ): string {
				if ( false !== strpos( $output, 'data-bn-theme=' ) ) {
					return $output;
				}
				return $output . ' data-bn-theme="light" data-bn-density="comfortable"';
			}
		);
	}

	/**
	 * Register all CSS and JS handles.
	 *
	 * Nothing is enqueued here — handles are activated on demand by templates.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$v = self::VERSION;

		// ── Web fonts — self-hosted Inter + Plus Jakarta Sans ─────────────────
		// Fonts live in assets/fonts/ — no Google Fonts, no network dependency.
		// bn-base depends on this so fonts load whenever BuddyNext is active.
		// Theme override: if the active theme's theme.json defines a "body" or
		// "display" font-family preset, WordPress generates
		// --wp--preset--font-family--body / --wp--preset--font-family--display
		// which override --font-body / --font-display in TokenService.
		wp_register_style(
			'bn-fonts',
			$this->assets_url . 'css/bn-fonts.css',
			array(),
			$v
		);

		// ── Global base styles ─────────────────────────────────────────────────
		wp_register_style(
			'bn-base',
			$this->assets_url . 'css/bn-base.css',
			array( 'bn-fonts' ),
			$v
		);

		// ── Hub shell styles (full-viewport canvas, rail, optional right sidebar) ──
		// Loaded on every BuddyNext hub by PageRouter::enqueue_hub_assets().
		// Depends on bn-base for the token system.
		wp_register_style(
			'bn-shell',
			$this->assets_url . 'css/bn-shell.css',
			array( 'bn-base' ),
			$v
		);

		// ── Shell font-scale + theme bootstrap script ──────────────────────────
		// Classic script (not a module) — must run before the rail renders
		// so saved preferences are applied without a flash. Loaded in the
		// head and runs immediately. Themes that ship font-scale / theme
		// controls trigger updates via the data-bn-action attributes
		// documented in the script header.
		wp_register_script(
			'bn-shell-font-scale',
			$this->assets_url . 'js/shell/font-scale.js',
			array( 'wp-i18n' ),
			$v,
			false
		);
		wp_set_script_translations( 'bn-shell-font-scale', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// ── Shell extras (search overlay, notif dropdown, hover card, shortcuts) ─
		// Loaded in the footer so it can hydrate any shell-level UI surfaces.
		// Data is localized by PageRouter::enqueue_hub_assets() into
		// window.bnShellData before this script runs.
		wp_register_script(
			'bn-shell-extras',
			$this->assets_url . 'js/shell/extras.js',
			array( 'wp-i18n' ),
			$v,
			true
		);
		wp_set_script_translations( 'bn-shell-extras', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// ── Account menu disclosure ────────────────────────────────────────────
		// Ships with the header CSS rather than the hub bundles: the header
		// section renders in ANY theme, through the block, the shortcode or a
		// per-theme shim, on ordinary theme pages as well as hub pages. Fully
		// delegated from the document, so it needs no data and no mount point and
		// survives a client-side navigation.
		wp_register_script(
			'bn-header-user-menu',
			$this->assets_url . 'js/header/user-menu.js',
			array(),
			$v,
			true
		);

		// ── Feature CSS (each depends on the base) ─────────────────────────────
		$feature_styles = array(
			'bn-feed',
			'bn-explore',
			'bn-profile',
			'bn-spaces',
			'bn-members',
			'bn-messages',
			'bn-notifications',
			'bn-notification-prefs',
			'bn-search',
			'bn-hashtags',
			'bn-auth',
			'bn-onboarding',
			'bn-gamification',
			'bn-moderation',
			'bn-connections',
			'bn-header',
			'bn-settings',
			'bn-media-upload',
		);

		// A few feature stylesheets reuse another feature's shared component and
		// therefore depend on it (not just bn-base). bn-spaces renders space
		// member cards through the shared .bn-md-card component, which lives in
		// bn-members.css — so it must load alongside bn-spaces. The media upload
		// composer styles the same tiles bn-media.css owns, so it loads after it.
		$extra_style_deps = array(
			'bn-spaces'       => array( 'bn-members' ),
			'bn-media-upload' => array( 'bn-media' ),
		);

		foreach ( $feature_styles as $handle ) {
			$slug = str_replace( 'bn-', '', $handle );
			$deps = array_merge( array( 'bn-base' ), $extra_style_deps[ $handle ] ?? array() );
			wp_register_style(
				$handle,
				$this->assets_url . 'css/' . $handle . '.css',
				$deps,
				$v
			);
		}
	}

	/**
	 * Register BuddyNext Interactivity API stores as WordPress Script Modules.
	 *
	 * WordPress 6.5+ uses the Script Modules system for the Interactivity API.
	 * Store files are ES modules that import from '@wordpress/interactivity'.
	 * Registered IDs follow the '@buddynext/{feature}' convention.
	 *
	 * @return void
	 */
	public function register_script_modules(): void {
		// Shared shell modules — used as dependencies by feature stores.
		// `@buddynext/shell-dialog` exposes bnConfirm/bnPrompt/bnToast for
		// stores that need accessible replacements for window.confirm/prompt.
		wp_register_script_module(
			'@buddynext/shell-dialog',
			$this->assets_url . 'js/shell/dialog.js',
			array(),
			$this->module_version( 'js/shell/dialog.js' )
		);

		// `@buddynext/rest-client` is the single front-end REST client
		// (restFetch + automatic stale-nonce refresh). Every feature store
		// imports it; it depends on shell-dialog for the error toast.
		wp_register_script_module(
			'@buddynext/rest-client',
			$this->assets_url . 'js/shell/rest-client.js',
			array( array( 'id' => '@buddynext/shell-dialog' ) ),
			$this->module_version( 'js/shell/rest-client.js' )
		);

		// `@buddynext/nav-init` exposes onNavReady() — the uniform init binder
		// every store uses so imperative setup re-runs after a client-side
		// navigation (buddynext:navigated), not only on DOMContentLoaded.
		wp_register_script_module(
			'@buddynext/nav-init',
			$this->assets_url . 'js/shell/nav-init.js',
			array(),
			$this->module_version( 'js/shell/nav-init.js' )
		);

		// `@buddynext/navigate` owns the bare-`buddynext` store's navigate
		// action. The Interactivity router loads as a dynamic dependency, so it
		// is fetched once on the first client-side navigation and reused.
		wp_register_script_module(
			'@buddynext/navigate',
			$this->assets_url . 'js/shell/navigate.js',
			array(
				array( 'id' => '@wordpress/interactivity' ),
				array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				),
			),
			$this->module_version( 'js/shell/navigate.js' )
		);

		$feature_modules = array(
			'@buddynext/feed'               => 'feed/store',
			'@buddynext/profile'            => 'profile/store',
			'@buddynext/spaces'             => 'spaces/store',
			'@buddynext/members'            => 'members/store',
			'@buddynext/messages'           => 'messages/store',
			'@buddynext/notifications'      => 'notifications/store',
			'@buddynext/notification-prefs' => 'notifications/prefs-store',
			'@buddynext/search'             => 'search/store',
			'@buddynext/hashtags'           => 'hashtags/store',
			'@buddynext/auth'               => 'auth/store',
			'@buddynext/auth-login'         => 'auth/login-store',
			'@buddynext/auth-signup'        => 'auth/signup-store',
			'@buddynext/auth-verify'        => 'auth/verify-store',
			'@buddynext/auth-reset'         => 'auth/reset-store',
			'@buddynext/onboarding'         => 'onboarding/store',
			'@buddynext/gamification'       => 'gamification/store',
			'@buddynext/moderation'         => 'moderation/store',
			'@buddynext/space-members'      => 'space-members/store',
			'@buddynext/space-fields'       => 'space-fields/store',
			'@buddynext/social-buttons'     => 'social/follow-store',
			'@buddynext/media-upload'       => 'media/upload-store',
			'@buddynext/media-albums'       => 'media/albums-store',
		);

		// Feature stores that import from ../shell/dialog.js need the
		// shell-dialog module declared as a dependency so WP emits the
		// correct import-map entry and the browser fetches it as a module.
		$shell_dialog_consumers = array(
			'@buddynext/feed',
			'@buddynext/moderation',
			'@buddynext/space-members',
			'@buddynext/space-fields',
			'@buddynext/profile',
			'@buddynext/members',
			'@buddynext/social-buttons',
			'@buddynext/messages',
			'@buddynext/media-upload',
			'@buddynext/media-albums',
		);

		foreach ( $feature_modules as $id => $path ) {
			// Every store imports the shared REST client and the nav-init
			// helper, so both are declared as dependencies uniformly (the
			// browser still only fetches what a module actually imports).
			$deps = array(
				array( 'id' => '@wordpress/interactivity' ),
				array( 'id' => '@buddynext/rest-client' ),
				array( 'id' => '@buddynext/nav-init' ),
			);
			if ( in_array( $id, $shell_dialog_consumers, true ) ) {
				$deps[] = array( 'id' => '@buddynext/shell-dialog' );
			}
			// The feed paginates by letting the Interactivity Router swap its region
			// (actions.loadMore) — the only supported way to add cards that are actually
			// hydrated, since the API hydrates islands present at first paint and injected
			// markup stays inert. Declared dynamic, exactly as @buddynext/navigate does, so
			// the router is fetched on the first Load-more click rather than on every feed
			// page view.
			if ( '@buddynext/feed' === $id ) {
				$deps[] = array(
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				);
			}
			wp_register_script_module(
				$id,
				$this->assets_url . 'js/' . $path . '.js',
				$deps,
				$this->module_version( 'js/' . $path . '.js' )
			);
		}
	}

	/**
	 * Compute an mtime-based version string for a script module.
	 *
	 * Script modules are registered via wp_register_script_module() and emitted
	 * through the import-map, so they never pass through the script_loader_src
	 * filter that version_by_mtime() hooks. Without this, module JS edits keep
	 * the fixed BUDDYNEXT_VERSION query arg and stay cached in the browser. This
	 * mirrors version_by_mtime() so module edits also always reach the browser.
	 *
	 * @param string $relative Asset-relative path (e.g. 'js/feed/store.js').
	 * @return string Version string (base version plus file mtime when readable).
	 */
	private function module_version( string $relative ): string {
		$base = (string) self::VERSION;
		if ( ! defined( 'BUDDYNEXT_DIR' ) ) {
			return $base;
		}
		$path = constant( 'BUDDYNEXT_DIR' ) . 'assets/' . ltrim( $relative, '/' );
		if ( is_file( $path ) ) {
			return $base . '.' . (string) filemtime( $path );
		}
		return $base;
	}

	/**
	 * Enqueue a named feature bundle (CSS + Script Module together).
	 *
	 * Enqueues the CSS stylesheet and the WP Script Module for the given
	 * feature slug. Called from PageRouter before wp_head() fires so both
	 * assets are included in the page output.
	 *
	 * @param string $feature Feature slug without prefix (e.g. 'feed', 'profile').
	 * @return void
	 */
	/**
	 * Inject translated string dictionaries into the Interactivity API state so
	 * Script Modules can render localized copy. Script Modules cannot use
	 * wp_set_script_translations() (no per-module JED loading in core), so each
	 * feature's user-facing strings are translated server-side here and read in
	 * the store via state.i18n.<key> with the English literal as a JS fallback.
	 * Interpolated strings use sprintf-style placeholders (e.g. '@%s') so the
	 * full phrase stays translatable and word order is locale-controlled.
	 *
	 * @return void
	 */
	public function inject_interactivity_i18n(): void {
		if ( is_admin() || ! function_exists( 'wp_interactivity_state' ) ) {
			return;
		}
		$this->i18n_social();
		$this->i18n_feed();
		$this->i18n_profile();
		$this->i18n_members();
		$this->i18n_spaces();
		$this->i18n_messages();
		$this->i18n_media();
		$this->i18n_moderation();
		$this->i18n_onboarding();
		$this->i18n_notifications();
		$this->i18n_notification_prefs();
		$this->i18n_search();
		$this->i18n_hashtags();
		$this->i18n_space_members();
		$this->i18n_space_fields();
		$this->i18n_auth();
		$this->i18n_auth_login();
		$this->i18n_auth_signup();
		$this->i18n_auth_verify();
		$this->i18n_auth_reset();
	}

	/**
	 * Social/follow-store: Follow + Connect buttons and their request inboxes.
	 * One shared dictionary is read by all four social namespaces in the store.
	 *
	 * @return void
	 */
	private function i18n_social(): void {
		wp_interactivity_state(
			'buddynext/follow-button',
			array(
				'i18n' => array(
					// Follow button labels.
					'follow'                  => __( 'Follow', 'buddynext' ),
					'following'               => __( 'Following', 'buddynext' ),
					'requested'               => __( 'Requested', 'buddynext' ),
					'ariaFollow'              => __( 'Follow this user', 'buddynext' ),
					'ariaUnfollow'            => __( 'Unfollow this user', 'buddynext' ),
					'ariaCancelRequest'       => __( 'Cancel follow request', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastUnfollowed'         => __( 'Unfollowed @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestCancelled'   => __( 'Follow request to @%s cancelled', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestSent'        => __( 'Follow request sent to @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastNowFollowing'       => __( 'Now following @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastCouldNotUnfollow'   => __( 'Could not unfollow @%s. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastCouldNotFollow'     => __( 'Could not follow @%s. Try again.', 'buddynext' ),
					// Follow-request inbox.
					/* translators: %s: member display name. */
					'toastCanFollowYou'       => __( '@%s can now follow you', 'buddynext' ),
					'toastApproveFailed'      => __( 'Could not approve request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestDeclined'    => __( 'Request from @%s declined', 'buddynext' ),
					'toastDeclineFailed'      => __( 'Could not decline request. Try again.', 'buddynext' ),
					// Connection button labels.
					'connect'                 => __( 'Connect', 'buddynext' ),
					'connected'               => __( 'Connected', 'buddynext' ),
					'respond'                 => __( 'Respond', 'buddynext' ),
					/* translators: %s: member display name. */
					'noteBody'                => __( 'Add a personal message to your request to @%s, or send it without one.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastConnectionSent'     => __( 'Connection request sent to @%s', 'buddynext' ),
					'toastCouldNotConnect'    => __( 'Could not send connection request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestWithdrawn'   => __( 'Request to @%s withdrawn', 'buddynext' ),
					'toastCouldNotWithdraw'   => __( 'Could not withdraw request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastConnectedWith'      => __( 'Connected with @%s', 'buddynext' ),
					'toastCouldNotAccept'     => __( 'Could not accept request. Try again.', 'buddynext' ),
					'toastCouldNotDecline'    => __( 'Could not decline request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastDisconnected'       => __( 'Disconnected from @%s', 'buddynext' ),
					'toastCouldNotDisconnect' => __( 'Could not disconnect. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Feed/store: post cards, composer, comments, share modal, and realtime pills.
	 * One shared dictionary is read by every namespace + DOM builder in feed/store.js.
	 *
	 * @return void
	 */
	private function i18n_feed(): void {
		wp_interactivity_state(
			'buddynext/feed',
			array(
				// Canonical hub URLs for client-side navigation. Resolved through
				// PageRouter so renamed hub slugs (buddynext_slug_*) are honoured —
				// the store must never hand-roll '/activity/...' paths.
				'urls'          => array(
					'search'      => \BuddyNext\Core\PageRouter::search_url(),
					'hashtagBase' => \BuddyNext\Core\PageRouter::activity_url() . 'hashtag/',
					'people'      => \BuddyNext\Core\PageRouter::people_url(),
					'spaces'      => \BuddyNext\Core\PageRouter::spaces_url(),
				),
				// Where a handle ends, per \BuddyNext\Profile\Handle — the same
				// definition the PHP mention parsers use. The composer typeahead
				// walks this set backwards from the cursor to find the token, so a
				// JS copy that drifted from PHP would offer the member a mention
				// the server then failed to resolve.
				'handleCharset' => \BuddyNext\Profile\Handle::CHARSET,
				// Scheduling speaks the SITE's timezone, not the browser's. A <input
				// type="datetime-local"> is browser-local by nature, so the store needs the
				// site's offset to translate both ways. Without it an author in IST picks
				// "12:50" and the post card — which renders with wp_date() in the site zone —
				// reports "7:20 am". Same instant, two numbers, and it reads as a bug.
				//
				// Seconds offset for the target instant, so DST is handled: a fixed offset
				// would drift by an hour for any site scheduling across a DST boundary.
				'tz'            => array(
					'offset' => (int) ( timezone_offset_get( wp_timezone(), new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) ) ),
					'label'  => wp_timezone()->getName(),
				),
				'i18n'          => array(
					// Reschedule control on the post-card edit form.
					'scheduledFor'            => __( 'Scheduled for', 'buddynext' ),
					/* translators: %s: the site's timezone, e.g. "Asia/Kolkata" or "+00:00". */
					'scheduledForTz'          => __( 'Scheduled for (%s)', 'buddynext' ),
					'scheduleInvalid'         => __( 'Pick a valid date and time.', 'buddynext' ),
					'schedulePast'            => __( 'Pick a time in the future.', 'buddynext' ),
					'timeJustNow'             => __( 'just now', 'buddynext' ),
					/* translators: %d: number of minutes. */
					'timeMinutesAgo'          => __( '%dm ago', 'buddynext' ),
					/* translators: %d: number of hours. */
					'timeHoursAgo'            => __( '%dh ago', 'buddynext' ),
					/* translators: %d: number of days. */
					'timeDaysAgo'             => __( '%dd ago', 'buddynext' ),
					'user'                    => __( 'User', 'buddynext' ),
					'you'                     => __( 'You', 'buddynext' ),
					/* translators: %s: user ID */
					'userNumber'              => __( 'User #%s', 'buddynext' ),
					'loading'                 => __( 'Loading…', 'buddynext' ),
					'retry'                   => __( 'Retry', 'buddynext' ),
					'save'                    => __( 'Save', 'buddynext' ),
					'cancel'                  => __( 'Cancel', 'buddynext' ),
					'edit'                    => __( 'Edit', 'buddynext' ),
					'delete'                  => __( 'Delete', 'buddynext' ),
					'reply'                   => __( 'Reply', 'buddynext' ),
					'report'                  => __( 'Report', 'buddynext' ),
					'pin'                     => __( 'Pin', 'buddynext' ),
					'unpin'                   => __( 'Unpin', 'buddynext' ),
					'pinned'                  => __( 'Pinned', 'buddynext' ),
					'edited'                  => __( '(edited)', 'buddynext' ),
					'editedSpaced'            => __( ' (edited)', 'buddynext' ),
					'cannotBeUndone'          => __( 'This cannot be undone.', 'buddynext' ),
					'networkError'            => __( 'Network error. Try again.', 'buddynext' ),
					'emoji'                   => __( 'Emoji', 'buddynext' ),
					'insertEmoji'             => __( 'Insert emoji', 'buddynext' ),
					'image'                   => __( 'image', 'buddynext' ),
					'react'                   => __( 'React', 'buddynext' ),
					'chooseReaction'          => __( 'Choose reaction', 'buddynext' ),
					'reactionLike'            => __( 'Like', 'buddynext' ),
					'reactionLove'            => __( 'Love', 'buddynext' ),
					'reactionHaha'            => __( 'Haha', 'buddynext' ),
					'reactionWow'             => __( 'Wow', 'buddynext' ),
					'reactionSad'             => __( 'Sad', 'buddynext' ),
					'reactionAngry'           => __( 'Angry', 'buddynext' ),
					'signInToReact'           => __( 'Sign in to react to comments.', 'buddynext' ),
					'reactionUpdateFailed'    => __( 'Could not update your reaction. Try again.', 'buddynext' ),
					'reactionsLoadFailed'     => __( 'Could not load reactions. Try again.', 'buddynext' ),
					'oneReaction'             => __( '1 reaction', 'buddynext' ),
					/* translators: %d: number of reactions */
					'manyReactions'           => __( '%d reactions', 'buddynext' ),
					'writeReply'              => __( 'Write a reply...', 'buddynext' ),
					'postReply'               => __( 'Post reply', 'buddynext' ),
					'commentDeleted'          => __( 'This comment was deleted.', 'buddynext' ),
					'commentDeletedToast'     => __( 'Comment deleted', 'buddynext' ),
					'commentDeleteFailed'     => __( 'Could not delete comment. Try again.', 'buddynext' ),
					'commentUpdated'          => __( 'Comment updated', 'buddynext' ),
					'commentUpdateFailed'     => __( 'Could not update comment. Try again.', 'buddynext' ),
					'commentAdded'            => __( 'Comment added', 'buddynext' ),
					'commentPostFailed'       => __( 'Could not post your comment. Try again.', 'buddynext' ),
					'commentsLoadFailed'      => __( 'Could not load comments. ', 'buddynext' ),
					'commentsNetworkError'    => __( 'Network error. Comments could not be loaded.', 'buddynext' ),
					'commentPinned'           => __( 'Comment pinned', 'buddynext' ),
					'commentUnpinned'         => __( 'Comment unpinned', 'buddynext' ),
					'pinStatusFailed'         => __( 'Could not change pin status. Try again.', 'buddynext' ),
					'replyFailed'             => __( 'Could not post reply. Try again.', 'buddynext' ),
					'deleteCommentTitle'      => __( 'Delete this comment?', 'buddynext' ),
					'reportComment'           => __( 'Report this comment', 'buddynext' ),
					'oneComment'              => __( '1 comment', 'buddynext' ),
					/* translators: %d: number of comments */
					'manyComments'            => __( '%d comments', 'buddynext' ),
					'reportPost'              => __( 'Report this post', 'buddynext' ),
					'reportSubmitted'         => __( 'Report submitted. Thanks for keeping the community safe.', 'buddynext' ),
					'reportFailed'            => __( 'Could not submit report. Try again.', 'buddynext' ),
					'saved'                   => __( 'Saved', 'buddynext' ),
					'removedFromSaved'        => __( 'Removed from saved', 'buddynext' ),
					'bookmarkAddFailed'       => __( 'Could not save this post. Try again.', 'buddynext' ),
					'bookmarkRemoveFailed'    => __( 'Could not remove this post from saved. Try again.', 'buddynext' ),
					'deletePostTitle'         => __( 'Delete this post?', 'buddynext' ),
					'postDeleteFailed'        => __( 'Could not delete this post. Try again.', 'buddynext' ),
					'postNotEditable'         => __( 'This post cannot be edited.', 'buddynext' ),
					'editPostContent'         => __( 'Edit post content', 'buddynext' ),
					'postContentEmpty'        => __( 'Post content cannot be empty.', 'buddynext' ),
					'postUpdated'             => __( 'Post updated', 'buddynext' ),
					'postUpdateFailed'        => __( 'Could not update the post. Try again.', 'buddynext' ),
					'linkPreviewAttached'     => __( 'Link preview attached', 'buddynext' ),
					'removeLinkPreview'       => __( 'Remove link preview', 'buddynext' ),
					'postPinned'              => __( 'Post pinned', 'buddynext' ),
					'postUnpinned'            => __( 'Post unpinned', 'buddynext' ),
					'postPinFailed'           => __( 'Could not pin this post. Try again.', 'buddynext' ),
					'postUnpinFailed'         => __( 'Could not unpin this post. Try again.', 'buddynext' ),
					'voteRecorded'            => __( 'Vote recorded', 'buddynext' ),
					'voteFailed'              => __( 'Could not record your vote. Try again.', 'buddynext' ),
					'announcementEnded'       => __( 'Announcement ended', 'buddynext' ),
					'announcementEndFailed'   => __( 'Could not end the announcement. Try again.', 'buddynext' ),
					'share'                   => __( 'Share', 'buddynext' ),
					'shared'                  => __( 'Shared', 'buddynext' ),
					/* translators: %d: share count */
					'shareWithCount'          => __( 'Share · %d', 'buddynext' ),
					/* translators: %d: share count */
					'sharedWithCount'         => __( 'Shared · %d', 'buddynext' ),
					'repost'                  => __( 'Repost', 'buddynext' ),
					'reposting'               => __( 'Reposting…', 'buddynext' ),
					'reposted'                => __( 'Reposted', 'buddynext' ),
					'repostFailed'            => __( 'Could not repost. Try again.', 'buddynext' ),
					'linkCopied'              => __( 'Link copied', 'buddynext' ),
					'linkCopyFailed'          => __( 'Could not copy link.', 'buddynext' ),
					'post'                    => __( 'Post', 'buddynext' ),
					'posting'                 => __( 'Posting…', 'buddynext' ),
					'postPublished'           => __( 'Post published', 'buddynext' ),
					'postScheduled'           => __( 'Post scheduled', 'buddynext' ),
					'postSubmittedForReview'  => __( 'Your post was submitted for review.', 'buddynext' ),
					'postPublishFailed'       => __( 'Could not publish your post. Try again.', 'buddynext' ),
					'noPermissionToPost'      => __( 'You don’t have permission to post here.', 'buddynext' ),
					'verifyResent'            => __( 'Verification email sent. Check your inbox.', 'buddynext' ),
					'verifyResendFailed'      => __( 'Could not resend the verification email. Try again.', 'buddynext' ),
					'resendVerification'      => __( 'Resend verification email', 'buddynext' ),
					'reviewAccountStatus'     => __( 'Review your account status', 'buddynext' ),
					'pollMinOptions'          => __( 'Add at least two poll options.', 'buddynext' ),
					'savingDraft'             => __( 'Saving draft…', 'buddynext' ),
					'draftSaved'              => __( 'Draft saved', 'buddynext' ),
					'draftRestored'           => __( 'Draft restored', 'buddynext' ),
					'scheduleRoom'            => __( 'Schedule room', 'buddynext' ),
					'scheduling'              => __( 'Scheduling…', 'buddynext' ),
					'voiceTitleTimeRequired'  => __( 'Title and start time are required.', 'buddynext' ),
					'voiceRoomScheduled'      => __( 'Voice room scheduled', 'buddynext' ),
					'voiceScheduleFailed'     => __( 'Could not schedule the voice room. Try again.', 'buddynext' ),
					'privacyPublic'           => __( 'Public', 'buddynext' ),
					'privacyFollowers'        => __( 'Followers', 'buddynext' ),
					'privacyConnections'      => __( 'Connections', 'buddynext' ),
					'privacyPrivate'          => __( 'Only me', 'buddynext' ),
					'privacySpaceMembers'     => __( 'Space members', 'buddynext' ),
					'imageUploadsUnavailable' => __( 'Image uploads are not available on this site.', 'buddynext' ),
					'mediaEngineInactive'     => __( 'Image uploads are unavailable (media engine not active).', 'buddynext' ),
					/* translators: %d: maximum number of images */
					'maxImagesPerPost'        => __( 'You can attach at most %d images per post.', 'buddynext' ),
					'oneMoreImage'            => __( 'Only 1 more image can be added.', 'buddynext' ),
					/* translators: %d: number of additional images */
					'moreImages'              => __( 'Only %d more images can be added.', 'buddynext' ),
					/* translators: 1: file name, 2: HTTP status code */
					'uploadFailedError'       => __( 'Could not upload %1$s (error %2$d).', 'buddynext' ),
					'joined'                  => __( 'Joined', 'buddynext' ),
					'feedEnd'                 => __( "You've reached the end.", 'buddynext' ),
					'oneNewPost'              => __( '1 new post — refresh to view', 'buddynext' ),
					/* translators: %d: number of new posts */
					'manyNewPosts'            => __( '%d new posts — refresh to view', 'buddynext' ),
					/* translators: %d: the display ceiling for the new-posts pill (e.g. "99+ new posts"). */
					'manyNewPostsCapped'      => __( '%d+ new posts — refresh to view', 'buddynext' ),
					'oneNewComment'           => __( '1 new comment — show', 'buddynext' ),
					/* translators: %d: number of new comments */
					'manyNewComments'         => __( '%d new comments — show', 'buddynext' ),
					// Report button + comment loader + poll results + composer validation.
					// These keys are read by feed/store.js via t() but were never injected,
					// so they always rendered the English JS fallback regardless of locale.
					'reported'                => __( 'Reported', 'buddynext' ),
					'reportAlready'           => __( 'You already reported this.', 'buddynext' ),
					'viewMoreComments'        => __( 'View more comments', 'buddynext' ),
					'loadingComments'         => __( 'Loading…', 'buddynext' ),
					'oneVote'                 => __( '1 vote', 'buddynext' ),
					/* translators: %d: number of votes. */
					'manyVotes'               => __( '%d votes', 'buddynext' ),
					'pollNeedsQuestion'       => __( 'Add a question for your poll.', 'buddynext' ),
					'composerEmpty'           => __( 'Write something to share.', 'buddynext' ),
					'shareFailed'             => __( 'Could not share this post. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Members/store: directory filter bar, JS-built cards, Follow/Connect/kebab
	 * actions, and the cross-surface Block + Report modals.
	 *
	 * @return void
	 */
	private function i18n_members(): void {
		wp_interactivity_state(
			'buddynext/members',
			array(
				'i18n' => array(
					'follow'                        => __( 'Follow', 'buddynext' ),
					'following'                     => __( 'Following', 'buddynext' ),
					'connect'                       => __( 'Connect', 'buddynext' ),
					'connected'                     => __( 'Connected', 'buddynext' ),
					'requested'                     => __( 'Requested', 'buddynext' ),
					'accept'                        => __( 'Accept', 'buddynext' ),
					'decline'                       => __( 'Decline', 'buddynext' ),
					'editProfile'                   => __( 'Edit profile', 'buddynext' ),
					'viewProfile'                   => __( 'View profile', 'buddynext' ),
					'message'                       => __( 'Message', 'buddynext' ),
					'mute'                          => __( 'Mute', 'buddynext' ),
					'unmute'                        => __( 'Unmute', 'buddynext' ),
					'block'                         => __( 'Block', 'buddynext' ),
					'report'                        => __( 'Report', 'buddynext' ),
					'ariaMoreActions'               => __( 'More actions', 'buddynext' ),
					'degreeFirst'                   => __( '1st', 'buddynext' ),
					'degreeSecond'                  => __( '2nd', 'buddynext' ),
					'mutualConnectionSingular'      => __( '1 mutual connection', 'buddynext' ),
					/* translators: %d: number of mutual connections */
					'mutualConnectionPlural'        => __( '%d mutual connections', 'buddynext' ),
					'pagerNext'                     => __( 'Next »', 'buddynext' ),
					'memberFallback'                => __( 'member', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastUnfollowed'               => __( 'Unfollowed @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastNowFollowing'             => __( 'Now following @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastCouldNotUnfollow'         => __( 'Could not unfollow @%s. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastCouldNotFollow'           => __( 'Could not follow @%s. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'noteBody'                      => __( 'Add a personal message to your request to @%s, or send it without one.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastConnectionSent'           => __( 'Connection request sent to @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastCouldNotSendRequest'      => __( 'Could not send request to @%s. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestWithdrawn'         => __( 'Request to @%s withdrawn', 'buddynext' ),
					'toastCouldNotWithdraw'         => __( 'Could not withdraw request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastDisconnected'             => __( 'Disconnected from @%s', 'buddynext' ),
					'toastCouldNotDisconnect'       => __( 'Could not disconnect. Try again.', 'buddynext' ),
					'toastCouldNotUpdateConnection' => __( 'Could not update connection. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastConnectedWith'            => __( 'Connected with @%s', 'buddynext' ),
					'toastCouldNotAccept'           => __( 'Could not accept request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastRequestDeclined'          => __( 'Request from @%s declined', 'buddynext' ),
					'toastCouldNotDecline'          => __( 'Could not decline request. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastMuted'                    => __( 'Muted @%s', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastUnmuted'                  => __( 'Unmuted @%s', 'buddynext' ),
					'toastCouldNotUpdateMute'       => __( 'Could not update mute state. Try again.', 'buddynext' ),
					/* translators: %s: member display name. */
					'blockTitleNamed'               => __( 'Block %s?', 'buddynext' ),
					'blockTitleGeneric'             => __( 'Block this member?', 'buddynext' ),
					/* translators: %s: member display name. */
					'toastBlocked'                  => __( '@%s blocked', 'buddynext' ),
					'toastCouldNotBlock'            => __( 'Could not block. Try again.', 'buddynext' ),
					'toastReportSubmitted'          => __( 'Report submitted. Thanks for keeping the community safe.', 'buddynext' ),
					'toastCouldNotReport'           => __( 'Could not submit report. Try again.', 'buddynext' ),
					'errorLoadMembers'              => __( 'Could not load members. Check your connection and try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Spaces/store: directory, home, membership CTAs, settings, moderation,
	 * composer, invites, image uploaders and the shared confirm modal.
	 *
	 * @return void
	 */
	private function i18n_spaces(): void {
		wp_interactivity_state(
			'buddynext/spaces',
			array(
				'i18n' => array(
					// Inline check-icon SVG so the JS membership-button swap can
					// rebuild the "Joined" state with its leading icon (matching the
					// SSR markup) instead of clobbering it with a text-only label.
					'iconCheck'                       => IconService::render( 'check' ),
					'labelJoin'                       => __( 'Join', 'buddynext' ),
					'labelJoined'                     => __( 'Joined', 'buddynext' ),
					'labelRequested'                  => __( 'Requested', 'buddynext' ),
					'labelRequestToJoin'              => __( 'Request to join', 'buddynext' ),
					'labelManage'                     => __( 'Manage', 'buddynext' ),
					'labelPublic'                     => __( 'Public', 'buddynext' ),
					'ariaJoinedClickToLeave'          => __( 'Joined - click to leave', 'buddynext' ),
					'ariaRequestPendingClickToCancel' => __( 'Request pending - click to cancel', 'buddynext' ),
					/* translators: %d: number of members. */
					'membersCount'                    => __( '%d members', 'buddynext' ),
					'paywallMembersOnly'              => __( 'This space is available to members only.', 'buddynext' ),
					'paywallBecomeMember'             => __( 'Become a Member', 'buddynext' ),
					'paywallNotConfigured'            => __( 'Membership purchase is not configured yet. Please check back soon.', 'buddynext' ),
					'couldNotJoin'                    => __( 'Could not join this space.', 'buddynext' ),
					// requestJoin's failure branch. It suppresses the shared error
					// toast, so without this a rejected request (a banned member, say)
					// only flickered the button and said nothing.
					'couldNotRequestJoin'             => __( 'Could not send your request.', 'buddynext' ),
					'invitationAccepted'              => __( 'Invitation accepted.', 'buddynext' ),
					'couldNotAcceptInvite'            => __( 'Could not accept the invitation.', 'buddynext' ),
					'invitationDeclined'              => __( 'Invitation declined.', 'buddynext' ),
					'couldNotDeclineInvite'           => __( 'Could not decline the invitation.', 'buddynext' ),
					'purchaseNotConfigured'           => __( 'Membership purchase is not configured yet.', 'buddynext' ),
					'redirecting'                     => __( 'Redirecting…', 'buddynext' ),
					'couldNotStartCheckout'           => __( 'Could not start checkout. Please try again later.', 'buddynext' ),
					'requestApproved'                 => __( 'Request approved.', 'buddynext' ),
					'requestDeclined'                 => __( 'Request declined.', 'buddynext' ),
					'couldNotApproveRequest'          => __( 'Could not approve the request.', 'buddynext' ),
					'couldNotDeclineRequest'          => __( 'Could not decline the request.', 'buddynext' ),
					'post'                            => __( 'Post', 'buddynext' ),
					'cancel'                          => __( 'Cancel', 'buddynext' ),
					'posting'                         => __( 'Posting…', 'buddynext' ),
					'reportPost'                      => __( 'Report post', 'buddynext' ),
					'reportSubmitted'                 => __( 'Report submitted. Thanks for keeping the community safe.', 'buddynext' ),
					'couldNotSubmitReport'            => __( 'Could not submit report. Try again.', 'buddynext' ),
					'shared'                          => __( 'Shared!', 'buddynext' ),
					'roleUpdated'                     => __( 'Role updated.', 'buddynext' ),
					'roleModerator'                   => __( 'Moderator', 'buddynext' ),
					'roleMember'                      => __( 'Member', 'buddynext' ),
					'couldNotUpdateRole'              => __( 'Could not update role.', 'buddynext' ),
					'memberRemoved'                   => __( 'Member removed.', 'buddynext' ),
					'couldNotRemoveMember'            => __( 'Could not remove member.', 'buddynext' ),
					'memberBanned'                    => __( 'Member banned.', 'buddynext' ),
					'couldNotBanMember'               => __( 'Could not ban member.', 'buddynext' ),
					'chooseNewOwner'                  => __( 'Choose a new owner.', 'buddynext' ),
					'ownershipTransferred'            => __( 'Ownership transferred.', 'buddynext' ),
					'couldNotTransfer'                => __( 'Could not transfer ownership.', 'buddynext' ),
					'nameDoesNotMatch'                => __( 'The name does not match.', 'buddynext' ),
					'spaceDeleted'                    => __( 'Space deleted.', 'buddynext' ),
					'couldNotDelete'                  => __( 'Could not delete the space.', 'buddynext' ),
					'saving'                          => __( 'Saving…', 'buddynext' ),
					'changesSaved'                    => __( 'Changes saved.', 'buddynext' ),
					'couldNotSaveChanges'             => __( 'Could not save changes.', 'buddynext' ),
					'permissionsSaved'                => __( 'Permissions saved.', 'buddynext' ),
					'couldNotSavePermissions'         => __( 'Could not save permissions.', 'buddynext' ),
					'spaceArchived'                   => __( 'Space archived.', 'buddynext' ),
					'couldNotArchive'                 => __( 'Could not archive the space. Try again.', 'buddynext' ),
					'spaceRestored'                   => __( 'Space restored.', 'buddynext' ),
					'couldNotRestore'                 => __( 'Could not restore the space. Try again.', 'buddynext' ),
					'couldNotUpdateNotifPref'         => __( 'Could not update notification preference.', 'buddynext' ),
					'notifPrefSaved'                  => __( 'Notification preference saved.', 'buddynext' ),
					'enterUsernameOrEmail'            => __( 'Enter a username or email address.', 'buddynext' ),
					'invitationSent'                  => __( 'Invitation sent.', 'buddynext' ),
					'couldNotSendInvite'              => __( 'Could not send the invitation.', 'buddynext' ),
					'enterName'                       => __( 'Please enter a name.', 'buddynext' ),
					'creating'                        => __( 'Creating…', 'buddynext' ),
					'spaceCreated'                    => __( 'Space created.', 'buddynext' ),
					'couldNotCreateSpace'             => __( 'Could not create the space.', 'buddynext' ),
					'createSpace'                     => __( 'Create space', 'buddynext' ),
					'confirmClose'                    => __( 'Close', 'buddynext' ),
					'pleaseConfirm'                   => __( 'Please confirm', 'buddynext' ),
					'confirm'                         => __( 'Confirm', 'buddynext' ),
					'couldNotUploadCover'             => __( 'Could not upload cover.', 'buddynext' ),
					'coverUpdated'                    => __( 'Cover updated.', 'buddynext' ),
					'couldNotRemoveCover'             => __( 'Could not remove cover.', 'buddynext' ),
					'coverRemoved'                    => __( 'Cover removed.', 'buddynext' ),
					'uploading'                       => __( 'Uploading…', 'buddynext' ),
					'couldNotUploadIcon'              => __( 'Could not upload icon.', 'buddynext' ),
					'iconUpdated'                     => __( 'Icon updated.', 'buddynext' ),
					'couldNotRemoveIcon'              => __( 'Could not remove icon.', 'buddynext' ),
					'iconRemoved'                     => __( 'Icon removed.', 'buddynext' ),
					'networkError'                    => __( 'Network error.', 'buddynext' ),
					'networkErrorRetry'               => __( 'Network error. Please try again.', 'buddynext' ),
					// Spaces directory card meta + keyset pager. Read by spaces/store.js
					// via t() but never injected, so they always rendered English.
					/* translators: %d: number of sub-spaces */
					'subspaceCountOne'                => __( '%d sub-space', 'buddynext' ),
					/* translators: %d: number of sub-spaces */
					'subspacesCount'                  => __( '%d sub-spaces', 'buddynext' ),
					'pagerLabel'                      => __( 'Spaces directory pages', 'buddynext' ),
					'nextPage'                        => __( 'Next ›', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Messages/store: native /messages/ UI — composer, thread actions, reactions,
	 * reports, block, group create / manage.
	 *
	 * @return void
	 */
	private function i18n_messages(): void {
		wp_interactivity_state(
			'buddynext/messages',
			array(
				'i18n' => array(
					'composeNewGroup'           => __( 'New group', 'buddynext' ),
					'composeNewMessage'         => __( 'New message', 'buddynext' ),
					'composeHint'               => __( 'Search for a person to message.', 'buddynext' ),
					'composeNone'               => __( 'No people found.', 'buddynext' ),
					// Recipient search failed (403/429/500/offline). Rendered as an
					// in-list hint inside the open dropdown, not a toast, so the
					// picker never keeps showing the previous query's stale rows.
					'composeError'              => __( 'Could not search right now. Try again.', 'buddynext' ),
					'memberCountSingular'       => __( '1 member', 'buddynext' ),
					/* translators: %d: number of members. */
					'memberCountPlural'         => __( '%d members', 'buddynext' ),
					'roleAdmin'                 => __( 'Admin', 'buddynext' ),
					'roleMember'                => __( 'Member', 'buddynext' ),
					'makeMember'                => __( 'Make member', 'buddynext' ),
					'makeAdmin'                 => __( 'Make admin', 'buddynext' ),
					'groupRenamed'              => __( 'Group renamed.', 'buddynext' ),
					'groupActionFailed'         => __( 'Something went wrong.', 'buddynext' ),
					'groupCreateFailed'         => __( 'Could not create the group.', 'buddynext' ),
					'groupLeaveConfirm'         => __( 'Leave this group?', 'buddynext' ),
					'groupLeaveBody'            => __( 'You will stop receiving messages from this conversation.', 'buddynext' ),
					'groupLeaveOk'              => __( 'Leave', 'buddynext' ),
					'attachment'                => __( 'Attachment', 'buddynext' ),
					// DM attachment upload failed (too large, disallowed type, quota).
					// Fallback only — the server's own reason is preferred when present.
					'attachmentUploadFailed'    => __( 'Could not attach that file. Try a different one.', 'buddynext' ),
					'noPhotosShared'            => __( 'No photos shared yet.', 'buddynext' ),
					'mediaEmpty'                => __( 'No photos to share yet.', 'buddynext' ),
					'emojiPickerClose'          => __( 'Close emoji picker', 'buddynext' ),
					'sendDeniedBlocked'         => __( 'You can no longer message this person.', 'buddynext' ),
					'sendDeniedDmsDisabled'     => __( 'This person isn’t accepting messages right now.', 'buddynext' ),
					'sendDeniedConnectionsOnly' => __( 'This person only accepts messages from their connections.', 'buddynext' ),
					'sendDeniedRateLimited'     => __( 'You’re sending messages too quickly — please wait a moment.', 'buddynext' ),
					'sendDeniedTooLong'         => __( 'That message is too long to send.', 'buddynext' ),
					'sendDeniedNotParticipant'  => __( 'You can no longer post to this conversation.', 'buddynext' ),
					'sendDeniedGeneric'         => __( 'Your message couldn’t be sent. Please try again.', 'buddynext' ),
					'reportMessageTitle'        => __( 'Report this message', 'buddynext' ),
					'reportMessageSuccess'      => __( 'Message reported. Our moderators will review it.', 'buddynext' ),
					'reportMessageFailed'       => __( 'Could not report this message. Try again.', 'buddynext' ),
					'thisMember'                => __( 'this member', 'buddynext' ),
					/* translators: %s: member display name. */
					'blockTitle'                => __( 'Block %s?', 'buddynext' ),
					'blockBody'                 => __( 'They will not be able to message you, and you will not see each other across the community. You can unblock them later from their profile.', 'buddynext' ),
					'blockConfirm'              => __( 'Block', 'buddynext' ),
					/* translators: %s: member display name. */
					'blockSuccess'              => __( '%s blocked.', 'buddynext' ),
					/* translators: %s: member display name. */
					'blockFailed'               => __( 'Could not block %s. Try again.', 'buddynext' ),
					'reportConversationTitle'   => __( 'Report this conversation', 'buddynext' ),
					'reportConversationSuccess' => __( 'Reported. Our moderators will review it.', 'buddynext' ),
					'reportConversationFailed'  => __( 'Could not submit the report. Try again.', 'buddynext' ),
					// Delete + unsend message flow. Read by messages/store.js via t() but
					// never injected, so the entire delete/unsend UX rendered English.
					'deleteMsgTitle'            => __( 'Delete this message?', 'buddynext' ),
					'deleteMsgBody'             => __( 'This removes the message for everyone and leaves a "message deleted" note. This cannot be undone.', 'buddynext' ),
					'deleteMsgConfirm'          => __( 'Delete', 'buddynext' ),
					'msgDeleted'                => __( 'This message was deleted', 'buddynext' ),
					'deleteMsgNotSender'        => __( 'You can only delete your own messages.', 'buddynext' ),
					'deleteMsgFailed'           => __( 'Could not delete the message. Try again.', 'buddynext' ),
					'unsendMsgTitle'            => __( 'Unsend this message?', 'buddynext' ),
					'unsendMsgBody'             => __( 'This removes the message for everyone, with no trace. Unsend only works for a short time after sending.', 'buddynext' ),
					'unsendMsgConfirm'          => __( 'Unsend', 'buddynext' ),
					'unsendMsgExpired'          => __( 'The time to unsend this message has passed. You can still delete it.', 'buddynext' ),
					'unsendMsgNotSender'        => __( 'You can only unsend your own messages.', 'buddynext' ),
					'unsendMsgFailed'           => __( 'Could not unsend the message. Try again.', 'buddynext' ),
					// Conversation delete failed (403/500/offline). The store keeps the
					// confirm modal open and toasts this instead of redirecting to the
					// inbox, which used to make a failed delete look done.
					'deleteConversationFailed'  => __( 'Could not delete this conversation. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Media/albums store: strings the media-tab context does not carry because
	 * they are rendered imperatively into the detail / picker grids (error and
	 * retry copy, reorder rollback).
	 *
	 * @return void
	 */
	private function i18n_media(): void {
		wp_interactivity_state(
			'buddynext/media-albums',
			array(
				'i18n' => array(
					'detailFailed'   => __( 'Could not load this album.', 'buddynext' ),
					'pickerFailed'   => __( 'Could not load your media.', 'buddynext' ),
					'loadFailedBody' => __( 'Something went wrong. Check your connection and try again.', 'buddynext' ),
					'retry'          => __( 'Try again', 'buddynext' ),
					'reorderFailed'  => __( 'Could not save the new order. Your photos were put back.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Profile/store: profile view + edit — crop/cover modals, repeater builder,
	 * validation, relationship actions, password + 2FA flows.
	 *
	 * @return void
	 */
	private function i18n_profile(): void {
		wp_interactivity_state(
			'buddynext/profile',
			array(
				'i18n' => array(
					'cropAvatar'               => __( 'Crop avatar', 'buddynext' ),
					'positionAvatar'           => __( 'Position your avatar', 'buddynext' ),
					'zoom'                     => __( 'Zoom', 'buddynext' ),
					'cancel'                   => __( 'Cancel', 'buddynext' ),
					'apply'                    => __( 'Apply', 'buddynext' ),
					'couldNotProcessImage'     => __( 'Could not process the image. Try a different file.', 'buddynext' ),
					'repositionCover'          => __( 'Reposition cover photo', 'buddynext' ),
					'coverDragHint'            => __( 'Drag to reposition · scroll or use the slider to zoom', 'buddynext' ),
					'thisField'                => __( 'This field', 'buddynext' ),
					'avatarSaveFailed'         => __( 'Avatar could not be saved', 'buddynext' ),
					'coverSaveFailed'          => __( 'Cover could not be saved', 'buddynext' ),
					'profileSaved'             => __( 'Profile saved', 'buddynext' ),
					'fieldsNeedAttention'      => __( 'Some fields need attention', 'buddynext' ),
					'saveFailed'               => __( 'Could not save. Please try again.', 'buddynext' ),
					'present'                  => __( 'Present', 'buddynext' ),
					'unmute'                   => __( 'Unmute', 'buddynext' ),
					'mute'                     => __( 'Mute', 'buddynext' ),
					'unrestrict'               => __( 'Unrestrict', 'buddynext' ),
					'restrict'                 => __( 'Restrict', 'buddynext' ),
					'unblock'                  => __( 'Unblock', 'buddynext' ),
					'block'                    => __( 'Block', 'buddynext' ),
					/* translators: %d: number of remaining two-factor backup codes (singular). */
					'backupCodeLeftSingular'   => __( '%d backup code left.', 'buddynext' ),
					/* translators: %d: number of remaining two-factor backup codes (plural). */
					'backupCodesLeftPlural'    => __( '%d backup codes left.', 'buddynext' ),
					'dataExportDownloaded'     => __( 'Your data export has downloaded.', 'buddynext' ),
					'dataExportFailed'         => __( 'Could not export your data. Please try again.', 'buddynext' ),
					'deleteAccountTitle'       => __( 'Delete your account?', 'buddynext' ),
					'deleteAccountMessage'     => __( 'This permanently deletes your account and removes your data. This cannot be undone.', 'buddynext' ),
					'deleteAccountConfirm'     => __( 'Delete my account', 'buddynext' ),
					'deleteAccountFailed'      => __( 'Could not delete your account.', 'buddynext' ),
					'deleteAccountFailedRetry' => __( 'Could not delete your account. Please try again.', 'buddynext' ),
					'profile'                  => __( 'Profile', 'buddynext' ),
					'profileLinkCopied'        => __( 'Profile link copied', 'buddynext' ),
					'couldNotCopyLongPress'    => __( 'Could not copy. Long-press the URL.', 'buddynext' ),
					/* translators: %s: profile URL. */
					'copyThisLink'             => __( 'Copy this link: %s', 'buddynext' ),
					'socialUnlinked'           => __( 'Account unlinked', 'buddynext' ),
					'connect'                  => __( 'Connect', 'buddynext' ),
					'socialUnlinkFailed'       => __( 'Could not unlink. Try again.', 'buddynext' ),
					'memberTypeSaved'          => __( 'Member type updated', 'buddynext' ),
					'memberTypeFailed'         => __( 'Could not update member type', 'buddynext' ),
					'prefSaved'                => __( 'Preference saved', 'buddynext' ),
					'displayNameRequired'      => __( 'Display name is required.', 'buddynext' ),
					'invalidUrl'               => __( 'Enter a valid URL (https://example.com).', 'buddynext' ),
					/* translators: %s: field label. */
					'fieldRequired'            => __( '%s is required.', 'buddynext' ),
					'avatarReady'              => __( 'Avatar ready — click Save changes to keep it', 'buddynext' ),
					'couldNotPrepareImage'     => __( 'Could not prepare image. Try again.', 'buddynext' ),
					'removePhotoTitle'         => __( 'Remove profile photo?', 'buddynext' ),
					'removePhotoBody'          => __( 'Your photo will be replaced with your initials. You can upload a new one any time.', 'buddynext' ),
					'remove'                   => __( 'Remove', 'buddynext' ),
					'photoRemoved'             => __( 'Profile photo removed', 'buddynext' ),
					'photoRemoveFailed'        => __( 'Could not remove your photo. Try again.', 'buddynext' ),
					'coverReady'               => __( 'Cover ready — click Save changes to keep it', 'buddynext' ),
					'followed'                 => __( 'Followed', 'buddynext' ),
					'couldNotFollow'           => __( 'Could not follow. Try again.', 'buddynext' ),
					'unfollowed'               => __( 'Unfollowed', 'buddynext' ),
					'couldNotUnfollow'         => __( 'Could not unfollow. Try again.', 'buddynext' ),
					'connectNoteBody'          => __( 'Add a personal message to your connection request, or send it without one.', 'buddynext' ),
					'connectionSent'           => __( 'Connection request sent', 'buddynext' ),
					'couldNotSendRequest'      => __( 'Could not send request', 'buddynext' ),
					'requestWithdrawn'         => __( 'Request withdrawn', 'buddynext' ),
					'connected'                => __( 'Connected', 'buddynext' ),
					'requestDeclined'          => __( 'Request declined', 'buddynext' ),
					'disconnected'             => __( 'Disconnected', 'buddynext' ),
					'copyFailed'               => __( 'Could not copy link.', 'buddynext' ),
					'unmuted'                  => __( 'Unmuted', 'buddynext' ),
					'muted'                    => __( 'Muted', 'buddynext' ),
					'muteFailed'               => __( 'Could not update mute state', 'buddynext' ),
					'noLongerRestricted'       => __( 'No longer restricted', 'buddynext' ),
					'restricted'               => __( 'Restricted. They can still see your profile, but their comments are hidden from others.', 'buddynext' ),
					'restrictFailed'           => __( 'Could not update restrict state', 'buddynext' ),
					/* translators: %s: member display name. */
					'memberBlockedNamed'       => __( '%s blocked', 'buddynext' ),
					'memberBlocked'            => __( 'Member blocked', 'buddynext' ),
					'blockFailed'              => __( 'Could not block. Try again.', 'buddynext' ),
					'reportFailed'             => __( 'Could not submit report. Try again.', 'buddynext' ),
					'reportSubmitted'          => __( 'Report submitted. Thanks for keeping the community safe.', 'buddynext' ),
					'checkInboxConfirm'        => __( 'Check your inbox to confirm.', 'buddynext' ),
					'verifyEmailFailed'        => __( 'Could not send verification email. Try again.', 'buddynext' ),
					'pwTooShort'               => __( 'Too short', 'buddynext' ),
					'pwWeak'                   => __( 'Weak', 'buddynext' ),
					'pwFair'                   => __( 'Fair', 'buddynext' ),
					'pwGood'                   => __( 'Good', 'buddynext' ),
					'pwStrong'                 => __( 'Strong', 'buddynext' ),
					'pwExcellent'              => __( 'Excellent', 'buddynext' ),
					'enterCurrentPassword'     => __( 'Enter your current password.', 'buddynext' ),
					'enterNewPassword'         => __( 'Enter a new password.', 'buddynext' ),
					'passwordMinChars'         => __( 'Use at least 8 characters.', 'buddynext' ),
					'passwordsNoMatch'         => __( 'Passwords do not match.', 'buddynext' ),
					'passwordUpdated'          => __( 'Password updated.', 'buddynext' ),
					'passwordChangeFailed'     => __( 'Could not change password. Try again.', 'buddynext' ),
					'signedOutEverywhere'      => __( 'Signed out of every other session.', 'buddynext' ),
					'signOutFailed'            => __( 'Could not sign out everywhere. Try again.', 'buddynext' ),
					'twofaSetupFailed'         => __( 'Could not start setup. Try again.', 'buddynext' ),
					'twofaCodeMismatch'        => __( 'That code did not match.', 'buddynext' ),
					'somethingWentWrong'       => __( 'Something went wrong. Try again.', 'buddynext' ),
					'twofaOn'                  => __( 'Two-factor authentication is on.', 'buddynext' ),
					'enterPassword'            => __( 'Enter your password.', 'buddynext' ),
					'twofaRegenFailed'         => __( 'Could not regenerate codes.', 'buddynext' ),
					'twofaOff'                 => __( 'Two-factor authentication is off.', 'buddynext' ),
					'twofaDisableFailed'       => __( 'Could not turn off two-factor.', 'buddynext' ),
					'unblocked'                => __( 'Unblocked', 'buddynext' ),
					'unblockFailed'            => __( 'Could not unblock', 'buddynext' ),
					// Per-field visibility selector (edit profile). Read by profile/store.js
					// via t() but never injected, so the picker labels always rendered
					// English regardless of locale.
					'visWhoCanSee'             => __( 'Who can see this field', 'buddynext' ),
					'visPublic'                => __( 'Public', 'buddynext' ),
					'visMembers'               => __( 'Members', 'buddynext' ),
					'visFollowers'             => __( 'Followers', 'buddynext' ),
					'visConnections'           => __( 'Connections', 'buddynext' ),
					'visPrivate'               => __( 'Only me', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Moderation/store: report queue, user sanctions, appeals, and space
	 * moderation dialogs/toasts.
	 *
	 * @return void
	 */
	private function i18n_moderation(): void {
		wp_interactivity_state(
			'buddynext/moderation',
			array(
				'i18n' => array(
					'dismissFailed'         => __( 'Could not dismiss the report. Try again.', 'buddynext' ),
					'removeContentTitle'    => __( 'Remove this content?', 'buddynext' ),
					'removeContentBody'     => __( 'The reported item will be taken down from public view and the report marked resolved.', 'buddynext' ),
					'removeLabel'           => __( 'Remove', 'buddynext' ),
					'contentRemoved'        => __( 'Content removed.', 'buddynext' ),
					'removeContentFailed'   => __( 'Could not remove the content. Try again.', 'buddynext' ),
					'warningSent'           => __( 'Warning sent.', 'buddynext' ),
					'warnUserFailed'        => __( 'Could not warn the user.', 'buddynext' ),
					'strikeIssued'          => __( 'Strike issued.', 'buddynext' ),
					'strikeUserFailed'      => __( 'Could not issue a strike.', 'buddynext' ),
					// Reverse strike — the counterpart to the above. The queue row's
					// strike dots and count re-render from these after every strike or
					// reversal, so the admin can see what they are undoing.
					'strikeReversed'        => __( 'Strike reversed.', 'buddynext' ),
					'reverseStrikeFailed'   => __( 'Could not reverse the strike. Try again.', 'buddynext' ),
					'noActiveStrikes'       => __( 'This member has no active strikes.', 'buddynext' ),
					/* translators: %d: number of strikes. */
					'strikeCountOne'        => __( '%d strike', 'buddynext' ),
					/* translators: %d: number of active strikes. */
					'strikeCountOther'      => __( '%d strikes', 'buddynext' ),
					/* translators: %d: number of active strikes. */
					'reverseStrikeAria'     => __( 'Reverse the most recent strike (%d active)', 'buddynext' ),
					'suspendUserTitle'      => __( 'Suspend this user?', 'buddynext' ),
					'suspendUserBody'       => __( 'They will be unable to post or interact for 7 days, and their posts will be hidden.', 'buddynext' ),
					'suspendLabel'          => __( 'Suspend', 'buddynext' ),
					'userSuspended'         => __( 'User suspended for 7 days.', 'buddynext' ),
					'suspendUserFailed'     => __( 'Could not suspend the user.', 'buddynext' ),
					'appealTooShort'        => __( 'Please describe why you are appealing (at least 10 characters).', 'buddynext' ),
					'appealSubmitted'       => __( 'Your appeal has been submitted.', 'buddynext' ),
					'appealSubmitFailed'    => __( 'Could not submit your appeal. Try again.', 'buddynext' ),
					'approveAppealTitle'    => __( 'Approve this appeal?', 'buddynext' ),
					'approveAppealBody'     => __( 'The member’s suspension will be lifted and they will be notified.', 'buddynext' ),
					'approveLabel'          => __( 'Approve', 'buddynext' ),
					'appealApproved'        => __( 'Appeal approved — suspension lifted.', 'buddynext' ),
					'approveAppealFailed'   => __( 'Could not approve the appeal. Try again.', 'buddynext' ),
					'denyAppealTitle'       => __( 'Deny this appeal?', 'buddynext' ),
					'denyAppealBody'        => __( 'The suspension stays in place. The member will be notified of the decision.', 'buddynext' ),
					'denyLabel'             => __( 'Deny', 'buddynext' ),
					'appealDenied'          => __( 'Appeal denied.', 'buddynext' ),
					'denyAppealFailed'      => __( 'Could not deny the appeal. Try again.', 'buddynext' ),
					'removeFromSpaceTitle'  => __( 'Remove this member from the space?', 'buddynext' ),
					'removeFromSpaceBody'   => __( 'They will lose access to this space immediately.', 'buddynext' ),
					// Space moderation panel. These actions suppress the shared
					// error toast because they own their feedback, so every
					// outcome needs a string here or a failure (a 403, say) is
					// silent.
					'memberWarned'          => __( 'Warning sent.', 'buddynext' ),
					'warnMemberFailed'      => __( 'Could not warn the member.', 'buddynext' ),
					'removeFromSpaceFailed' => __( 'Could not remove the member from this space. Try again.', 'buddynext' ),
					'approveRequestFailed'  => __( 'Could not approve the join request. Try again.', 'buddynext' ),
					'declineRequestFailed'  => __( 'Could not decline the join request. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Onboarding/store: 4-step wizard — step labels, profile preview, username
	 * availability, join/follow buttons, avatar upload, and completion.
	 *
	 * @return void
	 */
	private function i18n_onboarding(): void {
		wp_interactivity_state(
			'buddynext/onboarding',
			array(
				'i18n' => array(
					/* translators: 1: current step number, 2: total step count */
					'stepLabel'                => __( 'Step %1$s of %2$s', 'buddynext' ),
					'displayNameError'         => __( 'Display name must be at least 2 characters.', 'buddynext' ),
					'previewName'              => __( 'Your name', 'buddynext' ),
					'previewHandle'            => __( '@username', 'buddynext' ),
					'previewBio'               => __( "Add a short bio so people know what you're into.", 'buddynext' ),
					'usernameChecking'         => __( 'Checking…', 'buddynext' ),
					'usernameAvailable'        => __( 'Available', 'buddynext' ),
					'usernameTaken'            => __( 'Taken', 'buddynext' ),
					'btnJoin'                  => __( 'Join', 'buddynext' ),
					'btnJoined'                => __( 'Joined', 'buddynext' ),
					'btnFollow'                => __( 'Follow', 'buddynext' ),
					'btnFollowing'             => __( 'Following', 'buddynext' ),
					'toastSkipped'             => __( 'Skipped. You can fill in your profile any time.', 'buddynext' ),
					'toastJoinedSpace'         => __( 'Joined the space.', 'buddynext' ),
					'toastLeftSpace'           => __( 'Left the space.', 'buddynext' ),
					'toastSpaceUpdateFailed'   => __( 'Could not update space. Please try again.', 'buddynext' ),
					'toastFollowing'           => __( 'Following.', 'buddynext' ),
					'toastUnfollowed'          => __( 'Unfollowed.', 'buddynext' ),
					'toastFollowUpdateFailed'  => __( 'Could not update follow. Please try again.', 'buddynext' ),
					'toastImageTooLarge'       => __( 'Image too large. Max 4MB.', 'buddynext' ),
					'toastImageDimensions'     => __( 'Image must be at most 1024×1024 pixels. Please choose a smaller photo.', 'buddynext' ),
					'toastPhotoUploadFailed'   => __( 'Could not upload photo. Please try again.', 'buddynext' ),
					'toastPhotoUpdated'        => __( 'Profile photo updated.', 'buddynext' ),
					'toastAllSet'              => __( 'You are all set. Welcome aboard!', 'buddynext' ),
					'toastFinishFailed'        => __( 'Could not finish onboarding. Please try again.', 'buddynext' ),
					'toastInterestsSaveFailed' => __( 'Could not save your interests. Please try again.', 'buddynext' ),
					'errorGeneric'             => __( 'Something went wrong. Please try again.', 'buddynext' ),
					// Finish-step failures. The wizard used to fire the profile / handle /
					// channel writes and forget them, so a rejected save still reached the
					// success screen. Each failure now keeps the member on the wizard with
					// the reason, so their data is never lost behind "You're all set".
					'errorProfileSaveFailed'   => __( 'Your profile could not be saved. Please check your details and try again.', 'buddynext' ),
					'errorHandleTaken'         => __( 'That username is already taken. Please choose another.', 'buddynext' ),
					'errorChannelsSaveFailed'  => __( 'Your notification settings could not be saved. Please try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Notifications/store: read/dismiss toasts, space-invite accept/decline, and
	 * the mark-all-read failure path.
	 *
	 * @return void
	 */
	private function i18n_notifications(): void {
		wp_interactivity_state(
			'buddynext/notifications',
			array(
				'i18n' => array(
					'markAllReadFailed'   => __( 'Could not mark all as read.', 'buddynext' ),
					'markReadFailed'      => __( 'Could not mark this notification as read.', 'buddynext' ),
					'dismissFailed'       => __( 'Could not dismiss. Try again.', 'buddynext' ),
					'inviteAccepted'      => __( 'Invitation accepted — you have joined the space.', 'buddynext' ),
					'inviteAcceptFailed'  => __( 'Could not accept the invitation.', 'buddynext' ),
					'inviteDeclined'      => __( 'Invitation declined.', 'buddynext' ),
					'inviteDeclineFailed' => __( 'Could not decline the invitation.', 'buddynext' ),
					'networkError'        => __( 'Network error. Try again.', 'buddynext' ),

					/*
					 * The "N new" pill beside the page title. It is server-rendered from the
					 * same string in parts/notifications-hero.php and re-rendered by the
					 * store as the count changes, so both paths must read from one
					 * translation — without this key the JS could only ever write a bare
					 * number, and the word silently disappeared on hydration.
					 */
					/* translators: %s is the formatted number of unread notifications (e.g. "12" or "99+"). */
					'unreadBadge'         => __( '%s new', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Notifications/prefs-store: notification preferences save bar + toasts.
	 *
	 * @return void
	 */
	private function i18n_notification_prefs(): void {
		wp_interactivity_state(
			'buddynext/notification-prefs',
			array(
				'i18n' => array(
					'justNow'              => __( 'Just now', 'buddynext' ),
					/* translators: %d: number of seconds */
					'secondsAgo'           => __( '%ds ago', 'buddynext' ),
					/* translators: %d: number of minutes */
					'minutesAgo'           => __( '%d min ago', 'buddynext' ),
					/* translators: %d: number of hours. */
					'hoursAgo'             => __( '%dh ago', 'buddynext' ),
					'statusSaving'         => __( 'Saving...', 'buddynext' ),
					'statusUnsavedChanges' => __( 'Unsaved changes', 'buddynext' ),
					/* translators: %s: relative time the prefs were last saved (e.g. "2 min ago") */
					'statusSaved'          => __( 'Saved %s', 'buddynext' ),
					'spacePrefSaved'       => __( 'Space preference saved.', 'buddynext' ),
					'spacePrefSaveFailed'  => __( 'Could not save space preference.', 'buddynext' ),
					'prefsSaveFailed'      => __( 'Could not save preferences.', 'buddynext' ),
					'prefsSaved'           => __( 'Preferences saved.', 'buddynext' ),
					// Global broadcast opt-out — Pro renders the control inside the
					// Channels card, this store drives it (actions.setBroadcastOptOut).
					'broadcastsOptedOut'   => __( 'You will no longer receive newsletters or announcements.', 'buddynext' ),
					'broadcastsOptedIn'    => __( 'You will receive newsletters and announcements again.', 'buddynext' ),
					'broadcastsFailed'     => __( 'Could not update your broadcast email preference.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Search/store: saved-search composer messages (name-required, saved
	 * confirmation, Pro-required failure notice).
	 *
	 * @return void
	 */
	private function i18n_search(): void {
		wp_interactivity_state(
			'buddynext/search',
			array(
				'i18n' => array(
					'nameSearchFirst'       => __( 'Please name this search first.', 'buddynext' ),
					'searchSaved'           => __( 'Search saved.', 'buddynext' ),
					'searchSaveProRequired' => __( 'Could not save. Saved searches require BuddyNext Pro.', 'buddynext' ),
					'recentSearchesLabel'   => __( 'Recent searches', 'buddynext' ),
					'recentSearchesTitle'   => __( 'Recent:', 'buddynext' ),
					'clear'                 => __( 'Clear', 'buddynext' ),
					'suggestionsLabel'      => __( 'Search suggestions', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Hashtags/store: hashtag follow toggle feedback. The store registers under
	 * buddynext/feed for directives but reads its strings from this dedicated
	 * buddynext/hashtags namespace so its small dictionary stays separate.
	 *
	 * @return void
	 */
	private function i18n_hashtags(): void {
		wp_interactivity_state(
			'buddynext/hashtags',
			array(
				// Canonical compose target (see the feed store's `urls` note).
				'urls' => array(
					'activity' => \BuddyNext\Core\PageRouter::activity_url(),
				),
				'i18n' => array(
					'followUpdateFailed' => __( 'Could not update follow state. Try again.', 'buddynext' ),
					/* translators: %s: hashtag slug (without the leading #) */
					'unfollowedHashtag'  => __( 'Unfollowed #%s', 'buddynext' ),
					/* translators: %s: hashtag slug (without the leading #) */
					'followingHashtag'   => __( 'Following #%s', 'buddynext' ),
					'networkError'       => __( 'Network error. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Space-members/store: per-card kebab menu plus the Remove-member and
	 * Change-role management actions.
	 *
	 * @return void
	 */
	private function i18n_space_members(): void {
		wp_interactivity_state(
			'buddynext/space-members',
			array(
				'i18n' => array(
					'removeMemberTitle'  => __( 'Remove this member?', 'buddynext' ),
					'removeMemberBody'   => __( 'They will lose access to this space immediately.', 'buddynext' ),
					'remove'             => __( 'Remove', 'buddynext' ),
					'removeMemberFailed' => __( 'Could not remove member. Try again.', 'buddynext' ),
					'updateRoleFailed'   => __( 'Could not update role. Try again.', 'buddynext' ),
					'banMemberTitle'     => __( 'Ban this member?', 'buddynext' ),
					'banMemberBody'      => __( 'They will be removed and blocked from rejoining this space.', 'buddynext' ),
					'ban'                => __( 'Ban', 'buddynext' ),
					'banMemberFailed'    => __( 'Could not ban member. Try again.', 'buddynext' ),
					'unbanMemberTitle'   => __( 'Unban this member?', 'buddynext' ),
					'unbanMemberBody'    => __( 'They will be able to request or join this space again.', 'buddynext' ),
					'unban'              => __( 'Unban', 'buddynext' ),
					'unbanMemberFailed'  => __( 'Could not unban member. Try again.', 'buddynext' ),
					'enterIdentifier'    => __( 'Enter a username or email address.', 'buddynext' ),
					'inviteSent'         => __( 'Invitation sent.', 'buddynext' ),
					'inviteFailed'       => __( 'Could not send the invitation.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Store: the space custom-fields panel save/validation toasts.
	 *
	 * @return void
	 */
	private function i18n_space_fields(): void {
		wp_interactivity_state(
			'buddynext/space-fields',
			array(
				'i18n' => array(
					'saved'      => __( 'Fields saved.', 'buddynext' ),
					'saveFailed' => __( 'Could not save the fields. Try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Auth/store: login/register tab labels + password-strength meter + the
	 * password show/hide toggle.
	 *
	 * @return void
	 */
	private function i18n_auth(): void {
		wp_interactivity_state(
			'buddynext/auth',
			array(
				'i18n' => array(
					'strengthWeak'   => __( 'Weak', 'buddynext' ),
					'strengthFair'   => __( 'Fair', 'buddynext' ),
					'strengthGood'   => __( 'Good', 'buddynext' ),
					'strengthStrong' => __( 'Strong', 'buddynext' ),
					'hide'           => __( 'Hide', 'buddynext' ),
					'show'           => __( 'Show', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Auth/login-store: login form — validation, sign-in path, 2FA code step,
	 * and email-code fallback toasts.
	 *
	 * @return void
	 */
	private function i18n_auth_login(): void {
		wp_interactivity_state(
			'buddynext/auth-login',
			array(
				'i18n' => array(
					/* translators: %s: masked email address the code was sent to */
					'codeSentTo'         => __( 'Code sent to %s', 'buddynext' ),
					'codeSentCheckEmail' => __( 'Code sent — check your email', 'buddynext' ),
					'enterEmailPassword' => __( 'Enter your email and password to sign in.', 'buddynext' ),
					'invalidCredentials' => __( 'Invalid email or password.', 'buddynext' ),
					'signedIn'           => __( 'Signed in.', 'buddynext' ),
					'genericError'       => __( 'Something went wrong. Please try again.', 'buddynext' ),
					'twofaIncorrect'     => __( 'That code was not correct.', 'buddynext' ),
					'emailCodeSent'      => __( 'If your session is still valid, a code is on its way.', 'buddynext' ),
					'emailCodeFailed'    => __( 'Could not send the code. Try your authenticator app.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Auth/signup-store: registration form — inline field validation, the
	 * password-strength labels, and create-account toasts.
	 *
	 * @return void
	 */
	private function i18n_auth_signup(): void {
		wp_interactivity_state(
			'buddynext/auth-signup',
			array(
				'i18n' => array(
					'strengthWeak'    => __( 'Weak', 'buddynext' ),
					'strengthFair'    => __( 'Fair', 'buddynext' ),
					'strengthGood'    => __( 'Good', 'buddynext' ),
					'strengthStrong'  => __( 'Strong', 'buddynext' ),
					'fillRequired'    => __( 'Please fill in your email, username, and password.', 'buddynext' ),
					'agreeTerms'      => __( 'Please agree to the Terms of Service and Privacy Policy to continue.', 'buddynext' ),
					'answerChallenge' => __( 'Please answer the verification question.', 'buddynext' ),
					'createFailed'    => __( 'Could not create your account.', 'buddynext' ),
					'accountCreated'  => __( 'Account created. Welcome aboard!', 'buddynext' ),
					'genericError'    => __( 'Something went wrong. Please try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Auth/verify-store: email-verification resend toasts.
	 *
	 * @return void
	 */
	private function i18n_auth_verify(): void {
		wp_interactivity_state(
			'buddynext/auth-verify',
			array(
				'i18n' => array(
					'verificationSent' => __( 'Verification email sent. Check your inbox.', 'buddynext' ),
					'genericError'     => __( 'Something went wrong. Please try again.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Auth/reset-store: password-reset request + set-new-password screens.
	 *
	 * @return void
	 */
	private function i18n_auth_reset(): void {
		wp_interactivity_state(
			'buddynext/auth-reset',
			array(
				'i18n' => array(
					'enterEmailOrUsername'  => __( 'Please enter your email or username.', 'buddynext' ),
					'resetLinkSent'         => __( 'If an account matches, a reset link is on its way.', 'buddynext' ),
					'somethingWentWrong'    => __( 'Something went wrong. Please try again.', 'buddynext' ),
					'chooseNewPassword'     => __( 'Please choose a new password.', 'buddynext' ),
					'couldNotResetPassword' => __( 'Could not reset your password.', 'buddynext' ),
					'passwordUpdated'       => __( 'Password updated. Please sign in.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Enqueue a feature's registered style and Interactivity script module.
	 *
	 * Resolves the feature slug to the `bn-<slug>` style handle and the
	 * `@buddynext/<slug>` script module, enqueuing both by handle so WordPress
	 * resolves them at print time. Features that ship only a script module (and
	 * reuse another feature's stylesheet) have no registered style handle, so
	 * the style enqueue is a harmless no-op.
	 *
	 * @param string $feature Feature slug to enqueue assets for.
	 * @return void
	 */
	public function enqueue( string $feature ): void {
		$slug   = sanitize_key( $feature );
		$handle = 'bn-' . $slug;
		// Route assets are enqueued on template_redirect, before the styles are
		// registered on wp_enqueue_scripts, so we must enqueue by handle
		// unconditionally and let WordPress resolve it at print time. Features
		// that ship only an Interactivity module and reuse another feature's
		// stylesheet (e.g. space-members → bn-spaces) simply have no registered
		// bn-<slug> handle, so this enqueue is a harmless no-op for them.
		wp_enqueue_style( $handle );
		wp_enqueue_script_module( '@buddynext/' . $slug );
	}
}
