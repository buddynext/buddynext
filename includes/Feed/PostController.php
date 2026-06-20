<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for post CRUD.
 *
 * Routes (all under buddynext/v1):
 *   POST   /posts           — create a post
 *   GET    /posts/{id}      — get a post
 *   PUT    /posts/{id}      — update a post (owner only)
 *   DELETE /posts/{id}      — delete a post (owner only)
 *   POST   /posts/{id}/pin  — pin a post (owner only)
 *   DELETE /posts/{id}/pin  — unpin a post (owner only)
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\PostService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles post lifecycle over REST.
 */
class PostController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => array( $this, 'require_auth' ),
				// Schema for the create-post payload: gives the REST layer type
				// validation + sanitisation + self-documentation. Every field is
				// optional here — PostService::create() owns the business rules
				// (e.g. content required unless poll/media), so requiredness is not
				// duplicated. create_post()/PostService still sanitise as
				// defence-in-depth.
				// Types are declared (not custom sanitize_callbacks) so the REST
				// layer validates + coerces by schema uniformly; create_post() /
				// PostService::create() remain the single sanitisation point.
				'args'                => array(
					'type'                    => array(
						'type'        => 'string',
						'description' => __( 'Post type slug (text, photo, poll, link, announcement, …).', 'buddynext' ),
						'required'    => false,
					),
					'content'                 => array(
						'type'        => 'string',
						'description' => __( 'Post body text.', 'buddynext' ),
						'required'    => false,
					),
					'privacy'                 => array(
						'type'        => 'string',
						'description' => __( 'Audience: public, followers, connections, private.', 'buddynext' ),
						'required'    => false,
					),
					'space_id'                => array(
						'type'        => 'integer',
						'description' => __( 'Target space ID; 0 or omitted posts to the global feed.', 'buddynext' ),
						'required'    => false,
					),
					'media_ids'               => array(
						'type'        => 'array',
						'description' => __( 'Attached media attachment IDs.', 'buddynext' ),
						'required'    => false,
						'items'       => array( 'type' => 'integer' ),
					),
					'link_url'                => array(
						'type'        => 'string',
						'description' => __( 'URL for a link-preview post.', 'buddynext' ),
						'required'    => false,
					),
					'link_meta'               => array(
						'type'        => 'object',
						'description' => __( 'Resolved link-preview metadata (title, description, thumbnail).', 'buddynext' ),
						'required'    => false,
					),
					'options'                 => array(
						'type'        => 'array',
						'description' => __( 'Poll option labels (type=poll).', 'buddynext' ),
						'required'    => false,
						'items'       => array( 'type' => 'string' ),
					),
					'poll_end_date'           => array(
						'type'        => 'string',
						'description' => __( 'Poll close datetime, UTC Y-m-d H:i:s (type=poll).', 'buddynext' ),
						'required'    => false,
					),
					'content_warning'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the post carries a content warning.', 'buddynext' ),
						'required'    => false,
					),
					'content_warning_type'    => array(
						'type'        => 'string',
						'description' => __( 'Content-warning category (nsfw, spoilers, violence, language).', 'buddynext' ),
						'required'    => false,
					),
					'scheduled_at'            => array(
						'type'        => 'string',
						'description' => __( 'Future publish datetime, UTC Y-m-d H:i:s (requires schedule-post capability).', 'buddynext' ),
						'required'    => false,
					),
					'announcement_expires_at' => array(
						'type'        => 'string',
						'description' => __( 'Announcement auto-expire datetime, UTC Y-m-d H:i:s (type=announcement).', 'buddynext' ),
						'required'    => false,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/pending-posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'my_pending_posts' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/link-preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'link_preview' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/posts/(?P<id>[\d]+)/pin',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'pin_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unpin_post' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);
	}

	/**
	 * Create a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = get_current_user_id();
		$space_id = $request->get_param( 'space_id' ) ? (int) $request->get_param( 'space_id' ) : null;

		// Role-map enforcement. Posting inside a space is governed by the
		// space-post capability (with space context); a feed post by the
		// create-post capability. Scheduling additionally requires schedule-post.
		if ( $space_id ) {
			$gate = $this->require_cap( 'buddynext-spaces/post', array( 'space_id' => $space_id ) );
		} else {
			$gate = $this->require_cap( 'buddynext-feed/create-post' );
		}
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( $request->get_param( 'scheduled_at' ) ) {
			$sched_gate = $this->require_cap( 'buddynext-feed/schedule-post' );
			if ( is_wp_error( $sched_gate ) ) {
				return $sched_gate;
			}
		}

		$data = array(
			'type'                 => sanitize_key( $request->get_param( 'type' ) ?? 'text' ),
			'content'              => wp_kses_post( (string) ( $request->get_param( 'content' ) ?? '' ) ),
			'privacy'              => null !== $request->get_param( 'privacy' ) ? sanitize_key( (string) $request->get_param( 'privacy' ) ) : null,
			'space_id'             => $space_id,
			'media_ids'            => $request->get_param( 'media_ids' ),
			'link_url'             => $this->parse_link_url( $request ),
			'link_meta'            => $request->get_param( 'link_meta' ),
			'options'              => $request->get_param( 'options' ),
			// Optional poll deadline (UTC datetime); only honoured for type=poll.
			'poll_end_date'        => $request->get_param( 'poll_end_date' ),
			'content_warning'      => (bool) $request->get_param( 'content_warning' ),
			'content_warning_type' => $this->sanitize_warning_type( $request->get_param( 'content_warning_type' ) ),
			'scheduled_at'         => $request->get_param( 'scheduled_at' ),
			// Optional announcement expiry (UTC datetime); only honoured for type=announcement.
			'announcement_expires_at' => $request->get_param( 'announcement_expires_at' ),
		);

		$service = function_exists( 'buddynext_service' )
			? buddynext_service( 'post_service' )
			: new PostService();

		$result = $service->create( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			// Safeguard errors already carry a status code; preserve it when present.
			$error_data = $result->get_error_data();
			if ( empty( $error_data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		$post = $service->get( $result );

		// Attach the server-rendered card so the composer can prepend the new
		// post in place (no reload). Only for live (published) posts — held
		// (pending) and scheduled posts are not in the live feed, so the client
		// shows a status toast instead of inserting a card.
		if ( is_array( $post ) && 'published' === ( $post['status'] ?? 'published' ) ) {
			$post['html'] = FeedController::render_card_html( $post, $user_id, 'home' );
		}

		return new WP_REST_Response( $post, 201 );
	}

	/**
	 * GET /me/pending-posts — the current member's own posts held for approval,
	 * so the front end can show a "waiting for review" surface. Members only ever
	 * see their own held posts here.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function my_pending_posts( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$per_page = absint( $request->get_param( 'per_page' ) );
		$items    = ( new PostService() )->get_pending_by_author( $user_id, $per_page );

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => count( $items ),
			),
			200
		);
	}

	/**
	 * Return Open Graph metadata for a URL so the composer can render a live
	 * link-preview card before the post is submitted.
	 *
	 * Gated on the site-owner `buddynext_enable_link_preview` toggle (default on)
	 * so disabling previews disables both the fetch and the rendered card. Returns
	 * a flat { url, title, description, thumbnail } payload; empty strings when the
	 * URL is unreachable or carries no OG tags.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function link_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! (bool) get_option( 'buddynext_enable_link_preview', true ) ) {
			return new WP_Error(
				'link_preview_disabled',
				__( 'Link previews are disabled.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$url = esc_url_raw( (string) ( $request->get_param( 'url' ) ?? '' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'A valid URL is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$service = function_exists( 'buddynext_service' )
			? buddynext_service( 'post_service' )
			: new PostService();

		$meta = $service->og_meta( $url );

		return new WP_REST_Response(
			array(
				'url'         => $url,
				'title'       => (string) ( $meta['title'] ?? '' ),
				'description' => (string) ( $meta['description'] ?? '' ),
				'thumbnail'   => (string) ( $meta['thumbnail'] ?? '' ),
			),
			200
		);
	}

	/**
	 * Get a single post.
	 *
	 * Enforces four authorization gates so that a `__return_true` permission
	 * callback never leaks privacy-restricted content:
	 *   1. Block list — if either user has blocked the other, return 404 so the
	 *      post's existence is not disclosed.
	 *   2. Secret-space membership — posts in spaces of type `secret` are only
	 *      visible to active space members and site admins.
	 *   3. Followers-only privacy — `followers` posts are visible to the author
	 *      and to users following the author at request time.
	 *   4. Private privacy — `private` posts are visible to the author only.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$service = new PostService();
		$post    = $service->get( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Single source of truth for the 4 privacy gates (block / secret-space /
		// followers-only / private). Reused by the engagement-read endpoints so the
		// gate logic lives in exactly one place.
		$denied = $service->visibility_error( $post_id, get_current_user_id() );
		if ( $denied instanceof WP_Error ) {
			return $denied;
		}

		return new WP_REST_Response( $post, 200 );
	}

	/**
	 * Update a post's content or privacy.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$service = new PostService();

		$data = array();
		if ( null !== $request->get_param( 'content' ) ) {
			$data['content'] = wp_kses_post( (string) $request->get_param( 'content' ) );
		}
		if ( null !== $request->get_param( 'privacy' ) ) {
			$data['privacy'] = sanitize_key( $request->get_param( 'privacy' ) );
		}
		if ( null !== $request->get_param( 'content_warning' ) ) {
			$data['content_warning'] = (bool) $request->get_param( 'content_warning' );
		}
		if ( null !== $request->get_param( 'content_warning_type' ) ) {
			$data['content_warning_type'] = $this->sanitize_warning_type( $request->get_param( 'content_warning_type' ) );
		}

		$result = $service->update( $post_id, $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( $service->get( $post_id ), 200 );
	}

	/**
	 * Delete a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$service = new PostService();

		// Deleting someone else's post requires the delete-any-post capability;
		// deleting your own is always allowed (delete-own-post, member default).
		$author_id = (int) $service->get_author_id( $post_id );
		if ( $author_id > 0 && $author_id !== $user_id ) {
			$gate = $this->require_cap( 'buddynext-feed/delete-any-post' );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}

		$result = $service->delete( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Pin a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pin_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_cap( 'buddynext-feed/pin-post' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$result  = ( new PostService() )->pin( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'pinned' => true ), 200 );
	}

	/**
	 * Unpin a post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unpin_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_cap( 'buddynext-feed/pin-post' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$result  = ( new PostService() )->unpin( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'pinned' => false ), 200 );
	}

	/**
	 * Validate and return a content_warning_type value, or null when invalid/absent.
	 *
	 * Allowed types: nsfw, spoilers, violence, language.
	 *
	 * @param mixed $raw Raw value from the request parameter.
	 * @return string|null
	 */
	private function sanitize_warning_type( mixed $raw ): ?string {
		if ( null === $raw ) {
			return null;
		}

		$allowed = array( 'nsfw', 'spoilers', 'violence', 'language' );
		$value   = sanitize_key( (string) $raw );

		return in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Sanitize and return the link_url from the request, or null if empty.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string|null
	 */
	private function parse_link_url( WP_REST_Request $request ): ?string {
		$raw = esc_url_raw( (string) ( $request->get_param( 'link_url' ) ?? '' ) );
		return '' !== $raw ? $raw : null;
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
}
