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

		// Repair the BuddyNext header user section (bell + messages + avatar) when
		// BuddyX's auto-place shim drops it into the theme header. Runs after
		// bn-header is enqueued (priority 15) so the inline style attaches.
		add_action( 'wp_enqueue_scripts', array( $this, 'header_section_overrides' ), 20 );
	}

	/**
	 * Scoped CSS that re-asserts BuddyNext's header chrome inside BuddyX's header.
	 *
	 * BuddyX styles every header link with `.main-navigation a` /
	 * `.buddypress-icons-wrapper a` (display:block, width:100%, its own padding),
	 * which collapses the bell/message icons and spreads the avatar from its
	 * caret. Re-assert the component sizing, scoped to `.bn-header-user-section`
	 * so nothing else in the BuddyX header is affected. (The red-button leak on
	 * the caret and every other BuddyNext button is handled the native way — the
	 * theme's button token rule excludes `[class*="bn-"]`.)
	 *
	 * Hooked on: wp_enqueue_scripts (priority 20).
	 */
	public function header_section_overrides(): void {
		if ( ! is_user_logged_in() || ! wp_style_is( 'bn-header', 'enqueued' ) ) {
			return;
		}

		$css = <<<'CSS'
.bn-header-user-section .bn-block-notification-bell {
	position: relative;
	display: inline-flex;
}
.bn-header-user-section .bn-notification-bell-link,
.bn-header-user-section .bn-header-msg {
	display: inline-flex;
	width: 40px;
	height: 40px;
	padding: 0;
	color: var(--bn-ink-2, #475569);
}
.bn-header-user-section .bn-notification-bell-link:hover,
.bn-header-user-section .bn-notification-bell-link:focus-visible,
.bn-header-user-section .bn-header-msg:hover,
.bn-header-user-section .bn-header-msg:focus-visible {
	color: var(--bn-ink, #0f172a);
}
.bn-header-user-section .bn-header-user__avatar {
	display: inline-flex;
	width: auto;
	padding: 0;
}
.bn-header-user-section .bn-header-user__name {
	display: inline-block;
	width: auto;
	padding: 0;
}
.bn-header-user-section .bn-header-user__item {
	display: flex;
	width: 100%;
	padding: var(--bn-s2, 0.5rem) var(--bn-s3, 0.75rem);
}
CSS;

		wp_add_inline_style( 'bn-header', $css );
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
