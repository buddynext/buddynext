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
	 * Query arg on the first page after a sign-in.
	 *
	 * @var string
	 */
	public const FIRST_PAGE_ARG = 'bn_signed_in';

	/**
	 * Register the first-page cache guard.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'keep_first_page_out_of_caches' ), 1 );
	}

	/**
	 * The URL a member lands on right after signing in.
	 *
	 * A page cache answers from its guest copy before PHP runs, so nothing a page does
	 * can save the first request after a sign-in: it has to be a URL no cache holds. A
	 * REST sign-in has it worst, because LiteSpeed Cache refuses to set its vary cookie
	 * on a REST request (vary.cls.php, rest_api_init), so the member stays in the guest
	 * bucket until some uncached page runs PHP. Unique per sign-in, so a copy cached from
	 * anyone else's visit can never be the one served, and no plugin list to keep up.
	 *
	 * @param string $url Destination.
	 * @return string
	 */
	public static function first_page_url( string $url ): string {
		return add_query_arg( self::FIRST_PAGE_ARG, wp_generate_password( 8, false ), $url );
	}

	/**
	 * Keep a marked page out of every cache, and the marker out of the address bar.
	 *
	 * Open to anyone, which is safe: all it does is what any unknown query string does to
	 * a cache, plus refuse to store the answer, so a visitor cannot plant a copy.
	 *
	 * @return void
	 */
	public static function keep_first_page_out_of_caches(): void {
		if ( ! isset( $_GET[ self::FIRST_PAGE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence only, nothing is read from it.
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		add_action(
			'wp_head',
			static function (): void {
				wp_print_inline_script_tag( 'var u=new URL(location.href);u.searchParams.delete(' . wp_json_encode( self::FIRST_PAGE_ARG ) . ');history.replaceState(history.state,"",u);' );
			},
			1
		);
	}

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
		$user = $this->admit( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
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

		$this->sign_in( $user, $remember );

		return true;
	}

	/**
	 * Finish a sign-in once the second factor has verified.
	 *
	 * The challenge ticket lives up to five minutes, so the member could have been
	 * put on hold, or sit behind a newly blocked network, since the ticket was
	 * issued. The same gates as start() run again; only the 2FA challenge is skipped.
	 *
	 * @param int  $user_id  Member whose code verified.
	 * @param bool $remember Whether to issue a long-lived cookie.
	 * @return true|WP_Error True when signed in, or the gate's refusal.
	 */
	public function finish_second_factor( int $user_id, bool $remember ): bool|WP_Error {
		$user = $this->admit( $user_id );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$this->sign_in( $user, $remember );

		return true;
	}

	/**
	 * The words to show a member whose sign-in a gate refused after they proved who they are.
	 *
	 * Only the refusals the owner allows are spelled out (the approval hold by default).
	 * A network refusal is never reported: saying so would confirm the credentials to
	 * whoever is being refused.
	 *
	 * @param WP_Error $refusal Error from start() or finish_second_factor().
	 * @return string
	 */
	public static function refusal_message( WP_Error $refusal ): string {
		/** This filter is documented in includes/Auth/AuthController.php. */
		$reportable = (array) apply_filters( 'buddynext_login_reportable_gate_codes', array( 'bn_pending_approval' ) );

		return in_array( $refusal->get_error_code(), $reportable, true )
			? $refusal->get_error_message()
			: __( 'Sign-in is not available right now.', 'buddynext' );
	}

	/**
	 * Load the member and replay the gates every door must respect.
	 *
	 * The same chain wp_authenticate() runs. The blocked-network refusal and the
	 * admin-approval hold live here, as do any third-party gates the owner has installed.
	 *
	 * @param int $user_id Member to admit.
	 * @return WP_User|WP_Error
	 */
	private function admit( int $user_id ): WP_User|WP_Error {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'bn_no_user', __( 'That account could not be found.', 'buddynext' ) );
		}

		$checked = apply_filters( 'wp_authenticate_user', $user, '' );

		return is_wp_error( $checked ) ? $checked : $user;
	}

	/**
	 * Set the auth cookie and fire wp_login. The only place BuddyNext does either.
	 *
	 * @param WP_User $user     Member to sign in.
	 * @param bool    $remember Whether to issue a long-lived cookie.
	 * @return void
	 */
	private function sign_in( WP_User $user, bool $remember ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );

		/** This action is documented in wp-includes/user.php (wp_signon). */
		do_action( 'wp_login', $user->user_login, $user );
	}
}
