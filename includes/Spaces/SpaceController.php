<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for spaces.
 *
 * Routes (all under buddynext/v1):
 *   GET    /spaces                              — list spaces (public)
 *   POST   /spaces                              — create a space (auth required)
 *   GET    /spaces/{id}                         — get a space (public)
 *   PUT    /spaces/{id}                         — update a space (owner only)
 *   DELETE /spaces/{id}                         — delete a space (owner only)
 *   GET    /spaces/{id}/members                 — list members (public for open/private)
 *   GET    /spaces/{id}/pending-requests        — list pending join requests (owner/mod only)
 *   POST   /spaces/{id}/join                    — join or request-to-join (auth required)
 *   POST   /spaces/{id}/leave                   — leave a space (auth required)
 *   POST   /spaces/{id}/invite                  — invite a user (owner/mod only)
 *   POST   /spaces/{id}/approve-request         — approve a pending request (owner/mod only)
 *
 * Space bans live on the canonical plural routes in ModerationController
 * (POST/GET /spaces/{id}/bans, DELETE /spaces/{id}/bans/{user_id}); the old
 * singular /ban routes that duplicated them were removed.
 *
 * Join semantics by space type:
 *   Open    → status='active' immediately, returns {joined: true}
 *   Private → status='pending' (request), returns {requested: true}
 *   Secret  → invite-only; 403 unless user has a pending 'invited' status
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\REST\BaseRestController;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Media\MediaClient;
use BuddyNext\Notifications\NotificationService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles space lifecycle and membership over REST.
 */
class SpaceController extends BaseRestController {

	/**
	 * Members decidable in one bulk join-request call.
	 *
	 * A collapsed notification row represents a handful of people; this is a
	 * ceiling on the write loop, not a target.
	 */
	private const BULK_DECISION_MAX = 100;

	/**
	 * Register the controller's routes.
	 */
	/**
	 * The directory's query contract.
	 *
	 * GET /spaces read TWELVE parameters while declaring none, so openapi.json --
	 * which is generated from the route registry -- advertised the whole spaces
	 * directory as unpaginated and unfilterable. A client reading route truth had no
	 * way to discover per_page, page, orderby, type, category_id or paginate, and
	 * nothing sanitised any of them.
	 *
	 * Two deliberate omissions:
	 *
	 * No `enum` on `type`. Space types are registry-driven and custom types are
	 * registrable over REST, so a fixed list here would reject a type the site
	 * legitimately has.
	 *
	 * No `enum` on `orderby`/`order` either, and no validate_callback anywhere in
	 * this block -- which is the honest choice rather than a lazy one. Core validates
	 * an arg only when it carries an explicit validate_callback
	 * (WP_REST_Request::has_valid_params()), and adding one here would turn today's
	 * silent fallback into a 400 for existing callers. SpaceService already
	 * allow-lists orderby before it reaches ORDER BY and falls back to member_count,
	 * so the value is defended; declaring an enum we do not enforce would just be
	 * documentation that reads as a guarantee. Describe what is accepted; do not
	 * claim to reject.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function directory_args(): array {
		return array(
			'per_page'          => array(
				'type'              => 'integer',
				'default'           => 20,
				'description'       => 'Spaces per page.',
				'sanitize_callback' => 'absint',
			),
			'page'              => array(
				'type'              => 'integer',
				'default'           => 1,
				'description'       => '1-based page number.',
				'sanitize_callback' => 'absint',
			),
			'paginate'          => array(
				'type'        => 'string',
				'description' => 'Set to 1/true/yes to wrap rows with total + total_pages. Ignored on the search path -- see list_spaces().',
			),
			'orderby'           => array(
				'type'              => 'string',
				'description'       => 'member_count | name | created_at, or an alias: popular, active, newest, alphabetical. NOTE: active is an alias for member_count, not recency.',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'             => array(
				'type'              => 'string',
				'description'       => 'ASC or DESC.',
				'sanitize_callback' => 'sanitize_key',
			),
			'type'              => array(
				'type'              => 'string',
				'description'       => 'Space type slug. Registry-driven, so custom types are accepted.',
				'sanitize_callback' => 'sanitize_key',
			),
			'category_id'       => array(
				'type'              => 'integer',
				'description'       => 'Filter by space category.',
				'sanitize_callback' => 'absint',
			),
			'search'            => array(
				'type'              => 'string',
				'description'       => 'Free-text search. Takes precedence over q.',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'q'                 => array(
				'type'              => 'string',
				'description'       => 'Alias for search.',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'mine'              => array(
				'type'        => 'string',
				'description' => '1/true/yes to scope to spaces the viewer belongs to.',
			),
			'membership'        => array(
				'type'              => 'string',
				'description'       => 'manage = owned/moderated, joined = plain membership. Implies the member scope.',
				'sanitize_callback' => 'sanitize_key',
			),
			'include_subspaces' => array(
				'type'        => 'string',
				'description' => '1/true/yes to include sub-spaces in the listing.',
			),
		);
	}

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/spaces',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_spaces' ),
					'permission_callback' => '__return_true',
					'args'                => $this->directory_args(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_space' ),
					'permission_callback' => array( $this, 'require_space_creation_role' ),
					'args'                => $this->create_space_args(),
				),
			)
		);

		// Suggested spaces for the current viewer (ranked discovery). Auth-required —
		// suggestions are per-viewer. Registered before '/spaces/(?P<id>\d+)' so the
		// literal 'suggestions' segment is unambiguous.
		register_rest_route(
			'buddynext/v1',
			'/spaces/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'suggested_spaces' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 6,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Space field definitions (the form schema the app + web render from). Public
		// read — values are per-space and gated on GET /spaces/{id}. Registered
		// before the {id} route, but '\d+' means 'fields' can never match it anyway.
		register_rest_route(
			'buddynext/v1',
			'/spaces/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_field_definitions' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/fields',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_space_fields' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_space' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_members' ),
				'permission_callback' => '__return_true',
				'args'                => $this->member_pagination_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/media',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_media' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'description'       => 'Page of media-bearing posts.',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 24,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'description'       => 'Media-bearing posts per page. Each post may carry several attachments.',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/subspaces',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_subspaces' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/pending-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pending_requests' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => $this->member_pagination_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/join',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'join_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'leave_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'leave_space' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/join/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_join_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/invite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'invite_member' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Spec-conformant approve/decline endpoints: POST /spaces/{id}/members/{user_id}/approve|decline.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/decline',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decline_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Bulk decision on a space's pending join requests. Exists because the
		// notifications screen collapses "8 people asked to join Design Guild"
		// into one row, and that row needs ONE call - looping the per-member
		// endpoint from the browser would just move an N-round-trip fan-out to
		// the client.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/decide-bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide_requests_bulk' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'user_ids' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'decision' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn ( $v ): bool => in_array( (string) $v, array( 'approve', 'decline' ), true ),
					),
				),
			)
		);

		// Legacy approve-request endpoint (kept for backwards compatibility).
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/approve-request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve_request' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)/role',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'change_member_role' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/members/(?P<user_id>[\d]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_member' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/transfer-ownership',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'transfer_ownership' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Spec-conformant alias used by space-home action row.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/transfer',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'transfer_ownership' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Archive (POST) / restore (DELETE) a space. The owner/admin check lives
		// in SpaceService::archive(); auth is required to reach it.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/archive',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'archive_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unarchive_space' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		// Per-space notification preference for the current user.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/notification-pref',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_notification_pref' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_notification_pref' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		// PUT alias for permissions (general settings PUT already exists on
		// /spaces/{id}); this provides an explicit permissions-only endpoint.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/permissions',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_permissions' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Space avatar (icon). Multipart upload routed through ImageStorageService
		// — per-owner WebP variations in uploads/bn-space-avatars/{id}/, never a
		// WP attachment. DELETE removes the folder and clears the column.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/avatar',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_space_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_space_avatar' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		// Space cover. Multipart upload routed through ImageStorageService —
		// per-owner WebP variations in uploads/bn-space-covers/{id}/.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/cover',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_space_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_space_cover' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);

		// Unlink a media item from a space drive. A space owner/moderator removes a
		// member's contributed item from the space WITHOUT deleting it — MediaVerse
		// moves it back to the member's own personal drive as private. The inverse
		// of the contribute path (commit 4186355f) that lands member media on the
		// space drive. Auth here, moderator gate in the handler.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/media/(?P<media_id>[\d]+)/unlink',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unlink_space_media' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		// Lightweight lookup the media lightbox calls on open to decide whether to
		// offer the moderator "Unlink from space" item: is this media on a space
		// drive, and may the viewer moderate that space? Keeps the unlink control
		// out of the menu everywhere it would only ever fail.
		register_rest_route(
			'buddynext/v1',
			'/media/(?P<media_id>[\d]+)/space-context',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'media_space_context' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Whether the current viewer may unlink a media item from its space.
	 *
	 * Returns the item's space id (when it lives on a space drive) and whether the
	 * viewer may unlink it — its owner or a space moderator, the same gate
	 * unlink_space_media enforces. The lightbox uses it to show the "Remove from
	 * space" control only where the action would actually be allowed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function media_space_context( WP_REST_Request $request ): WP_REST_Response {
		$media_id = (int) $request->get_param( 'media_id' );
		$user_id  = get_current_user_id();
		$repo     = MediaClient::repo();

		$space_id   = 0;
		$can_unlink = false;
		if ( null !== $repo && 'space' === (string) $repo->get( $media_id, 'drive_type' ) ) {
			$drive_id = (int) $repo->get( $media_id, 'drive_id' );
			if ( $drive_id > 0 ) {
				// Owner (reclaiming their own) or moderator — the same pair
				// unlink_space_media enforces.
				$owner_id  = (int) $repo->get( $media_id, 'post_author' );
				$role      = ( new SpaceMemberService() )->get_role( $drive_id, $user_id );
				$is_author = $owner_id > 0 && $owner_id === $user_id;
				if ( $is_author || SpaceRoles::can_moderate( $role, $user_id ) ) {
					$space_id   = $drive_id;
					$can_unlink = true;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'space_id'   => $space_id,
				'can_unlink' => $can_unlink,
			),
			200
		);
	}

	/**
	 * Return the current user's per-space notification preference.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_notification_pref( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$pref     = ( new SpaceMemberService() )->get_notification_pref( $space_id, $user_id );

		return new WP_REST_Response( array( 'pref' => $pref ), 200 );
	}

	/**
	 * Update the current user's per-space notification preference.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_notification_pref( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$pref     = sanitize_key( (string) ( $request->get_param( 'pref' ) ?? '' ) );

		$result = ( new SpaceMemberService() )->set_notification_pref( $space_id, $user_id, $pref );

		if ( is_wp_error( $result ) ) {
			$status = ( 'invalid_pref' === $result->get_error_code() ) ? 422 : 403;
			$result->add_data( array( 'status' => $status ) );
			return $result;
		}

		return new WP_REST_Response( array( 'pref' => $pref ), 200 );
	}

	/**
	 * Update space permissions (delegates to update_space with whitelisted fields).
	 *
	 * Permissions stored as wp_options under bn_space_<id>_<key>:
	 *   - require_join_approval
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_permissions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can change permissions.', 'buddynext' ), array( 'status' => 403 ) );
		}

		$bools = array(
			'require_join_approval' => 'require_join_approval',
		);
		foreach ( $bools as $key => $opt ) {
			$param = $request->get_param( $key );
			if ( null !== $param ) {
				update_space_meta( $space_id, $opt, $param ? '1' : '0' );
			}
		}

		return new WP_REST_Response(
			array(
				'require_join_approval' => (int) buddynext_get_space_field( $space_id, 'require_join_approval' ),
			),
			200
		);
	}

	/**
	 * Unlink a media item from a space drive (keep the owner's copy).
	 *
	 * The item's OWNER (reclaiming their own contribution) or a space moderator
	 * removes an item from the space WITHOUT deleting it: MediaVerse moves the row
	 * back to its owner's own personal drive as private, so the member keeps their
	 * file — it is simply no longer associated with the space, and they delete it
	 * from their own Files if they want it gone. The inverse of the contribute path
	 * that lands member uploads on the space drive (commit 4186355f).
	 *
	 * Unlink is a DRIVE action, not a moderation strike and not a post edit: it
	 * touches only the media's drive association, never a post that embedded it. A
	 * post in the space that referenced the item keeps its reference; the item is
	 * now private, so it shows to its owner and fails the visibility gate for
	 * others — the correct consequence of the owner reclaiming it.
	 *
	 * Refuses unless the item is actually on THIS space's drive, so the action can
	 * never move media that belongs to another space or a user's own drive.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unlink_space_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$media_id = (int) $request->get_param( 'media_id' );
		$user_id  = get_current_user_id();

		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$repo = MediaClient::repo();
		if ( null === $repo ) {
			return new WP_Error( 'media_unavailable', __( 'Media is unavailable.', 'buddynext' ), array( 'status' => 503 ) );
		}

		// The item must currently live on THIS space's drive, or there is nothing
		// to unlink here — refuse rather than silently move an unrelated item.
		$drive_type = (string) $repo->get( $media_id, 'drive_type' );
		$drive_id   = (int) $repo->get( $media_id, 'drive_id' );
		if ( 'space' !== $drive_type || $drive_id !== $space_id ) {
			return new WP_Error( 'not_on_space_drive', __( 'This item is not on this space\'s drive.', 'buddynext' ), array( 'status' => 409 ) );
		}

		$owner_id = (int) $repo->get( $media_id, 'post_author' );

		// Two people may pull an item off a space drive: its OWNER, reclaiming their
		// own contribution, and a space moderator, removing someone else's. A plain
		// member may not touch another member's file. Same authority the Files tab
		// and the media lightbox draw their Remove control from.
		$role      = ( new SpaceMemberService() )->get_role( $space_id, $user_id );
		$is_author = $owner_id > 0 && $owner_id === $user_id;
		if ( ! $is_author && ! SpaceRoles::can_moderate( $role, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot remove this item from the space.', 'buddynext' ), array( 'status' => 403 ) );
		}

		if ( $owner_id <= 0 ) {
			return new WP_Error( 'unlink_failed', __( 'Could not resolve the item owner.', 'buddynext' ), array( 'status' => 500 ) );
		}

		// Return the item to its owner's personal drive as private, via MediaVerse's
		// own index writer (set_many writes drive_type/drive_id/privacy atomically
		// and fires mvs_media_privacy_changed). The item was just confirmed to exist
		// on this space's drive, so this is an update, never a partial insert. Keeps
		// the member's copy; removes the space's. Item is on the space drive, so it
		// exists — no new MediaVerse code, just its existing API.
		$repo->set_many(
			$media_id,
			array(
				'drive_type' => 'user',
				'drive_id'   => $owner_id,
				'privacy'    => 'private',
			)
		);

		// Tell the owner their item left the space (they keep it). Skipped when a
		// moderator unlinks their own upload. A native BuddyNext event, so the pref
		// catalogue — not a collect-only rule — decides whether it also emails.
		if ( $owner_id > 0 && $owner_id !== $user_id ) {
			( new NotificationService() )->create(
				array(
					'recipient_id' => $owner_id,
					'sender_id'    => $user_id,
					'type'         => 'bn.space_media_unlinked',
					'object_type'  => 'space',
					'object_id'    => $space_id,
					'group_key'    => "space_media_unlinked_{$space_id}_{$owner_id}",
					'data'         => array(
						'space_id' => $space_id,
						'media_id' => $media_id,
					),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'unlinked' => true,
				'media_id' => $media_id,
				'space_id' => $space_id,
			),
			200
		);
	}

	// ── Space CRUD ──────────────────────────────────────────────────────────

	/**
	 * List spaces with optional filters.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function list_spaces( WP_REST_Request $request ): WP_REST_Response {
		$per_page_param = $request->get_param( 'per_page' );
		$page_param     = $request->get_param( 'page' );
		$orderby_param  = $request->get_param( 'orderby' );
		$order_param    = $request->get_param( 'order' );

		// Cap per_page at 50 (story requirement); default 12.
		$per_page = absint( null !== $per_page_param ? $per_page_param : 12 );
		if ( 0 === $per_page ) {
			$per_page = 12;
		}
		$per_page = min( 50, $per_page );

		// Map directory-friendly sort aliases (popular/active/newest/alphabetical)
		// onto the underlying service contract (orderby + order).
		$orderby = sanitize_key( (string) ( null !== $orderby_param ? $orderby_param : 'member_count' ) );
		$order   = sanitize_key( (string) ( null !== $order_param ? $order_param : 'DESC' ) );

		$sort_alias_map = array(
			'popular'      => array( 'member_count', 'DESC' ),
			'active'       => array( 'member_count', 'DESC' ),
			'newest'       => array( 'created_at', 'DESC' ),
			'alphabetical' => array( 'name', 'ASC' ),
		);
		if ( isset( $sort_alias_map[ $orderby ] ) ) {
			list( $orderby, $order ) = $sort_alias_map[ $orderby ];
		}

		$args = array(
			'per_page' => $per_page,
			'page'     => absint( null !== $page_param ? $page_param : 1 ),
			'orderby'  => $orderby,
			'order'    => $order,
		);

		// Viewer context drives secret-space visibility: site admins see every
		// space; other viewers see unlisted spaces only when they own them or
		// hold an active membership. Mirrors templates/spaces/directory.php.
		$viewer           = get_current_user_id();
		$args['viewer']   = $viewer;
		$args['is_admin'] = current_user_can( 'manage_options' );

		$type_param = $request->get_param( 'type' );
		if ( null !== $type_param && '' !== (string) $type_param ) {
			$type_value = sanitize_key( (string) $type_param );
			if ( SpaceTypeRegistry::instance()->is_valid( $type_value ) ) {
				$args['type'] = $type_value;
				// Secret-type chip: anonymous viewers get nothing; admins see all
				// (handled by is_admin in the service); members/owners are scoped
				// to their own spaces by the service via the viewer arg.
				if ( ! SpaceTypeRegistry::instance()->is_listed( $type_value ) && 0 === $viewer ) {
					return new WP_REST_Response( array(), 200 );
				}
			}
		}

		if ( null !== $request->get_param( 'category_id' ) ) {
			$args['category_id'] = absint( $request->get_param( 'category_id' ) );
		}

		// "My Spaces" scope — restrict to spaces the viewer owns or actively
		// belongs to. The service's `member` arg INNER JOINs active memberships
		// (which include the owner row written at creation). Logged-in only.
		$mine_param = $request->get_param( 'mine' );
		if ( $viewer > 0 && null !== $mine_param && in_array( (string) $mine_param, array( '1', 'true', 'yes' ), true ) ) {
			$args['member'] = $viewer;
		}

		// Relationship filter on the member scope: 'managed' = spaces the viewer
		// owns or moderates, 'joined' = plain membership. Implies the member scope,
		// so a client can request just one bucket (each with its own pagination).
		// The response also carries `viewer_role` per space for client-side grouping.
		$membership_param = $request->get_param( 'membership' );
		if ( $viewer > 0 && in_array( (string) $membership_param, array( 'managed', 'joined' ), true ) ) {
			$args['member']      = $viewer;
			$args['member_role'] = 'managed' === (string) $membership_param ? 'manage' : 'joined';
		}

		// Top-level browse shows root spaces only — sub-spaces are reached from
		// their parent. Mirrors templates/spaces/directory.php so SSR + REST match.
		// "My Spaces" and search still surface sub-spaces; the include_subspaces
		// opt-in flattens the All view to the full list.
		$bn_include_subspaces = in_array( (string) $request->get_param( 'include_subspaces' ), array( '1', 'true', 'yes' ), true );
		if ( ! isset( $args['member'] ) && ! $bn_include_subspaces ) {
			$args['roots_only'] = true;
		}

		$search_param = null !== $request->get_param( 'search' )
			? sanitize_text_field( (string) $request->get_param( 'search' ) )
			: ( null !== $request->get_param( 'q' )
				? sanitize_text_field( (string) $request->get_param( 'q' ) )
				: '' );

		// Opt-in pagination metadata for the reactive directory: paginate=1 wraps the
		// rows with a total + total_pages (so the client can rebuild its pager for the
		// filtered set). Default + the search path stay a bare array (back-compat).
		$bn_paginate = in_array( (string) $request->get_param( 'paginate' ), array( '1', 'true', 'yes' ), true );

		if ( '' === $search_param && $bn_paginate ) {
			$result = ( new SpaceService() )->list_spaces_with_total( $args );
			$items  = $this->enrich_directory_rows( (array) ( $result['items'] ?? array() ), $viewer );
			$total  = (int) ( $result['total'] ?? 0 );

			return new WP_REST_Response(
				array(
					'items'       => $items,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
					'page'        => absint( null !== $page_param ? $page_param : 1 ),
				),
				200
			);
		}

		if ( '' !== $search_param ) {
			$spaces = ( new SpaceService() )->search( $search_param, $args );
		} else {
			$spaces = ( new SpaceService() )->list_spaces( $args );
		}

		$spaces = $this->enrich_directory_rows( (array) $spaces, $viewer );

		return new WP_REST_Response( $spaces, 200 );
	}

	/**
	 * GET /spaces/suggestions — ranked suggested spaces for the current viewer.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function suggested_spaces( WP_REST_Request $request ): WP_REST_Response {
		$viewer = get_current_user_id();
		$limit  = absint( $request->get_param( 'limit' ) );
		$limit  = $limit > 0 ? min( 24, $limit ) : 6;

		$rows = ( new SpaceSuggestionService() )->suggest( $viewer, $limit );
		foreach ( $rows as &$bn_row ) {
			unset( $bn_row['_bn_score'] ); // Internal ranking field — not part of the API.
		}
		unset( $bn_row );

		return new WP_REST_Response( $this->enrich_directory_rows( $rows, $viewer ), 200 );
	}

	/**
	 * Enrich directory rows with the category label/slug and the viewer's
	 * membership state, so the reactive JS card builder can render the same
	 * card as templates/spaces/directory.php (category line, membership-aware
	 * CTA) instead of a stripped-down "always Join" card.
	 *
	 * @param array $rows      Hydrated space rows from the service.
	 * @param int   $viewer_id Current viewer user ID (0 when logged out).
	 * @return array Rows with category_name, category_slug and membership_* keys.
	 */
	private function enrich_directory_rows( array $rows, int $viewer_id ): array {
		if ( empty( $rows ) ) {
			return $rows;
		}

		global $wpdb;

		$space_ids = array_map( static fn( $r ) => (int) ( $r['id'] ?? 0 ), $rows );
		$space_ids = array_values( array_filter( $space_ids ) );
		if ( empty( $space_ids ) ) {
			return $rows;
		}

		$id_in = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );

		// Category name/slug per space. $id_in is a "%d,%d,..." string built from
		// array_fill( count( $space_ids ) ), bound through ...$space_ids; the literal
		// placeholders live inside it, so the analyser reports UnfinishedPrepare.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$cat_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS space_id, c.name AS category_name, c.slug AS category_slug
				 FROM {$wpdb->prefix}bn_spaces s
				 LEFT JOIN {$wpdb->prefix}bn_space_categories c ON c.id = s.category_id
				 WHERE s.id IN ( {$id_in} )",
				...$space_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$cat_map = array();
		foreach ( (array) $cat_rows as $cr ) {
			$cat_map[ (int) $cr->space_id ] = array(
				'category_name' => $cr->category_name,
				'category_slug' => $cr->category_slug,
			);
		}

		// Viewer membership per space.
		$member_map = array();
		if ( $viewer_id > 0 ) {
			$m_args = array_merge( array( $viewer_id ), $space_ids );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$m_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT space_id, role, status FROM {$wpdb->prefix}bn_space_members
					 WHERE user_id = %d AND space_id IN ( {$id_in} )",
					...$m_args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $m_rows as $mr ) {
				$member_map[ (int) $mr->space_id ] = array(
					'role'   => (string) $mr->role,
					'status' => (string) $mr->status,
				);
			}
		}

		// Visibility-scoped sub-space count per card (one grouped query for the page),
		// so the directory can surface "N sub-spaces" pre-join without a per-card count.
		$subspace_counts = ( new SpaceService() )->count_visible_subspaces_for(
			$space_ids,
			$viewer_id,
			current_user_can( 'manage_options' )
		);

		$tones = \BuddyNext\Profile\AvatarService::IDENTITY_TONES;

		// One instance for the whole page rather than one per row.
		$member_svc = new SpaceMemberService();

		// Prime the two caches the per-row permission flags below actually read,
		// so the page batch does the job it was written for (card 10130355643):
		// - bn_space meta (who_can_invite, consulted by can_invite()) in one
		// query for the whole page instead of one per row;
		// - the viewer's role_{space}_{user} entries from $member_map, including
		// the misses as '' - "not an active member" is an answer, and caching
		// it is what stops every non-member row re-querying. Without this the
		// batch fed a local array only and each row cost ~3 uncached
		// permission resolutions (can_invite + two buddynext_can flags) on a
		// surface designed for 20-30k spaces per site.
		update_meta_cache( 'bn_space', $space_ids );
		if ( $viewer_id > 0 ) {
			$viewer_roles = array();
			foreach ( $space_ids as $prime_sid ) {
				$prime_row                        = $member_map[ (int) $prime_sid ] ?? null;
				$viewer_roles[ (int) $prime_sid ] =
					( null !== $prime_row && 'active' === $prime_row['status'] ) ? $prime_row['role'] : '';
			}
			$member_svc->prime_viewer_roles( $viewer_id, $viewer_roles );
		}

		foreach ( $rows as &$row ) {
			$sid                   = (int) ( $row['id'] ?? 0 );
			$row['category_name']  = $cat_map[ $sid ]['category_name'] ?? null;
			$row['category_slug']  = $cat_map[ $sid ]['category_slug'] ?? null;
			$row['subspace_count'] = (int) ( $subspace_counts[ $sid ] ?? 0 );
			$row['cover_tone']     = $tones[ $sid % count( $tones ) ];
			$row['type_label']     = SpaceService::type_label( (string) ( $row['type'] ?? 'open' ) );
			$row['type_tone']      = SpaceTypeRegistry::instance()->tone( (string) ( $row['type'] ?? 'open' ) );
			$row['join_method']    = SpaceTypeRegistry::instance()->join_method( (string) ( $row['type'] ?? 'open' ) );

			$membership               = $member_map[ $sid ] ?? null;
			$row['membership_role']   = $membership['role'] ?? '';
			$row['membership_status'] = $membership['status'] ?? '';

			// Viewer-relative permission flags. Without these the app inferred
			// rights from membership_role rank, which under-shows for a site admin
			// who is not a member of the space, and for spaces whose owner set
			// who_can_invite = members.
			//
			// Two management flags, not one, because "manage" is two tiers here:
			// can_manage     -> may reach the Manage screen (owner+mod, admins too)
			// can_edit_space -> may write identity/reach/structure (OWNER only)
			// One boolean would promise the app a Manage screen whose name/type/
			// rules fields then 403 on save.
			$row['can_invite']     = $viewer_id > 0 && $member_svc->can_invite( $sid, $viewer_id );
			$row['can_manage']     = $viewer_id > 0 && buddynext_can( $viewer_id, 'buddynext-spaces/manage-settings', array( 'space_id' => $sid ) );
			$row['can_edit_space'] = $viewer_id > 0 && buddynext_can( $viewer_id, 'buddynext-manage-space', array( 'space_id' => $sid ) );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Permission gate for space creation.
	 *
	 * Enforces the Settings → Spaces → "Who can create spaces" setting
	 * (buddynext_space_creation_role): 'member' = any logged-in user, 'admin' =
	 * site administrators only. Defaults to 'member'.
	 *
	 * @return true|WP_Error
	 */
	public function require_space_creation_role(): bool|WP_Error {
		$auth = $this->require_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		// Single source of truth: the role map (Roles & Capabilities tab). The
		// legacy buddynext_space_creation_role option is folded into the map in
		// PermissionService::get_role_map(), so an existing "admins only" setting
		// is preserved while the Roles tab toggle now actually governs creation.
		return $this->require_cap( 'buddynext-spaces/create' );
	}

	/**
	 * Declared schema for POST /spaces.
	 *
	 * This route declared NO args until 1.1.6, so `OPTIONS /buddynext/v1/spaces`
	 * returned an empty argument list: the discovery document told an integrator
	 * nothing, and there was no way to learn the parameter names except by
	 * reading the source. That is how a caller ends up sending `visibility` and
	 * never finding out it was ignored.
	 *
	 * The type enum is asked of SpaceTypeRegistry rather than written out, so a
	 * site that registers a custom space type gets it in the schema instead of
	 * having its own type rejected by a hardcoded list.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function create_space_args(): array {
		$types        = SpaceTypeRegistry::instance()->keys();
		$visibilities = array_values(
			array_unique(
				array_map(
					static fn( string $t ): string => SpaceTypeRegistry::instance()->visibility( $t ),
					$types
				)
			)
		);

		return array(
			'name'        => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'Space name. Required, 100 characters or fewer.', 'buddynext' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'URL slug. Derived from the name when omitted.', 'buddynext' ),
			),

			/*
			 * Deliberately no `enum` on either of these.
			 *
			 * An enum hands validation to the REST schema layer, which rejects a bad
			 * value with 400 rest_invalid_param and its own body. This endpoint has
			 * always answered an invalid type with 422 and a per-field `params` map
			 * — the same shape it uses for name, slug and parent_id. Declaring the
			 * enum swapped both the status and the body for one field, which is a
			 * contract break for any client already handling the 422, and
			 * SpaceControllerTest::test_create_space_invalid_type_returns_422 caught
			 * it. The accepted values are named in the descriptions so discovery
			 * still tells an integrator what to send; validation stays where this
			 * endpoint's convention already lives.
			 */
			'type'        => array(
				'type'        => 'string',
				'description' => sprintf(
					/* translators: %s: comma-separated list of accepted space types. */
					__( 'Space type. Canonical parameter; defaults to the site default. One of: %s.', 'buddynext' ),
					implode( ', ', $types )
				),
			),
			'visibility'  => array(
				'type'        => 'string',
				'description' => sprintf(
					/* translators: %s: comma-separated list of accepted visibilities. */
					__( 'Alias for `type`, resolved to the type carrying that visibility. Sending both with different meanings is refused. One of: %s.', 'buddynext' ),
					implode( ', ', $visibilities )
				),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'Space description.', 'buddynext' ),
			),
			'category_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Space category id.', 'buddynext' ),
			),
			'parent_id'   => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Parent space id, to create a sub-space.', 'buddynext' ),
			),
		);
	}

	/**
	 * The space type this request is asking for — from `type`, or `visibility`.
	 *
	 * `type` is canonical. `visibility` is accepted because it names a real
	 * property of a type in SpaceTypeRegistry (the `open` type has visibility
	 * `public`), so an integrator reaching for it is not guessing — and until
	 * 1.1.6 the handler read only `type` and DISCARDED `visibility` in silence.
	 * A caller asking for a private space got the site default, which on a
	 * default install is `open`: the space they created to be private was
	 * publicly readable and nothing said so. That is a privacy incident, not a
	 * naming quibble, which is why the alias exists rather than the parameter
	 * simply being documented.
	 *
	 * Mapped through the registry rather than by string equality — `visibility:
	 * public` is the `open` type, and treating the two vocabularies as identical
	 * would send that straight back to the invalid-type fallback this fixes.
	 *
	 * Sending BOTH with different meanings is refused rather than resolved. There
	 * is no correct guess between "type: open" and "visibility: private", and
	 * picking one silently is the exact failure being fixed here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error Type slug, or WP_Error on a contradictory request.
	 */
	private function resolve_requested_type( WP_REST_Request $request ): string|WP_Error {
		$type_raw       = $request->get_param( 'type' );
		$visibility_raw = $request->get_param( 'visibility' );

		$from_type = null !== $type_raw ? sanitize_key( (string) $type_raw ) : '';

		$from_visibility = '';
		if ( null !== $visibility_raw && '' !== (string) $visibility_raw ) {
			$from_visibility = SpaceTypeRegistry::instance()->type_for_visibility( (string) $visibility_raw );
			if ( '' === $from_visibility ) {
				return new WP_Error(
					'rest_invalid_param',
					__( 'Validation failed.', 'buddynext' ),
					array(
						'status' => 422,
						'params' => array(
							'visibility' => sprintf(
								/* translators: %s: comma-separated list of accepted values. */
								__( 'Unknown visibility. Accepted values: %s.', 'buddynext' ),
								implode(
									', ',
									array_map(
										static fn( string $t ): string => SpaceTypeRegistry::instance()->visibility( $t ),
										SpaceTypeRegistry::instance()->keys()
									)
								)
							),
						),
					)
				);
			}
		}

		if ( '' !== $from_type && '' !== $from_visibility && $from_type !== $from_visibility ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Validation failed.', 'buddynext' ),
				array(
					'status' => 422,
					'params' => array(
						'visibility' => __( 'This request asks for two different space types at once — `type` and `visibility` disagree. Send one of them.', 'buddynext' ),
					),
				)
			);
		}

		if ( '' !== $from_type ) {
			return $from_type;
		}
		if ( '' !== $from_visibility ) {
			return $from_visibility;
		}

		return sanitize_key( (string) get_option( 'buddynext_space_default_type', 'open' ) );
	}

	/**
	 * Create a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		$name     = sanitize_text_field( (string) ( $request->get_param( 'name' ) ?? '' ) );
		$slug_raw = (string) ( $request->get_param( 'slug' ) ?? '' );
		$slug     = sanitize_title( $slug_raw );
		$type     = $this->resolve_requested_type( $request );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$description = sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?? '' ) );
		$category_id = absint( $request->get_param( 'category_id' ) );
		$parent_id   = absint( $request->get_param( 'parent_id' ) );

		$validation_errors = array();

		// Sub-space: the new space nests under a parent. Only someone who manages
		// the parent may add a sub-space to it; SpaceService::create() then
		// enforces the two-level depth limit and the per-space max-sub-spaces cap.
		if ( $parent_id > 0 ) {
			$parent_space = ( new SpaceService() )->get( $parent_id );
			if ( null === $parent_space ) {
				$validation_errors['parent_id'] = __( 'The selected parent space does not exist.', 'buddynext' );
			} elseif ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $parent_id ) ) ) {
				$validation_errors['parent_id'] = __( 'You can only add a sub-space to a space you manage.', 'buddynext' );
			}
		}

		if ( '' === $name ) {
			$validation_errors['name'] = __( 'A name is required.', 'buddynext' );
		} elseif ( mb_strlen( $name ) > 100 ) {
			$validation_errors['name'] = __( 'Name must be 100 characters or fewer.', 'buddynext' );
		}

		if ( '' === $slug && '' !== $name ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $slug ) {
			$validation_errors['slug'] = __( 'A slug is required.', 'buddynext' );
		}

		if ( ! SpaceTypeRegistry::instance()->is_valid( $type ) ) {
			$validation_errors['type'] = __( 'Invalid space type.', 'buddynext' );
		}

		if ( mb_strlen( $description ) > 160 ) {
			$validation_errors['description'] = __( 'Description must be 160 characters or fewer.', 'buddynext' );
		}

		if ( ! empty( $validation_errors ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Validation failed.', 'buddynext' ),
				array(
					'status' => 422,
					'params' => $validation_errors,
				)
			);
		}

		$data = array(
			'name'        => $name,
			'slug'        => $slug,
			'type'        => $type,
			'description' => $description,
		);
		if ( $category_id > 0 ) {
			$data['category_id'] = $category_id;
		}
		if ( $parent_id > 0 ) {
			$data['parent_id'] = $parent_id;
		}

		$result = ( new SpaceService() )->create( $user_id, $data );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = ( 'slug_taken' === $code ) ? 422 : 400;
			$result->add_data(
				array(
					'status' => $status,
					'params' => ( 'slug_taken' === $code )
						? array( 'slug' => __( 'This slug is already in use.', 'buddynext' ) )
						: array(),
				)
			);
			return $result;
		}

		$space = ( new SpaceService() )->get( $result );

		return new WP_REST_Response( $space, 201 );
	}

	/**
	 * Get a single space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Existence gate — the canonical resolver, the same one the server-rendered
		// space page consults, so the app and the page can never disagree.
		if ( ! SpaceVisibility::can_view_space( $space, get_current_user_id() ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Attach registered space fields with their values. Members-only fields are
		// included for members and anyone who can manage the space; a public viewer
		// sees public fields only. App and web render from this same payload.
		$viewer_id       = get_current_user_id();
		$space['fields'] = SpaceFieldRegistry::instance()->resolve_for_space(
			(int) $space['id'],
			$this->viewer_can_see_member_fields( (int) $space['id'], $viewer_id )
		);

		// Breadcrumb: a sub-space carries a compact summary of its parent, plus a
		// live count of its own children so a space-home can show "N sub-spaces".
		$space['parent'] = ( new SpaceService() )->parent_summary( (int) ( $space['parent_id'] ?? 0 ), get_current_user_id() );
		// Visibility-scoped so the count matches the children this viewer can actually
		// open — never leak a total that includes secret/unlisted sub-spaces.
		$space['subspace_count'] = ( new SpaceService() )->count_visible_subspaces( (int) $space['id'], $viewer_id, current_user_can( 'manage_options' ) );

		// Viewer-relative membership — the SAME block list_spaces() attaches, so the space
		// header and the directory row can never disagree on join state (member / pending /
		// none). Without this the app's detail fetch overwrites the optimistic "Requested".
		$space['join_method']       = SpaceTypeRegistry::instance()->join_method( (string) ( $space['type'] ?? 'open' ) );
		$membership                 = ( new SpaceMemberService() )->membership_map( $viewer_id, array( (int) $space['id'] ) )[ (int) $space['id'] ] ?? null;
		$space['membership_role']   = $membership['role'] ?? '';
		$space['membership_status'] = $membership['status'] ?? '';

		// Same three viewer-relative permission flags the directory rows carry, so
		// a card and the space header can never disagree about what the viewer may
		// do. See the note in enrich_directory_rows() for why manage is two flags.
		$bn_space_id             = (int) $space['id'];
		$space['can_invite']     = $viewer_id > 0 && ( new SpaceMemberService() )->can_invite( $bn_space_id, $viewer_id );
		$space['can_manage']     = $viewer_id > 0 && buddynext_can( $viewer_id, 'buddynext-spaces/manage-settings', array( 'space_id' => $bn_space_id ) );
		$space['can_edit_space'] = $viewer_id > 0 && buddynext_can( $viewer_id, 'buddynext-manage-space', array( 'space_id' => $bn_space_id ) );

		return new WP_REST_Response( $space, 200 );
	}

	/**
	 * GET /spaces/{id}/subspaces — a parent's sub-spaces, paginated + visibility-scoped.
	 *
	 * Public read: a secret child is hidden from a non-member exactly like a
	 * top-level secret space. Index-backed and bounded so a parent's children
	 * list never scans at scale.
	 *
	 * @param WP_REST_Request $request REST request (id, page, per_page).
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_subspaces( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$parent_id = (int) $request->get_param( 'id' );
		$service   = new SpaceService();
		$parent    = $service->get( $parent_id );

		if ( null === $parent ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// A hidden (secret) parent must not confirm it exists — 404. A listed but
		// gated (private) parent exists publicly, but its structure is content:
		// only its members / owner / moderators / site admins may list its
		// children. Both answers come from the canonical resolver.
		$viewer_id = get_current_user_id();
		if ( ! SpaceVisibility::can_view_space( $parent, $viewer_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}
		if ( ! SpaceVisibility::can_view_content( $parent, $viewer_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have access to this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$per_page_param = $request->get_param( 'per_page' );
		$page_param     = $request->get_param( 'page' );
		$per_page       = max( 1, min( 50, absint( null !== $per_page_param ? $per_page_param : 24 ) ) );
		$page           = max( 1, absint( null !== $page_param ? $page_param : 1 ) );
		$offset         = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'subspaces' => $service->get_subspaces( $parent_id, $per_page, $offset, $viewer_id, current_user_can( 'manage_options' ) ),
				'total'     => $service->count_visible_subspaces( $parent_id, $viewer_id, current_user_can( 'manage_options' ) ),
				'page'      => $page,
				'per_page'  => $per_page,
			),
			200
		);
	}

	/**
	 * Whether a viewer may see 'members'-visibility space fields: a member of the
	 * space, or anyone who can manage it.
	 *
	 * @param int $space_id Space ID.
	 * @param int $viewer_id Current user ID (0 when logged out).
	 * @return bool
	 */
	private function viewer_can_see_member_fields( int $space_id, int $viewer_id ): bool {
		if ( $viewer_id <= 0 ) {
			return false;
		}
		if ( ( new SpaceMemberService() )->is_member( $space_id, $viewer_id ) ) {
			return true;
		}

		return (bool) buddynext_service( 'permissions' )->can(
			$viewer_id,
			'buddynext-manage-space',
			array( 'space_id' => $space_id )
		);
	}

	/**
	 * GET /spaces/fields — the registered field definitions (form schema, no values).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_space_field_definitions( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$fields = array();
		foreach ( SpaceFieldRegistry::instance()->get_fields() as $field ) {
			$fields[] = array(
				'key'         => $field['key'],
				'label'       => $field['label'],
				'description' => $field['description'],
				'type'        => $field['type'],
				// Through the engine, never $field['options'] directly — a live-optioned
				// type stores no options at registration and would advertise an empty
				// pick list. See FieldType::choices().
				'options'     => \BuddyNext\Profile\FieldType::choices( $field ),
				'section'     => $field['section'],
				'sort_order'  => $field['sort_order'],
				'visibility'  => $field['visibility'],
				'is_required' => $field['is_required'],
			);
		}

		return new WP_REST_Response( array( 'fields' => $fields ), 200 );
	}

	/**
	 * POST /spaces/{id}/fields — save space field values (space managers only).
	 *
	 * Atomic per the registry: a required-field error rejects the whole submit
	 * with a 422 and per-field messages; otherwise all values save and return 200.
	 *
	 * @param WP_REST_Request $request REST request with a `fields` object param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_space_fields( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$space    = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();

		// The route admits anyone who manages the space at moderator level or above;
		// WHICH fields they may actually write is then decided per field, inside
		// save_for_space(), by SpaceFieldRegistry::can_write(). A moderator gets the
		// moderation settings and a 422 on anything else.
		//
		// This used to demand `buddynext-manage-space` (owner-only) for every field,
		// which was half of the split-brain: a moderator could rewrite who_can_post
		// from the settings screen and got a 403 for the same field over REST. One
		// authority now answers both, so the web UI and the app cannot disagree.
		if ( ! buddynext_service( 'permissions' )->can(
			$user_id,
			'buddynext-moderate-space',
			array( 'space_id' => $space_id )
		) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$values = (array) $request->get_param( 'fields' );
		$result = SpaceFieldRegistry::instance()->save_for_space( $space_id, $values, $user_id );

		// Promotion of eligible fields to space tabs is presentation, not a field
		// value — persisted only when the values validated, and only when the
		// caller sent the `tabs` key (so a values-only save leaves tabs untouched).
		// Owner-only: which fields become tabs is a structural decision about the
		// space, not a moderation one.
		if ( empty( $result['errors'] ) && null !== $request->get_param( 'tabs' )
			&& buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			$tabs = array_map( 'strval', (array) $request->get_param( 'tabs' ) );
			SpaceFieldRegistry::instance()->set_promoted_tabs( $space_id, $tabs );
		}

		$status = empty( $result['errors'] ) ? 200 : 422;

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Update a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$service  = new SpaceService();

		$data = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$data['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}
		// Accepts `visibility` as an alias for `type`, exactly as create does.
		// Parity matters more here than on create: an owner tightening an existing
		// space from open to private is the request you can least afford to
		// discard in silence, because the space stays publicly readable and the
		// screen says it worked.
		if ( null !== $request->get_param( 'type' ) || null !== $request->get_param( 'visibility' ) ) {
			$resolved = $this->resolve_requested_type( $request );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$data['type'] = $resolved;
		}
		// Move under a new parent (>0) or detach to the top level (0). The service
		// validates depth, cycles, the per-parent cap, and manage permission.
		if ( null !== $request->get_param( 'parent_id' ) ) {
			$data['parent_id'] = (int) $request->get_param( 'parent_id' );
		}

		// App parity: the service has always supported these three, and the front-end
		// settings screen writes all of them — but the REST route never forwarded
		// them, so a Space's category, slug and house rules were unreachable from the
		// native app (and from any integration). SpaceService::update() does the
		// validation (reserved + unique slug, category existence), so this only has
		// to pass them through.
		if ( null !== $request->get_param( 'category_id' ) ) {
			$data['category_id'] = absint( $request->get_param( 'category_id' ) );
		}
		if ( null !== $request->get_param( 'slug' ) ) {
			$data['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'rules' ) ) {
			$data['rules'] = sanitize_textarea_field( (string) $request->get_param( 'rules' ) );
		}

		// Pro-gated field: the service only persists it when Pro validates the ability
		// slug (buddynext_sanitize_space_required_ability); ignored on Free.
		if ( null !== $request->get_param( 'required_ability' ) ) {
			$data['required_ability'] = sanitize_text_field( (string) $request->get_param( 'required_ability' ) );
		}

		$result = $service->update( $space_id, $user_id, $data );

		if ( is_wp_error( $result ) ) {
			// Respect the error's own HTTP status (404 / 422 from a parent move),
			// defaulting to 403 for the permission/not-found errors that carry none.
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 403;
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		return new WP_REST_Response( $service->get( $space_id ), 200 );
	}

	/**
	 * Delete a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		// Route-level ownership gate, matching the avatar/cover handlers' dual
		// gate. The route's permission_callback only asserts auth, so without
		// this any logged-in user reaches the handler and is rejected one layer
		// deeper by SpaceService::delete(); gate here so non-managers are turned
		// away before any lookup work.
		$gate = $this->require_space_manager( $space_id, $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Soft gate: when the client passes the X-BN-Confirm-Space-Name header,
		// it must exactly match the space's current name. This prevents a
		// stray DELETE from removing a space that the user didn't intend to
		// confirm by typed name. When the header is absent we fall back to
		// the existing permission check, preserving back-compat for CLI / API
		// consumers that already check identity another way.
		$header_name = (string) $request->get_header( 'X-BN-Confirm-Space-Name' );
		if ( '' !== $header_name ) {
			$space = ( new SpaceService() )->get( $space_id );
			if ( null === $space ) {
				return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
			}
			if ( $header_name !== (string) $space['name'] ) {
				return new WP_Error(
					'confirm_mismatch',
					__( 'Confirmation name does not match.', 'buddynext' ),
					array( 'status' => 422 )
				);
			}
		}

		$result = ( new SpaceService() )->delete( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	// ── Membership ──────────────────────────────────────────────────────────

	/**
	 * Shared optional pagination args for the members + pending-requests routes.
	 * Both default to unbounded (per_page omitted) for backward compatibility.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function member_pagination_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
		);
	}

	/**
	 * GET /spaces/{id}/media — the space's shared photos and attachments.
	 *
	 * The web Media tab derived its grid from the feed, so the app had no way to
	 * offer one without scanning the feed itself — fragile and unpaginated, as
	 * the card that asked for this said.
	 *
	 * Gated by SpaceVisibility::can_view_content(), the SAME decision point the
	 * space feed and the single-post read use, so media cannot leak from a
	 * private space that refuses its own feed. A refused viewer gets 404, not
	 * 403 — the space's existence is not confirmed either.
	 *
	 * Pagination is over media-bearing POSTS (an indexed query); each post can
	 * contribute several attachments, so `items` is longer than `per_page`. That
	 * is deliberate — the alternative, offsetting into a flattened attachment
	 * list, re-reads every earlier post on every page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$viewer_id = get_current_user_id();
		$space     = ( new SpaceService() )->get( $space_id );

		if ( null === $space || ! \BuddyNext\Spaces\SpaceVisibility::can_view_content( $space, $viewer_id ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Mirror the web Media tab's own condition (Nav/Providers/SpaceNav.php):
		// media engine present AND the site-wide media integration enabled AND
		// the per-space Media-tab field on. Without all three here, a space
		// whose owner switched the tab off - or a site with the integration
		// disabled entirely - still served its media over REST, so the setting
		// silently stopped applying the moment a client asked through the API.
		// Per the bridge contract, the Integrations toggle gates EACH surface.
		if ( ! \BuddyNext\Media\MediaClient::available()
			|| ! buddynext_integration_enabled( 'media', 'nav' )
			|| ! (bool) buddynext_get_space_field( $space_id, 'mvs_media_tab' ) ) {
			return new WP_Error(
				'media_tab_disabled',
				__( 'Media is not enabled for this space.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( $per_page, 100 ) : 24;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$feed  = buddynext_service( 'feed' );
		$total = (int) $feed->space_media_post_count( $space_id );
		$rows  = $feed->space_media_rows( $space_id, $per_page, ( $page - 1 ) * $per_page );

		// Prime the engine's row cache once for the whole page instead of letting
		// descriptor() fire a query per tile.
		$all_ids = array();
		foreach ( $rows as $row ) {
			foreach ( $row['media_ids'] as $mid ) {
				$all_ids[] = $mid;
			}
		}
		$repo = \BuddyNext\Media\MediaClient::repo();
		if ( $all_ids && $repo && method_exists( $repo, 'prefetch' ) ) {
			$repo->prefetch( array_values( array_unique( $all_ids ) ) );
		}

		$items = array();
		foreach ( $rows as $row ) {
			foreach ( $row['media_ids'] as $media_id ) {
				$descriptor = \BuddyNext\Media\MediaUrlResolver::descriptor( $media_id );

				// Null means deleted, or not visible to THIS viewer — the engine
				// owns that call. Skipping keeps a revoked attachment out of the
				// grid rather than rendering a broken tile.
				if ( null === $descriptor ) {
					continue;
				}

				$items[] = array_merge(
					$descriptor,
					array(
						'media_id'   => $media_id,
						'post_id'    => $row['post_id'],
						'user_id'    => $row['user_id'],
						'created_at' => $row['created_at'],
					)
				);
			}
		}

		return new WP_REST_Response(
			array(
				'items'       => $items,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			),
			200
		);
	}

	/**
	 * GET /spaces/{id}/members — the space roster.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$space     = ( new SpaceService() )->get( $space_id );
		$viewer_id = get_current_user_id();

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Roster gate — the canonical resolver, the SAME call the server-rendered
		// members page makes. Private and secret rosters are members-only (owner,
		// moderators, active members, site admins); a site owner re-opens them with
		// one `buddynext_space_can_view_roster` filter that moves both surfaces.
		// The gate runs BEFORE any roster row is fetched — never fetch 100k rows to
		// then refuse them. Pagination args are declared via member_pagination_args().
		if ( ! SpaceVisibility::can_view_roster( $space, $viewer_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have access to this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Pagination is opt-in: with no per_page the full roster is returned
		// (backward-compatible). A paginating client passes page/per_page and
		// reads the total from the X-WP-Total header — the response body stays a
		// bare members array.
		$member_service = new SpaceMemberService();
		// Always paginate — a member list is never returned unbounded. An absent /
		// non-positive per_page defaults to a sane page rather than the old "all" path
		// (which loaded a full 50k roster); the real total comes from count_members.
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 50;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$members = $member_service->get_members( $space_id, $viewer_id, $per_page, ( $page - 1 ) * $per_page );
		$total   = $member_service->count_members( $space_id, $viewer_id );

		$response = new WP_REST_Response( $members, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( (int) ceil( $total / $per_page ) ) );

		return $response;
	}

	/**
	 * Return pending join requests for a space.
	 *
	 * Only the space owner, a moderator, or a site admin may access this endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pending_requests( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id   = (int) $request->get_param( 'id' );
		$current_id = get_current_user_id();
		$space      = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$member_service = new SpaceMemberService();
		$actor_role     = $member_service->get_role( $space_id, $current_id );

		if ( ! SpaceRoles::can_moderate( $actor_role, $current_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Only space owners and moderators can view pending requests.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Opt-in pagination: per_page bounds the query and 'total' reports the
		// full count (not the page size). Without per_page the original
		// unbounded list is returned.
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		if ( $per_page > 0 ) {
			$per_page = min( 100, $per_page );
			$requests = $member_service->get_pending_requests( $space_id, $per_page, ( $page - 1 ) * $per_page );
			$total    = $member_service->count_pending_requests( $space_id );
		} else {
			$requests = $member_service->get_pending_requests( $space_id );
			$total    = count( $requests );
		}

		// Enrich each request with the requester's name + avatar so an owner can recognise
		// who is asking to join — the raw rows carry only user_id. Bounded by per_page and
		// primed in one pass (cache_users), so no per-row user lookup.
		$user_ids = array_values( array_filter( array_map( static fn( $r ) => (int) ( $r['user_id'] ?? 0 ), $requests ) ) );
		if ( ! empty( $user_ids ) ) {
			cache_users( $user_ids );
		}
		$requests = array_map(
			static function ( $r ) {
				$uid               = (int) ( $r['user_id'] ?? 0 );
				$user              = $uid ? get_userdata( $uid ) : null;
				$r['display_name'] = $user ? $user->display_name : __( 'Member', 'buddynext' );
				$r['avatar_url']   = $uid ? get_avatar_url( $uid, array( 'size' => 96 ) ) : '';
				return $r;
			},
			$requests
		);

		return new WP_REST_Response(
			array(
				'items' => $requests,
				'total' => $total,
			),
			200
		);
	}

	/**
	 * Join a space (or request to join for private, 403 for secret without invite).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function join_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$members = new SpaceMemberService();

		// Enforce space ban at the REST layer before any join path executes.
		if ( $members->is_banned_from_space( $space_id, $user_id ) ) {
			return new WP_Error(
				'space_banned',
				__( 'You have been banned from this space.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// A standing invitation: accepting it joins directly, regardless of the
		// space's normal join method. Without this, an invited user on a
		// request-to-join (private) space would be routed through request_join()
		// and stay 'invited' instead of becoming a member. join() promotes the
		// existing 'invited' row to 'active'.
		if ( 'invited' === $members->get_status( $space_id, $user_id ) ) {
			$result = $members->join( $space_id, $user_id );
			if ( is_wp_error( $result ) ) {
				return $this->preserve_status( $result, 400 );
			}
			return new WP_REST_Response( array( 'joined' => true ), 200 );
		}

		// Role-map enforcement, applied only to non-invited joins. A standing
		// invitation is itself the authorization to join, so it returns above
		// before this gate — otherwise a subscriber whose role-map lacks the
		// join cap was 403'd ("Your role does not permit this action") even
		// though they were explicitly invited. The space ban above is enforced
		// regardless.
		$gate = $this->require_cap( 'buddynext-spaces/join', array( 'space_id' => $space_id ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Invite-only types: require a standing invitation.
		if ( 'invite' === SpaceTypeRegistry::instance()->join_method( (string) $space['type'] ) ) {
			if ( 'invited' !== $members->get_status( $space_id, $user_id ) ) {
				return new WP_Error(
					'invite_only',
					__( 'This space is invite only. You must be invited to join.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		// Join routing is driven by the space TYPE (open=direct, private=request,
		// secret=invite) AND the per-space "Require approval to join" permission
		// option. When a direct-join (open) space has require_join_approval
		// enabled, the join is downgraded to a pending request so the owner/mod
		// must approve it — matching the Permissions panel toggle.
		$join_method = (string) SpaceTypeRegistry::instance()->join_method( (string) $space['type'] );

		if (
			'direct' === $join_method
			&& (bool) buddynext_get_space_field( $space_id, 'require_join_approval' )
		) {
			$join_method = 'request';
		}

		// Request-to-join types: submit a join request.
		if ( 'request' === $join_method ) {
			$current_status = $members->get_status( $space_id, $user_id );

			// If already an active member or has a pending request, treat as success.
			if ( 'active' === $current_status ) {
				return new WP_REST_Response( array( 'joined' => true ), 200 );
			}

			$result = $members->request_join( $space_id, $user_id );

			if ( is_wp_error( $result ) ) {
				return $this->preserve_status( $result, 400 );
			}

			return new WP_REST_Response( array( 'requested' => true ), 200 );
		}

		// Open spaces: join directly.
		$result = $members->join( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'joined' => true ), 200 );
	}

	/**
	 * Leave a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function leave_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();
		$result   = ( new SpaceMemberService() )->leave( $space_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response(
			array(
				'left'   => true,
				'joined' => false,
			),
			200
		);
	}

	/**
	 * Cancel the current user's pending join request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_join_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$result   = ( new SpaceMemberService() )->cancel_request( $space_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->preserve_status( $result, 400 );
		}

		return new WP_REST_Response( array( 'cancelled' => true ), 200 );
	}

	/**
	 * Invite a user to a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invite_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id        = (int) $request->get_param( 'id' );
		$inviter_id      = get_current_user_id();
		$invited_user_id = absint( $request->get_param( 'user_id' ) );

		// Allow invite by username or email when no numeric user_id is supplied,
		// so the hero Invite modal can mirror the settings-panel invite form.
		if ( 0 === $invited_user_id ) {
			$identifier = sanitize_text_field( (string) ( $request->get_param( 'identifier' ) ?? '' ) );
			// Accept an @-prefixed username — member mentions use @username across
			// the platform, so the invite field should too. Emails never carry a
			// leading @, so this only affects username input.
			$identifier = ltrim( $identifier, '@' );
			if ( '' !== $identifier ) {
				$invited_user = is_email( $identifier )
					? get_user_by( 'email', $identifier )
					: get_user_by( 'login', $identifier );
				if ( $invited_user instanceof \WP_User ) {
					$invited_user_id = (int) $invited_user->ID;
				} elseif ( is_email( $identifier ) ) {
					// No account yet: send a space-linked email invitation. On
					// registration the new account is dropped straight into this
					// space (see AuthController::register), so the invite link is
					// not a dead end for people who aren't members yet.
					$space = ( new SpaceService() )->get( $space_id );
					if ( null === $space ) {
						return new WP_Error(
							'space_not_found',
							__( 'Space not found.', 'buddynext' ),
							array( 'status' => 404 )
						);
					}
					if ( ! ( new SpaceMemberService() )->can_invite( $space_id, $inviter_id ) ) {
						return new WP_Error(
							'forbidden',
							__( 'Only the space owner or a moderator can invite members.', 'buddynext' ),
							array( 'status' => 403 )
						);
					}
					( new \BuddyNext\Onboarding\InviteService() )->create(
						$identifier,
						'',
						\BuddyNext\Onboarding\InviteService::DEFAULT_TTL_DAYS,
						$space_id
					);
					return new WP_REST_Response( array( 'email_invited' => true ), 200 );
				} else {
					return new WP_Error(
						'user_not_found',
						__( 'No user found with that username or email.', 'buddynext' ),
						array( 'status' => 404 )
					);
				}
			}
		}

		if ( 0 === $invited_user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id or identifier parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->invite( $space_id, $inviter_id, $invited_user_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'invited' => true ), 200 );
	}

	/**
	 * Approve or decline several pending join requests in one call.
	 *
	 * Every user is put through the SAME SpaceMemberService method the per-member
	 * endpoints use, one at a time. That is deliberate: the permission check, the
	 * "is there actually a pending request" check and the resulting notification
	 * all live in there, and a bulk path that bypassed them to save queries would
	 * be a second, weaker set of rules for the same decision.
	 *
	 * Partial success is reported, not hidden. Between a moderator loading the
	 * page and pressing the button, a request can be withdrawn or approved by
	 * somebody else - so each id gets its own outcome and the caller is told which
	 * ones did not apply, rather than being shown a blanket success or a blanket
	 * failure for a batch that was mostly fine.
	 *
	 * @since 1.1.6
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decide_requests_bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$decision = (string) $request->get_param( 'decision' );

		$user_ids = array_values( array_unique( array_filter( array_map(
			'absint',
			(array) $request->get_param( 'user_ids' )
		) ) ) );

		if ( array() === $user_ids ) {
			return new WP_Error(
				'missing_user_id',
				__( 'At least one user_id is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		// Bounded so one call cannot become an unbounded write loop. A collapsed
		// notification row is a handful of people; anything approaching this many
		// belongs in the space's own members screen.
		if ( count( $user_ids ) > self::BULK_DECISION_MAX ) {
			return new WP_Error(
				'too_many_users',
				sprintf(
					/* translators: %d: maximum number of members per bulk decision. */
					__( 'Up to %d members can be decided at once.', 'buddynext' ),
					self::BULK_DECISION_MAX
				),
				array( 'status' => 400 )
			);
		}

		if ( null === ( new SpaceService() )->get( $space_id ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$members  = new SpaceMemberService();
		$done     = array();
		$failed   = array();

		foreach ( $user_ids as $user_id ) {
			$result = 'approve' === $decision
				? $members->approve_request( $space_id, $actor_id, $user_id )
				: $members->decline_request( $space_id, $actor_id, $user_id );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'user_id' => $user_id,
					'code'    => $result->get_error_code(),
				);
				continue;
			}

			$done[] = $user_id;
		}

		// 403 only when EVERY id was refused for permission - one moderator who
		// may not act on this space. A mixed result is a success with detail;
		// failing the whole call would throw away the approvals that did happen.
		if ( array() === $done && array() !== $failed ) {
			$first = (string) ( $failed[0]['code'] ?? '' );
			return new WP_Error(
				$first !== '' ? $first : 'bulk_decision_failed',
				'no_pending_request' === $first
					? __( 'Those join requests are no longer pending.', 'buddynext' )
					: __( 'You do not have permission to decide these requests.', 'buddynext' ),
				array(
					'status' => 'no_pending_request' === $first ? 404 : 403,
					'failed' => $failed,
				)
			);
		}

		return new WP_REST_Response(
			array(
				'decision' => $decision,
				'decided'  => $done,
				'failed'   => $failed,
			),
			200
		);
	}

	/**
	 * Approve a pending join request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$user_id  = absint( $request->get_param( 'user_id' ) );

		if ( 0 === $user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->approve_request( $space_id, $actor_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$status_code = 'no_pending_request' === $result->get_error_code() ? 404 : 403;
			$result->add_data( array( 'status' => $status_code ) );
			return $result;
		}

		return new WP_REST_Response( array( 'approved' => true ), 200 );
	}

	/**
	 * Decline a pending join request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$actor_id = get_current_user_id();
		$user_id  = absint( $request->get_param( 'user_id' ) );

		if ( 0 === $user_id ) {
			return new WP_Error(
				'missing_user_id',
				__( 'A user_id parameter is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$space = ( new SpaceService() )->get( $space_id );

		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$result = ( new SpaceMemberService() )->decline_request( $space_id, $actor_id, $user_id );

		if ( is_wp_error( $result ) ) {
			$status_code = 'no_pending_request' === $result->get_error_code() ? 404 : 403;
			$result->add_data( array( 'status' => $status_code ) );
			return $result;
		}

		return new WP_REST_Response( array( 'declined' => true ), 200 );
	}

	/**
	 * Change a member's role within a space.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function change_member_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$target_id = (int) $request->get_param( 'user_id' );
		$actor_id  = get_current_user_id();
		$new_role  = sanitize_key( (string) $request->get_param( 'role' ) );

		if ( ! in_array( $new_role, array( 'moderator', 'member' ), true ) ) {
			return new WP_Error( 'invalid_role', __( 'Role must be moderator or member.', 'buddynext' ), array( 'status' => 400 ) );
		}

		$result = ( new SpaceMemberService() )->change_role( $space_id, $target_id, $new_role, $actor_id );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'role' => $new_role ), 200 );
	}

	/**
	 * Remove a member from a space (without banning).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$target_id = (int) $request->get_param( 'user_id' );
		$actor_id  = get_current_user_id();

		$service = new SpaceMemberService();
		$role    = $service->get_role( $space_id, $actor_id );

		if ( ! SpaceRoles::can_moderate( $role, $actor_id ) ) {
			return new WP_Error( 'forbidden', __( 'Only owners and moderators can remove members.', 'buddynext' ), array( 'status' => 403 ) );
		}

		// Cannot remove the owner.
		$target_role = $service->get_role( $space_id, $target_id );
		if ( 'owner' === $target_role ) {
			return new WP_Error( 'cannot_remove_owner', __( 'The space owner cannot be removed.', 'buddynext' ), array( 'status' => 403 ) );
		}

		if ( ! $service->remove( $space_id, $target_id, $actor_id ) ) {
			return new WP_Error( 'remove_failed', __( 'Could not remove the member.', 'buddynext' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'removed' => true ), 200 );
	}

	/**
	 * Transfer space ownership to another member.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function transfer_ownership( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id     = (int) $request->get_param( 'id' );
		$current_user = get_current_user_id();
		$new_owner_id = absint( $request->get_param( 'new_owner_id' ) );

		if ( 0 === $new_owner_id ) {
			return new WP_Error( 'missing_new_owner_id', __( 'A new_owner_id is required.', 'buddynext' ), array( 'status' => 400 ) );
		}

		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// buddynext-own-space, not manage-space: the message already said "only the
		// space owner", and manage-space now includes moderators.
		if ( ! buddynext_service( 'permissions' )->can( $current_user, 'buddynext-own-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can transfer ownership.', 'buddynext' ), array( 'status' => 403 ) );
		}

		$member_service = new SpaceMemberService();

		// New owner must be an active member.
		if ( ! $member_service->is_member( $space_id, $new_owner_id ) ) {
			return new WP_Error( 'not_a_member', __( 'The new owner must be an active member of the space.', 'buddynext' ), array( 'status' => 422 ) );
		}

		// assign_owner() is the ownership primitive: it re-checks the capability,
		// moves bn_spaces.owner_id and the role='owner' row together, and reports
		// a real failure instead of returning a 200 over a half-applied write.
		$transferred = ( new SpaceService() )->assign_owner( $space_id, $new_owner_id, $current_user );

		if ( is_wp_error( $transferred ) ) {
			return $transferred;
		}

		return new WP_REST_Response(
			array(
				'transferred'  => true,
				'new_owner_id' => $new_owner_id,
			),
			200
		);
	}

	/**
	 * Archive a space (owner/admin). Members keep read access; new activity is
	 * refused (see the read-only guards in PostService/CommentService/join).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function archive_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$result   = ( new SpaceService() )->archive( $space_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'archived' => true ), 200 );
	}

	/**
	 * Restore an archived space to active (owner/admin).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unarchive_space( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$result   = ( new SpaceService() )->unarchive( $space_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'archived' => false ), 200 );
	}

	// ── Images ──────────────────────────────────────────────────────────────

	/**
	 * Upload a space avatar (icon).
	 *
	 * Stored as organized, per-owner WebP variations in
	 * uploads/bn-space-avatars/{id}/ via ImageStorageService — no WP attachment,
	 * no orphans on replace. The resulting URL is persisted to the
	 * `bn_spaces.avatar_url` column. Only the space owner (or a site admin) may
	 * change the image.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_space_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_space_image_upload( $request, 'avatar' );
	}

	/**
	 * Upload a space cover image.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_space_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_space_image_upload( $request, 'cover' );
	}

	/**
	 * Remove a space avatar (icon) — wipes the folder and clears the column.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_space_avatar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_space_image_delete( $request, 'avatar' );
	}

	/**
	 * Remove a space cover image.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_space_cover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_space_image_delete( $request, 'cover' );
	}

	/**
	 * Shared space avatar/cover upload logic.
	 *
	 * @param WP_REST_Request $request Incoming request (multipart, file field `image`).
	 * @param string          $kind    'avatar' | 'cover'.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_space_image_upload( WP_REST_Request $request, string $kind ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		$gate = $this->require_space_manager( $space_id, $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		/*
		 * The WP REST API verifies the X-WP-Nonce header before this callback
		 * fires; WPCS cannot see that layer, so suppress its nonce/index checks
		 * for the $_FILES read below.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		 */
		$file = isset( $_FILES['image'] ) && is_array( $_FILES['image'] ) ? $_FILES['image'] : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( empty( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'image_missing', __( 'No file uploaded or upload error.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$max = ( 'cover' === $kind ) ? 5 * 1024 * 1024 : 2 * 1024 * 1024;
		if ( (int) ( $file['size'] ?? 0 ) > $max ) {
			return new WP_Error(
				'image_too_large',
				( 'cover' === $kind ) ? __( 'File must be under 5MB.', 'buddynext' ) : __( 'File must be under 2MB.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		$name     = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$check    = wp_check_filetype_and_ext( $tmp_name, $name );
		$allowed  = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( 'avatar' === $kind ) {
			$allowed[] = 'image/gif';
		}

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error( 'image_invalid_type', __( 'Only JPEG, PNG, or WebP images are accepted.', 'buddynext' ), array( 'status' => 422 ) );
		}

		$stored = ( new \BuddyNext\Media\ImageStorageService() )->store( $tmp_name, $kind, 'space', $space_id );
		if ( is_wp_error( $stored ) ) {
			return new WP_Error( 'image_upload_failed', $stored->get_error_message(), array( 'status' => 500 ) );
		}

		$column = ( 'cover' === $kind ) ? 'cover_image_url' : 'avatar_url';
		$result = ( new SpaceService() )->update( $space_id, $user_id, array( $column => $stored ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		// Non-destructive framing for the cover, mirroring the member cover: the
		// image is stored uncropped and the owner pans/zooms it at render via a
		// focal point. Same clamp + shape as ProfileController::handle_cover_upload
		// so both covers behave identically. Gated already by require_space_manager
		// above - repositioning is part of managing the cover, not a new capability.
		if ( 'cover' === $kind ) {
			$this->store_space_cover_focal( $space_id );
		}

		return new WP_REST_Response( array( $column => $stored ), 200 );
	}

	/**
	 * Persist the space cover's focal point (pan X/Y + zoom) from the request.
	 *
	 * Mirrors ProfileController's member-cover focal block: writes only when the
	 * posted X/Y are both within 0..100, clamps zoom to 1..3, and stores the
	 * {x, y, zoom} array under the same meta key the member cover uses. Silent
	 * no-op when the fields are absent (a plain upload with no reposition).
	 *
	 * @param int $space_id Space whose cover was just uploaded.
	 * @return void
	 */
	private function store_space_cover_focal( int $space_id ): void {
		/*
		 * The REST nonce is verified before this callback runs, and each value is
		 * unslashed then cast to float; WPCS sees neither layer for a $_POST read,
		 * so suppress both sniffs for the three reads (mirrors ProfileController).
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 */
		$focal_x    = isset( $_POST['focal_x'] ) ? (float) wp_unslash( (string) $_POST['focal_x'] ) : -1.0;
		$focal_y    = isset( $_POST['focal_y'] ) ? (float) wp_unslash( (string) $_POST['focal_y'] ) : -1.0;
		$focal_zoom = isset( $_POST['focal_zoom'] ) ? (float) wp_unslash( (string) $_POST['focal_zoom'] ) : 1.0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $focal_x < 0.0 || $focal_x > 100.0 || $focal_y < 0.0 || $focal_y > 100.0 ) {
			return;
		}

		update_space_meta(
			$space_id,
			'buddynext_cover_focal',
			array(
				'x'    => round( $focal_x, 2 ),
				'y'    => round( $focal_y, 2 ),
				'zoom' => round( max( 1.0, min( 3.0, $focal_zoom ) ), 3 ),
			)
		);
	}

	/**
	 * Shared space avatar/cover delete logic.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $kind    'avatar' | 'cover'.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle_space_image_delete( WP_REST_Request $request, string $kind ): WP_REST_Response|WP_Error {
		$space_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		$gate = $this->require_space_manager( $space_id, $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		( new \BuddyNext\Media\ImageStorageService() )->delete( $kind, 'space', $space_id );

		// Drop the focal point with the cover so a future upload starts centred
		// rather than inheriting the removed image's framing (mirror of the member
		// cover cleanup in Admin\Members).
		if ( 'cover' === $kind ) {
			delete_space_meta( $space_id, 'buddynext_cover_focal' );
		}

		$column = ( 'cover' === $kind ) ? 'cover_image_url' : 'avatar_url';
		$result = ( new SpaceService() )->update( $space_id, $user_id, array( $column => '' ) );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		return new WP_REST_Response( array( $column => '' ), 200 );
	}

	/**
	 * Guard: the current user must be the space owner or a site admin.
	 *
	 * @param int $space_id Space being managed.
	 * @param int $user_id  Acting user.
	 * @return true|WP_Error
	 */
	private function require_space_manager( int $space_id, int $user_id ): bool|WP_Error {
		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage this space.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
