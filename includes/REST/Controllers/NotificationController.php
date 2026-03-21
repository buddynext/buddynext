<?php
/**
 * REST controller for notifications.
 *
 * Routes (all under buddynext/v1):
 *   GET  /me/notifications              — list notifications (auth required)
 *   GET  /me/notifications/unread-count — unread badge count (auth required)
 *   POST /me/notifications/{id}/read    — mark one notification read (auth required)
 *   POST /me/notifications/read-all     — mark all read (auth required)
 *   GET  /me/notification-prefs         — get notification preferences (auth required)
 *   PUT  /me/notification-prefs         — update notification preferences (auth required)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles notification reads and state changes over REST.
 */
class NotificationController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_notifications' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notifications/unread-count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'unread_count' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notifications/read-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_all_read' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notifications/(?P<id>[\d]+)/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notification-prefs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_notification_prefs' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_notification_prefs' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);
	}

	/**
	 * Return paginated notifications for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_notifications( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = min( (int) ( $request->get_param( 'per_page' ) ?? 20 ), 50 );

		$result = ( new NotificationService() )->list_for_user( $user_id, $cursor, $per_page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return the unread notification count for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unread_count( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		$count   = ( new NotificationService() )->unread_count( $user_id );

		return new WP_REST_Response( array( 'count' => $count ), 200 );
	}

	/**
	 * Mark a single notification as read.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$notif_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$result   = ( new NotificationService() )->mark_read( $notif_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'read' => true ), 200 );
	}

	/**
	 * Mark all notifications as read for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function mark_all_read( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		( new NotificationService() )->mark_all_read( $user_id );

		return new WP_REST_Response( array( 'read' => true ), 200 );
	}

	/**
	 * Return notification preferences for the current user.
	 *
	 * Returns all explicitly stored type preferences. Types not present here
	 * use platform defaults: on_site=true, email_freq='immediate'.
	 *
	 * @return WP_REST_Response
	 */
	public function get_notification_prefs(): WP_REST_Response {
		$user_id = get_current_user_id();
		$prefs   = ( new NotificationPrefService() )->get_all_prefs( $user_id );

		return new WP_REST_Response( array( 'prefs' => $prefs ), 200 );
	}

	/**
	 * Update notification preferences for the current user.
	 *
	 * Accepts a JSON body where keys are notification type strings and values
	 * are objects with optional on_site (bool) and email_freq (string) fields.
	 * Example: {"bn.new_follower": {"on_site": true, "email_freq": "daily"}}
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_notification_prefs( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();

		if ( ! is_array( $body ) || empty( $body ) ) {
			return new WP_Error(
				'invalid_prefs',
				__( 'Request body must be a JSON object of notification preferences.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$service = new NotificationPrefService();
		$service->set_all_prefs( $user_id, $body );

		$updated = $service->get_all_prefs( $user_id );

		return new WP_REST_Response( array( 'prefs' => $updated ), 200 );
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
