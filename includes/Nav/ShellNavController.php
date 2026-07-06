<?php
/**
 * Shell navigation REST controller.
 *
 * GET buddynext/v1/shell-nav returns the fully resolved app-shell navigation
 * (owner overrides applied) plus live unread badge counts in one call, so a native
 * app renders the same rail/bottom-bar the web shell does instead of hardcoding it.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes the resolved shell nav over REST.
 */
class ShellNavController {

	/**
	 * Register the shell-nav route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/shell-nav',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_shell_nav' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'hub' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET /shell-nav — resolved nav items + badge counts for the current user.
	 *
	 * Public: a logged-out visitor gets the community items (Feed / Explore /
	 * Members / Spaces); a logged-in user also gets Notifications / Messages (with
	 * badges) and their personal group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_shell_nav( WP_REST_Request $request ): WP_REST_Response {
		$hub  = sanitize_key( (string) $request->get_param( 'hub' ) );
		$data = ( new ShellNavService() )->resolve( get_current_user_id(), $hub );

		return new WP_REST_Response( $data, 200 );
	}
}
