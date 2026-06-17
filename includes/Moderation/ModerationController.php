<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Moderation REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST   /reports                               — submit a report (auth required)
 *   GET    /reports                               — list reports for an object (admin only)
 *   GET    /reports/queue                         — paginated pending+escalated queue (admin/mod)
 *   POST   /reports/{id}/dismiss                  — dismiss a report (admin only)
 *   POST   /reports/{id}/escalate                 — escalate a report (admin only)
 *   POST   /reports/{id}/resolve                  — resolve a report (admin only)
 *   GET    /users/{id}/strikes                    — list active strikes for a user (admin only)
 *   POST   /users/{id}/strikes                    — issue a strike (admin only)
 *   POST   /users/{id}/strikes/{sid}/reverse      — reverse a strike (admin only)
 *   GET    /posts/{id}/content-warning            — get content warning state (public)
 *   PUT    /posts/{id}/content-warning            — set/clear content warning (admin only)
 *   POST   /users/{id}/warn                       — issue a warning to a user (admin only)
 *   POST   /users/{id}/shadow-ban                 — shadow-ban a user (admin only)
 *   DELETE /users/{id}/shadow-ban                 — remove shadow ban (admin only)
 *   DELETE /users/{id}/suspend                    — unsuspend a user (admin only)
 *   GET    /users/{id}/suspension                 — get active suspension record (admin only)
 *   POST   /me/appeals                            — submit an appeal (authenticated)
 *   GET    /appeals                               — list pending appeals (admin only)
 *   PUT    /appeals/{id}/approve                  — approve an appeal (admin only)
 *   PUT    /appeals/{id}/deny                     — deny an appeal (admin only)
 *   GET    /spaces/{id}/bans                      — list space bans (owner/admin)
 *   POST   /spaces/{id}/bans                      — ban a user from a space (owner/admin)
 *   DELETE /spaces/{id}/bans/{user_id}            — unban a user from a space (owner/admin)
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\REST\BaseRestController;
use BuddyNext\Spaces\SpaceMemberService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles report submission, queue actions, and strike management.
 */
class ModerationController extends BaseRestController {

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
						'space_id'    => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
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
		// Site admins see the full queue. Space moderators see only their spaces' reports.
		register_rest_route(
			'buddynext/v1',
			'/reports/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'require_queue_access' ),
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

		// Pre-moderation (approval) queue: list held posts + approve/reject. Same
		// queue-access gate as reports (site admins see all; space moderators see
		// their spaces). This is the REST face of the admin Pending tab.
		register_rest_route(
			'buddynext/v1',
			'/moderation/pending',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pending' ),
				'permission_callback' => array( $this, 'require_queue_access' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve_post' ),
				'permission_callback' => array( $this, 'require_queue_access' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>\d+)/reject',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject_post' ),
				'permission_callback' => array( $this, 'require_queue_access' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'reason' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Moderation audit log — admin-only read access to the append-only
		// bn_mod_log trail. The log was previously write-only over REST.
		register_rest_route(
			'buddynext/v1',
			'/moderation/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_moderation_log' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'user_id'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'action'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
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
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'escalate_report' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reports/(?P<id>[\d]+)/resolve',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'resolve_report' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reports/(?P<id>[\d]+)/remove',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'remove_report_content' ),
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

		// User warning.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/warn',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'warn_user' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'      => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Shadow-ban.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/shadow-ban',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'shadow_ban_user' ),
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_shadow_ban' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
					),
				),
			)
		);

		// Unsuspend via DELETE.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/suspend',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_suspension' ),
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

		// Get active suspension.
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/suspension',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_suspension' ),
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

		// Submit an appeal from the current user's own account.
		register_rest_route(
			'buddynext/v1',
			'/me/appeals',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_own_appeal' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// App-readiness read endpoints. WordPress merges multiple
		// register_rest_route() calls on the same path, so the GET methods below
		// sit alongside the existing POST/DELETE handlers without replacing them.

		// The current user's own appeals (any status).
		register_rest_route(
			'buddynext/v1',
			'/me/appeals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_my_appeals' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Warning history for a user (admin only).
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/warnings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_user_warnings' ),
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

		// Shadow-ban status for a user (admin only).
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/shadow-ban',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_shadow_ban_status' ),
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

		// Active suspensions for a user (admin only).
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/suspensions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_user_suspensions' ),
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

		// List pending appeals (admin only).
		// WordPress merges multiple register_rest_route() calls on the same path —
		// GET is added alongside the existing POST without replacing it.
		register_rest_route(
			'buddynext/v1',
			'/appeals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_appeals' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						// Matches the handler's hard cap (min($per_page, 50)); the
						// schema previously advertised 100 it would never honour.
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Appeal decisions.
		register_rest_route(
			'buddynext/v1',
			'/appeals/(?P<id>[\d]+)/approve',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'approve_appeal' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'   => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'note' => array(
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
			'/appeals/(?P<id>[\d]+)/deny',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'deny_appeal' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id'   => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'note' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Space bans.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/bans',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_space_bans' ),
					'permission_callback' => array( $this, 'require_space_owner_or_admin' ),
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
					'callback'            => array( $this, 'ban_from_space' ),
					'permission_callback' => array( $this, 'require_space_owner_or_admin' ),
					'args'                => array(
						'id'      => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'user_id' => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'reason'  => array(
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
			'/spaces/(?P<id>[\d]+)/bans/(?P<user_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unban_from_space' ),
				'permission_callback' => array( $this, 'require_space_owner_or_admin' ),
				'args'                => array(
					'id'      => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'user_id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
				),
			)
		);

		// Content warning — public GET to check warning state; admin PUT to set/clear.
		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/content-warning',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_warning' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_content_warning' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id'                   => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'content_warning'      => array(
							'required' => true,
							'type'     => 'boolean',
						),
						'content_warning_type' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'nsfw',
							'sanitize_callback' => 'sanitize_key',
						),
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
		$gate = $this->require_cap( 'buddynext-moderation/report' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$result = ( new ModerationService() )->report(
			get_current_user_id(),
			(string) ( $request->get_param( 'object_type' ) ?? '' ),
			(int) $request->get_param( 'object_id' ),
			(string) ( $request->get_param( 'reason' ) ?? 'other' ),
			(int) $request->get_param( 'space_id' ),
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
	 * GET /moderation/log — read the moderation audit trail (admin only).
	 *
	 * Returns a paginated, newest-first slice of bn_mod_log with optional
	 * user_id and action filters. The log is append-only; this is read access
	 * to the trail the spec promised but never exposed over REST.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_moderation_log( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new ModerationLogService() )->get_log(
			array(
				'user_id'  => (int) $request->get_param( 'user_id' ),
				'action'   => (string) ( $request->get_param( 'action' ) ?? '' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'page'     => (int) $request->get_param( 'page' ),
			)
		);

		return new WP_REST_Response( $result, 200 );
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
	 * Remove the content a report targets and resolve the report.
	 *
	 * @param WP_REST_Request $request Request with the report `id`.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_report_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service   = new ModerationService();
		$report_id = (int) $request->get_param( 'id' );
		$actor_id  = get_current_user_id();

		$result = $service->remove_content( $report_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'remove_content',
			array(
				'object_id'   => $report_id,
				'object_type' => 'report',
			)
		);

		return new WP_REST_Response( array( 'removed' => true ), 200 );
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
	 * Site admins receive the unfiltered global queue. Space moderators who do
	 * not hold manage_options see only reports that belong to spaces they own or
	 * moderate. If a space moderator manages no spaces the result is always empty.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response {
		$service = new ModerationService();

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

		// Non-site-admin moderators may only see reports for their own spaces.
		if ( ! current_user_can( 'manage_options' ) ) {
			$args['space_ids'] = $service->get_moderated_space_ids( get_current_user_id() );
		}

		$result = $service->get_queue( $args );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /moderation/pending — posts held for pre-moderation approval. Site
	 * admins see all; space moderators see only their spaces.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_pending( WP_REST_Request $request ): WP_REST_Response {
		$posts    = new \BuddyNext\Feed\PostService();
		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$space_ids = array();
		if ( ! current_user_can( 'manage_options' ) ) {
			$space_ids = ( new ModerationService() )->get_moderated_space_ids( get_current_user_id() );
		}

		return new WP_REST_Response(
			array(
				'items'    => $posts->get_pending_for_review( $per_page, $offset, $space_ids ),
				'total'    => $posts->count_pending( $space_ids ),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * POST /posts/{id}/approve — publish a held post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request['id'] );
		$scope   = $this->guard_pending_scope( $post_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( ! ( new \BuddyNext\Feed\PostService() )->approve_pending( $post_id ) ) {
			return new WP_Error( 'bn_premod_not_pending', __( 'This post is not awaiting approval.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'status'  => 'published',
			),
			200
		);
	}

	/**
	 * POST /posts/{id}/reject — reject (soft-delete) a held post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request['id'] );
		$scope   = $this->guard_pending_scope( $post_id );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		if ( ! ( new \BuddyNext\Feed\PostService() )->reject_pending( $post_id, $reason ) ) {
			return new WP_Error( 'bn_premod_not_pending', __( 'This post is not awaiting approval.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'status'  => 'rejected',
			),
			200
		);
	}

	/**
	 * Ensure the current user may moderate the given pending post: site admins
	 * always may; space moderators only within their moderated spaces.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	private function guard_pending_scope( int $post_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$post     = ( new \BuddyNext\Feed\PostService() )->get( $post_id );
		$space_id = (int) ( $post['space_id'] ?? 0 );
		$allowed  = array_map( 'intval', ( new ModerationService() )->get_moderated_space_ids( get_current_user_id() ) );
		if ( $space_id <= 0 || ! in_array( $space_id, $allowed, true ) ) {
			return new WP_Error( 'bn_forbidden', __( 'You cannot moderate this post.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
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
	 * Apply or remove a content warning on a post (admin only).
	 *
	 * Updates bn_posts: content_warning and content_warning_type columns.
	 * content_warning_type must be one of: nsfw, spoilers, violence, language.
	 * When content_warning is false the type is stored as-is but has no visual effect.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_content_warning( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id       = absint( $request->get_param( 'id' ) );
		$has_warning   = (bool) $request->get_param( 'content_warning' );
		$warning_type  = sanitize_key( (string) ( $request->get_param( 'content_warning_type' ) ?? 'nsfw' ) );
		$allowed_types = array( 'nsfw', 'spoilers', 'violence', 'language' );

		if ( ! in_array( $warning_type, $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_content_warning_type',
				__( 'content_warning_type must be one of: nsfw, spoilers, violence, language.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new ModerationService() )->set_post_content_warning( $post_id, $has_warning, $warning_type );

		if ( null === $result ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( false === $result ) {
			return new WP_Error(
				'db_error',
				__( 'Failed to update content warning.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'                   => $post_id,
				'content_warning'      => $has_warning,
				'content_warning_type' => $warning_type,
			),
			200
		);
	}

	/**
	 * Permission callback for the moderation queue endpoint.
	 *
	 * Site admins (manage_options) pass unconditionally. Space moderators who
	 * hold an owner or moderator role in at least one space also pass — the
	 * get_queue() handler will then scope the results to their spaces only.
	 *
	 * @return bool|WP_Error
	 */
	public function require_queue_access(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Allow access if the user moderates at least one space.
		$space_ids = ( new ModerationService() )->get_moderated_space_ids( get_current_user_id() );
		if ( ! empty( $space_ids ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'You do not have permission to view the moderation queue.', 'buddynext' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission callback: require the current user to be a space owner, moderator, or site admin.
	 *
	 * Reads the space ID from the `id` URL parameter. If the user holds
	 * manage_options they pass unconditionally. Otherwise they must hold the
	 * 'owner' or 'moderator' role in the requested space.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function require_space_owner_or_admin( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$space_id = (int) $request->get_param( 'id' );
		$role     = ( new SpaceMemberService() )->get_role( $space_id, get_current_user_id() );

		if ( in_array( $role, array( 'owner', 'moderator' ), true ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'You must be a space owner or moderator.', 'buddynext' ), array( 'status' => 403 ) );
	}

	/**
	 * Issue a warning to a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function warn_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request->get_param( 'id' );
		$message  = (string) ( $request->get_param( 'message' ) ?? '' );
		$actor_id = get_current_user_id();

		$result = ( new ModerationService() )->warn( $user_id, $actor_id, $message );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array(
				'warned'  => true,
				'user_id' => $user_id,
			),
			200
		);
	}

	/**
	 * Apply a shadow ban to a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function shadow_ban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'buddynext' ), array( 'status' => 400 ) );
		}

		( new ModerationService() )->set_shadow_ban( $user_id );

		return new WP_REST_Response(
			array(
				'shadow_banned' => true,
				'user_id'       => $user_id,
			),
			200
		);
	}

	/**
	 * Remove a shadow ban from a user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_shadow_ban( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );

		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user ID.', 'buddynext' ), array( 'status' => 400 ) );
		}

		( new ModerationService() )->remove_shadow_ban( $user_id );

		return new WP_REST_Response(
			array(
				'shadow_banned' => false,
				'user_id'       => $user_id,
			),
			200
		);
	}

	/**
	 * Lift an active suspension via DELETE /users/{id}/suspend.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_suspension( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();

		$result = ( new ModerationService() )->unsuspend_user( $user_id, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		( new ModerationLogService() )->log( $actor_id, 'unsuspend_user', array( 'target_user_id' => $user_id ) );

		return new WP_REST_Response(
			array(
				'suspended' => false,
				'user_id'   => $user_id,
			),
			200
		);
	}

	/**
	 * Return the active suspension record for a user.
	 *
	 * Returns null (not a 404) when no active suspension exists — the absence of
	 * a suspension is a valid and expected state.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_user_suspension( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = (int) $request->get_param( 'id' );
		$suspension = ( new ModerationService() )->get_suspension( $user_id );

		return new WP_REST_Response( $suspension, 200 );
	}

	/**
	 * Submit a moderation appeal from the currently authenticated user.
	 *
	 * Unlike the legacy POST /appeals (which requires a suspension_id), this
	 * endpoint records a free-form appeal tied only to the submitting user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_own_appeal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$message = (string) ( $request->get_param( 'message' ) ?? '' );

		$result = ( new ModerationService() )->create_appeal( $user_id, $message );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'appeal_id' => $result ), 201 );
	}

	/**
	 * Return a paginated list of pending appeals (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_appeals( WP_REST_Request $request ): WP_REST_Response {
		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = absint( $request->get_param( 'page' ) );
		$per_page = $per_page > 0 ? min( $per_page, 50 ) : 20;
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		$service = new ModerationService();

		return new WP_REST_Response(
			array(
				'items' => $service->get_pending_appeals( $per_page, $offset ),
				'total' => $service->count_pending_appeals(),
			),
			200
		);
	}

	/**
	 * GET /me/appeals — the current user's own appeals (any status).
	 *
	 * @return WP_REST_Response
	 */
	public function list_my_appeals(): WP_REST_Response {
		$appeals = ( new ModerationService() )->get_user_appeals( get_current_user_id() );

		return new WP_REST_Response( $appeals, 200 );
	}

	/**
	 * GET /users/{id}/warnings — warning history for a user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_user_warnings( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = (int) $request->get_param( 'id' );
		$warnings = ( new ModerationService() )->get_warnings( $user_id );

		return new WP_REST_Response( $warnings, 200 );
	}

	/**
	 * GET /users/{id}/shadow-ban — shadow-ban status for a user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_shadow_ban_status( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'id' );

		return new WP_REST_Response(
			array(
				'user_id'       => $user_id,
				'shadow_banned' => ( new ModerationService() )->is_shadow_banned( $user_id ),
			),
			200
		);
	}

	/**
	 * GET /users/{id}/suspensions — active suspensions for a user (admin only).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_user_suspensions( WP_REST_Request $request ): WP_REST_Response {
		$user_id     = (int) $request->get_param( 'id' );
		$suspensions = ( new ModerationService() )->get_user_suspensions( $user_id );

		return new WP_REST_Response( $suspensions, 200 );
	}

	/**
	 * Approve an appeal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_appeal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$appeal_id = (int) $request->get_param( 'id' );
		$note      = (string) ( $request->get_param( 'note' ) ?? '' );
		$actor_id  = get_current_user_id();

		$result = ( new ModerationService() )->decide_appeal( $appeal_id, 'approved', $note, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'approve_appeal',
			array(
				'object_id'   => $appeal_id,
				'object_type' => 'appeal',
			)
		);

		return new WP_REST_Response(
			array(
				'appeal_id' => $appeal_id,
				'decision'  => 'approved',
			),
			200
		);
	}

	/**
	 * Deny an appeal.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deny_appeal( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$appeal_id = (int) $request->get_param( 'id' );
		$note      = (string) ( $request->get_param( 'note' ) ?? '' );
		$actor_id  = get_current_user_id();

		$result = ( new ModerationService() )->decide_appeal( $appeal_id, 'denied', $note, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		( new ModerationLogService() )->log(
			$actor_id,
			'deny_appeal',
			array(
				'object_id'   => $appeal_id,
				'object_type' => 'appeal',
			)
		);

		return new WP_REST_Response(
			array(
				'appeal_id' => $appeal_id,
				'decision'  => 'denied',
			),
			200
		);
	}

	/**
	 * List all ban records for a space.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_space_bans( WP_REST_Request $request ): WP_REST_Response {
		$space_id = (int) $request->get_param( 'id' );

		$items = ( new SpaceMemberService() )->get_space_bans( $space_id );

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Ban a user from a space.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ban_from_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = (int) $request->get_param( 'user_id' );
		$reason   = (string) ( $request->get_param( 'reason' ) ?? '' );
		$actor_id = get_current_user_id();

		$result = ( new SpaceMemberService() )->ban_from_space( $space_id, $user_id, $actor_id, $reason );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response(
			array(
				'banned'   => true,
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			200
		);
	}

	/**
	 * Return the content warning state for a post.
	 *
	 * Public — anyone may check whether a post carries a content warning before
	 * deciding whether to view it. Returns has_warning and warning_type via
	 * ModerationService. Returns 404 when the post does not exist.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_content_warning( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );

		$warning = ( new ModerationService() )->get_post_content_warning( $post_id );

		if ( null === $warning ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $warning, 200 );
	}

	/**
	 * Lift a space ban.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unban_from_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = (int) $request->get_param( 'user_id' );

		$result = ( new SpaceMemberService() )->unban_from_space( $space_id, $user_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A false return means no active ban was lifted (no such ban, or invalid
		// ids) — don't report a successful unban that never happened.
		if ( false === $result ) {
			return new WP_Error(
				'ban_not_found',
				__( 'No active ban was found to lift for this member.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'banned'   => false,
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			200
		);
	}
}
