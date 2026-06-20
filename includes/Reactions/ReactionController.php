<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Reaction REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST /reactions/toggle — toggle a reaction for the current user (auth required)
 *   GET  /reactions        — get reaction count + current user status (public)
 *
 * @package BuddyNext\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Reactions;

use BuddyNext\Reactions\ReactionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use BuddyNext\REST\BaseRestController;

/**
 * Handles reaction toggle and count reads over REST.
 */
class ReactionController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/reactions/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
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
					'emoji'       => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'like',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_count' ),
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
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/reactions/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_reactors' ),
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
					'limit'       => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 100,
						'minimum'  => 1,
						'maximum'  => 100,
					),
				),
			)
		);
	}

	/**
	 * Toggle a reaction for the current user.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error 403 when the feature is off, or when the
	 *                                   actor is suspended or blocked.
	 */
	public function toggle( WP_REST_Request $request ) {
		$gate = $this->reactions_enabled_gate();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$service     = new ReactionService();
		$user_id     = get_current_user_id();
		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$emoji       = (string) $request->get_param( 'emoji' );

		$toggle = $service->toggle( $user_id, $object_type, $object_id, $emoji );
		if ( is_wp_error( $toggle ) ) {
			// Surface the Trust-&-Safety refusal (suspended actor / block between
			// the actor and the post author) as a proper 403 instead of a silent
			// success. The service stamps the status; default to 403 if absent.
			$data = $toggle->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$toggle->add_data( array( 'status' => 403 ) );
			}
			return $toggle;
		}

		$has_reacted = $service->has_reacted( $user_id, $object_type, $object_id );

		return new WP_REST_Response(
			array(
				'has_reacted' => $has_reacted,
				'emoji'       => $has_reacted ? $service->get_user_emoji( $user_id, $object_type, $object_id ) : null,
				'count'       => $service->count( $object_type, $object_id ),
			),
			200
		);
	}

	/**
	 * Block reaction writes when the site owner has disabled the Reactions feature.
	 *
	 * The canonical on/off switch is the FeatureRegistry 'reactions' feature
	 * (Settings → Features, default on). When it is off the frontend removes the
	 * React button + emoji picker and the reaction-summary chips; this enforces the
	 * same gate on the API so toggles cannot be driven directly. The count/list
	 * reads (GET) stay readable. Returns a 403 WP_Error when disabled, true otherwise.
	 *
	 * @return true|WP_Error
	 */
	private function reactions_enabled_gate() {
		$features = function_exists( 'buddynext_service' ) ? buddynext_service( 'features' ) : null;

		if ( is_object( $features ) && method_exists( $features, 'is_enabled' ) && ! $features->is_enabled( 'reactions' ) ) {
			return new WP_Error(
				'reactions_disabled',
				__( 'Reactions are turned off on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Whether the post behind an engagement target is hidden from the current viewer.
	 *
	 * Resolves the (object_type, object_id) target to its owning post via
	 * PostService::resolve_post_id() and applies the single shared visibility gate
	 * (PostService::visibility_error()). Targets with no gateable post are treated
	 * as visible. Degrades to "visible" when the service container is unavailable.
	 *
	 * @param string $object_type Engagement object type ('post', 'comment', …).
	 * @param int    $object_id   Engagement object ID.
	 * @return bool True when the owning post is not viewable by the current user.
	 */
	private function is_post_hidden_from_viewer( string $object_type, int $object_id ): bool {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		$posts = buddynext_service( 'post_service' );
		if ( ! $posts instanceof \BuddyNext\Feed\PostService ) {
			return false;
		}

		$post_id = $posts->resolve_post_id( $object_type, $object_id );
		if ( $post_id <= 0 ) {
			return false;
		}

		return $posts->visibility_error( $post_id, get_current_user_id() ) instanceof WP_Error;
	}

	/**
	 * Return reaction count (and current user state if authenticated).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_count( WP_REST_Request $request ): WP_REST_Response {
		$service     = new ReactionService();
		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = (int) $request->get_param( 'object_id' );

		// Privacy gate: a reaction count discloses engagement on a post. Return a
		// zero count (no viewer state) when the owning post is not viewable.
		if ( $this->is_post_hidden_from_viewer( $object_type, $object_id ) ) {
			return new WP_REST_Response( array( 'count' => 0 ), 200 );
		}

		$data = array( 'count' => $service->count( $object_type, $object_id ) );

		if ( is_user_logged_in() ) {
			$user_id             = get_current_user_id();
			$data['has_reacted'] = $service->has_reacted( $user_id, $object_type, $object_id );
			$data['emoji']       = $service->get_user_emoji( $user_id, $object_type, $object_id );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Return the list of users who reacted to an object, with their emoji
	 * and a hydrated display name + avatar URL for direct UI consumption.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_reactors( WP_REST_Request $request ): WP_REST_Response {
		$service     = new ReactionService();
		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = (int) $request->get_param( 'object_id' );
		$limit       = (int) $request->get_param( 'limit' );

		// Privacy gate: the reactor list names members who engaged with the post.
		// Return an empty list when the owning post is not viewable.
		if ( $this->is_post_hidden_from_viewer( $object_type, $object_id ) ) {
			return new WP_REST_Response(
				array(
					'items' => array(),
					'total' => 0,
				),
				200
			);
		}

		$raw = $service->get_reactors( $object_type, $object_id, $limit );

		// Prime the user cache in ONE query so the per-row display_name + avatar
		// lookups below hit the cache instead of issuing a get_userdata() query
		// each (N+1 on a popular post's reactor list). cache_users() bulk-loads
		// the users and their meta.
		$reactor_ids = array_map( static fn( $r ): int => (int) $r['user_id'], $raw );
		if ( ! empty( $reactor_ids ) ) {
			cache_users( array_values( array_unique( $reactor_ids ) ) );
		}

		$items = array();
		foreach ( $raw as $row ) {
			$items[] = array(
				'user_id'      => $row['user_id'],
				'display_name' => (string) get_the_author_meta( 'display_name', $row['user_id'] ),
				'avatar_url'   => (string) get_avatar_url( $row['user_id'], array( 'size' => 32 ) ),
				'emoji'        => $row['emoji'],
				'created_at'   => $row['created_at'],
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => $service->count( $object_type, $object_id ),
			),
			200
		);
	}
}
