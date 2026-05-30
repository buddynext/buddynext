<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for the member directory.
 *
 * Powers the reactive members directory (templates/directory/members.php).
 * Returns paginated, filterable JSON the frontend store can use to render
 * the grid without a full page reload.
 *
 * Route (under buddynext/v1):
 *   GET /members
 *     - search        string  Search by display name / login
 *     - sort          string  newest | alphabetical | most_active | online
 *     - relation      string  all | following | connections
 *     - member_type   string  Type slug
 *     - location      string  LIKE match against the location field mirror
 *     - online        bool    Restrict to users active in the last 5 minutes
 *     - cursor        string  Opaque pagination cursor
 *     - per_page      int     1-50 (default 20)
 *
 * Each item in the response includes pre-computed view fields used by the
 * client renderer: display_name, handle, avatar_url, bio_excerpt,
 * profile_url, messages_url, member_type label, follow_status, connection
 * status, mutual_count, is_online.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\Core\PageRouter;
use BuddyNext\MemberTypes\MemberTypeService;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles directory reads over REST.
 */
class MemberDirectoryController {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_members' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'sort'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'newest',
					),
					'relation'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'all',
					),
					'member_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'location'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'online'      => array(
						'type'              => 'boolean',
						'default'           => false,
					),
					'cursor'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'per_page'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 20,
					),
				),
			)
		);
	}

	/**
	 * GET /members handler.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function list_members( WP_REST_Request $request ): WP_REST_Response {
		$viewer_id = get_current_user_id();
		$search    = (string) $request->get_param( 'search' );
		$sort_raw  = (string) $request->get_param( 'sort' );
		$relation  = (string) $request->get_param( 'relation' );
		$type_slug = (string) $request->get_param( 'member_type' );
		$location  = (string) $request->get_param( 'location' );
		$online    = (bool) $request->get_param( 'online' );
		$cursor    = (string) $request->get_param( 'cursor' );
		$per_page  = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );

		$sort_allowed = array( 'newest', 'alphabetical', 'most_active', 'online' );
		$sort         = in_array( $sort_raw, $sort_allowed, true ) ? $sort_raw : 'newest';

		$relation_allowed = array( 'all', 'following', 'connections' );
		$relation         = in_array( $relation, $relation_allowed, true ) ? $relation : 'all';

		$filters = array(
			'search' => $search,
			'sort'   => $sort,
		);

		if ( '' !== $location ) {
			$filters['location'] = $location;
		}

		if ( $online ) {
			$filters['online_only'] = true;
		}

		if ( 'connections' === $relation ) {
			$filters['connection_status'] = 'connections';
		}

		$directory = buddynext_service( 'member_directory' );

		$page = $directory->list_members( $viewer_id, '' === $cursor ? null : $cursor, $per_page, $filters );

		// Resolve following filter post-query (the service only supports connection filtering today).
		if ( 'following' === $relation && $viewer_id > 0 ) {
			$follows       = buddynext_service( 'follows' );
			$followed_ids  = $follows->following( $viewer_id );
			$page['items'] = array_values(
				array_filter(
					(array) $page['items'],
					static function ( $row ) use ( $followed_ids ) {
						return in_array( (int) $row['user_id'], $followed_ids, true );
					}
				)
			);
		}

		// Member-type post-filter (denormalised meta).
		if ( '' !== $type_slug ) {
			$page['items'] = array_values(
				array_filter(
					(array) $page['items'],
					static function ( $row ) use ( $type_slug ) {
						return (string) get_user_meta( (int) $row['user_id'], 'bn_member_type', true ) === $type_slug;
					}
				)
			);
		}

		$items = array_map(
			fn( $row ) => $this->shape_item( $row, $viewer_id ),
			(array) $page['items']
		);

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'next_cursor' => $page['next_cursor'] ?? null,
				'total'       => (int) ( $page['total'] ?? 0 ),
			)
		);
	}

	/**
	 * Shape a service item into the client-facing payload.
	 *
	 * @param array<string, mixed> $row       Raw service row.
	 * @param int                  $viewer_id Current viewer.
	 * @return array<string, mixed>
	 */
	private function shape_item( array $row, int $viewer_id ): array {
		$uid          = (int) ( $row['user_id'] ?? 0 );
		$display_name = (string) ( $row['display_name'] ?? '' );
		$user         = get_user_by( 'id', $uid );
		$login        = $user instanceof \WP_User ? (string) $user->user_login : '';
		$bio          = (string) ( $row['bio'] ?? '' );

		$blocks = buddynext_service( 'blocks' );

		$type_slug = (string) get_user_meta( $uid, 'bn_member_type', true );
		$type_name = '';
		if ( '' !== $type_slug ) {
			$types = buddynext_service( 'member_types' );
			$type  = $types->get_by_slug( $type_slug );
			if ( null !== $type ) {
				$type_name = (string) $type['name'];
			}
		}

		// Follow / connection state — derived once per row.
		$is_following = false;
		$conn_status  = null;
		$is_self      = ( $viewer_id === $uid );
		$messages_url = '';
		$can_interact = ( $viewer_id > 0 && ! $is_self );

		if ( $can_interact ) {
			$follows      = buddynext_service( 'follows' );
			$conns        = buddynext_service( 'connections' );
			$is_following = $follows->is_following( $viewer_id, $uid );
			$conn_status  = $conns->status( $viewer_id, $uid );
			$messages_url = add_query_arg( array( 'recipient' => $uid ), PageRouter::messages_url() );
		}

		// Skip rows where viewer has blocked the user or vice-versa.
		if ( $viewer_id > 0 && $blocks->is_blocking_either( $viewer_id, $uid ) ) {
			$is_following = false;
			$conn_status  = null;
		}

		return array(
			'user_id'        => $uid,
			'display_name'   => $display_name,
			'handle'         => $login,
			'avatar_url'     => (string) ( $row['avatar_url'] ?? '' ),
			'profile_url'    => PageRouter::profile_url( $uid ),
			'messages_url'   => $messages_url,
			'bio_excerpt'    => '' !== $bio ? wp_trim_words( $bio, 18 ) : '',
			'is_online'      => (bool) ( $row['is_online'] ?? false ),
			'follower_count' => (int) ( $row['follower_count'] ?? 0 ),
			'mutual_count'   => (int) ( $row['mutual_connection_count'] ?? 0 ),
			'member_type'    => array(
				'slug' => $type_slug,
				'name' => $type_name,
			),
			'is_self'        => $is_self,
			'can_interact'   => $can_interact,
			'is_following'   => $is_following,
			'connection'     => $this->shape_connection_state( $viewer_id, $uid, $conn_status ),
		);
	}

	/**
	 * Resolve direction-aware connection state for a peer.
	 *
	 * @param int         $viewer_id Viewer user ID.
	 * @param int         $peer_id   Peer user ID.
	 * @param string|null $status    Symmetric status string from ConnectionService.
	 * @return array{state:string,can_message:bool}
	 */
	private function shape_connection_state( int $viewer_id, int $peer_id, ?string $status ): array {
		if ( null === $status || 'declined' === $status || 'withdrawn' === $status ) {
			return array(
				'state'       => 'none',
				'can_message' => false,
			);
		}
		if ( 'accepted' === $status ) {
			return array(
				'state'       => 'accepted',
				'can_message' => true,
			);
		}
		if ( 'pending' === $status ) {
			$conns = buddynext_service( 'connections' );
			$sent  = $conns->pending_sent( $viewer_id );
			if ( in_array( $peer_id, $sent, true ) ) {
				return array(
					'state'       => 'pending-sent',
					'can_message' => false,
				);
			}
			return array(
				'state'       => 'pending-received',
				'can_message' => false,
			);
		}
		return array(
			'state'       => 'none',
			'can_message' => false,
		);
	}
}
