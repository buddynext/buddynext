<?php
/**
 * Moderation REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST /reports                          — submit a report (auth required)
 *   GET  /reports                          — list reports for an object (admin only)
 *   GET  /reports/queue                    — paginated pending+escalated queue (admin only)
 *   POST /reports/{id}/dismiss             — dismiss a report (admin only)
 *   POST /reports/{id}/escalate            — escalate a report (admin only)
 *   POST /reports/{id}/resolve             — resolve a report (admin only)
 *   GET  /users/{id}/strikes               — list active strikes for a user (admin only)
 *   POST /users/{id}/strikes               — issue a strike (admin only)
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

		// Moderation queue — pending + escalated, paginated.
		register_rest_route(
			'buddynext/v1',
			'/reports/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'per_page'    => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'        => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'object_type' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'reason'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
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
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_user_strikes' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
					),
				),
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

		// Suspend / unsuspend.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/suspend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suspend_user' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'            => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'reason'        => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'duration_days' => array(
						'required' => false,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'hide_posts'    => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/unsuspend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unsuspend_user' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
				),
			)
		);

		// Appeals.
		register_rest_route(
			'buddynext/v1',
			'/appeals',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_appeal' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'suspension_id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'message'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/appeals/(?P<id>[\d]+)/resolve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_appeal' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'            => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'decision'      => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approved', 'denied' ),
					),
					'reviewer_note' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
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
			0,
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
	 * Return the paginated moderation queue (pending + escalated reports).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'per_page' => absint( $request->get_param( 'per_page' ) ),
			'page'     => absint( $request->get_param( 'page' ) ),
		);

		$object_type_param = $request->get_param( 'object_type' );
		if ( null !== $object_type_param && '' !== $object_type_param ) {
			$args['object_type'] = sanitize_key( (string) $object_type_param );
		}

		$reason_param = $request->get_param( 'reason' );
		if ( null !== $reason_param && '' !== $reason_param ) {
			$args['reason'] = sanitize_key( (string) $reason_param );
		}

		$result = ( new ModerationService() )->get_queue( $args );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return active strikes for a user with the total count.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_user_strikes( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'id' );
		$service = new ModerationService();

		$strikes = $service->get_strikes( $user_id );
		$count   = $service->get_active_strike_count( $user_id );

		return new WP_REST_Response(
			array(
				'user_id' => $user_id,
				'count'   => $count,
				'strikes' => $strikes,
			),
			200
		);
	}

	/**
	 * Suspend a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suspend_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$reason   = (string) ( $request->get_param( 'reason' ) ?? '' );
		$opts     = array();

		$duration = $request->get_param( 'duration_days' );
		if ( null !== $duration ) {
			$opts['duration_days'] = absint( $duration );
		}

		if ( $request->get_param( 'hide_posts' ) ) {
			$opts['hide_posts'] = true;
		}

		$result = ( new ModerationService() )->suspend_user( $user_id, $actor_id, $reason, $opts );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'suspend_user',
			array(
				'target_user_id' => $user_id,
				'note'           => $reason,
			)
		);

		return new WP_REST_Response( array( 'suspension_id' => $result ), 201 );
	}

	/**
	 * Lift an active user suspension.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unsuspend_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();

		$result = ( new ModerationService() )->unsuspend_user( $user_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		( new ModerationLogService() )->log( $actor_id, 'unsuspend_user', array( 'target_user_id' => $user_id ) );

		return new WP_REST_Response( array( 'unsuspended' => true ), 200 );
	}

	/**
	 * Submit a suspension appeal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_appeal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id       = get_current_user_id();
		$suspension_id = (int) $request->get_param( 'suspension_id' );
		$message       = (string) ( $request->get_param( 'message' ) ?? '' );

		$result = ( new ModerationService() )->submit_appeal( $user_id, $suspension_id, $message );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'appeal_id' => $result ), 201 );
	}

	/**
	 * Resolve a suspension appeal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resolve_appeal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$appeal_id     = (int) $request->get_param( 'id' );
		$actor_id      = get_current_user_id();
		$decision      = (string) ( $request->get_param( 'decision' ) ?? '' );
		$reviewer_note = (string) ( $request->get_param( 'reviewer_note' ) ?? '' );

		$result = ( new ModerationService() )->resolve_appeal( $appeal_id, $actor_id, $decision, $reviewer_note );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = 'forbidden' === $code ? 403 : 400;
			$result->add_data( array( 'status' => $status ) );
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'resolve_appeal',
			array(
				'object_id'   => $appeal_id,
				'object_type' => 'appeal',
				'note'        => $decision,
			)
		);

		return new WP_REST_Response( array( 'resolved' => true ), 200 );
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
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Admins only.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
