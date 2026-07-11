<?php
/**
 * The WordPress core registration form, brought under BuddyNext's policy.
 *
 * BuddyNext force-enabled wp-login.php?action=register and then protected none
 * of it: no captcha, no honeypot, no time-trap, no rate limit, no allowed-domain
 * check, and in invite-only mode no invite requirement. Everything the owner
 * configured in Settings governed exactly one of the doors into their community.
 *
 * Two things are fixed here.
 *
 * 1. We are a plugin, not a SaaS. BuddyNext must not overwrite a decision the
 *    owner made on their own site. `closed` is now a real registration mode, and
 *    an owner who closes registration finds it still closed.
 *
 * 2. By default BuddyNext is the only front door and the core form redirects to
 *    it. An owner may re-enable the core form — some sites have other plugins
 *    hooking register_form — and when they do, it still passes through the shared
 *    gate. The choice is which signup UI, never whether the owner's policy applies.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Governs wp-login.php?action=register.
 */
class CoreRegistration {

	/**
	 * Option: may the WordPress core registration form be used at all?
	 */
	public const OPT_ALLOW = 'buddynext_allow_core_registration';

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'login_form_register', array( $this, 'maybe_redirect_to_buddynext' ) );
		add_filter( 'registration_errors', array( $this, 'apply_policy' ), 10, 3 );

		// Mirror the registration mode onto WP core's users_can_register. This used
		// to live in Admin\Settings, so it only ran inside wp-admin — a mode set by
		// WP-CLI or by code never reached the core flag.
		add_action( 'update_option_buddynext_reg_mode', array( $this, 'sync_core_registration' ), 10, 2 );
		add_action( 'add_option_buddynext_reg_mode', array( $this, 'sync_core_registration' ), 10, 2 );
	}

	/**
	 * Mirror the BuddyNext registration mode onto WP core's users_can_register.
	 *
	 * MIRROR, not override. 'closed' is a real, selectable mode and an owner who
	 * closes registration finds it still closed. Previously the UI could only
	 * produce open|invite|approval, so this forced users_can_register to 1 on every
	 * save: an owner could not turn registration off through BuddyNext at all, and
	 * one who turned it off in Settings -> General had it silently switched back on
	 * by us. We are a plugin, not a SaaS; that decision is theirs.
	 *
	 * @param mixed $unused_or_old First hook arg (old value on update, option name on add) — unused.
	 * @param mixed $value         The saved registration mode.
	 * @return void
	 */
	public function sync_core_registration( $unused_or_old, $value ): void {
		unset( $unused_or_old );

		update_option( 'users_can_register', 'closed' === (string) $value ? '0' : '1' );
	}

	/**
	 * Is the core registration form available on this site?
	 *
	 * @return bool
	 */
	public static function is_allowed(): bool {
		return (bool) get_option( self::OPT_ALLOW, false );
	}

	/**
	 * Send visitors to the BuddyNext signup route unless the owner opted in.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_buddynext(): void {
		if ( self::is_allowed() ) {
			return;
		}

		wp_safe_redirect( \BuddyNext\Core\PageRouter::signup_url() );
		exit;
	}

	/**
	 * Run the shared registration policy on the core form.
	 *
	 * Applies even when the owner has re-enabled the form: opting into a different
	 * signup UI must never mean opting out of the spam protection and access
	 * policy they configured.
	 *
	 * @param WP_Error $errors Existing registration errors.
	 * @param string   $login  Submitted username (unused; the policy keys on email).
	 * @param string   $email  Submitted email address.
	 * @return WP_Error
	 */
	public function apply_policy( $errors, $login, $email ) {
		unset( $login );

		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}

		$policy = buddynext_service( 'registration_policy' );

		$access = $policy->check_access( (string) $email, null, RegistrationPolicy::SOURCE_CORE );
		if ( is_wp_error( $access ) ) {
			$errors->add( $access->get_error_code(), $access->get_error_message() );
			return $errors;
		}

		$guard = ( new RegistrationGuard() )->check(
			array(
				'source' => RegistrationPolicy::SOURCE_CORE,
				'email'  => (string) $email,
				'ip'     => AuthController::client_ip(),
			)
		);
		if ( is_wp_error( $guard ) ) {
			$errors->add( $guard->get_error_code(), $guard->get_error_message() );
		}

		return $errors;
	}
}
