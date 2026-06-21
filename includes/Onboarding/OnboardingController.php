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
				'args'                => array(
					'display_name' => array(
						'required' => false,
						'type'     => 'string',
					),
					'bio'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'slug'         => array(
						'required' => false,
						'type'     => 'string',
					),
					'channels'     => array(
						'required' => false,
						'type'     => 'object',
					),
					'spaces'       => array(
						'required' => false,
						'type'     => 'array',
					),
					'user_ids'     => array(
						'required' => false,
						'type'     => 'array',
					),
					'interests'    => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/interests',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_interests' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'interests' => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);
	}

	/**
	 * POST /me/interests — persist the member's chosen interest labels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_interests( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$interests = (array) $request->get_param( 'interests' );
		$stored    = $this->service->save_interests( $user_id, $interests );

		return new WP_REST_Response(
			array(
				'saved'     => true,
				'interests' => $stored,
			),
			200
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
	 * This is the authoritative completion transaction: it persists every
	 * piece of wizard state server-side (so the journey is durable even if
	 * the client's optimistic per-step REST calls failed, were aborted by
	 * the redirect, or never fired on a slow connection), then marks the
	 * wizard complete and returns the redirect target.
	 *
	 * Body params (all optional):
	 *   display_name — string profile display name.
	 *   bio          — string profile bio.
	 *   slug         — string desired profile slug / handle.
	 *   channels     — object { email, in_app, push } notification toggles.
	 *   spaces       — int[] of space IDs to join.
	 *   user_ids     — int[] of users to follow.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function complete( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		// Already complete — idempotent: just return the redirect target so a
		// double-submit or stale tab cannot re-fire downstream hooks.
		if ( $this->service->is_complete( $user_id ) ) {
			return new WP_REST_Response(
				array(
					'completed'   => true,
					'redirect_to' => \BuddyNext\Core\PageRouter::profile_url( $user_id ),
				),
				200
			);
		}

		// Step 1 — profile (display name + bio).
		$profile = array();
		if ( null !== $request->get_param( 'display_name' ) ) {
			$profile['display_name'] = (string) $request->get_param( 'display_name' );
		}
		if ( null !== $request->get_param( 'bio' ) ) {
			$profile['bio'] = (string) $request->get_param( 'bio' );
		}
		if ( ! empty( $profile ) ) {
			$this->service->save_profile( $user_id, $profile );
		}

		// Step 1 — chosen handle / slug (non-fatal on collision).
		$slug = $request->get_param( 'slug' );
		if ( is_string( $slug ) && '' !== trim( $slug ) ) {
			$this->service->save_slug( $user_id, $slug );
		}

		// Step 4 — notification channel preferences.
		$channels = $request->get_param( 'channels' );
		if ( is_array( $channels ) ) {
			$this->service->save_channels( $user_id, $channels );
		}

		// Step 2 — join selected spaces.
		$spaces = (array) $request->get_param( 'spaces' );
		if ( ! empty( $spaces ) && function_exists( 'buddynext_service' ) ) {
			$space_members = buddynext_service( 'space_members' );
			if ( $space_members && method_exists( $space_members, 'join' ) ) {
				foreach ( array_map( 'absint', $spaces ) as $space_id ) {
					if ( $space_id > 0 ) {
						$space_members->join( $space_id, $user_id );
					}
				}
			}
		}

		// Step 3 — follow selected members.
		$user_ids = (array) $request->get_param( 'user_ids' );
		if ( ! empty( $user_ids ) && function_exists( 'buddynext_service' ) ) {
			$follows = buddynext_service( 'follows' );
			if ( $follows && method_exists( $follows, 'follow' ) ) {
				foreach ( array_map( 'absint', $user_ids ) as $follow_id ) {
					if ( $follow_id > 0 && $follow_id !== $user_id ) {
						$follows->follow( $user_id, $follow_id );
					}
				}
			}
		}

		// Interests — persist the chosen labels (idempotent) when supplied.
		$interests = $request->get_param( 'interests' );
		if ( is_array( $interests ) && ! empty( $interests ) ) {
			$this->service->save_interests( $user_id, $interests );
		}

		// Mark complete + fire buddynext_onboarding_completed (first call only).
		$this->service->finish( $user_id );

		return new WP_REST_Response(
			array(
				'completed'   => true,
				// Land the new member on their own profile — the thing they just
				// built in the wizard — rather than the activity feed.
				'redirect_to' => \BuddyNext\Core\PageRouter::profile_url( $user_id ),
			),
			200
		);
	}

	/**
	 * Permission callback — require an authenticated user.
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
}
