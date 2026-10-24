<?php
/**
 * REST: a member's Portfolio (suite integration panels) for the app.
 *
 * Serves the SAME data the web Portfolio tab renders (`SuitePanelHost::panels()`,
 * fed by the `buddynext_member_suite_panels` provider filter) as JSON, so the
 * native app renders the Portfolio without scraping server HTML. One data source,
 * two surfaces.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use BuddyNext\REST\BaseRestController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET buddynext/v1/members/{id}/portfolio
 */
class PortfolioController extends BaseRestController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected string $namespace = 'buddynext/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/members/(?P<id>\d+)/portfolio',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_portfolio' ),
					// Public route (like the web profile tab), but the read is gated
					// per-request by the canonical profile-visibility check inside
					// get_portfolio() — the same gate the profile template uses.
					// Owner-only data (manage links) is stripped there too.
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Return the member's Portfolio panels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_portfolio( WP_REST_Request $request ): WP_REST_Response {
		$member_id = (int) $request['id'];
		if ( $member_id <= 0 || ! get_userdata( $member_id ) ) {
			return new WP_REST_Response(
				array(
					'member_id' => $member_id,
					'is_owner'  => false,
					'panels'    => array(),
				),
				200
			);
		}

		$viewer_id = get_current_user_id();

		// SECURITY: gate the read with the canonical profile-visibility check (block +
		// public/followers/connections) — the same gate the web Portfolio tab honours
		// via PrivacyService::can_view_profile. Without it this public route leaked a
		// member's job/resume panels to a blocked viewer / a non-follower of a private
		// account. Return the neutral empty-panels shape (as for a missing user) so
		// neither the data nor the member's existence leaks.
		if ( function_exists( 'buddynext_service' ) ) {
			$privacy = buddynext_service( 'privacy' );
			if ( $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
				&& ! $privacy->can_view_profile( $viewer_id, $member_id ) ) {
				return new WP_REST_Response(
					array(
						'member_id' => $member_id,
						'is_owner'  => false,
						'panels'    => array(),
					),
					200
				);
			}
		}

		$is_owner = $viewer_id === $member_id;
		$panels   = ( new SuitePanelHost() )->panels( $member_id );

		$out = array();
		foreach ( $panels as $panel ) {
			// An owner-only panel (e.g. the "continue learning" shelf) is never
			// exposed to another viewer — drop it whole, don't just hide its CTA.
			if ( ! $is_owner && ! empty( $panel['owner_only'] ) ) {
				continue;
			}
			// Owner-only "Manage" links never go to other viewers.
			if ( ! $is_owner ) {
				$panel['owner_cta'] = array();
			}
			unset( $panel['priority'], $panel['owner_only'] );
			$out[] = $panel;
		}

		return new WP_REST_Response(
			array(
				'member_id' => $member_id,
				'is_owner'  => $is_owner,
				'panels'    => $out,
			),
			200
		);
	}
}
