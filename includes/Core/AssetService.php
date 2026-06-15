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
			array(),
			self::VERSION,
			true
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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

		// Email Templates editor: only on Settings → Email Templates tab
		// (Hub slug 'templates'). The hook suffix for the top-level BuddyNext
		// page is "toplevel_page_buddynext".
		if ( 'toplevel_page_buddynext' === $hook_suffix && 'templates' === $active_tab ) {
			wp_enqueue_style(
				'bn-admin-email',
				$this->assets_url . 'css/bn-admin-email.css',
				array( 'bn-admin' ),
				self::VERSION
			);
			wp_enqueue_script(
				'bn-email-editor',
				$this->assets_url . 'js/admin/email-editor.js',
				array(),
				self::VERSION,
				true
			);
		}

		// Navigation Manager: only on Settings → Navigation tab.
		if ( 'toplevel_page_buddynext' === $hook_suffix && 'navigation' === $active_tab ) {
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
			array(),
			$v,
			false
		);

		// ── Shell extras (search overlay, notif dropdown, hover card, shortcuts) ─
		// Loaded in the footer so it can hydrate any shell-level UI surfaces.
		// Data is localized by PageRouter::enqueue_hub_assets() into
		// window.bnShellData before this script runs.
		wp_register_script(
			'bn-shell-extras',
			$this->assets_url . 'js/shell/extras.js',
			array(),
			$v,
			true
		);

		// ── Feature CSS (each depends on the base) ─────────────────────────────
		$feature_styles = array(
			'bn-feed',
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
			'bn-space-members',
			'bn-header',
			'bn-settings',
		);

		foreach ( $feature_styles as $handle ) {
			$slug = str_replace( 'bn-', '', $handle );
			wp_register_style(
				$handle,
				$this->assets_url . 'css/' . $handle . '.css',
				array( 'bn-base' ),
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
			'@buddynext/onboarding'         => 'onboarding/store',
			'@buddynext/gamification'       => 'gamification/store',
			'@buddynext/moderation'         => 'moderation/store',
			'@buddynext/connections'        => 'connections/store',
			'@buddynext/space-members'      => 'space-members/store',
			'@buddynext/social-buttons'     => 'social/follow-store',
		);

		// Feature stores that import from ../shell/dialog.js need the
		// shell-dialog module declared as a dependency so WP emits the
		// correct import-map entry and the browser fetches it as a module.
		$shell_dialog_consumers = array(
			'@buddynext/feed',
			'@buddynext/connections',
			'@buddynext/moderation',
			'@buddynext/space-members',
			'@buddynext/profile',
			'@buddynext/members',
			'@buddynext/social-buttons',
			'@buddynext/messages',
		);

		foreach ( $feature_modules as $id => $path ) {
			$deps = array( array( 'id' => '@wordpress/interactivity' ) );
			if ( in_array( $id, $shell_dialog_consumers, true ) ) {
				$deps[] = array( 'id' => '@buddynext/shell-dialog' );
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
	public function enqueue( string $feature ): void {
		$slug   = sanitize_key( $feature );
		$handle = 'bn-' . $slug;
		wp_enqueue_style( $handle );
		wp_enqueue_script_module( '@buddynext/' . $slug );
	}
}
