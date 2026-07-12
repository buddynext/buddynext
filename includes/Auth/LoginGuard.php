<?php
/**
 * Blocked-IP enforcement on sign-in.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\Auth;

use BuddyNext\Contracts\ListenerInterface;
use BuddyNext\Moderation\SafeguardService;
use WP_Error;
use WP_User;

/**
 * Refuses a sign-in when the client IP is on the owner's blocklist
 * (option `buddynext_blocked_ips`).
 *
 * The blocklist already gated posting, commenting and registration — but not
 * sign-in, which made it far weaker than it read: a blocked address could still
 * authenticate and keep using every account it already held. It could not post,
 * so the list looked like it worked, while the abusive session itself was never
 * interrupted.
 *
 * Enforcement binds to TWO filters, because BuddyNext has two distinct paths to
 * a session and neither one subsumes the other:
 *
 *   `authenticate` (priority 30)
 *       Every caller of wp_authenticate(): wp-login.php, BuddyNext's REST
 *       /auth/login, XML-RPC, and application passwords. Core resolves an
 *       application password on this same filter at priority 20, so 30 runs
 *       after it and refuses the resolved user.
 *
 *   `wp_authenticate_user` (priority 5)
 *       SessionIssuer does NOT run the `authenticate` chain — it replays only
 *       this filter (that is where the admin-approval hold lives). Social login
 *       and two-factor completion mint their sessions through SessionIssuer, so
 *       without this second binding those two doors would stay wide open. This is
 *       exactly the "secondary entry point" the subsystem-first rule is about.
 *       Priority 5 puts the network refusal ahead of the approval hold at 10 — a
 *       blocked IP is not something a member can resolve by waiting to be
 *       approved, so it should not be told to wait.
 *
 * Admins are deliberately NOT exempt: a blocklist with a hole for the most
 * valuable accounts is not a blocklist. The self-lockout risk that creates is
 * handled where it belongs — Settings::sanitize_ip_list() refuses to save the
 * owner's own current address into the list, so the mistake cannot be made in the
 * first place.
 *
 * Proxy/CDN deployments resolve the real client address through the existing
 * `buddynext_client_ip` filter; there is no second IP-resolution path here.
 */
final class LoginGuard implements ListenerInterface {

	/**
	 * Bind the blocklist to both session-minting chains.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'authenticate', array( $this, 'refuse_blocked_ip' ), 30, 1 );
		add_filter( 'wp_authenticate_user', array( $this, 'refuse_blocked_ip' ), 5, 1 );
	}

	/**
	 * Refuse the sign-in when the client IP is on the blocklist.
	 *
	 * Returns the refusal even when $user is already a WP_Error: a blocked network
	 * is told its network is blocked, not whether the password it guessed was
	 * right. Anything that is not a signed-in user (null, an existing error) needs
	 * no further gate, so only a WP_User is inspected.
	 *
	 * @param null|WP_User|WP_Error $user User, error, or null from earlier filters.
	 * @return null|WP_User|WP_Error The user untouched, or a blocked_ip error.
	 */
	public function refuse_blocked_ip( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		$safeguard = buddynext_service( 'safeguard' );
		if ( ! $safeguard instanceof SafeguardService
			|| ! $safeguard->ip_is_blocked( SafeguardService::client_ip() ) ) {
			return $user;
		}

		return new WP_Error(
			'bn_blocked_ip',
			__( 'Sign-in from your network is not allowed.', 'buddynext' ),
			array( 'status' => 403 )
		);
	}
}
