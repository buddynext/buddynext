<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Enforce two-factor authentication on WordPress's native sign-in paths.
 *
 * BuddyNext's REST login (AuthController) already interposes a 2FA challenge
 * between the password step and the session, but wp-login.php and XML-RPC go
 * straight through WordPress core's authenticate chain and set the auth cookie
 * without ever asking for a second factor — so a member who turned 2FA on could
 * still be signed in with just a password through those paths. That is the
 * bypass this guard closes:
 *
 *   - wp-login.php : after the password verifies, the cookie core just set is
 *     cleared and an interim TOTP/email/backup-code form is interposed; the
 *     session is only re-established once a code verifies.
 *   - XML-RPC      : an interactive second factor is impossible over XML-RPC, so
 *     password auth is refused for 2FA accounts (use an application password).
 *
 * The challenge reuses TwoFactorService's existing login-challenge ticket
 * primitives, so the wp-login.php flow and the REST flow share one code path.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Contracts\ListenerInterface;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Closes the wp-login.php / XML-RPC two-factor bypass.
 */
class TwoFactorLoginGuard implements ListenerInterface {

	/**
	 * The wp-login.php action that renders / validates the interim challenge.
	 */
	private const ACTION = 'bn_2fa';

	/**
	 * Register the native-login guards.
	 *
	 * @return void
	 */
	public function register(): void {
		// Late on the chain so $user is the resolved WP_User (password verified).
		add_filter( 'authenticate', array( $this, 'block_xmlrpc' ), 100, 1 );
		add_action( 'wp_login', array( $this, 'interpose_challenge' ), 10, 2 );
		add_action( 'login_form_' . self::ACTION, array( $this, 'handle_challenge' ) );
	}

	/**
	 * Refuse password sign-in over XML-RPC for accounts with 2FA enabled.
	 *
	 * XML-RPC carries the account password and cannot prompt for a second
	 * factor, so allowing it would defeat 2FA. Application passwords remain the
	 * supported mechanism for programmatic access.
	 *
	 * @param mixed $user Result of the authenticate chain so far.
	 * @return mixed Original value, or WP_Error to block.
	 */
	public function block_xmlrpc( $user ) {
		if ( $user instanceof WP_User
			&& defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST
			&& TwoFactorService::is_enabled( (int) $user->ID )
		) {
			return new WP_Error(
				'bn_2fa_xmlrpc_blocked',
				__( 'This account uses two-factor authentication and cannot sign in over XML-RPC. Use an application password instead.', 'buddynext' )
			);
		}
		return $user;
	}

	/**
	 * Interpose the interim 2FA form on a wp-login.php sign-in.
	 *
	 * Fires on wp_login, where wp_signon has already verified the password and
	 * set the auth cookie. For a 2FA account we clear that cookie and present the
	 * challenge instead, so no usable session exists until a code verifies. The
	 * pagenow guard keeps this scoped to wp-login.php — the REST flow (which also
	 * fires wp_login after its own 2FA step) and other programmatic logins are
	 * left untouched.
	 *
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       Authenticated user.
	 * @return void
	 */
	public function interpose_challenge( $user_login, $user = null ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		if ( ! TwoFactorService::is_enabled( (int) $user->ID ) ) {
			return;
		}

		// The login form's nonce was already validated by wp_signon before this
		// action fired, so reading the submitted remember/redirect values here is
		// safe; we only echo them back after sanitising.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$remember    = ! empty( $_POST['rememberme'] );
		$redirect_to = isset( $_REQUEST['redirect_to'] )
			? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		wp_clear_auth_cookie();

		$token = TwoFactorService::issue_login_challenge( (int) $user->ID, $remember );

		$this->render_form( $token, $redirect_to );
		exit;
	}

	/**
	 * Render or validate the interim challenge at wp-login.php?action=bn_2fa.
	 *
	 * @return void
	 */
	public function handle_challenge(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token       = isset( $_REQUEST['bn_2fa_token'] )
			? sanitize_text_field( wp_unslash( (string) $_REQUEST['bn_2fa_token'] ) )
			: '';
		$redirect_to = isset( $_REQUEST['redirect_to'] )
			? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) )
			: '';
		$send_email  = ! empty( $_GET['bn_2fa_send_email'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ticket = '' !== $token ? TwoFactorService::peek_login_challenge( $token ) : null;
		if ( null === $ticket ) {
			wp_safe_redirect( add_query_arg( 'bn_2fa', 'expired', wp_login_url( $redirect_to ) ) );
			exit;
		}

		// Email-code fallback: mail a one-time code, then re-show the form.
		if ( $send_email ) {
			TwoFactorService::send_email_code( $ticket['user'] );
			$this->render_form(
				$token,
				$redirect_to,
				'',
				__( 'We emailed a one-time code to the address on your account.', 'buddynext' )
			);
			exit;
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( $is_post ) {
			// The bn_2fa_token IS the CSRF credential: a 32-char, single-use,
			// short-TTL server-side ticket minted only after the password verified
			// (same model as the REST /auth/2fa flow). A WP nonce can't be used
			// here because interpose_challenge clears the auth cookie, so the POST
			// arrives as user 0 and a user-bound nonce would never match.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$code = isset( $_POST['bn_2fa_code'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['bn_2fa_code'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			if ( TwoFactorService::verify_login_code( $ticket['user'], $code ) ) {
				TwoFactorService::consume_login_challenge( $token );
				$this->complete( (int) $ticket['user'], ! empty( $ticket['remember'] ), $redirect_to );
			}

			$this->render_form(
				$token,
				$redirect_to,
				__( 'That code was not correct. Try again, or use a backup code.', 'buddynext' )
			);
			exit;
		}

		$this->render_form( $token, $redirect_to );
		exit;
	}

	/**
	 * Establish the session once the second factor has verified, then redirect.
	 *
	 * Mirrors AuthController::complete_login() — set the cookie and fire wp_login
	 * — but removes this guard's own wp_login hook first so completing the login
	 * does not re-trigger the challenge.
	 *
	 * @param int    $user_id     Verified user ID.
	 * @param bool   $remember    Persistent cookie.
	 * @param string $redirect_to Post-login destination (validated).
	 * @return void
	 */
	private function complete( int $user_id, bool $remember, string $redirect_to ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember, is_ssl() );

		remove_action( 'wp_login', array( $this, 'interpose_challenge' ), 10 );
		/** This action is documented in wp-includes/user.php (wp_signon). */
		do_action( 'wp_login', $user->user_login, $user );

		$dest = '' !== $redirect_to ? $redirect_to : admin_url();
		wp_safe_redirect( wp_validate_redirect( $dest, admin_url() ) );
		exit;
	}

	/**
	 * Render the interim challenge form in the native wp-login.php chrome.
	 *
	 * @param string $token       Challenge ticket token.
	 * @param string $redirect_to Post-login destination.
	 * @param string $error       Optional error message.
	 * @param string $notice      Optional info notice.
	 * @return void
	 */
	private function render_form( string $token, string $redirect_to, string $error = '', string $notice = '' ): void {
		$action_url = site_url( 'wp-login.php?action=' . self::ACTION, 'login_post' );
		$email_url  = add_query_arg(
			array(
				'action'            => self::ACTION,
				'bn_2fa_token'      => rawurlencode( $token ),
				'bn_2fa_send_email' => '1',
				'redirect_to'       => rawurlencode( $redirect_to ),
			),
			site_url( 'wp-login.php', 'login' )
		);

		$wp_error = new WP_Error();
		if ( '' !== $error ) {
			$wp_error->add( 'bn_2fa_error', $error );
		}

		login_header( __( 'Two-Factor Verification', 'buddynext' ), '', $wp_error );

		if ( '' !== $notice ) {
			echo '<p class="message">' . esc_html( $notice ) . '</p>';
		}
		?>
		<form name="bn_2fa_form" id="loginform" action="<?php echo esc_url( $action_url ); ?>" method="post">
			<p><?php esc_html_e( 'Enter the code from your authenticator app, an emailed code, or a backup code to finish signing in.', 'buddynext' ); ?></p>
			<p>
				<label for="bn_2fa_code"><?php esc_html_e( 'Verification code', 'buddynext' ); ?></label>
				<input type="text" name="bn_2fa_code" id="bn_2fa_code" class="input" value="" size="20"
					autocomplete="one-time-code" inputmode="numeric" autocapitalize="off" autofocus="autofocus" />
			</p>
			<input type="hidden" name="bn_2fa_token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
			<p class="submit">
				<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
					value="<?php esc_attr_e( 'Verify', 'buddynext' ); ?>" />
			</p>
		</form>
		<p id="nav">
			<a href="<?php echo esc_url( $email_url ); ?>"><?php esc_html_e( 'Email me a code instead', 'buddynext' ); ?></a>
		</p>
		<?php
		login_footer( 'bn_2fa_code' );
		exit;
	}
}
