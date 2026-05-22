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
				),
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
				'saved'    => true,
				'pending'  => $candidate,
				'message'  => __( 'Check your inbox to confirm.', 'buddynext' ),
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

		$creds = array(
			'user_login'    => is_email( $user_input ) ? $this->resolve_login_for_email( $user_input ) : $user_input,
			'user_password' => $password,
			'remember'      => $remember,
		);

		$user = wp_signon( $creds, is_ssl() );

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

		$user_id = wp_create_user( $user_login, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'rest_registration_failed',
				$user_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Sign the user in immediately.
		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, false, is_ssl() );

		$redirect_to = \BuddyNext\Core\PageRouter::onboarding_url();
		if ( get_option( 'buddynext_email_verify' ) ) {
			$redirect_to = add_query_arg( 'email', rawurlencode( $email ), home_url( '/verify-email/' ) );
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
	 * Resend the verification email for the currently logged-in user.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_verification(): WP_REST_Response|WP_Error {
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
		$user_id  = get_current_user_id();
		$svc      = new VerificationService();
		$verified = $svc->is_verified( $user_id );

		return new WP_REST_Response( array( 'verified' => $verified ), 200 );
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
