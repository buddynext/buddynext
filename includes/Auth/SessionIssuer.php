<?php
/**
 * Session issuance — the single authority for handing out an auth cookie.
 *
 * NOTHING in BuddyNext may call wp_set_auth_cookie() directly. Social login used
 * to, and that one shortcut was the root of two critical bypasses:
 *
 *   - Admin approval. The hold is a `wp_authenticate_user` filter (Core\Plugin),
 *     so it only fires for callers that go through the core authenticate chain.
 *     A raw cookie never touches that chain, so a pending member got a session.
 *   - Two-factor. TwoFactorLoginGuard interposes on `wp_login`, but bails unless
 *     $pagenow is wp-login.php. The OAuth callback runs on template_redirect, so
 *     the guard returned immediately — and the cookie was already set anyway.
 *
 * Centralising issuance means a door cannot sign somebody in without the gates.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Issues an authenticated session, running the gates every door must respect.
 */
class SessionIssuer {

	/**
	 * Start a session for a member, or refuse with the reason why.
	 *
	 * @param int  $user_id  Member to sign in.
	 * @param bool $remember Whether to issue a long-lived cookie.
	 * @return true|WP_Error True when the member is signed in. WP_Error
	 *                       'bn_pending_approval' when they are held for admin
	 *                       approval; 'bn_2fa_required' — carrying a `ticket` in
	 *                       the error data — when they must complete a two-factor
	 *                       challenge before a session is issued.
	 */
	public function start( int $user_id, bool $remember ): bool|WP_Error {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'bn_no_user', __( 'That account could not be found.', 'buddynext' ) );
		}

		// The same chain wp_authenticate() runs. The admin-approval hold lives
		// here, as do any third-party gates the owner has installed.
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// Two-factor: do not sign in. Issue a challenge ticket and let the caller
		// route the member to the code prompt.
		if ( TwoFactorService::is_enabled( $user_id ) ) {
			return new WP_Error(
				'bn_2fa_required',
				__( 'Enter your two-factor code to finish signing in.', 'buddynext' ),
				array(
					'status' => 200,
					'ticket' => TwoFactorService::issue_login_challenge( $user_id, $remember ),
				)
			);
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember, is_ssl() );

		/** This action is documented in wp-includes/user.php (wp_signon). */
		do_action( 'wp_login', $user->user_login, $user );

		return true;
	}
}
