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
use BuddyNext\REST\BaseRestController;
use BuddyNext\SocialGraph\FollowService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles directory reads over REST.
 */
class MemberDirectoryController extends BaseRestController {

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
						'type'    => 'boolean',
						'default' => false,
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

		// Following filter is applied natively inside list_members() (JOIN on
		// bn_follows) so the COUNT/total and cursor reflect only followed users —
		// previously it was post-filtered here, leaving total unfiltered and
		// producing phantom empty pages.
		if ( 'following' === $relation ) {
			$filters['relation'] = 'following';
		}

		if ( '' !== $type_slug ) {
			$filters['member_type'] = $type_slug;
		}

		$directory = buddynext_service( 'member_directory' );

		$page = $directory->list_members( $viewer_id, '' === $cursor ? null : $cursor, $per_page, $filters );

		// Member-type + following + connection filtering are all applied inside
		// list_members() so pagination and totals stay correct.

		$rows = (array) $page['items'];

		// Prime per-row lookups in bulk to avoid an N+1 across the page. One
		// query each primes the user cache (get_user_by), the usermeta cache
		// (member_type), the viewer's following set, the viewer↔peer connection
		// statuses, and the viewer's block relationships.
		$page_ids = array_values(
			array_filter(
				array_map( static fn( $row ) => (int) ( $row['user_id'] ?? 0 ), $rows )
			)
		);

		$following_set  = array();
		$connection_map = array();
		$blocked_either = array();
		if ( $page_ids ) {
			cache_users( $page_ids );
			update_meta_cache( 'user', $page_ids );

			if ( $viewer_id > 0 ) {
				$following_set  = array_fill_keys( buddynext_service( 'follows' )->following( $viewer_id ), true );
				$connection_map = buddynext_service( 'connections' )->statuses_for( $viewer_id, $page_ids );
				$blocked_either = buddynext_service( 'blocks' )->blocking_either_map( $viewer_id, $page_ids );
			}
		}

		$items = array_map(
			fn( $row ) => $this->shape_item( $row, $viewer_id, $following_set, $connection_map, $blocked_either ),
			$rows
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
	 * @param array<string, mixed> $row            Raw service row.
	 * @param int                  $viewer_id      Current viewer.
	 * @param array<int, true>     $following_set  Viewer→followed lookup (uid keyed).
	 * @param array<int, string>   $connection_map Viewer↔peer status, peer-uid keyed.
	 * @param array<int, true>     $blocked_either Peers in a block relationship with the viewer.
	 * @return array<string, mixed>
	 */
	private function shape_item( array $row, int $viewer_id, array $following_set = array(), array $connection_map = array(), array $blocked_either = array() ): array {
		$uid          = (int) ( $row['user_id'] ?? 0 );
		$display_name = (string) ( $row['display_name'] ?? '' );
		$user         = get_user_by( 'id', $uid );
		$login        = $user instanceof \WP_User ? (string) $user->user_login : '';
		$bio          = (string) ( $row['bio'] ?? '' );

		$type_slug = (string) get_user_meta( $uid, 'bn_member_type', true );
		$type_name = '';
		$type_icon = '';
		if ( '' !== $type_slug ) {
			$types = buddynext_service( 'member_types' );
			$type  = $types->get_by_slug( $type_slug );
			if ( null !== $type ) {
				$type_name = (string) $type['name'];
				// Re-sanitised on output so the JS card can render the badge icon
				// (the directory grid re-renders client-side) exactly like the
				// server member-card.php.
				$type_icon = MemberTypeService::render_icon_svg( (string) ( $type['icon_svg'] ?? '' ) );
			}
		}

		// Follow / connection state — read from the page-level maps primed in
		// list_members(), so no per-row query is issued here.
		$is_following = false;
		$conn_status  = null;
		$is_self      = ( $viewer_id === $uid );
		$messages_url = '';
		$can_interact = ( $viewer_id > 0 && ! $is_self );

		if ( $can_interact ) {
			$is_following = isset( $following_set[ $uid ] );
			$conn_status  = $connection_map[ $uid ] ?? null;
			$messages_url = add_query_arg( array( 'recipient' => $uid ), PageRouter::messages_url() );
		}

		// Skip rows where viewer has blocked the user or vice-versa.
		$is_blocked_pair = ( $viewer_id > 0 && isset( $blocked_either[ $uid ] ) );
		if ( $is_blocked_pair ) {
			$is_following = false;
			$conn_status  = null;
		}

		// Privacy-gated affordances — mirror PrivacyService::can_follow/can_connect
		// so the JS-rendered card hides the same CTAs the server template does
		// (who_can_follow 'nobody' or who_can_connect 'nobody'/'followers' must not
		// offer an action the target forbids). Reads the page-primed usermeta, so
		// no per-row query is added.
		$can_follow  = false;
		$can_connect = false;
		if ( $can_interact && ! $is_blocked_pair ) {
			$privacy = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
			if ( $privacy && method_exists( $privacy, 'get_preference' ) ) {
				$can_follow   = 'everyone' === $privacy->get_preference( $uid, 'who_can_follow' );
				$connect_pref = $privacy->get_preference( $uid, 'who_can_connect' );
				$can_connect  = 'everyone' === $connect_pref
					|| ( 'followers' === $connect_pref && $is_following );
			} else {
				$can_follow  = true;
				$can_connect = true;
			}
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
				'slug'     => $type_slug,
				'name'     => $type_name,
				'icon_svg' => $type_icon,
			),
			'is_self'        => $is_self,
			'can_interact'   => $can_interact,
			'can_follow'     => $can_follow,
			'can_connect'    => $can_connect,
			'is_following'   => $is_following,
			'connection'     => $this->shape_connection_state( $conn_status ),
		);
	}

	/**
	 * Resolve direction-aware connection state for a peer.
	 *
	 * @param string|null $status Direction-aware status from ConnectionService::statuses_for()
	 *                            (accepted / pending-sent / pending-received / …), or null.
	 * @return array{state:string,can_message:bool}
	 */
	private function shape_connection_state( ?string $status ): array {
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
		// Direction comes from the page-level statuses_for() map (pending-sent /
		// pending-received), so there is no per-row pending_sent() query. A bare
		// 'pending' (defensive fallback) is treated as received.
		if ( 'pending-sent' === $status ) {
			return array(
				'state'       => 'pending-sent',
				'can_message' => false,
			);
		}
		if ( 'pending-received' === $status || 'pending' === $status ) {
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
