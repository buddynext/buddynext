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
 *       /auth/login, and XML-RPC. Core resolves an application password on this
 *       same filter at priority 20, so 30 runs after it — but see the third
 *       binding: that is NOT the path a REST client takes.
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
 *   `wp_authenticate_application_password_errors`
 *       An earlier revision of this docblock claimed the `authenticate` binding
 *       covered application passwords. It does not, and the mistake left the
 *       blocklist off the one door a mobile app uses.
 *
 *       Core registers `wp_authenticate_application_password()` on `authenticate`
 *       at 20, which is where that claim came from. But a REST client sending
 *       `Authorization: Basic` never reaches it: core resolves the credential on
 *       `determine_current_user` via `wp_validate_application_password()`
 *       (wp-includes/user.php), and that function calls
 *       `wp_authenticate_application_password()` DIRECTLY rather than running the
 *       `authenticate` chain. So `authenticate` — and every filter on it —
 *       simply does not fire for REST.
 *
 *       This action is core's own extension point for exactly this ("allows for
 *       plugins to add additional constraints to prevent an application password
 *       from being used"). It fires on both paths, so it is the only binding that
 *       actually covers a mobile client. Adding to the passed WP_Error refuses the
 *       credential.
 *
 * The third binding is the "secondary entry point" rule again, one layer down: an
 * app password is a fourth door to a session, and it was the only one nothing
 * watched.
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
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'refuse_blocked_ip_app_password' ), 10, 2 );
	}

	/**
	 * Refuse an application password when the client IP is on the blocklist.
	 *
	 * Core hands this action a WP_Error to add to; adding refuses the credential.
	 * It is a separate callback from refuse_blocked_ip() only because the shape
	 * differs — the decision is the same one, taken through ip_is_blocked(), so
	 * there is a single blocklist rule and not two that can drift.
	 *
	 * @param WP_Error $error Error object to add to.
	 * @param WP_User  $user  The user the credential resolved to.
	 * @return void
	 */
	public function refuse_blocked_ip_app_password( WP_Error $error, WP_User $user ): void {
		if ( $this->refuse_blocked_ip( $user ) instanceof WP_Error ) {
			$error->add(
				'bn_blocked_ip',
				__( 'Sign-in from your network is not allowed.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}
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
