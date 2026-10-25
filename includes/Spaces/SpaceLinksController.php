<?php
/**
 * REST: what a space is linked to.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET and DELETE buddynext/v1/spaces/{id}/links. Linking itself is the addon's act:
 * only the addon knows who may link what.
 */
class SpaceLinksController extends BaseRestController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/links',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_links' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_link' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'source' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'type'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'object' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Only someone who can manage the space sees or removes its links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$space_id = (int) $request['id'];
		if ( null === ( new SpaceService() )->get( $space_id ) ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		if ( ! self::viewer_can_manage( get_current_user_id(), $space_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage this space.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Whether a member may see and remove a space's links.
	 *
	 * @param int $user_id  Viewer (0 = guest).
	 * @param int $space_id Space.
	 * @return bool
	 */
	public static function viewer_can_manage( int $user_id, int $space_id ): bool {
		return $user_id > 0 && buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) );
	}

	/**
	 * The space's links, named for display.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_links( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'links' => self::describe( (int) $request['id'] ) ), 200 );
	}

	/**
	 * Remove one link. The space and its members are untouched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function remove_link( WP_REST_Request $request ): WP_REST_Response {
		$space_id = (int) $request['id'];
		( new SpaceLinkService() )->unlink( $space_id, (string) $request['source'], (string) $request['type'], (int) $request['object'] );
		return new WP_REST_Response( array( 'links' => self::describe( $space_id ) ), 200 );
	}

	/**
	 * A space's links as the panel and the app show them.
	 *
	 * A link whose addon is not active is listed without a name and marked unavailable:
	 * nothing is acted on until it returns, and nothing is lost meanwhile.
	 *
	 * @param int $space_id Space.
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe( int $space_id ): array {
		$service = new SpaceLinkService();
		$managed = $service->is_managed( $space_id );
		$out     = array();
		foreach ( $service->links( $space_id ) as $link ) {
			$source = $service->source( $link['source'] );
			$label  = null !== $source ? $source->link_label( $link['type'], $link['id'] ) : array(
				'name' => '',
				'url'  => '',
			);
			// The addon's own name ("Book Club"), not its slug; the slug only if the addon is gone.
			$entry = \BuddyNext\Integrations\IntegrationRegistry::instance()->get( $link['source'] );
			$out[] = array(
				'source'       => $link['source'],
				'source_label' => is_array( $entry ) ? (string) $entry['label'] : ucfirst( str_replace( '_', ' ', $link['source'] ) ),
				'type'         => $link['type'],
				'id'           => $link['id'],
				'name'         => (string) $label['name'],
				'url'          => (string) $label['url'],
				'available'    => null !== $source,
				'managed'      => $managed,
			);
		}
		return $out;
	}
}
