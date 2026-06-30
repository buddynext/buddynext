<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for authentication-related endpoints.
 *
 * Routes (all under buddynext/v1):
 *   POST /auth/login          — log a user in by email/username + password
 *   POST /auth/register       — create a new user account
 *   POST /auth/verify/resend  — resend email verification for current user
 *   GET  /auth/verify/status  — check email verification status for current user
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Auth\VerificationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles email verification REST endpoints.
 */
class AuthController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/auth/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
					'remember' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/2fa',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_two_factor' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'twofa_token' => array(
						'required' => true,
						'type'     => 'string',
					),
					'code'        => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/2fa/email-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_two_factor_email' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'twofa_token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'        => array(
						'required' => true,
						'type'     => 'string',
					),
					'user_login'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'password'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'terms_agreed' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'invite'       => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/lost-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lost_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_login' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/reset-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'login'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/approve/(?P<id>[\d]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_member' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/verify/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend_verification' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/verify/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'verification_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/change-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_password' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'current_password' => array(
						'required' => true,
						'type'     => 'string',
					),
					'new_password'     => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/change-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_email' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'email' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/sign-out-everywhere',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sign_out_everywhere' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/auth/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_nonce' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /auth/nonce — mint a fresh wp_rest nonce for this session.
	 *
	 * Used by the shared front-end REST client to recover from a stale-nonce
	 * 403 without forcing a full page reload. Chicken-and-egg subtlety: the
	 * refresh request itself carries the stale nonce, so core's
	 * rest_cookie_check_errors() has already downgraded it to user 0 — minting
	 * here would produce an anonymous nonce that can never verify against the
	 * caller's logged-in cookie. Re-validate the auth cookie directly (the same
	 * trust basis core's own admin-ajax `rest-nonce` refresh uses: cookie alone,
	 * no nonce) and mint for that user. Safe: the response is a nonce usable only
	 * by the same session, and cross-origin callers cannot read it.
	 *
	 * @return WP_REST_Response
	 */
	public function get_nonce(): WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			$cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $cookie_user ) {
				wp_set_current_user( $cookie_user );
			}
		}

		$response = new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		// A nonce response must never come from a page/CDN cache.
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );

		return $response;
	}

	/**
	 * POST /auth/change-password — set a new password after verifying the current one.
	 *
	 * Returns 422 with field-keyed errors on failure (mirrors update_profile).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$user      = get_userdata( $user_id );
		$current   = (string) $request->get_param( 'current_password' );
		$candidate = (string) $request->get_param( 'new_password' );

		$errors = array();

		if ( ! $user ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Account not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === trim( $current ) ) {
			$errors['current_password'] = __( 'Enter your current password.', 'buddynext' );
		} elseif ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			$errors['current_password'] = __( 'Current password does not match.', 'buddynext' );
		}

		if ( '' === trim( $candidate ) ) {
			$errors['new_password'] = __( 'Enter a new password.', 'buddynext' );
		} elseif ( strlen( $candidate ) < 8 ) {
			$errors['new_password'] = __( 'Use at least 8 characters.', 'buddynext' );
		} elseif ( $current === $candidate ) {
			$errors['new_password'] = __( 'New password must be different from current password.', 'buddynext' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		wp_set_password( $candidate, $user_id );

		// wp_set_password() destroys all session tokens — re-authenticate so the
		// current request's cookie stays valid for the response/redirect chain.
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		return new WP_REST_Response(
			array( 'saved' => true ),
			200
		);
	}

	/**
	 * POST /auth/change-email — request an email change, sending verification.
	 *
	 * Stores the candidate in usermeta and triggers a verification token via
	 * the existing VerificationService. The swap only happens after the user
	 * clicks the link in the email (confirm-then-swap pattern).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$user      = get_userdata( $user_id );
		$candidate = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! $user ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Account not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$errors = array();

		if ( '' === $candidate || ! is_email( $candidate ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'buddynext' );
		} elseif ( strtolower( $candidate ) === strtolower( (string) $user->user_email ) ) {
			$errors['email'] = __( 'That is already your email.', 'buddynext' );
		} elseif ( email_exists( $candidate ) ) {
			$errors['email'] = __( 'An account with that email already exists.', 'buddynext' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'saved'  => false,
					'errors' => $errors,
				),
				422
			);
		}

		update_user_meta( $user_id, 'bn_pending_email', $candidate );

		/**
		 * Fires after a user has requested an email change. The default
		 * VerificationListener hooks here to send a confirmation email
		 * with a token-bearing link. Plugins can override to send via a
		 * branded template or alternative transport.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $user_id   Account requesting the change.
		 * @param string $candidate Pending email address.
		 */
		do_action( 'buddynext_email_change_requested', $user_id, $candidate );

		return new WP_REST_Response(
			array(
				'saved'   => true,
				'pending' => $candidate,
				'message' => __( 'Check your inbox to confirm.', 'buddynext' ),
			),
			200
		);
	}

	/**
	 * POST /auth/sign-out-everywhere — destroy all session tokens, then re-auth
	 * the current request so the response stays valid.
	 *
	 * @return WP_REST_Response
	 */
	public function sign_out_everywhere(): WP_REST_Response {
		$user_id = get_current_user_id();

		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		// Re-issue a cookie for the current device so the user can see the
		// confirmation toast without being booted on the response.
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		return new WP_REST_Response(
			array( 'signed_out' => true ),
			200
		);
	}

	/**
	 * POST /auth/login — authenticate a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_input = trim( (string) $request->get_param( 'user' ) );
		$password   = (string) $request->get_param( 'password' );
		$remember   = (bool) $request->get_param( 'remember' );

		if ( '' === $user_input || '' === $password ) {
			return new WP_Error(
				'rest_missing_credentials',
				__( 'Please enter your email or username and your password.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$login = is_email( $user_input ) ? $this->resolve_login_for_email( $user_input ) : $user_input;

		// Verify the password (runs the full authenticate chain, including the
		// pending-approval gate) WITHOUT setting an auth cookie yet — so a 2FA
		// challenge can be interposed before the session is actually created.
		$user = wp_authenticate( $login, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'rest_login_failed',
				__( 'Invalid email or password.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		if ( '' === $redirect_to ) {
			$redirect_to = \BuddyNext\Core\PageRouter::activity_url();
		}

		// Two-factor gate (optional, per-user). When on, hold the session and
		// hand back a one-time challenge ticket; the cookie is only set once a
		// code verifies at /auth/2fa.
		if ( TwoFactorService::is_enabled( (int) $user->ID ) ) {
			$token = TwoFactorService::issue_login_challenge( (int) $user->ID, $remember );
			return new WP_REST_Response(
				array(
					'success'        => true,
					'twofa_required' => true,
					'twofa_token'    => $token,
					'email_hint'     => $this->mask_email( (string) $user->user_email ),
					'redirect_to'    => esc_url_raw( $redirect_to ),
				),
				200
			);
		}

		$this->complete_login( $user, $remember );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user->ID,
				'redirect_to' => esc_url_raw( $redirect_to ),
			),
			200
		);
	}

	/**
	 * POST /auth/2fa — complete a two-factor sign-in by verifying a code.
	 *
	 * Accepts a TOTP code, an emailed one-time code, or a single-use backup code.
	 * The challenge ticket is consumed only on success, so a mistyped code can be
	 * retried until the ticket expires.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_two_factor( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = (string) $request->get_param( 'twofa_token' );
		$code  = (string) $request->get_param( 'code' );

		$ticket = TwoFactorService::peek_login_challenge( $token );
		if ( null === $ticket ) {
			return new WP_Error(
				'rest_2fa_expired',
				__( 'Your sign-in session expired. Please enter your password again.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$verified = TwoFactorService::verify_login_challenge( $token, $ticket['user'], $code );
		if ( is_wp_error( $verified ) ) {
			// 'bn_2fa_locked' once the per-ticket attempt cap is hit (429), otherwise
			// a wrong-code rejection (422). The throttle is shared with the wp-login
			// bn_2fa path so brute-force enforcement is identical on both surfaces.
			$locked = 'bn_2fa_locked' === $verified->get_error_code();
			return new WP_Error(
				$locked ? 'rest_2fa_locked' : 'rest_2fa_failed',
				$verified->get_error_message(),
				array(
					'status' => $locked ? 429 : 422,
					'fields' => array( 'code' => __( 'Incorrect or expired code.', 'buddynext' ) ),
				)
			);
		}

		TwoFactorService::consume_login_challenge( $token );

		$user = get_userdata( $ticket['user'] );
		if ( ! $user ) {
			return new WP_Error( 'rest_2fa_failed', __( 'Account not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$this->complete_login( $user, $ticket['remember'] );

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		if ( '' === $redirect_to ) {
			$redirect_to = \BuddyNext\Core\PageRouter::activity_url();
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user->ID,
				'redirect_to' => esc_url_raw( $redirect_to ),
			),
			200
		);
	}

	/**
	 * POST /auth/2fa/email-code — email a one-time fallback code to the user
	 * holding a valid challenge ticket. The response is deliberately generic so
	 * it never reveals whether the ticket or address is valid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_two_factor_email( WP_REST_Request $request ): WP_REST_Response {
		$token  = (string) $request->get_param( 'twofa_token' );
		$ticket = TwoFactorService::peek_login_challenge( $token );
		// Only send when the ticket is valid AND past the per-ticket resend
		// cooldown, so the endpoint can't be used to mail-bomb a member. The
		// response stays generic either way so it never reveals ticket validity.
		if ( null !== $ticket && TwoFactorService::can_resend_email_code( $token ) ) {
			TwoFactorService::send_email_code( $ticket['user'] );
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If your session is still valid, a code is on its way to your email.', 'buddynext' ),
			),
			200
		);
	}

	/**
	 * Complete sign-in for a verified user: set the current user, issue the auth
	 * cookie, and fire wp_login (the tail of wp_signon, split out so a 2FA step
	 * can run between password verification and session creation).
	 *
	 * @param \WP_User $user     Verified user.
	 * @param bool     $remember Persistent cookie.
	 * @return void
	 */
	private function complete_login( \WP_User $user, bool $remember ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		/** This action is documented in wp-includes/user.php (wp_signon). */
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Mask an email for a privacy-safe hint (e.g. "a***e@example.com").
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '';
		}
		$name   = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		$first  = substr( $name, 0, 1 );
		$last   = strlen( $name ) > 1 ? substr( $name, -1 ) : '';
		return $first . '***' . $last . $domain;
	}

	/**
	 * POST /auth/register — create a new account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'users_can_register' ) ) {
			return new WP_Error(
				'rest_registration_closed',
				__( 'Registration is currently closed.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Registration policy: open | invite | approval (Settings → Registration).
		$reg_mode = (string) get_option( 'buddynext_reg_mode', buddynext_default_reg_mode() );

		// Resolve any invitation token up front, in every registration mode: an
		// invite-only community requires it, but a space invitation link also
		// carries one in open/approval mode so the new account can be dropped into
		// the space it was invited to (see the space_id handling after creation).
		$token  = sanitize_text_field( (string) $request->get_param( 'invite' ) );
		$invite = '' !== $token ? ( new \BuddyNext\Onboarding\InviteService() )->get_by_token( $token ) : null;
		if ( 'invite' === $reg_mode ) {
			if ( null === $invite ) {
				return new WP_Error(
					'rest_invite_required',
					__( 'This community is invite-only — a valid invitation is required to register.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		$user_login   = sanitize_user( (string) $request->get_param( 'user_login' ), true );
		$password     = (string) $request->get_param( 'password' );
		$terms_agreed = (bool) $request->get_param( 'terms_agreed' );

		$errors = array();

		if ( ! $terms_agreed ) {
			$errors['terms_agreed'] = __( 'You must accept the Terms of Service to continue.', 'buddynext' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'buddynext' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'An account already exists with this email address.', 'buddynext' );
		}

		if ( '' === $user_login || strlen( $user_login ) < 3 ) {
			$errors['user_login'] = __( 'Username must be at least 3 characters.', 'buddynext' );
		} elseif ( ! validate_username( $user_login ) ) {
			$errors['user_login'] = __( 'Username contains invalid characters.', 'buddynext' );
		} elseif ( username_exists( $user_login ) ) {
			$errors['user_login'] = __( 'This username is already taken.', 'buddynext' );
		}

		if ( strlen( $password ) < 8 ) {
			$errors['password'] = __( 'Password must be at least 8 characters.', 'buddynext' );
		}

		// Custom profile fields the owner opted into the registration form. Each
		// is sanitised through the field-type engine and required ones are
		// enforced here, so their errors join the same inline envelope as the
		// core fields (and the account is never created on a bad submission).
		$reg_fields  = array();
		$reg_values  = array();
		$profile_svc = null;
		try {
			$profile_svc = buddynext_service( 'profiles' );
		} catch ( \Throwable $e ) {
			$profile_svc = null;
		}
		if ( is_object( $profile_svc ) && method_exists( $profile_svc, 'get_registration_fields' ) ) {
			$reg_fields = $profile_svc->get_registration_fields();
			foreach ( $reg_fields as $reg_field ) {
				$field_key = (string) $reg_field['field_key'];
				$raw       = $request->get_param( 'bn_field_' . $field_key );
				$value     = \BuddyNext\Profile\FieldType::sanitize( $reg_field, null === $raw ? '' : $raw );

				if ( is_wp_error( $value ) ) {
					$errors[ 'bn_field_' . $field_key ] = $value->get_error_message();
					continue;
				}

				$is_empty = ( '' === $value || null === $value || array() === $value );
				if ( ! empty( $reg_field['is_required'] ) && $is_empty ) {
					/* translators: %s: profile field label. */
					$errors[ 'bn_field_' . $field_key ] = sprintf( __( '%s is required.', 'buddynext' ), $reg_field['label'] );
					continue;
				}

				$reg_values[ $field_key ] = $value;
			}
		}

		if ( ! empty( $errors ) ) {
			$err = new WP_Error(
				'rest_registration_failed',
				__( 'Please correct the errors below.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
			return $err;
		}

		// Spam / abuse gate (rate limit, honeypot, time-trap, human check).
		// Runs on well-formed input so humans see field validation first, then
		// the guard only gates real attempts before we create the account.
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$gate = ( new RegistrationGuard() )->check(
			array(
				'email'            => $email,
				'user_login'       => $user_login,
				'ip'               => $ip,
				'honeypot'         => (string) $request->get_param( RegistrationGuard::honeypot_field() ),
				'token'            => (string) $request->get_param( 'reg_token' ),
				'challenge_token'  => (string) $request->get_param( 'challenge_token' ),
				'challenge_answer' => (string) $request->get_param( 'challenge_answer' ),
			)
		);
		if ( is_wp_error( $gate ) ) {
			$code   = $gate->get_error_code();
			$status = ( 'bn_reg_rate' === $code ) ? 429 : 422;
			// Surface the human-check failure inline on its field; other guard
			// rejections (rate limit, spam score) are deliberately top-level only.
			$fields = ( 'bn_reg_challenge' === $code )
				? array( 'challenge' => $gate->get_error_message() )
				: array();
			return new WP_Error(
				'rest_registration_failed',
				$gate->get_error_message(),
				array(
					'status' => $status,
					'fields' => $fields,
				)
			);
		}

		$user_id = wp_create_user( $user_login, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'rest_registration_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Redeem the invitation now that the account exists.
		if ( null !== $invite ) {
			( new \BuddyNext\Onboarding\InviteService() )->mark_registered( (int) $invite['id'] );

			// Space-linked invite: the person clicked "Accept invitation" in a
			// space invite email, so drop the new account straight into that
			// space as an active member — the link lands them inside it instead
			// of on a generic feed.
			$invite_space_id = isset( $invite['space_id'] ) ? (int) $invite['space_id'] : 0;
			if ( $invite_space_id > 0 ) {
				( new \BuddyNext\Spaces\SpaceMemberService() )->join( $invite_space_id, (int) $user_id );
			}
		}

		// Seed the new member's DM-privacy preference from the site default
		// (buddynext_default_dm_access). Members can change it later in their own
		// privacy settings; we only set it here, when no explicit value exists,
		// so the site owner's default applies to fresh accounts.
		self::seed_default_dm_access( (int) $user_id );

		// Persist any registration profile-field values collected + validated
		// above. DB-backed fields go through save_profile (bn_profile_values +
		// searchable usermeta); programmatic (virtual) fields with no row are
		// stored to usermeta so their value is not lost.
		self::save_registration_fields( (int) $user_id, $reg_fields, $reg_values, $profile_svc );

		// Email verification is handled by VerificationListener::on_user_register,
		// which wp_create_user() above already triggered via the user_register
		// hook. That listener is gated on the buddynext_email_verify setting, so
		// it sends the verification email only when verification is required.
		// Do NOT create a token here as well: when the setting is OFF it would
		// send an unwanted email, and when ON it would send a duplicate.

		// Approval mode: hold the account for an administrator. The login gate
		// (wp_authenticate_user) blocks sign-in until the flag is cleared, so we
		// do NOT auto-authenticate here.
		if ( 'approval' === $reg_mode ) {
			update_user_meta( (int) $user_id, 'bn_pending_approval', '1' );

			/**
			 * Fires when a new registration is created but needs admin approval.
			 *
			 * @param int    $user_id New (pending) user ID.
			 * @param string $email   Registered email.
			 */
			do_action( 'buddynext_registration_pending', (int) $user_id, $email );

			return new WP_REST_Response(
				array(
					'success' => true,
					'pending' => true,
					'user_id' => (int) $user_id,
					'message' => __( 'Your account was created and is awaiting administrator approval.', 'buddynext' ),
				),
				200
			);
		}

		// Sign the user in immediately.
		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, false, is_ssl() );

		// Send the new member to the welcome wizard when onboarding is enabled
		// (FeatureRegistry — the canonical toggle); otherwise land them on the
		// activity feed. Email verification, when enabled, takes precedence so
		// the account is confirmed before anything else. Members who arrive by
		// other paths (admin-created, social login) are caught by the
		// OnboardingListener template_redirect gate.
		$onboarding_on = function_exists( 'buddynext_service' )
			&& buddynext_service( 'features' )->is_enabled( 'onboarding' );

		$redirect_to = $onboarding_on
			? \BuddyNext\Core\PageRouter::onboarding_url()
			: \BuddyNext\Core\PageRouter::activity_url();

		if ( get_option( 'buddynext_email_verify', false ) ) {
			$redirect_to = \BuddyNext\Core\PageRouter::hub_url(
				'buddynext_slug_auth',
				'buddynext_page_auth'
			) . 'verify/';
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'user_id'     => (int) $user_id,
				'redirect_to' => esc_url_raw( $redirect_to ),
			),
			200
		);
	}

	/**
	 * Resolve a username for a given email address.
	 *
	 * @param string $email Email.
	 * @return string Username or original input if no match.
	 */
	private function resolve_login_for_email( string $email ): string {
		$user = get_user_by( 'email', $email );
		return $user ? $user->user_login : $email;
	}

	/**
	 * POST /auth/lost-password — request a password-reset link.
	 *
	 * Drives WordPress core retrieve_password() so the secure reset key and
	 * delivery are owned by core; BuddyNext only provides the branded screen.
	 * The reset email's link is rewritten to the branded /{auth}/reset/ screen
	 * via the retrieve_password_message filter (registered in register()).
	 *
	 * Always returns the same generic success message whether or not the
	 * account exists — no account enumeration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function lost_password( WP_REST_Request $request ): WP_REST_Response {
		$login = sanitize_text_field( (string) $request->get_param( 'user_login' ) );

		$generic = new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If an account matches that email or username, we have sent a link to reset its password.', 'buddynext' ),
			),
			200
		);

		if ( '' === $login ) {
			return $generic;
		}

		// retrieve_password() accepts a login or email; it sends the reset email
		// when the account exists and returns true, or a WP_Error otherwise. We
		// swallow the result so the response never reveals which.
		//
		// The email itself is branded globally by brand_reset_notification_email()
		// (hooked on retrieve_password_notification_email in
		// RegistrationEmailListener), so resets started here, from wp-login.php, or
		// programmatically all share the same branded shell, subject, From, and
		// /{auth}/reset/ link.
		retrieve_password( $login );

		return $generic;
	}

	/**
	 * Brand WordPress core's password-reset email for EVERY reset path.
	 *
	 * Hooked globally on retrieve_password_notification_email (registered in
	 * RegistrationEmailListener) so resets initiated from wp-login.php, the BN
	 * REST endpoint, or programmatically all receive the same branded HTML shell,
	 * subject, From identity, and /{auth}/reset/ deep link. Setting the HTML
	 * Content-Type in this email's own headers keeps the change scoped — it never
	 * leaks into unrelated wp_mail() calls.
	 *
	 * @param array<string, mixed> $defaults   Email parts: to, subject, message, headers.
	 * @param string               $key        Password-reset key.
	 * @param string               $user_login Username of the account being reset.
	 * @param \WP_User|mixed       $user_data  User object for the account (unused).
	 * @return array<string, mixed>
	 */
	public static function brand_reset_notification_email( array $defaults, string $key, string $user_login, $user_data = null ): array {
		unset( $user_data );

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		$url = add_query_arg(
			array(
				'key'   => rawurlencode( $key ),
				'login' => rawurlencode( $user_login ),
			),
			\BuddyNext\Core\PageRouter::reset_url()
		);

		$body = sprintf(
			/* translators: 1: site name, 2: username, 3: reset URL. */
			__( '<p>Someone requested a password reset for your %1$s account (<strong>%2$s</strong>).</p><p>If this was you, set a new password using the button below. If it wasn\'t, you can ignore this email and your password will stay the same.</p><p><a href="%3$s" style="display:inline-block;background:#0073aa;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600;">Reset my password</a></p><p style="font-size:13px;color:#6b7280;">Or paste this link into your browser:<br>%3$s</p>', 'buddynext' ),
			esc_html( $site_name ),
			esc_html( $user_login ),
			esc_url( $url )
		);

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Reset your %s password', 'buddynext' ),
			$site_name
		);

		$headers      = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name    = \BuddyNext\Notifications\EmailSender::from_name();
		$from_address = \BuddyNext\Notifications\EmailSender::from_address();
		if ( '' !== $from_address && is_email( $from_address ) ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %s <%s>', $from_name, $from_address )
				: 'From: ' . $from_address;
		}
		$reply_to = sanitize_email( (string) get_option( 'buddynext_email_reply_to', '' ) );
		if ( '' !== $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$defaults['subject'] = $subject;
		$defaults['message'] = \BuddyNext\Notifications\EmailSender::brand_wrap( $body, $subject );
		$defaults['headers'] = $headers;

		return $defaults;
	}

	/**
	 * POST /auth/reset-password — set a new password from a reset key.
	 *
	 * Validates the key with WordPress core check_password_reset_key() and
	 * commits via reset_password(), so the security model is core's. The branded
	 * screen at /{auth}/reset/?key=...&login=... posts here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key      = sanitize_text_field( (string) $request->get_param( 'key' ) );
		$login    = sanitize_text_field( (string) $request->get_param( 'login' ) );
		$password = (string) $request->get_param( 'password' );

		if ( strlen( $password ) < 8 ) {
			return new WP_Error(
				'rest_reset_failed',
				__( 'Please correct the errors below.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => array( 'password' => __( 'Password must be at least 8 characters.', 'buddynext' ) ),
				)
			);
		}

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'rest_reset_invalid',
				__( 'This password-reset link has expired or is invalid. Please request a new one.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		reset_password( $user, $password );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'message'     => __( 'Your password has been reset. You can now sign in.', 'buddynext' ),
				'redirect_to' => \BuddyNext\Core\PageRouter::auth_url(),
			),
			200
		);
	}

	/**
	 * Resend the verification email for the currently logged-in user.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_verification(): WP_REST_Response|WP_Error {
		// Email Verification feature off: the endpoint has nothing to do.
		if ( ! buddynext_feature_enabled( 'verification' ) ) {
			return new WP_Error(
				'buddynext_verification_disabled',
				__( 'Email verification is not enabled on this community.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();
		$svc     = new VerificationService();
		$result  = $svc->resend( $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array( 'message' => __( 'Verification email sent.', 'buddynext' ) ),
			200
		);
	}

	/**
	 * Return the email verification status for the currently logged-in user.
	 *
	 * @return WP_REST_Response
	 */
	public function verification_status(): WP_REST_Response {
		// Email Verification feature off: there is no "unverified" state, so the
		// caller should treat every account as cleared rather than gating on a
		// subsystem the owner disabled.
		if ( ! buddynext_feature_enabled( 'verification' ) ) {
			return new WP_REST_Response(
				array(
					'verified' => true,
					'enabled'  => false,
				),
				200
			);
		}

		$user_id  = get_current_user_id();
		$svc      = new VerificationService();
		$verified = $svc->is_verified( $user_id );

		return new WP_REST_Response(
			array(
				'verified' => $verified,
				'enabled'  => true,
			),
			200
		);
	}

	/**
	 * Apply the site-default DM-privacy preference to a new account.
	 *
	 * Reads buddynext_default_dm_access (Settings → General → Direct Messaging)
	 * and writes it to the member's bn_privacy_dm meta — the same key the
	 * privacy settings screen and the messaging layer read. Only sets the value
	 * when the member has no explicit preference yet, so a member who later
	 * changes their privacy is never overwritten, and re-running registration
	 * flows stays idempotent.
	 *
	 * The site default is validated against the canonical audience vocabulary
	 * (everyone / members / connections / nobody) so a stale or filtered option
	 * value can never seed an invalid preference.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public static function seed_default_dm_access( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		// Don't clobber an explicit preference (e.g. set during onboarding).
		if ( '' !== (string) get_user_meta( $user_id, 'bn_privacy_dm', true ) ) {
			return;
		}

		$default   = (string) get_option( 'buddynext_default_dm_access', 'everyone' );
		$audiences = array( 'everyone', 'members', 'connections', 'nobody' );
		if ( ! in_array( $default, $audiences, true ) ) {
			$default = 'everyone';
		}

		update_user_meta( $user_id, 'bn_privacy_dm', $default );
	}

	/**
	 * Persist registration profile-field values onto a freshly created account.
	 *
	 * DB-backed fields (those with a bn_profile_fields row) are written via
	 * ProfileService::save_profile so they land in bn_profile_values and the
	 * searchable usermeta mirror. Programmatic/virtual fields (id 0, registered via
	 * buddynext_register_member_field() / buddynext_register_profile_field()) have no
	 * row, so their value is stored to usermeta as bn_field_{key} — the same key
	 * get_profile()'s virtual merge and save_profile()'s virtual branch use. Addons can
	 * take over storage entirely on the buddynext_registration_fields_saved action.
	 *
	 * @param int                             $user_id     New user id.
	 * @param array<int, array<string,mixed>> $reg_fields The registration field defs.
	 * @param array<string, mixed>            $reg_values  field_key => sanitised value.
	 * @param object|null                     $profile_svc Resolved ProfileService (or null).
	 * @return void
	 */
	private static function save_registration_fields( int $user_id, array $reg_fields, array $reg_values, $profile_svc ): void {
		if ( $user_id <= 0 || empty( $reg_fields ) ) {
			return;
		}

		$db_values = array();
		foreach ( $reg_fields as $reg_field ) {
			$field_key = (string) $reg_field['field_key'];
			if ( ! array_key_exists( $field_key, $reg_values ) ) {
				continue;
			}

			if ( (int) ( $reg_field['id'] ?? 0 ) > 0 ) {
				$db_values[ $field_key ] = $reg_values[ $field_key ];
			} else {
				// Virtual (programmatic) field: no table row to write to.
				update_user_meta( $user_id, 'bn_field_' . $field_key, $reg_values[ $field_key ] );
			}
		}

		if ( ! empty( $db_values ) && is_object( $profile_svc ) && method_exists( $profile_svc, 'save_profile' ) ) {
			$profile_svc->save_profile( $user_id, $db_values );
		}

		/**
		 * Fires after registration profile-field values are saved to a new account.
		 *
		 * @param int                  $user_id    New user id.
		 * @param array<string, mixed> $reg_values field_key => sanitised value.
		 * @param array                $reg_fields The registration field definitions.
		 */
		do_action( 'buddynext_registration_fields_saved', $user_id, $reg_values, $reg_fields );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback: require a site administrator.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do this.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /auth/approve/{id} — approve a pending (approval-mode) registration.
	 *
	 * Clears the bn_pending_approval flag so the account can sign in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_not_found', __( 'User not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( ! get_user_meta( $user_id, 'bn_pending_approval', true ) ) {
			return new WP_REST_Response(
				array(
					'approved' => true,
					'already'  => true,
				),
				200
			);
		}

		delete_user_meta( $user_id, 'bn_pending_approval' );

		/**
		 * Fires when an administrator approves a pending registration.
		 *
		 * @param int $user_id Approved user ID.
		 */
		do_action( 'buddynext_member_approved', $user_id );

		return new WP_REST_Response( array( 'approved' => true ), 200 );
	}
}
