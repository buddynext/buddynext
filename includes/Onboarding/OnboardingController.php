<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for onboarding wizard endpoints.
 *
 * Routes (all under buddynext/v1):
 *   POST /me/onboarding/step      — save data for the current step + advance.
 *   POST /me/onboarding/skip      — skip the wizard, mark complete.
 *   POST /me/onboarding/complete  — finalize the wizard with all step payloads.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles onboarding wizard REST endpoints.
 */
class OnboardingController {

	/**
	 * Service instance.
	 *
	 * @var OnboardingService
	 */
	private OnboardingService $service;

	/**
	 * Constructor.
	 *
	 * @param OnboardingService|null $service Injected service or default.
	 */
	public function __construct( ?OnboardingService $service = null ) {
		$this->service = $service ?? new OnboardingService();
	}

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/onboarding/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_step' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'step' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/onboarding/skip',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'skip' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/onboarding/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

	}

	/**
	 * POST /me/onboarding/step — save step data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_step( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$step    = (int) $request->get_param( 'step' );
		$data    = (array) $request->get_param( 'data' );

		$this->service->save_step( $user_id, $step, $data );

		return new WP_REST_Response(
			array(
				'saved'     => true,
				'next_step' => $this->service->get_step( $user_id ),
			),
			200
		);
	}

	/**
	 * POST /me/onboarding/skip — skip the wizard.
	 *
	 * @return WP_REST_Response
	 */
	public function skip(): WP_REST_Response {
		$this->service->skip( get_current_user_id() );
		return new WP_REST_Response(
			array( 'skipped' => true ),
			200
		);
	}

	/**
	 * POST /me/onboarding/complete — finish the wizard.
	 *
	 * Body params:
	 *   spaces      — int[] of space IDs to join.
	 *   user_ids    — int[] of users to follow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		$spaces   = (array) $request->get_param( 'spaces' );
		$user_ids = (array) $request->get_param( 'user_ids' );

		if ( ! empty( $spaces ) && function_exists( 'buddynext_service' ) ) {
			$space_members = buddynext_service( 'space_members' );
			foreach ( array_map( 'absint', $spaces ) as $space_id ) {
				if ( $space_id > 0 ) {
					$space_members->join( $space_id, $user_id );
				}
			}
		}

		if ( ! empty( $user_ids ) && function_exists( 'buddynext_service' ) ) {
			$follows = buddynext_service( 'follows' );
			foreach ( array_map( 'absint', $user_ids ) as $follow_id ) {
				if ( $follow_id > 0 && $follow_id !== $user_id ) {
					$follows->follow( $user_id, $follow_id );
				}
			}
		}

		$this->service->finish( $user_id );

		return new WP_REST_Response(
			array(
				'completed'   => true,
				'redirect_to' => \BuddyNext\Core\PageRouter::activity_url(),
			),
			200
		);
	}

	/**
	 * Permission callback — require an authenticated user.
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
