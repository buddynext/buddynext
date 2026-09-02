<?php
/**
 * Enqueue BuddyNext-native media assets (grid/tile styles + lightbox).
 *
 * BuddyNext owns its media UX — these are BN assets, never WPMediaVerse's. The
 * lightbox is a small vanilla script + a footer shell, loaded on BuddyNext
 * front-end pages so any media tile (feed, single post, profile, space) opens
 * in the BN lightbox.
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

/**
 * Registers + enqueues BN media assets on the front end.
 */
class MediaAssets {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_shell' ) );
	}

	/**
	 * Whether we are on a BuddyNext front-end page.
	 *
	 * @return bool
	 */
	private function is_bn_front(): bool {
		return ! is_admin() && did_action( 'buddynext_loaded' ) > 0;
	}

	/**
	 * Enqueue the media stylesheet + lightbox script.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_bn_front() ) {
			return;
		}

		$base_ver = defined( 'BUDDYNEXT_VERSION' ) ? (string) BUDDYNEXT_VERSION : '';
		$url      = defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : plugins_url( '/', dirname( __DIR__ ) );
		$dir      = defined( 'BUDDYNEXT_DIR' ) ? BUDDYNEXT_DIR : plugin_dir_path( dirname( __DIR__ ) );

		// Cache-bust on file mtime so CSS/JS edits reach the browser without a
		// plugin version bump (the version string stays fixed pre-release).
		$css_path = $dir . 'assets/css/bn-media.css';
		$js_path  = $dir . 'assets/js/media/lightbox.js';
		$css_ver  = file_exists( $css_path ) ? $base_ver . '.' . (string) filemtime( $css_path ) : $base_ver;
		$js_ver   = file_exists( $js_path ) ? $base_ver . '.' . (string) filemtime( $js_path ) : $base_ver;

		// bn-base, like every other BuddyNext feature stylesheet: bn-media.css
		// authors against the --bn-* tokens bn-base defines, so it has to load
		// after it. Declared here rather than left to enqueue order, because
		// order is not a guarantee — a stylesheet reached through a block gets
		// hoisted into the head by WordPress 6.9's wp_hoist_late_printed_styles()
		// and can land above anything it does not name as a dependency.
		wp_enqueue_style(
			'bn-media',
			$url . 'assets/css/bn-media.css',
			array( 'bn-base' ),
			$css_ver
		);

		wp_enqueue_script(
			'bn-media-lightbox',
			$url . 'assets/js/media/lightbox.js',
			array( 'wp-i18n' ),
			$js_ver,
			true
		);
		wp_set_script_translations( 'bn-media-lightbox', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// Config for the interactive lightbox. It consumes WPMediaVerse at the
		// API level only — reactions / comments / favorite / view all hit the
		// engine REST routes (mvs/v1/media/{id}/...). The reaction set mirrors
		// BuddyNext's own feed reactions so the UX is consistent.
		wp_localize_script(
			'bn-media-lightbox',
			'bnMedia',
			array(
				'mvsRest'       => esc_url_raw( rest_url( 'mvs/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'userId'        => get_current_user_id(),
				// Media reporting is a WPMediaVerse feature behind its own `mvs_reports_enabled`
				// filter (default OFF; our bridge turns it on). Ask it, rather than assuming — an
				// owner who switches it back off must get NO Report button, not a button that
				// answers 403.
				'canReport'     => (bool) apply_filters( 'mvs_reports_enabled', false ),
				'reactionTypes' => array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' ),
				'i18n'          => array(
					'view'        => __( 'view', 'buddynext' ),
					'views'       => __( 'views', 'buddynext' ),
					'favorite'    => __( 'Favorite', 'buddynext' ),
					'favorited'   => __( 'Favorited', 'buddynext' ),
					'noComments'  => __( 'No comments on this photo yet.', 'buddynext' ),
					'loginPrompt' => __( 'Log in to react and comment.', 'buddynext' ),
					'posting'     => __( 'Posting…', 'buddynext' ),
					// Read by lightbox.js when the clipboard API is unavailable; the key
					// was missing so the manual-copy prompt always rendered its English fallback.
					'copyManual'  => __( 'Copy this link: ', 'buddynext' ),
					// Shown in the Save panel when the collections route is absent
					// (MediaVerse Pro not installed). The control used to delete
					// itself on click instead, which reads as a broken button.
					'saveNoPro'     => __( 'Saving to a collection needs MediaVerse Pro. Use the bookmark on the post to save it.', 'buddynext' ),
					// Moderator "Remove from space" (unlink) confirm + result toasts.
					'unlinkConfirm' => __( 'Remove this from the space? The member keeps their own copy.', 'buddynext' ),
					'unlinkDone'    => __( 'Removed from the space.', 'buddynext' ),
					'unlinkFail'    => __( 'Could not remove it from the space.', 'buddynext' ),
				),
			)
		);
	}

	/**
	 * Print the lightbox shell once in the footer.
	 *
	 * @return void
	 */
	public function render_shell(): void {
		if ( ! $this->is_bn_front() ) {
			return;
		}
		if ( function_exists( 'buddynext_get_template' ) ) {
			buddynext_get_template( 'partials/media-lightbox.php' );
		}
	}
}
