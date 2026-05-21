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

		wp_enqueue_style(
			'bn-admin',
			$this->assets_url . 'css/bn-admin.css',
			array( 'bn-fonts', 'bn-base' ),
			self::VERSION
		);

		// Email Templates editor: enqueue the v2 split-pane editor script
		// only on its own submenu page. The hook suffix for a custom submenu
		// under the buddynext top-level slug is "buddynext_page_buddynext-
		// email-editor".
		if ( false !== strpos( $hook_suffix, 'buddynext-email-editor' ) ) {
			wp_enqueue_script(
				'bn-email-editor',
				$this->assets_url . 'js/admin/email-editor.js',
				array(),
				self::VERSION,
				true
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

		// ── Feature CSS (each depends on the base) ─────────────────────────────
		$feature_styles = array(
			'bn-feed',
			'bn-profile',
			'bn-spaces',
			'bn-members',
			'bn-messages',
			'bn-notifications',
			'bn-search',
			'bn-hashtags',
			'bn-auth',
			'bn-onboarding',
			'bn-gamification',
			'bn-moderation',
			'bn-connections',
			'bn-space-members',
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

		// ── Standalone scripts (classic, non-module) ─────────────────────────
		// bn-auth-verify is a small classic script for the email-verification
		// page's resend button. The auth Interactivity store is not enqueued
		// on this page because verify.php runs through get_header() outside
		// the hub-bundle path.
		wp_register_script(
			'bn-auth-verify',
			$this->assets_url . 'js/auth/verify-store.js',
			array(),
			$v,
			true
		);
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
		$v = self::VERSION;

		$feature_modules = array(
			'@buddynext/feed'           => 'feed/store',
			'@buddynext/profile'        => 'profile/store',
			'@buddynext/spaces'         => 'spaces/store',
			'@buddynext/members'        => 'members/store',
			'@buddynext/messages'       => 'messages/store',
			'@buddynext/notifications'  => 'notifications/store',
			'@buddynext/search'         => 'search/store',
			'@buddynext/hashtags'       => 'hashtags/store',
			'@buddynext/auth'           => 'auth/store',
			'@buddynext/onboarding'     => 'onboarding/store',
			'@buddynext/gamification'   => 'gamification/store',
			'@buddynext/moderation'     => 'moderation/store',
			'@buddynext/connections'    => 'connections/store',
			'@buddynext/space-members'  => 'space-members/store',
		);

		foreach ( $feature_modules as $id => $path ) {
			wp_register_script_module(
				$id,
				$this->assets_url . 'js/' . $path . '.js',
				array( array( 'id' => '@wordpress/interactivity' ) ),
				$v
			);
		}
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
