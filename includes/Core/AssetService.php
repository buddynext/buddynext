<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Frontend asset registration and enqueueing.
 *
 * Registers all BuddyNext CSS and JS handles on wp_enqueue_scripts.
 * Individual handles are enqueued lazily by the ShortcodeService,
 * template partials, or the Interactivity API stores.
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
	 * Register assets and hook into wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_script_modules' ) );
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
		$v = self::VERSION;

		$feature_modules = array(
			'@buddynext/feed'          => 'feed/store',
			'@buddynext/profile'       => 'profile/store',
			'@buddynext/spaces'        => 'spaces/store',
			'@buddynext/members'       => 'members/store',
			'@buddynext/messages'      => 'messages/store',
			'@buddynext/notifications' => 'notifications/store',
			'@buddynext/search'        => 'search/store',
			'@buddynext/hashtags'      => 'hashtags/store',
			'@buddynext/auth'          => 'auth/store',
			'@buddynext/onboarding'    => 'onboarding/store',
			'@buddynext/gamification'  => 'gamification/store',
			'@buddynext/moderation'    => 'moderation/store',
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
