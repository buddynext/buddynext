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
