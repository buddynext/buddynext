<?php
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
		add_action( 'wp_head', array( $this, 'output_google_fonts' ), 1 );
	}

	/**
	 * Output Google Fonts preconnect + stylesheet link.
	 *
	 * Hooked at priority 1 so it runs before other wp_head output.
	 *
	 * @return void
	 */
	public function output_google_fonts(): void {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Plus+Jakarta+Sans:wght@700;800&amp;display=swap" rel="stylesheet">' . "\n";
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

		// ── Global base styles ─────────────────────────────────────────────────
		wp_register_style(
			'bn-base',
			$this->assets_url . 'css/bn-base.css',
			array(),
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

		// ── Interactivity API stores ────────────────────────────────────────────
		$feature_scripts = array(
			'bn-feed'          => 'feed/store',
			'bn-profile'       => 'profile/store',
			'bn-spaces'        => 'spaces/store',
			'bn-members'       => 'members/store',
			'bn-messages'      => 'messages/store',
			'bn-notifications' => 'notifications/store',
			'bn-search'        => 'search/store',
			'bn-hashtags'      => 'hashtags/store',
			'bn-auth'          => 'auth/store',
			'bn-onboarding'    => 'onboarding/store',
			'bn-gamification'  => 'gamification/store',
			'bn-moderation'    => 'moderation/store',
		);

		foreach ( $feature_scripts as $handle => $path ) {
			wp_register_script(
				$handle,
				$this->assets_url . 'js/' . $path . '.js',
				array( 'wp-interactivity' ),
				$v,
				array( 'strategy' => 'defer' )
			);
		}
	}

	/**
	 * Enqueue a named feature bundle (CSS + JS together).
	 *
	 * Convenience method called from shortcodes and templates:
	 *   buddynext_service('assets')->enqueue('feed');
	 *
	 * @param string $feature Feature slug without 'bn-' prefix (e.g. 'feed').
	 * @return void
	 */
	public function enqueue( string $feature ): void {
		$handle = 'bn-' . sanitize_key( $feature );
		wp_enqueue_style( $handle );
		wp_enqueue_script( $handle );
	}
}
