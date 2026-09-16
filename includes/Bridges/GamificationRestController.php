<?php
/**
 * REST for the Gamification bridge — the member's Achievements/standing.
 *
 * Exposes the same figures the Achievements profile tab renders (points,
 * current streak, earned badges) as a plain JSON read model, so the app and
 * external developers can render standing without scraping the panel HTML.
 * wb-gamification stays the single source; this only reads + shapes it.
 *
 * Reference implementation of the bridge-REST pattern: a small controller on
 * BuddyNext\REST\BaseRestController, self-registered from the bridge's own
 * rest_api_init pass (bridges are conditional, so they are not wired into the
 * core REST\Router).
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
 * Gamification standing REST (buddynext/v1).
 */
final class GamificationRestController extends BaseRestController {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/members/(?P<id>\d+)/gamification',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_standing' ),
					// Standing (points, badges) is public social proof — the same
					// as the public Achievements tab.
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /members/{id}/gamification — the member's standing read model.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_standing( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 || ! get_userdata( $id ) ) {
			return new WP_Error( 'bn_member_not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		$data = ( new \BuddyNext\Profile\GamificationAchievements() )->standing_data( $id );
		return new WP_REST_Response( $data, 200 );
	}
}
