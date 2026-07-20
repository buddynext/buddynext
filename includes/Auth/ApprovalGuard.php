<?php
/**
 * Admin-approval hold enforcement on sign-in.
 *
 * @package BuddyNext
 */

declare(strict_types=1);

namespace BuddyNext\Auth;

use BuddyNext\Contracts\ListenerInterface;
use WP_Error;
use WP_User;

/**
 * Refuses a sign-in for an account still awaiting administrator approval.
 *
 * The hold is set at registration when `buddynext_reg_mode` is `approval`, and
 * cleared by `POST /auth/approve/{id}`. Until then the account exists and its
 * password is valid — the only thing standing between it and a session is this
 * guard, so every door to a session has to run it.
 *
 * Enforcement binds to TWO hooks, because the doors do not share a chain:
 *
 *   `wp_authenticate_user` (priority 10)
 *       wp-login.php, BuddyNext's REST /auth/login, and SessionIssuer (social
 *       login, two-factor completion). Priority 10 leaves room for LoginGuard's
 *       network refusal at 5 to answer first — a blocked IP is not something a
 *       member can resolve by waiting to be approved.
 *
 *   `wp_authenticate_application_password_errors`
 *       Application passwords, which reach neither of the above. Core resolves a
 *       REST `Authorization: Basic` credential on `determine_current_user` via
 *       `wp_validate_application_password()`, and that function calls
 *       `wp_authenticate_application_password()` directly instead of running the
 *       `authenticate` chain — so no filter on `authenticate`, and nothing on
 *       `wp_authenticate_user`, fires for a mobile client at all.
 *
 * That second binding is the whole point of this class existing. The hold used to
 * live as an inline closure on `wp_authenticate_user` in Plugin::init(), which
 * meant an account held for approval was refused at the login form and admitted
 * over REST: it could mint an application password through
 * `POST /auth/login {issue_app_password}` — a route whose own permission gate the
 * hold did fire on — and then use that credential indefinitely, while the site
 * owner watched an approval queue that no longer had any bearing on access. An
 * approval hold that only holds the front door is not an approval hold.
 *
 * @see LoginGuard for the same two-door problem, one layer out.
 */
final class ApprovalGuard implements ListenerInterface {

	/**
	 * Bind the hold to every chain that can mint a session.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'refuse_pending_approval' ), 10, 1 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'refuse_pending_approval_app_password' ), 10, 2 );
	}

	/**
	 * Whether the account is still waiting on an administrator.
	 *
	 * The single read of the hold, so the password door and the application-password
	 * door cannot answer differently.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_pending( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'bn_pending_approval', true );
	}

	/**
	 * Refuse a password sign-in for an account awaiting approval.
	 *
	 * Anything that is not a signed-in user (null, an existing error) needs no
	 * further gate, so only a WP_User is inspected.
	 *
	 * @param null|WP_User|WP_Error $user User, error, or null from earlier filters.
	 * @return null|WP_User|WP_Error The user untouched, or a pending-approval error.
	 */
	public function refuse_pending_approval( $user ) {
		if ( ! $user instanceof WP_User || ! $this->is_pending( $user->ID ) ) {
			return $user;
		}

		return new WP_Error(
			'bn_pending_approval',
			__( 'Your account is awaiting administrator approval.', 'buddynext' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Refuse an application password for an account awaiting approval.
	 *
	 * Core hands this action a WP_Error to add to; adding refuses the credential.
	 *
	 * @param WP_Error $error Error object to add to.
	 * @param WP_User  $user  The user the credential resolved to.
	 * @return void
	 */
	public function refuse_pending_approval_app_password( WP_Error $error, WP_User $user ): void {
		if ( $this->is_pending( $user->ID ) ) {
			$error->add(
				'bn_pending_approval',
				__( 'Your account is awaiting administrator approval.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}
	}
}
