<?php
/**
 * The "go back" line on a Space page for a member who came from somewhere else to join it.
 *
 * A page that sends a visitor to a Space (an event preview, a gated page) adds `return_to=<page>` and,
 * optionally, `return_for=event` to the Space link. Once the visitor is a member the Space page offers a
 * link back. It stores nothing: it reads the request, and only a link on this same site is ever shown.
 *
 * @package BuddyNext\Spaces
 * @since   1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Spaces;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the return page from the request.
 */
class SpaceReturnLink {

	/**
	 * The page to go back to: this site only, empty when none or when it points anywhere else.
	 *
	 * @return string
	 */
	public static function url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only link, nothing is changed.
		$raw = isset( $_GET['return_to'] ) ? rawurldecode( (string) wp_unslash( $_GET['return_to'] ) ) : '';
		return '' === $raw ? '' : (string) wp_validate_redirect( esc_url_raw( $raw ), '' );
	}

	/**
	 * Words for the link, from a fixed list so a crafted link cannot put its own text on the page.
	 *
	 * @return array{0: string, 1: string} Line, then button label.
	 */
	public static function words(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only link, nothing is changed.
		$for = isset( $_GET['return_for'] ) ? sanitize_key( (string) wp_unslash( $_GET['return_for'] ) ) : '';
		if ( 'event' === $for ) {
			return array( __( 'You came here from an event.', 'buddynext' ), __( 'Back to the event', 'buddynext' ) );
		}
		return array( __( 'You came here from another page.', 'buddynext' ), __( 'Go back', 'buddynext' ) );
	}
}
