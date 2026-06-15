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
use BuddyNext\Reactions\ReactionService;
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
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_comments' ),
					'permission_callback' => '__return_true',
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

		register_rest_route(
			'buddynext/v1',
			'/comments/(?P<id>[\d]+)/pin',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pin' ),
					'permission_callback' => array( $this, 'require_moderator' ),
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
					'permission_callback' => array( $this, 'require_moderator' ),
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
		$service     = new CommentService();
		$user_id     = get_current_user_id();
		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$content     = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );
		$parent_id   = $request->get_param( 'parent_id' ) !== null ? (int) $request->get_param( 'parent_id' ) : null;

		$result = $service->create( $user_id, $object_type, $object_id, $content, $parent_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$created = $service->get( $result );
		if ( null === $created ) {
			return new WP_Error( 'create_failed', __( 'Comment could not be retrieved after creation.', 'buddynext' ), array( 'status' => 500 ) );
		}

		// Match the shape returned by list_comments() so the JS can drop the
		// new comment node into the tree without a second round-trip.
		$created['author_name']       = (string) get_the_author_meta( 'display_name', $created['user_id'] );
		$created['author_avatar_url'] = (string) get_avatar_url( $created['user_id'], array( 'size' => 40 ) );
		$created['like_count']        = 0;
		$created['viewer_liked']      = false;
		$created['viewer_reaction']   = null;
		$created['can_edit']          = true;
		$created['can_delete']        = true;
		$created['can_pin']           = user_can( $user_id, 'manage_options' );
		$created['replies']           = array();
		$created['is_pinned']         = false;
		$created['author_meta_html']  = wp_kses_post(
			(string) apply_filters(
				'buddynext_comment_author_meta_html',
				'',
				(int) $created['user_id'],
				(int) $created['id']
			)
		);

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

		$result = $service->list(
			$object_type,
			$object_id,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		$reactions = new ReactionService();
		$viewer_id = get_current_user_id();

		// Single option lookup for the pinned comment id of this object.
		// Resolved once, not per-comment.
		$pinned_id = (int) get_option(
			'bn_pinned_comment_' . sanitize_key( $object_type ) . '_' . $object_id,
			0
		);

		// Soft-deleted comments — anonymize author + avatar so the thread
		// keeps its shape (nested replies stay attached) without leaking
		// the original author's identity.
		$anonymize = static function ( array &$c ): void {
			$c['author_name']       = __( 'Deleted user', 'buddynext' );
			$c['author_avatar_url'] = '';
			$c['content']           = __( '[deleted]', 'buddynext' );
		};

		// Enrich each comment with author display name, avatar URL, like
		// metadata, viewer permissions, and pinned state. Like fields drive
		// the heart toggle in the threaded UI; can_edit / can_delete /
		// can_pin let the JS decide which action buttons to render without
		// re-hitting the server on every paint. is_pinned drives the
		// "Pinned" badge in the thread head.
		$enrich = function ( array $comment ) use ( $reactions, $viewer_id, $pinned_id, $anonymize ): array {
			$comment['author_name']       = (string) get_the_author_meta( 'display_name', $comment['user_id'] );
			$comment['author_avatar_url'] = (string) get_avatar_url( $comment['user_id'], array( 'size' => 40 ) );
			$comment['like_count']        = $reactions->count( 'comment', (int) $comment['id'] );
			$comment['viewer_liked']      = $viewer_id > 0
				? $reactions->has_reacted( $viewer_id, 'comment', (int) $comment['id'] )
				: false;
			// Carry the specific emoji the viewer reacted with so the client can
			// render the right icon instead of always falling back to 'like'.
			$comment['viewer_reaction']   = $viewer_id > 0
				? $reactions->get_user_emoji( $viewer_id, 'comment', (int) $comment['id'] )
				: null;
			$comment['can_edit']          = $viewer_id > 0
				&& ( (int) $comment['user_id'] === $viewer_id || user_can( $viewer_id, 'manage_options' ) );
			$comment['can_delete']        = $comment['can_edit'];
			$comment['can_pin']           = $viewer_id > 0 && user_can( $viewer_id, 'manage_options' );
			$comment['is_pinned']         = ( $pinned_id > 0 && (int) $comment['id'] === $pinned_id );

			$comment['author_meta_html'] = wp_kses_post(
				(string) apply_filters(
					'buddynext_comment_author_meta_html',
					'',
					(int) $comment['user_id'],
					(int) $comment['id']
				)
			);

			if ( ! empty( $comment['is_deleted'] ) ) {
				$anonymize( $comment );
				// A deleted comment can never be edited, pinned, or react'd to.
				$comment['can_edit']     = false;
				$comment['can_delete']   = false;
				$comment['can_pin']      = false;
				$comment['like_count']      = 0;
				$comment['viewer_liked']    = false;
				$comment['viewer_reaction'] = null;
			}

			return $comment;
		};

		// Recurse through the full reply tree (N-deep up to the
		// CommentService::MAX_REPLY_DEPTH cap) so every node — including
		// the cap-level flattened leaves — gets the same enrichment.
		$walk            = function ( array $comment ) use ( $enrich, &$walk ): array {
			$comment = $enrich( $comment );
			if ( ! empty( $comment['replies'] ) ) {
				$comment['replies'] = array_map( $walk, $comment['replies'] );
			}
			return $comment;
		};
		$result['items'] = array_map( $walk, $result['items'] );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update a comment's content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service    = new CommentService();
		$comment_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();
		$content    = wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) );

		$result = $service->update( $comment_id, $user_id, $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = $service->get( $comment_id );
		if ( null === $updated ) {
			return new WP_Error( 'update_failed', __( 'Comment could not be retrieved after update.', 'buddynext' ), array( 'status' => 500 ) );
		}

		$reactions                    = new ReactionService();
		$updated['author_name']       = (string) get_the_author_meta( 'display_name', $updated['user_id'] );
		$updated['author_avatar_url'] = (string) get_avatar_url( $updated['user_id'], array( 'size' => 40 ) );
		$updated['like_count']        = $reactions->count( 'comment', $comment_id );
		$updated['viewer_liked']      = $user_id > 0 && $reactions->has_reacted( $user_id, 'comment', $comment_id );
		$updated['can_edit']          = true;
		$updated['can_delete']        = true;
		$updated['can_pin']           = user_can( $user_id, 'manage_options' );
		$updated['is_pinned']         = ( (int) get_option(
			'bn_pinned_comment_' . sanitize_key( (string) $updated['object_type'] ) . '_' . (int) $updated['object_id'],
			0
		) === $comment_id );
		$updated['author_meta_html']  = wp_kses_post(
			(string) apply_filters(
				'buddynext_comment_author_meta_html',
				'',
				(int) $updated['user_id'],
				$comment_id
			)
		);

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

		$result = $service->pin( $comment_id, $user_id );

		if ( ! $result ) {
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
	 * Require the user to be logged in.
	 *
	 * @return bool|WP_Error
	 */
}
