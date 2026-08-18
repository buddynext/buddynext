<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST: assign a member's community role from the Community Admin panel.
 *
 * The community role (member < moderator < admin) lives in the bn_community_role
 * user meta and is BuddyNext's own tier, independent of WordPress roles. Before
 * this endpoint it was only writable by the inbound access webhook, so a site
 * owner had no way to make someone a moderator. This is the write path the
 * Community Admin > Members view and the wp-admin Members list both call.
 *
 * @package BuddyNext\CommunityAdmin
 * @since 1.1.5
 */

declare( strict_types=1 );

namespace BuddyNext\CommunityAdmin;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles community-role assignment.
 */
class RoleController extends BaseRestController {

	/**
	 * The assignable community roles.
	 *
	 * @var array<int,string>
	 */
	private const ROLES = array( 'member', 'moderator', 'admin' );

	/**
	 * Register the controller's routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/community-admin/members/(?P<id>\d+)/role',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_role' ),
				'permission_callback' => array( $this, 'require_role_manager' ),
				'args'                => array(
					'id'   => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'role' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => self::ROLES,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission: only role managers may change roles.
	 *
	 * A role manager is a WordPress administrator (manage_options) or a member
	 * holding the BuddyNext community 'admin' role. Moderators can act in the
	 * panel but must NOT be able to change other members' roles.
	 *
	 * @return bool|WP_Error
	 */
	public function require_role_manager(): bool|WP_Error {
		$user_id = get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$roles = buddynext_service( 'roles' );
		if ( is_object( $roles ) && method_exists( $roles, 'is_admin' ) && $roles->is_admin( $user_id ) ) {
			return true;
		}
		return new WP_Error(
			'buddynext_role_forbidden',
			__( 'You are not allowed to change member roles.', 'buddynext' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * POST /community-admin/members/{id}/role — set a member's community role.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_role( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target_id = (int) $request['id'];
		$role      = sanitize_key( (string) $request['role'] );

		if ( ! in_array( $role, self::ROLES, true ) ) {
			return new WP_Error( 'buddynext_bad_role', __( 'Unknown role.', 'buddynext' ), array( 'status' => 400 ) );
		}

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'buddynext_user_not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// Granting the top 'admin' community role is a WordPress-administrator-only
		// action — a community admin may create moderators but not more admins, so
		// the tier can never be escalated past the site owner's intent.
		if ( 'admin' === $role && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'buddynext_admin_grant_forbidden',
				__( 'Only a site administrator can grant the Admin role.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$roles = buddynext_service( 'roles' );
		if ( ! is_object( $roles ) || ! method_exists( $roles, 'set_role' ) ) {
			return new WP_Error( 'buddynext_roles_unavailable', __( 'Role service unavailable.', 'buddynext' ), array( 'status' => 500 ) );
		}

		$roles->set_role( $target_id, $role );

		return new WP_REST_Response(
			array(
				'id'   => $target_id,
				'role' => $role,
			),
			200
		);
	}
}
