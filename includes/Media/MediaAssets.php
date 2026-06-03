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

		$ver = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : null;
		$url = defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : plugins_url( '/', dirname( __DIR__ ) );

		wp_enqueue_style(
			'bn-media',
			$url . 'assets/css/bn-media.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'bn-media-lightbox',
			$url . 'assets/js/media/lightbox.js',
			array(),
			$ver,
			true
		);

		// Config for the interactive lightbox. It consumes WPMediaVerse at the
		// API level only — reactions / comments / favorite / view all hit the
		// engine REST routes (mvs/v1/media/{id}/...). The reaction set mirrors
		// BuddyNext's own feed reactions so the UX is consistent.
		wp_localize_script(
			'bn-media-lightbox',
			'bnMedia',
			array(
				'mvsRest'      => esc_url_raw( rest_url( 'mvs/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'userId'       => get_current_user_id(),
				'reactionTypes' => array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' ),
				'i18n'         => array(
					'view'      => __( 'view', 'buddynext' ),
					'views'     => __( 'views', 'buddynext' ),
					'favorite'  => __( 'Favorite', 'buddynext' ),
					'favorited' => __( 'Favorited', 'buddynext' ),
					'noComments' => __( 'No comments yet. Be the first to say something!', 'buddynext' ),
					'loginPrompt' => __( 'Log in to react and comment.', 'buddynext' ),
					'posting'   => __( 'Posting…', 'buddynext' ),
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
