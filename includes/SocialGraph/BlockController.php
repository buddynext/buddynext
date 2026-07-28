<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for block and mute relationships.
 *
 * Routes (all under buddynext/v1):
 *   POST   /users/{id}/block — block a user
 *   DELETE /users/{id}/block — unblock a user
 *   POST   /users/{id}/mute  — mute a user
 *   DELETE /users/{id}/mute  — unmute a user
 *   GET    /me/blocked       — list blocked users
 *   GET    /me/muted         — list muted users
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
 * Handles block/mute operations and blocked-users reads.
 */
class BlockController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/block',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'block' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unblock' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/mute',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unmute' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/restrict',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restrict' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unrestrict' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/blocked',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_blocked' ),
				'permission_callback' => array( $this, 'require_auth' ),
				// These three read per_page / page in list_window() and supported
				// ?expand=members through the inherited helper, but declared NO args
				// at all — so nothing was validated, nothing was documented, and a
				// generated client could not see any of it. tests/REST/DeclaredParamsTest
				// exists for exactly this class of drift.
				'args'                => self::relationship_list_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/muted',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_muted' ),
				'permission_callback' => array( $this, 'require_auth' ),
				// These three read per_page / page in list_window() and supported
				// ?expand=members through the inherited helper, but declared NO args
				// at all — so nothing was validated, nothing was documented, and a
				// generated client could not see any of it. tests/REST/DeclaredParamsTest
				// exists for exactly this class of drift.
				'args'                => self::relationship_list_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/me/restricted',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_restricted' ),
				'permission_callback' => array( $this, 'require_auth' ),
				// These three read per_page / page in list_window() and supported
				// ?expand=members through the inherited helper, but declared NO args
				// at all — so nothing was validated, nothing was documented, and a
				// generated client could not see any of it. tests/REST/DeclaredParamsTest
				// exists for exactly this class of drift.
				'args'                => self::relationship_list_args(),
			)
		);
	}

	/**
	 * Block a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = buddynext_service( 'blocks' )->block( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'blocked' => true ), 200 );
	}

	/**
	 * Unblock a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unblock( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unblock( $current_id, $target_id );

		return new WP_REST_Response( array( 'blocked' => false ), 200 );
	}

	/**
	 * Mute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = buddynext_service( 'blocks' )->mute( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'muted' => true ), 200 );
	}

	/**
	 * Unmute a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unmute( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unmute( $current_id, $target_id );

		return new WP_REST_Response( array( 'muted' => false ), 200 );
	}

	/**
	 * Declared args shared by /me/blocked, /me/muted and /me/restricted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relationship_list_args(): array {
		return array(
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'description'       => 'Rows per page.',
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'description'       => 'Page number, 1-based.',
			),
			'expand'   => array(
				'type'        => 'string',
				'description' => 'Comma-separated expansions. `members` returns a `members` array of hydrated rows (display_name, avatar_url) alongside `ids`.',
			),
		);
	}

	/**
	 * Shape a relationship list: ids, plus hydrated rows on ?expand=members.
	 *
	 * The three endpoints returned a bare `{ ids: [12, 45, 77] }` — no names, no
	 * avatars, nothing renderable — so a blocked list could only draw tombstone
	 * rows. The hydration was already built and inherited (maybe_expand_members
	 * -> MemberDirectoryController::hydrate_members, batch-primed, O(1) queries
	 * per page) and simply never called here, while ConnectionController and
	 * FollowController have used it all along.
	 *
	 * Note hydrate_members() deliberately applies no directory-visibility filter.
	 * That is correct here: a member must be able to see, and un-block, someone
	 * who has opted out of the directory.
	 *
	 * @param WP_REST_Request $request   Incoming request.
	 * @param int[]           $ids       Matched user IDs.
	 * @param int             $viewer_id Viewer.
	 * @return array<string, mixed>
	 */
	private function shape_relationship_list( WP_REST_Request $request, array $ids, int $viewer_id ): array {
		$payload = array( 'ids' => $ids );

		$members = $this->maybe_expand_members( $request, $ids, $viewer_id );
		if ( null !== $members ) {
			$payload['members'] = $members;
		}

		return $payload;
	}

	/**
	 * Resolve [limit, offset] from a request's per_page/page params.
	 *
	 * Per_page defaults to 50 and is capped at 100; page is 1-based. Keeps the
	 * three relationship-list endpoints from returning an unbounded set on a
	 * member with thousands of blocks/mutes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0:int,1:int} [limit, offset]
	 */
	private function list_window( WP_REST_Request $request ): array {
		$per_page = absint( $request->get_param( 'per_page' ) );
		$per_page = $per_page > 0 ? min( $per_page, 100 ) : 50;
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		return array( $per_page, ( $page - 1 ) * $per_page );
	}

	/**
	 * Return the list of users blocked by the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_blocked( WP_REST_Request $request ): WP_REST_Response {
		$current_id         = get_current_user_id();
		[ $limit, $offset ] = $this->list_window( $request );
		$blocked            = buddynext_service( 'blocks' )->blocked_users( $current_id, $limit, $offset );

		return new WP_REST_Response( $this->shape_relationship_list( $request, $blocked, $current_id ), 200 );
	}

	/**
	 * Return the list of users muted by the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_muted( WP_REST_Request $request ): WP_REST_Response {
		$current_id         = get_current_user_id();
		[ $limit, $offset ] = $this->list_window( $request );
		$muted              = buddynext_service( 'blocks' )->muted_users( $current_id, $limit, $offset );

		return new WP_REST_Response( $this->shape_relationship_list( $request, $muted, $current_id ), 200 );
	}

	/**
	 * Restrict a user (Instagram-style soft block).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restrict( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();

		// Validate the target exists, consistent with block() and mute() (restrict
		// was the only create action that skipped this check).
		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error(
				'buddynext_user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = buddynext_service( 'blocks' )->restrict( $current_id, $target_id );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'restricted' => true ), 200 );
	}

	/**
	 * Unrestrict a user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unrestrict( WP_REST_Request $request ): WP_REST_Response {
		$target_id  = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		buddynext_service( 'blocks' )->unrestrict( $current_id, $target_id );

		return new WP_REST_Response( array( 'restricted' => false ), 200 );
	}

	/**
	 * Return the list of users restricted by the current user.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_restricted( WP_REST_Request $request ): WP_REST_Response {
		$current_id         = get_current_user_id();
		[ $limit, $offset ] = $this->list_window( $request );
		$restricted         = buddynext_service( 'blocks' )->restricted_users( $current_id, $limit, $offset );

		return new WP_REST_Response( $this->shape_relationship_list( $request, $restricted, $current_id ), 200 );
	}
}
