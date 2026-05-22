<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for notifications.
 *
 * Routes (all under buddynext/v1):
 *   GET  /me/notifications              - list notifications (auth required)
 *   GET  /me/notifications/unread-count - unread badge count (auth required)
 *   POST /me/notifications/{id}/read    - mark one notification read (auth required)
 *   POST /me/notifications/read-all     - mark all read (auth required)
 *   GET  /me/notification-prefs         - get notification preferences (auth required)
 *   PUT  /me/notification-prefs         - update notification preferences (auth required)
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Spaces\SpaceMemberService;
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
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'mark_all_read' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				// Keep POST for backwards compatibility.
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mark_all_read' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notifications/(?P<id>[\d]+)/read',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'mark_read' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				// Keep POST for backwards compatibility.
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mark_read' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/notifications/(?P<id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_notification' ),
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

		register_rest_route(
			'buddynext/v1',
			'/me/notification-channels',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_notification_channels' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_notification_channels' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/space-notification-prefs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_space_notification_prefs' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_space_notification_pref' ),
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

		$result   = ( new NotificationService() )->list_for_user( $user_id, $cursor, $per_page );
		$composer = new NotificationMessageService();
		$composed = $composer->compose_batch( $result['items'] ?? array() );

		// Enrich each row with the rendered message, link, icon, and label so
		// REST consumers (in-app dropdown, mobile, email tokens) share the
		// same presentation as the on-page list.
		foreach ( $result['items'] as $i => $item ) {
			$payload               = $composed[ $i ] ?? array();
			$result['items'][ $i ] = array_merge(
				$item,
				array(
					'message'    => (string) ( $payload['message'] ?? '' ),
					'url'        => (string) ( $payload['url'] ?? '' ),
					'icon'       => (string) ( $payload['icon'] ?? 'bell' ),
					'tone'       => (string) ( $payload['tone'] ?? 'info' ),
					'label'      => (string) ( $payload['label'] ?? '' ),
					'actor_name' => (string) ( $payload['actor_name'] ?? '' ),
				)
			);
		}

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
	 * Delete a single notification for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_notification( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$notif_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$result   = ( new NotificationService() )->delete( $notif_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Return notification preferences for the current user.
	 *
	 * Response merges the per-type catalogue defaults with any explicitly
	 * stored row in bn_notification_prefs so every catalogue type appears
	 * exactly once. The UI consumes the result without overlaying defaults
	 * client-side.
	 *
	 * @return WP_REST_Response
	 */
	public function get_notification_prefs(): WP_REST_Response {
		$user_id   = get_current_user_id();
		$stored    = ( new NotificationPrefService() )->get_all_prefs( $user_id );
		$catalogue = new NotificationPrefCatalogue();
		$resolved  = $catalogue->resolve_for_user( $stored );

		return new WP_REST_Response(
			array(
				'prefs'   => $resolved,
				'stored'  => $stored,
				'updated' => time(),
			),
			200
		);
	}

	/**
	 * Update notification preferences for the current user.
	 *
	 * Accepts a JSON body where keys are notification type strings and values
	 * are objects with optional on_site (bool) and email_freq (string) fields.
	 * Example: {"bn.new_follower": {"on_site": true, "email_freq": "daily"}}
	 *
	 * Returns 422 on any invalid email_freq value (must be one of immediate /
	 * daily / weekly / off).
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

		$valid_freq = array( 'immediate', 'daily', 'weekly', 'off' );
		$errors     = array();
		foreach ( $body as $type => $entry ) {
			if ( ! is_array( $entry ) ) {
				$errors[ (string) $type ] = __( 'Each preference must be an object.', 'buddynext' );
				continue;
			}
			if ( isset( $entry['email_freq'] ) && ! in_array( (string) $entry['email_freq'], $valid_freq, true ) ) {
				$errors[ (string) $type ] = __( 'email_freq must be one of: immediate, daily, weekly, off.', 'buddynext' );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'invalid_email_freq',
				__( 'One or more preferences had an invalid email_freq value.', 'buddynext' ),
				array(
					'status' => 422,
					'params' => $errors,
				)
			);
		}

		$service = new NotificationPrefService();
		$service->set_all_prefs( $user_id, $body );

		$catalogue = new NotificationPrefCatalogue();
		$resolved  = $catalogue->resolve_for_user( $service->get_all_prefs( $user_id ) );

		return new WP_REST_Response(
			array(
				'prefs'   => $resolved,
				'updated' => time(),
			),
			200
		);
	}

	/**
	 * Return master channel preferences for the current user.
	 *
	 * Stored in usermeta `bn_channel_prefs`. Push defaults off when the Pro
	 * push module is not loaded so the UI can hide the row without a separate
	 * feature-flag request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_notification_channels(): WP_REST_Response {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, 'bn_channel_prefs', true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$push_available = class_exists( '\\BuddyNextPro\\Push\\PushDispatcher' );

		$channels = array(
			'in_app' => array_key_exists( 'in_app', $stored ) ? (bool) $stored['in_app'] : true,
			'email'  => array_key_exists( 'email', $stored ) ? (bool) $stored['email'] : true,
			'push'   => array_key_exists( 'push', $stored ) ? (bool) $stored['push'] : $push_available,
		);

		return new WP_REST_Response(
			array(
				'channels'       => $channels,
				'push_available' => $push_available,
			),
			200
		);
	}

	/**
	 * Update master channel preferences for the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_notification_channels( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'invalid_channels',
				__( 'Request body must be a JSON object.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$current = get_user_meta( $user_id, 'bn_channel_prefs', true );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		foreach ( array( 'in_app', 'email', 'push' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$current[ $key ] = (bool) $body[ $key ];
			}
		}

		update_user_meta( $user_id, 'bn_channel_prefs', $current );

		return $this->get_notification_channels();
	}

	/**
	 * List the current user's per-space notification preferences.
	 *
	 * Returns one row per active space membership with the resolved preference
	 * (defaults to 'all' when no explicit row exists). The UI uses this to
	 * render the Spaces section of the prefs page.
	 *
	 * @return WP_REST_Response
	 */
	public function list_space_notification_prefs(): WP_REST_Response {
		global $wpdb;

		$user_id = get_current_user_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS space_id, s.name, s.slug, COALESCE( NULLIF( sm.notification_pref, '' ), 'all' ) AS pref
				 FROM {$wpdb->prefix}bn_spaces s
				 INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active'
				 ORDER BY s.name ASC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'space_id' => (int) $row['space_id'],
				'name'     => (string) $row['name'],
				'slug'     => (string) $row['slug'],
				'pref'     => (string) $row['pref'],
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Set the current user's notification preference for one space.
	 *
	 * Permission: logged-in user must be an active member of the space - the
	 * underlying NotificationPrefService::set_space_pref() only updates an
	 * existing membership row, so non-members get a 403 by virtue of false
	 * being returned.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_space_notification_pref( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = get_current_user_id();
		$body     = $request->get_json_params();
		$space_id = isset( $body['space_id'] ) ? (int) $body['space_id'] : 0;
		$pref     = isset( $body['pref'] ) ? (string) $body['pref'] : '';

		if ( $space_id <= 0 || '' === $pref ) {
			return new WP_Error(
				'invalid_space_pref',
				__( 'Both space_id and pref are required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $pref, array( 'all', 'mentions_only', 'none' ), true ) ) {
			return new WP_Error(
				'invalid_pref_value',
				__( 'pref must be one of: all, mentions_only, none.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$member_service = new SpaceMemberService();
		if ( ! $member_service->is_member( $space_id, $user_id ) ) {
			return new WP_Error(
				'not_a_member',
				__( 'You must be a member of the space to set its notification preference.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$saved = ( new NotificationPrefService() )->set_space_pref( $user_id, $space_id, $pref );
		if ( ! $saved ) {
			return new WP_Error(
				'space_pref_not_saved',
				__( 'Could not save space notification preference.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'space_id' => $space_id,
				'pref'     => $pref,
			),
			200
		);
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
