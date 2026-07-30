<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST endpoints for a member to manage their own two-factor authentication.
 *
 * Routes (all under buddynext/v1, auth required — a user only ever manages
 * their own 2FA):
 *   GET  /account/2fa            — current status (enabled, backup codes left).
 *   POST /account/2fa/setup      — begin enrolment: returns secret + otpauth URI.
 *   POST /account/2fa/confirm    — verify the first code, turn 2FA on, return
 *                                  one-time backup codes.
 *   POST /account/2fa/disable    — turn 2FA off (re-checks the account password).
 *   POST /account/2fa/backup     — regenerate backup codes (re-checks password).
 *
 * Sensitive transitions (disable, regenerate) re-verify the password so a
 * hijacked-but-still-logged-in session cannot quietly weaken the account.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Member-facing two-factor management controller.
 */
class TwoFactorController {

	/**
	 * Register the controller's routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$auth = array( $this, 'require_auth' );

		register_rest_route(
			'buddynext/v1',
			'/account/2fa',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/account/2fa/setup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setup' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/account/2fa/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => $auth,
				'args'                => array(
					'code' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/account/2fa/disable',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disable' ),
				'permission_callback' => $auth,
				'args'                => array(
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/account/2fa/backup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_backup' ),
				'permission_callback' => $auth,
				'args'                => array(
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: the request must be from a logged-in user.
	 *
	 * @return bool
	 */
	public function require_auth(): bool {
		return is_user_logged_in();
	}

	/**
	 * GET /account/2fa — report this user's 2FA status.
	 *
	 * @return WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		return new WP_REST_Response(
			array(
				'enabled'          => TwoFactorService::is_enabled( $user_id ),
				'required'         => TwoFactorService::is_required_for( $user ),
				'backup_remaining' => TwoFactorService::backup_codes_remaining( $user_id ),

				// Which second factors this member can actually complete a challenge
				// with, and where to do it. Without these the email fallback was
				// undiscoverable: it has been implemented and working since the login
				// guard shipped, but nothing in any response mentioned it, so a client
				// could only find it by reading PHP.
				//
				// That is not a hypothetical. QA reported email 2FA as "still 404, all
				// three routes missing" after probing /account/2fa/request-code,
				// /account/2fa/verify and /account/2fa/send-code - none of which have
				// ever existed. The real routes live under /auth/ because requesting and
				// submitting a code happen DURING a login challenge, when there is no
				// authenticated session yet and /account/* is unreachable by definition.
				// Naming them here is what stops that conclusion being drawn again.
				'methods'          => TwoFactorService::available_methods( $user_id ),
				'email_fallback'   => TwoFactorService::email_fallback_available( $user_id ),
				'challenge'        => array(
					'request_email_code' => rest_url( 'buddynext/v1/auth/2fa/email-code' ),
					'submit_code'        => rest_url( 'buddynext/v1/auth/2fa' ),
				),
			),
			200
		);
	}

	/**
	 * POST /account/2fa/setup — begin enrolment.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function setup(): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		if ( TwoFactorService::is_enabled( $user_id ) ) {
			return new WP_Error(
				'rest_2fa_already_on',
				__( 'Two-factor authentication is already on. Turn it off first to re-enrol.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		$enrol = TwoFactorService::begin_enrollment( $user_id );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'secret'      => $enrol['secret'],
				'otpauth_uri' => $enrol['uri'],
			),
			200
		);
	}

	/**
	 * POST /account/2fa/confirm — verify the first code and switch 2FA on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$result  = TwoFactorService::confirm_enrollment( $user_id, (string) $request->get_param( 'code' ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array(
					'status' => 422,
					'fields' => array( 'code' => $result->get_error_message() ),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'enabled'      => true,
				'backup_codes' => $result['backup_codes'],
			),
			200
		);
	}

	/**
	 * POST /account/2fa/disable — turn 2FA off after re-checking the password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disable( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		$gate = $this->verify_password( $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		TwoFactorService::disable( $user_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'enabled' => false,
			),
			200
		);
	}

	/**
	 * POST /account/2fa/backup — regenerate backup codes after re-checking the
	 * password. Invalidates any previous codes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate_backup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		if ( ! TwoFactorService::is_enabled( $user_id ) ) {
			return new WP_Error(
				'rest_2fa_off',
				__( 'Turn on two-factor authentication first.', 'buddynext' ),
				array( 'status' => 409 )
			);
		}

		$gate = $this->verify_password( $request );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'backup_codes' => TwoFactorService::generate_backup_codes( $user_id ),
			),
			200
		);
	}

	/**
	 * Re-verify the current user's password for a sensitive transition.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function verify_password( WP_REST_Request $request ): bool|WP_Error {
		$user     = wp_get_current_user();
		$password = (string) $request->get_param( 'password' );
		if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error(
				'rest_2fa_bad_password',
				__( 'Your password was not correct.', 'buddynext' ),
				array(
					'status' => 422,
					'fields' => array( 'password' => __( 'Incorrect password.', 'buddynext' ) ),
				)
			);
		}
		return true;
	}
}
