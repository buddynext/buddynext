<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for authentication-related endpoints.
 *
 * Routes (all under buddynext/v1):
 *   POST /auth/verify/resend  — resend email verification for current user
 *   GET  /auth/verify/status  — check email verification status for current user
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

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
