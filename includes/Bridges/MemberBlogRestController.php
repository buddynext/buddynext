<?php
/**
 * REST for the Member Blog bridge — a member's articles (blog posts).
 *
 * Exposes the same posts + pagination the profile Articles tab renders as a
 * JSON read model, so the app and developers read the panel from data instead
 * of scraping HTML. Backed by the bridge's own author query; drafts/pending
 * reach only the owner or an editor (same rule as the tab).
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Member articles REST (buddynext/v1).
 */
final class MemberBlogRestController extends BaseRestController {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/members/(?P<id>\d+)/blog',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_articles' ),
					// Published posts are public; drafts/pending are filtered to the
					// owner/editor inside the data method via the viewer id.
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'       => array(
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
						'page'     => array(
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'per_page' => array(
							'sanitize_callback' => 'absint',
							'default'           => 10,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /members/{id}/blog — the member's articles read model.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_articles( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'bn_member_not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$data     = ( new MemberBlogBridge() )->articles_data( $id, get_current_user_id(), $page, $per_page );
		return new WP_REST_Response( $data, 200 );
	}
}
