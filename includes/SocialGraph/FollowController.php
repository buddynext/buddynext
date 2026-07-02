<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for follow relationships.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/follow         — follow a user
 *   DELETE /users/{id}/follow         — unfollow a user
 *   GET    /users/{id}/followers      — list user's followers (public)
 *   GET    /users/{id}/following      — list who a user follows (public)
 *   GET    /follow-suggestions        — friends-of-friends suggestions
 *
 * @package BuddyNext\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\SocialGraph;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Handles follow/unfollow and follow-graph reads.
 */
class FollowController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/follow',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'follow' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unfollow' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/followers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_followers' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/following',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_following' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/follow-suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/follow-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_follow_requests' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/follow-requests/(?P<follower_id>[\d]+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_follow_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/follow-requests/(?P<follower_id>[\d]+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject_follow_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/follow-requests/count',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'follow_requests_count' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/follow/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'follow_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/account-type',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'account_type' ),
				// Auth-gated: a member's private/public account state is not anonymous
				// data — don't expose it to logged-out callers. No public caller relies
				// on this; it is only meaningful to a logged-in viewer deciding how to
				// follow or connect.
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * GET /users/{id}/follow/status — the current user's follow state for a target.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function follow_status( WP_REST_Request $request ): WP_REST_Response {
		$me      = get_current_user_id();
		$target  = (int) $request['id'];
		$follows = buddynext_service( 'follows' );

		return new WP_REST_Response(
			array(
				'is_following' => $follows->is_following( $me, $target ),
				'is_pending'   => $follows->has_pending_request( $me, $target ),
			),
			200
		);
	}

	/**
	 * GET /users/{id}/account-type — whether the target account is private (auth required).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function account_type( WP_REST_Request $request ): WP_REST_Response {
		$target = (int) $request['id'];

		return new WP_REST_Response(
			array( 'is_private' => buddynext_service( 'follows' )->is_private_account( $target ) ),
			200
		);
	}

	/**
	 * GET /me/follow-requests/count — pending inbound follow-request count.
	 *
	 * @return WP_REST_Response
	 */
	public function follow_requests_count(): WP_REST_Response {
		return new WP_REST_Response(
			array( 'count' => buddynext_service( 'follows' )->pending_followers_count( get_current_user_id() ) ),
			200
		);
	}

	/**
	 * Follow a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function follow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$gate = $this->require_cap( 'buddynext-connections/follow' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		if ( buddynext_service( 'blocks' )->is_blocking_either( $current_id, $target_id ) ) {
			return new WP_Error(
				'buddynext_blocked',
				__( 'You cannot follow this user.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$follows = buddynext_service( 'follows' );
		$result  = $follows->follow( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		// Surface the resolved state so the UI can distinguish a normal
		// follow from a follow-request that's waiting on approval.
		$is_pending = $follows->has_pending_request( $current_id, $target_id );

		return new WP_REST_Response(
			array(
				'following' => ! $is_pending,
				'pending'   => $is_pending,
			),
			200
		);
	}

	/**
	 * Unfollow a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unfollow( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Gate symmetrically with follow(): a role denied the follow capability must
		// not be able to mutate follow state via unfollow either.
		$gate = $this->require_cap( 'buddynext-connections/follow' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		buddynext_service( 'follows' )->unfollow( $current_id, $target_id );

		return new WP_REST_Response( array( 'following' => false ), 200 );
	}

	/**
	 * Return the list of followers for a user.
	 *
	 * Bidirectional block list is applied: any user that has blocked the viewer
	 * (or that the viewer has blocked) is omitted so block boundaries hold even
	 * on this public endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_followers( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = (int) $request->get_param( 'id' );
		$viewer_id = get_current_user_id();
		$per_page  = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );

		$service   = buddynext_service( 'follows' );
		$followers = $service->get_followers(
			$user_id,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		return new WP_REST_Response(
			array(
				'ids'      => $this->filter_blocked( $followers, $viewer_id ),
				'total'    => $service->follower_count( $user_id ),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * Return the list of users that a given user follows.
	 *
	 * Same block-list filter as get_followers().
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_following( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = (int) $request->get_param( 'id' );
		$viewer_id = get_current_user_id();
		$per_page  = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );

		$service   = buddynext_service( 'follows' );
		$following = $service->get_following(
			$user_id,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		return new WP_REST_Response(
			array(
				'ids'      => $this->filter_blocked( $following, $viewer_id ),
				'total'    => $service->following_count( $user_id ),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * Drop any ID that has a block relationship with the viewer in either direction.
	 *
	 * For logged-out viewers (viewer_id === 0) the list is returned untouched —
	 * there is no relationship to filter against.
	 *
	 * @param array<int, int> $ids       List of user IDs from the follow service.
	 * @param int             $viewer_id Current user (0 if logged out).
	 * @return array<int, int>
	 */
	private function filter_blocked( array $ids, int $viewer_id ): array {
		if ( $viewer_id <= 0 || empty( $ids ) ) {
			return array_values( array_map( 'intval', $ids ) );
		}

		$blocks = buddynext_service( 'blocks' );

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( int $other_id ) use ( $blocks, $viewer_id ): bool {
					return $other_id === $viewer_id || ! $blocks->is_blocking_either( $viewer_id, $other_id );
				}
			)
		);
	}

	/**
	 * GET /me/follow-requests — list pending follow requests for the
	 * current (private-account) user. Bounded + paginated.
	 *
	 * Response shape: { ids: int[], total: int, page: int, total_pages: int }
	 *
	 * @param WP_REST_Request $request REST request (reads the `page` param).
	 * @return WP_REST_Response
	 */
	public function list_follow_requests( WP_REST_Request $request ): WP_REST_Response {
		$owner_id = get_current_user_id();
		$follows  = buddynext_service( 'follows' );
		$per_page = 200;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		// Bounded + paginated — the inbox never returns thousands of IDs in one array
		// (follow-request bot-flood safety). The true count comes from the dedicated
		// counter so the UI can still show "N pending".
		$ids   = $follows->pending_followers( $owner_id, $per_page, ( $page - 1 ) * $per_page );
		$total = $follows->pending_followers_count( $owner_id );

		return new WP_REST_Response(
			array(
				'ids'         => $ids,
				'total'       => $total,
				'page'        => $page,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
			200
		);
	}

	/**
	 * POST /me/follow-requests/{follower_id}/approve — approve a pending
	 * follow request that landed on the current user's private account.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_follow_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner_id    = get_current_user_id();
		$follower_id = (int) $request->get_param( 'follower_id' );

		$ok = buddynext_service( 'follows' )->approve_follow_request( $owner_id, $follower_id );

		if ( ! $ok ) {
			return new WP_Error(
				'no_pending_request',
				__( 'No pending follow request from that user.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'approved' => true ), 200 );
	}

	/**
	 * POST /me/follow-requests/{follower_id}/reject — reject a pending
	 * follow request. The pending row is deleted outright so the
	 * requester just sees the request disappear; no rejected-history
	 * is kept.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject_follow_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner_id    = get_current_user_id();
		$follower_id = (int) $request->get_param( 'follower_id' );

		$ok = buddynext_service( 'follows' )->reject_follow_request( $owner_id, $follower_id );

		if ( ! $ok ) {
			return new WP_Error(
				'no_pending_request',
				__( 'No pending follow request from that user.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'rejected' => true ), 200 );
	}

	/**
	 * Return follow suggestions for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function get_suggestions(): WP_REST_Response {
		$current_id = get_current_user_id();
		// FollowService::suggestions() already excludes blocked users (either
		// direction), moderation-hidden users, self, and already-followed, so the
		// discovery surface is filtered at the source for every caller.
		$suggestions = buddynext_service( 'follows' )->suggestions( $current_id );

		return new WP_REST_Response( array( 'ids' => $suggestions ), 200 );
	}
}
