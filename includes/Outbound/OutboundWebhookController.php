<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Outbound webhook REST controller.
 *
 * Routes (all under buddynext/v1, all require manage_options):
 *   GET    /webhooks              — list all registered endpoints
 *   POST   /webhooks              — register a new endpoint
 *   DELETE /webhooks/{id}         — delete an endpoint and its log
 *   GET    /webhooks/{id}/log     — paginated delivery log (per_page, page)
 *   POST   /webhooks/{id}/test    — send a test ping
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use BuddyNext\Outbound\OutboundWebhookService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes outbound webhook management over the REST API.
 */
class OutboundWebhookController {

	/**
	 * Outbound webhook service instance.
	 *
	 * @var OutboundWebhookService
	 */
	private OutboundWebhookService $service;

	/**
	 * Constructor.
	 *
	 * @param OutboundWebhookService $service Injected service instance.
	 */
	public function __construct( OutboundWebhookService $service ) {
		$this->service = $service;
	}

	/**
	 * Register all REST routes for this controller.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/webhooks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_webhooks' ),
					'permission_callback' => array( $this, 'require_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_webhook' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'url'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_url',
						),
						'secret' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'events' => array(
							'required' => false,
							'type'     => 'array',
							'default'  => array(),
							'items'    => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/webhooks/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_webhook' ),
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

		register_rest_route(
			'buddynext/v1',
			'/webhooks/(?P<id>[\d]+)/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_log' ),
					'permission_callback' => array( $this, 'require_admin' ),
					'args'                => array(
						'id'       => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'per_page' => array(
							'required' => false,
							'type'     => 'integer',
							'default'  => 20,
							'minimum'  => 1,
							'maximum'  => 100,
						),
						'page'     => array(
							'required' => false,
							'type'     => 'integer',
							'default'  => 1,
							'minimum'  => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/webhooks/(?P<id>[\d]+)/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_webhook' ),
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
	}

	/**
	 * GET /webhooks — return all registered webhook endpoints.
	 *
	 * @param WP_REST_Request $request REST request (unused — no query params).
	 * @return WP_REST_Response
	 */
	public function list_webhooks( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request required by WP REST API contract.
		return new WP_REST_Response( $this->service->list_all(), 200 );
	}

	/**
	 * POST /webhooks — register a new webhook endpoint.
	 *
	 * Auto-generates a 40-character signing secret when the caller omits it.
	 *
	 * @param WP_REST_Request $request REST request with url, secret, events params.
	 * @return WP_REST_Response|WP_Error 201 with id+secret on success.
	 */
	public function create_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url    = (string) $request->get_param( 'url' );
		$secret = (string) $request->get_param( 'secret' );
		$events = (array) $request->get_param( 'events' );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false );
		}

		$result = $this->service->register( $url, $secret, $events, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'id'     => $result,
				'secret' => $secret,
			),
			201
		);
	}

	/**
	 * DELETE /webhooks/{id} — delete a webhook and its delivery log.
	 *
	 * @param WP_REST_Request $request REST request with id param.
	 * @return WP_REST_Response|WP_Error 200 on success, 404 when not found.
	 */
	public function delete_webhook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->service->delete( $id ) ) {
			return new WP_Error(
				'not_found',
				__( 'Webhook not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * GET /webhooks/{id}/log — paginated delivery log for an endpoint.
	 *
	 * @param WP_REST_Request $request REST request with id, per_page, page params.
	 * @return WP_REST_Response
	 */
	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );

		return new WP_REST_Response( $this->service->get_log( $id, $per_page, $page ), 200 );
	}

	/**
	 * POST /webhooks/{id}/test — send a test ping to an endpoint.
	 *
	 * @param WP_REST_Request $request REST request with id param.
	 * @return WP_REST_Response 200 on success, 502 when the endpoint fails.
	 */
	public function test_webhook( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request->get_param( 'id' );
		$success = $this->service->send_test_ping( $id );

		return new WP_REST_Response(
			array(
				'success' => $success,
				'message' => $success
					? __( 'Test ping delivered successfully.', 'buddynext' )
					: __( 'Test ping failed. Check the delivery log for details.', 'buddynext' ),
			),
			$success ? 200 : 502
		);
	}

	/**
	 * Permission callback — requires manage_options capability.
	 *
	 * @return bool|WP_Error True when authorised, WP_Error 403 otherwise.
	 */
	public function require_admin(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage webhooks.', 'buddynext' ),
			array( 'status' => 403 )
		);
	}
}
