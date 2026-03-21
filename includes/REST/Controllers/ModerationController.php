<?php
/**
 * Moderation REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST /reports                     — submit a report (auth required)
 *   GET  /reports                     — list reports for an object (admin only)
 *   POST /reports/{id}/dismiss        — dismiss a report (admin only)
 *   POST /reports/{id}/escalate       — escalate a report (admin only)
 *   POST /reports/{id}/resolve        — resolve a report (admin only)
 *   POST /users/{id}/strikes          — issue a strike (admin only)
 *   POST /users/{id}/strikes/{sid}/reverse — reverse a strike (admin only)
 *
 * @package BuddyNext\REST\Controllers
 */

declare( strict_types=1 );

namespace BuddyNext\REST\Controllers;

use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Moderation\ModerationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles report submission, queue actions, and strike management.
 */
class ModerationController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		// Report submission and listing.
		register_rest_route(
			'buddynext/v1',
			'/reports',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_report' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'object_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'reason'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'notes'       => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_reports' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'object_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
					),
				),
			)
		);

		// Report actions.
		register_rest_route(
			'buddynext/v1',
			'/reports/(?P<id>[\d]+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_report' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reports/(?P<id>[\d]+)/escalate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'escalate_report' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reports/(?P<id>[\d]+)/resolve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_report' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Strike management.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/strikes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue_strike' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'     => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'reason' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/strikes/(?P<sid>[\d]+)/reverse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reverse_strike' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);
	}

	/**
	 * Submit a content report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = ( new ModerationService() )->report(
			get_current_user_id(),
			(string) ( $request->get_param( 'object_type' ) ?? '' ),
			(int) $request->get_param( 'object_id' ),
			(string) ( $request->get_param( 'reason' ) ?? 'other' ),
			(string) ( $request->get_param( 'notes' ) ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'id' => $result ), 201 );
	}

	/**
	 * List reports for an object (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_reports( WP_REST_Request $request ): WP_REST_Response {
		$reports = ( new ModerationService() )->get_reports_for_object(
			(string) ( $request->get_param( 'object_type' ) ?? '' ),
			(int) $request->get_param( 'object_id' )
		);

		return new WP_REST_Response(
			array(
				'items' => $reports,
				'total' => count( $reports ),
			),
			200
		);
	}

	/**
	 * Dismiss a report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service   = new ModerationService();
		$report_id = (int) $request->get_param( 'id' );
		$actor_id  = get_current_user_id();

		$result = $service->dismiss( $report_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'dismiss_report',
			array(
				'object_id'   => $report_id,
				'object_type' => 'report',
			)
		);

		return new WP_REST_Response( array( 'dismissed' => true ), 200 );
	}

	/**
	 * Escalate a report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function escalate_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service   = new ModerationService();
		$report_id = (int) $request->get_param( 'id' );
		$actor_id  = get_current_user_id();

		$result = $service->escalate( $report_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'escalate_report',
			array(
				'object_id'   => $report_id,
				'object_type' => 'report',
			)
		);

		return new WP_REST_Response( array( 'escalated' => true ), 200 );
	}

	/**
	 * Resolve a report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service   = new ModerationService();
		$report_id = (int) $request->get_param( 'id' );
		$actor_id  = get_current_user_id();

		$result = $service->resolve( $report_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'resolve_report',
			array(
				'object_id'   => $report_id,
				'object_type' => 'report',
			)
		);

		return new WP_REST_Response( array( 'resolved' => true ), 200 );
	}

	/**
	 * Issue a strike against a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_strike( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service  = new ModerationService();
		$user_id  = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$reason   = (string) ( $request->get_param( 'reason' ) ?? '' );

		$result = $service->issue_strike( $user_id, $actor_id, $reason );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'issue_strike',
			array(
				'target_user_id' => $user_id,
				'note'           => $reason,
			)
		);

		return new WP_REST_Response( array( 'strike_id' => $result ), 201 );
	}

	/**
	 * Reverse a strike.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reverse_strike( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service   = new ModerationService();
		$strike_id = (int) $request->get_param( 'sid' );
		$user_id   = (int) $request->get_param( 'id' );
		$actor_id  = get_current_user_id();

		$result = $service->reverse_strike( $strike_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log( $actor_id, 'reverse_strike', array( 'target_user_id' => $user_id ) );

		return new WP_REST_Response( array( 'reversed' => true ), 200 );
	}

	/**
	 * Require the user to be logged in.
	 *
	 * @return bool|WP_Error
	 */
	public function require_auth(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Require manage_options capability.
	 *
	 * @return bool|WP_Error
	 */
	public function require_admin(): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Admins only.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
