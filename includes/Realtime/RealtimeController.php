<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for real-time presence.
 *
 * Route (under buddynext/v1):
 *   POST /me/presence/heartbeat - refresh the caller's bn_last_active stamp.
 *
 * This is the JS-driven counterpart to PresenceService's template_redirect
 * heartbeat. The client's existing 2-minute presence poll (per the Free
 * realtime contract) can POST here to keep `bn_last_active` fresh while a tab
 * stays open without navigating, so the "Online now" filter, online sort,
 * member-card dots, and OnlineMembersWidget stay accurate during a long
 * single-page session. The write itself is throttled inside PresenceService.
 *
 * Cookie-authenticated REST requires the standard `wp_rest` nonce sent as the
 * X-WP-Nonce header, per docs/specs/REST-FRONTEND-CONTRACT.md.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

use WP_Error;
use WP_REST_Response;

/**
 * Handles the presence heartbeat over REST.
 *
 * @since 1.0.0
 */
class RealtimeController {

	/**
	 * Presence writer this controller delegates to.
	 *
	 * @since 1.0.0
	 * @var PresenceService
	 */
	private PresenceService $presence;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PresenceService|null $presence Optional writer; a default is created when omitted.
	 */
	public function __construct( ?PresenceService $presence = null ) {
		$this->presence = $presence ?? new PresenceService();
	}

	/**
	 * Register the controller's routes.
	 *
	 * Called from the REST router on rest_api_init.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/presence/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'heartbeat' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Heartbeat handler — refresh the caller's presence timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response Always 200 with the resolved presence window.
	 */
	public function heartbeat(): WP_REST_Response {
		$user_id = get_current_user_id();
		$written = $this->presence->stamp( $user_id );

		return new WP_REST_Response(
			array(
				'online'  => true,
				'written' => $written,
				'window'  => 300,
			),
			200
		);
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error True when logged in, WP_Error 401 otherwise.
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
