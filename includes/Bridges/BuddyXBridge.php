<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyX theme bridge.
 *
 * Ensures seamless UI flow continuity between BuddyX theme pages and all
 * community plugin pages. Without this bridge, BuddyX wraps every
 * get_header() call in a `.container` div that constrains plugin layouts.
 *
 * ALWAYS-ON:
 * - Hooks buddyx_is_full_width_page → true on WPMediaVerse pages so the
 *   BuddyX header/footer skip the container wrapper.
 *
 * FUTURE (buddynext_css_vars filter):
 * - Map BuddyX Kirki Customizer values to --bn-* CSS tokens so the colour
 *   scheme carries across theme and plugin surfaces without manual config.
 *
 * This bridge bails immediately when the BuddyX theme is not active,
 * so it is safe to load unconditionally on every site.
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

/**
 * BuddyX ↔ BuddyNext integration layer.
 */
class BuddyXBridge {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action (priority 25).
	 * Bails immediately when BuddyX is not the active theme.
	 */
	public function init(): void {
		if ( 'buddyx' !== get_template() ) {
			return;
		}

		// Signal BuddyX to skip the .container wrapper on WPMediaVerse pages.
		add_filter( 'buddyx_is_full_width_page', array( $this, 'is_full_width_on_mediaverse' ) );
	}

	/**
	 * Return true on WPMediaVerse front-end pages.
	 *
	 * WPMediaVerse registers page IDs in options (mvs_page_dashboard,
	 * mvs_page_explore, mvs_page_upload). Detecting any of those as the
	 * current page tells BuddyX to skip its container wrapper.
	 *
	 * Hooked on: buddyx_is_full_width_page( bool $is_full_width )
	 *
	 * @param bool $is_full_width Existing value from earlier filters.
	 * @return bool
	 */
	public function is_full_width_on_mediaverse( bool $is_full_width ): bool {
		if ( $is_full_width ) {
			return true;
		}

		// WPMediaVerse plugin not active — nothing to do.
		if ( ! class_exists( 'WPMediaVerse\\Core\\Plugin' ) ) {
			return false;
		}

		$mvs_page_options = array(
			'mvs_page_dashboard',
			'mvs_page_explore',
			'mvs_page_upload',
			'mvs_page_media',
			'mvs_page_profile',
		);

		$queried_id = (int) get_queried_object_id();
		if ( 0 === $queried_id ) {
			return false;
		}

		foreach ( $mvs_page_options as $option_key ) {
			if ( (int) get_option( $option_key, 0 ) === $queried_id ) {
				return true;
			}
		}

		return false;
	}
}
