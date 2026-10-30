<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Comment REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST   /comments                — create a comment (auth required)
 *   GET    /comments                — list comments for an object (public)
 *   PUT    /comments/{id}           — update a comment (owner or admin)
 *   DELETE /comments/{id}           — delete a comment (owner or admin)
 *   POST   /comments/{id}/pin       — pin a comment (moderator only)
 *   DELETE /comments/{id}/pin       — unpin a comment (moderator only)
 *
 * @package BuddyNext\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Comments;

use BuddyNext\Comments\CommentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use BuddyNext\REST\BaseRestController;

/**
 * Handles comment CRUD and listing over REST.
 */
class CommentController extends BaseRestController {

	/**
	 * The web client asks for each comment's rendered HTML too
	 * (templates/parts/comment.php); the app reads the JSON only.
	 */
	private const RENDER_ARG = array(
		'required' => false,
		'type'     => 'string',
		'enum'     => array( 'html' ),
	);

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/comments',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
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
						'content'     => array(
							'required' => true,
							'type'     => 'string',
						),
						'parent_id'   => array(
							'required' => false,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'render'      => self::RENDER_ARG,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'render'      => self::RENDER_ARG,
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
						'per_page'    => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
						'page'        => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/comments/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'id'      => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 1,
						),
						'content' => array(
							'required' => true,
							'type'     => 'string',
						),
						'render'  => self::RENDER_ARG,
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'require_auth' ),
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

		// Pin/unpin: login is the only route-level gate. Fine-grained authorization
		// is the service's job — CommentService::can_pin_comment() grants pin rights
		// to site admins AND to moderators of the post's space, and pin()/unpin()
		// below turn a denial into a 403 (404-before-403 for a missing comment).
		// require_moderator() here would be require_admin() (manage_options), which
		// 403'd space moderators at the permission callback even though the service
		// — and the can_pin flag that renders their pin button — allow them.
		register_rest_route(
			'buddynext/v1',
			'/comments/(?P<id>[\d]+)/pin',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pin' ),
					'permission_callback' => array( $this, 'require_auth' ),
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
					'callback'            => array( $this, 'unpin' ),
					'permission_callback' => array( $this, 'require_auth' ),
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
	 * Create a comment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->comments_enabled_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Enforce the "Comment on posts" minimum-role setting (Settings → Roles &
		// Capabilities). The route's require_auth permission_callback only checks
		// that the user is logged in; without this gate the configured role was
		// resolved by PermissionService but never applied, so any member could
		// comment regardless of the setting.
		$cap_gate = $this->require_cap( 'buddynext-comments/create' );
		if ( is_wp_error( $cap_gate ) ) {
			return $cap_gate;
		}

		$service     = new CommentService();
		$user_id     = get_current_user_id();
		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$content     = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );
		$parent_id   = $request->get_param( 'parent_id' ) !== null ? (int) $request->get_param( 'parent_id' ) : null;

		// The post behind this target must be one the viewer can actually see. The
		// read endpoints on this controller have asked since they were written; the
		// write endpoint never did, so engagement landed on drafts, held posts and
		// deleted posts alike.
		$hidden = $this->engagement_target_error( $object_type, $object_id );
		if ( $hidden instanceof WP_Error ) {
			return $hidden;
		}

		$result = $service->create( $user_id, $object_type, $object_id, $content, $parent_id );

		if ( is_wp_error( $result ) ) {
			// Preserve a status the service already set (suspension / blocked IP /
			// archived space = 403, rate-limit = 429); only default to 400 when
			// none was given. WP_Error::add_data() REPLACES the data array, so an
			// unconditional re-stamp would flatten those to a wrong HTTP 400.
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		// Same shape as list_comments(), so the client can add it without a second request.
		$created = ( new CommentThread() )->one( (int) $result, $user_id );
		if ( null === $created ) {
			return new WP_Error( 'create_failed', __( 'Comment could not be retrieved after creation.', 'buddynext' ), array( 'status' => 500 ) );
		}
		$created = $this->with_html( $created, $request );

		return new WP_REST_Response( $created, 201 );
	}

	/**
	 * List top-level comments for an object.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_comments( WP_REST_Request $request ): WP_REST_Response {
		$service     = new CommentService();
		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$per_page    = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$page        = (int) ( $request->get_param( 'page' ) ?? 1 );

		// Privacy gate: never leak a private/secret/followers-only post's comment
		// thread to a viewer who could not read the post itself. Resolve the target
		// to its owning post and, when that post is not viewable, return an empty
		// thread instead of the comments.
		if ( $this->is_post_hidden_from_viewer( $object_type, $object_id ) ) {
			return new WP_REST_Response(
				array(
					'items' => array(),
					'total' => 0,
				),
				200
			);
		}

		$result          = ( new CommentThread() )->page( $object_type, $object_id, get_current_user_id(), max( 1, $page ), max( 1, $per_page ) );
		$result['items'] = array_map( fn( array $comment ): array => $this->with_html( $comment, $request ), $result['items'] );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update a comment's content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->comments_enabled_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();
		$content    = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );

		$result = $service->update( $comment_id, $user_id, $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = ( new CommentThread() )->one( $comment_id, $user_id );
		if ( null === $updated ) {
			return new WP_Error( 'update_failed', __( 'Comment could not be retrieved after update.', 'buddynext' ), array( 'status' => 500 ) );
		}
		// content_html carries the linked @mentions and #hashtags; the client puts it
		// back in place of the edited text, so links survive an edit.
		$updated = $this->with_html( $updated, $request );

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * Delete a comment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();

		$result = $service->delete( $comment_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Pin a comment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();

		// Resolve first so a missing comment is a 404, not a 403 — the service
		// returns a bare false for both not-found and no-permission. Mirrors unpin().
		if ( null === $service->get( $comment_id ) ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( ! $service->pin( $comment_id, $user_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot pin this comment.', 'buddynext' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( array( 'pinned' => true ), 200 );
	}

	/**
	 * Unpin the pinned comment on a comment's parent object.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unpin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();

		$comment = $service->get( $comment_id );
		if ( null === $comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$result = $service->unpin( (string) $comment['object_type'], (int) $comment['object_id'], $user_id );

		if ( ! $result ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot unpin this comment.', 'buddynext' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( array( 'pinned' => false ), 200 );
	}


	/**
	 * Add the comment's rendered HTML when the client asked for it (render=html).
	 *
	 * @param array<string, mixed> $comment Enriched comment (with replies).
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>
	 */
	private function with_html( array $comment, WP_REST_Request $request ): array {
		if ( 'html' === $request->get_param( 'render' ) ) {
			$post_id         = 'post' === (string) ( $comment['object_type'] ?? '' ) ? (int) $comment['object_id'] : 0;
			$comment['html'] = CommentThread::render( array( $comment ), $post_id, get_current_user_id() );
		}
		return $comment;
	}

	/**
	 * Block comment writes when the site owner has disabled the Comments feature.
	 *
	 * The canonical on/off switch is the FeatureRegistry 'comments' feature
	 * (Settings → Features, default on). When it is off the frontend hides the
	 * comment button + composer; this enforces the same gate on the API so writes
	 * cannot be driven directly. The comment LIST (GET) stays readable. Returns a
	 * 403 WP_Error when disabled, true otherwise.
	 *
	 * @return true|WP_Error
	 */
	private function comments_enabled_gate() {
		$features = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;

		if ( is_object( $features ) && method_exists( $features, 'is_enabled' ) && ! $features->is_enabled( 'comments' ) ) {
			return new WP_Error(
				'comments_disabled',
				__( 'Comments are turned off on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Require the user to be logged in.
	 *
	 * @return bool|WP_Error
	 */
}
